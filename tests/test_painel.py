"""Painel: login, sessão, CSRF, clientes, chaves, cota e histórico. Sem rede."""
import threading
from unittest.mock import patch

import pytest

pytest.importorskip("fastapi")
from fastapi.testclient import TestClient

from consulta_processos.cache import CacheTTL
from consulta_processos.config import Config
from consulta_processos.modelos import Processo
from consulta_processos.painel import seguranca
from consulta_processos.painel.banco import Banco
from consulta_processos.rest import ConfigAPI, criar_app

EMAIL, SENHA = "dono@empresa.com.br", "Senha-Forte-2026"
NUMERO = "5053283-30.2024.8.13.0079"      # dígito verificador correto
PROCESSO = Processo(numero=NUMERO, tribunal="TJMG")


@pytest.fixture
def banco(tmp_path):
    b = Banco(tmp_path / "api.db")
    b.criar_admin(EMAIL, SENHA, nome="Dono")
    return b


@pytest.fixture
def cliente_http(banco):
    app = criar_app(ConfigAPI(cookie_seguro=False), Config(), CacheTTL(), banco=banco)
    return TestClient(app, raise_server_exceptions=False)


def _entrar(http):
    r = http.post("/admin/api/login", json={"email": EMAIL, "senha": SENHA})
    assert r.status_code == 200, r.text
    return {"X-CSRF-Token": r.json()["csrf"]}


def _novo_cliente(http, csrf, **extra):
    dados = {"nome": "Dev Teste", "email": "dev@teste.com", "plano": "Start", "limite_mensal": 3,
             "limite_por_minuto": 100, **extra}
    r = http.post("/admin/api/clientes", json=dados, headers=csrf)
    assert r.status_code == 201, r.text
    return r.json()["cliente"]


def _chave(http, csrf, cliente_id):
    r = http.post(f"/admin/api/clientes/{cliente_id}/chaves", json={"nome": "produção"}, headers=csrf)
    assert r.status_code == 201, r.text
    return r.json()["chave"]


# ── senha e login ──

def test_senha_fraca_recusada(tmp_path):
    with pytest.raises(ValueError):
        Banco(tmp_path / "x.db").criar_admin("a@b.com", "curta")
    assert seguranca.problema_na_senha("somenteminusculas") is not None


def test_senha_guardada_com_hash(banco):
    import sqlite3
    guardado = sqlite3.connect(banco.caminho).execute("SELECT senha_hash FROM admins").fetchone()[0]
    assert SENHA not in guardado and guardado.startswith("scrypt$")


def test_painel_exige_login(cliente_http):
    assert cliente_http.get("/admin/api/clientes").status_code == 401


def test_login_errado_nao_revela_se_email_existe(cliente_http):
    a = cliente_http.post("/admin/api/login", json={"email": EMAIL, "senha": "Errada-123456"})
    b = cliente_http.post("/admin/api/login", json={"email": "ninguem@x.com", "senha": "Errada-123456"})
    assert a.status_code == b.status_code == 401
    assert a.json()["erro"] == b.json()["erro"]


def test_login_bloqueia_forca_bruta(cliente_http):
    for _ in range(5):
        cliente_http.post("/admin/api/login", json={"email": EMAIL, "senha": "Errada-123456"})
    r = cliente_http.post("/admin/api/login", json={"email": EMAIL, "senha": SENHA})
    assert r.status_code == 429          # nem a senha certa entra durante o bloqueio


def test_cookie_de_sessao_protegido(banco):
    app = criar_app(ConfigAPI(cookie_seguro=True), Config(), CacheTTL(), banco=banco)
    r = TestClient(app).post("/admin/api/login", json={"email": EMAIL, "senha": SENHA})
    cookie = r.headers["set-cookie"].lower()
    assert "httponly" in cookie and "samesite=strict" in cookie and "secure" in cookie
    assert "path=/admin" in cookie


def test_alteracao_sem_csrf_recusada(cliente_http):
    _entrar(cliente_http)
    r = cliente_http.post("/admin/api/clientes", json={"nome": "X", "email": "x@x.com"})
    assert r.status_code == 403
    r = cliente_http.post("/admin/api/clientes", json={"nome": "X", "email": "x@x.com"},
                          headers={"X-CSRF-Token": "falso"})
    assert r.status_code == 403


def test_sair_encerra_a_sessao(cliente_http):
    csrf = _entrar(cliente_http)
    assert cliente_http.post("/admin/api/sair", headers=csrf).status_code == 200
    assert cliente_http.get("/admin/api/clientes").status_code == 401


def test_restricao_por_ip(banco):
    capi = ConfigAPI(cookie_seguro=False, painel_ips="10.0.0.0/8")
    app = criar_app(capi, Config(), CacheTTL(), banco=banco)
    http = TestClient(app)            # o TestClient chega como "testclient", fora da faixa
    assert http.get("/admin").status_code == 404
    assert http.post("/admin/api/login", json={"email": EMAIL, "senha": SENHA}).status_code == 404


# ── clientes e chaves ──

def test_cria_cliente_e_chave_so_aparece_uma_vez(cliente_http, banco):
    csrf = _entrar(cliente_http)
    cliente = _novo_cliente(cliente_http, csrf)
    chave = _chave(cliente_http, csrf, cliente["id"])
    assert chave.startswith("rj_live_")
    detalhe = cliente_http.get(f"/admin/api/clientes/{cliente['id']}").json()
    assert chave not in str(detalhe)                       # só o prefixo volta
    assert detalhe["chaves"][0]["prefixo"] == chave[:14]
    import sqlite3
    linhas = sqlite3.connect(banco.caminho).execute("SELECT * FROM chaves").fetchall()
    assert chave not in str(linhas)                        # nem no banco


def test_email_invalido_recusado(cliente_http):
    csrf = _entrar(cliente_http)
    for email in ("nao-e-email", "a@<script>x.com", "a b@x.com", "a@x"):
        r = cliente_http.post("/admin/api/clientes", json={"nome": "X", "email": email}, headers=csrf)
        assert r.status_code == 422, email


def test_injecao_sql_vira_texto(cliente_http):
    csrf = _entrar(cliente_http)
    nome = "Robert'); DROP TABLE clientes;--"
    cliente = _novo_cliente(cliente_http, csrf, nome=nome)
    assert cliente["nome"] == nome
    assert len(cliente_http.get("/admin/api/clientes").json()["clientes"]) == 1


def test_campo_fora_da_lista_ignorado(cliente_http):
    csrf = _entrar(cliente_http)
    cliente = _novo_cliente(cliente_http, csrf)
    r = cliente_http.patch(f"/admin/api/clientes/{cliente['id']}", json={"id": 999, "criado_em": "x"},
                           headers=csrf)
    assert r.status_code == 200 and r.json()["cliente"]["id"] == cliente["id"]


def test_revogar_chave_de_outro_cliente_nao_funciona(cliente_http):
    csrf = _entrar(cliente_http)
    a = _novo_cliente(cliente_http, csrf, email="a@a.com")
    b = _novo_cliente(cliente_http, csrf, email="b@b.com")
    _chave(cliente_http, csrf, a["id"])
    chave_a = cliente_http.get(f"/admin/api/clientes/{a['id']}").json()["chaves"][0]["id"]
    r = cliente_http.delete(f"/admin/api/clientes/{b['id']}/chaves/{chave_a}", headers=csrf)
    assert r.status_code == 404


def test_auditoria_registra_acoes(cliente_http):
    csrf = _entrar(cliente_http)
    cliente = _novo_cliente(cliente_http, csrf)
    _chave(cliente_http, csrf, cliente["id"])
    acoes = [e["acao"] for e in cliente_http.get("/admin/api/eventos").json()["eventos"]]
    assert {"login", "cliente_criado", "chave_gerada"} <= set(acoes)
    assert all(e["admin_email"] == EMAIL for e in cliente_http.get("/admin/api/eventos").json()["eventos"]
               if e["acao"] != "login_falhou")


# ── API com chave do painel: cota, suspensão, histórico ──

def _consultar(http, chave, numero=NUMERO):
    return http.get(f"/v1/processos/{numero}", headers={"X-API-Key": chave})


def test_cota_mensal(cliente_http):
    csrf = _entrar(cliente_http)
    cliente = _novo_cliente(cliente_http, csrf, limite_mensal=2)
    chave = _chave(cliente_http, csrf, cliente["id"])
    with patch("consulta_processos.rest.consultar_processo_detalhado", return_value=(PROCESSO, [])):
        r1 = _consultar(cliente_http, chave)
        r2 = _consultar(cliente_http, chave)
        r3 = _consultar(cliente_http, chave)
    assert r1.status_code == r2.status_code == 200
    assert r1.headers["X-Cota-Restante"] == "1" and r2.headers["X-Cota-Restante"] == "0"
    assert r3.status_code == 402 and r3.json()["erro"]["codigo"] == "cota_esgotada"
    conta = cliente_http.get("/v1/conta", headers={"X-API-Key": chave}).json()
    assert conta["usado_no_mes"] == 2 and conta["restante"] == 0


def test_chamada_com_erro_nao_e_cobrada(cliente_http):
    csrf = _entrar(cliente_http)
    cliente = _novo_cliente(cliente_http, csrf, limite_mensal=5)
    chave = _chave(cliente_http, csrf, cliente["id"])
    with patch("consulta_processos.rest.consultar_processo_detalhado", return_value=(None, [])):
        assert _consultar(cliente_http, chave).status_code == 404
    assert _consultar(cliente_http, chave, "5053283-31.2024.8.13.0079").status_code == 422
    with patch("consulta_processos.rest.consultar_processo_detalhado", side_effect=RuntimeError("x")):
        assert _consultar(cliente_http, chave).status_code == 500
    conta = cliente_http.get("/v1/conta", headers={"X-API-Key": chave}).json()
    assert conta["usado_no_mes"] == 0
    historico = cliente_http.get(f"/admin/api/clientes/{cliente['id']}").json()["uso"]
    status = sorted(u["status"] for u in historico if u["caminho"].startswith("/v1/processos"))
    assert status == [404, 422, 500]


def test_cota_nao_estoura_com_chamadas_simultaneas(banco):
    cid = banco.criar_cliente({"nome": "C", "email": "c@c.com", "limite_mensal": 5})
    texto, _ = banco.criar_chave(cid)
    chave = banco.chave_valida(texto)
    liberadas = []

    def tentar():
        ok, _, _ = banco.reservar(chave, metodo="GET", caminho="/v1/x", ip="1", cobravel=True)
        liberadas.append(ok)

    threads = [threading.Thread(target=tentar) for _ in range(20)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    assert liberadas.count(True) == 5


def test_cliente_suspenso_bloqueado(cliente_http):
    csrf = _entrar(cliente_http)
    cliente = _novo_cliente(cliente_http, csrf)
    chave = _chave(cliente_http, csrf, cliente["id"])
    cliente_http.patch(f"/admin/api/clientes/{cliente['id']}", json={"ativo": False}, headers=csrf)
    r = _consultar(cliente_http, chave)
    assert r.status_code == 403 and r.json()["erro"]["codigo"] == "conta_suspensa"


def test_chave_revogada_para_na_hora(cliente_http):
    csrf = _entrar(cliente_http)
    cliente = _novo_cliente(cliente_http, csrf)
    chave = _chave(cliente_http, csrf, cliente["id"])
    chave_id = cliente_http.get(f"/admin/api/clientes/{cliente['id']}").json()["chaves"][0]["id"]
    cliente_http.delete(f"/admin/api/clientes/{cliente['id']}/chaves/{chave_id}", headers=csrf)
    assert cliente_http.get("/v1/conta", headers={"X-API-Key": chave}).status_code == 401


def test_limite_por_minuto_do_cliente(cliente_http):
    csrf = _entrar(cliente_http)
    cliente = _novo_cliente(cliente_http, csrf, limite_por_minuto=2)
    chave = _chave(cliente_http, csrf, cliente["id"])
    cabecalho = {"X-API-Key": chave}
    assert cliente_http.get(f"/v1/cnj/{NUMERO}", headers=cabecalho).status_code == 200
    assert cliente_http.get(f"/v1/cnj/{NUMERO}", headers=cabecalho).status_code == 200
    assert cliente_http.get(f"/v1/cnj/{NUMERO}", headers=cabecalho).status_code == 429


def test_rotas_do_painel_fora_da_documentacao(cliente_http):
    caminhos = cliente_http.get("/openapi.json").json()["paths"]
    assert not any(c.startswith("/admin") for c in caminhos)


def test_pagina_do_painel_com_csp_estrita(cliente_http):
    r = cliente_http.get("/admin")
    assert r.status_code == 200 and "Robô" in r.text
    csp = r.headers["Content-Security-Policy"]
    assert "script-src 'self'" in csp and "unsafe-inline" not in csp
    assert cliente_http.get("/admin/estatico/app.js").status_code == 200
    assert cliente_http.get("/admin/estatico/..%2Fbanco.py").status_code == 404
