"""Painel do monitor: servidor real numa porta livre, buscas falsas."""
import http.client
import json
import threading
from pathlib import Path

import pytest

from consulta_processos.monitor import Banco
from consulta_processos.monitor.painel import PAGINA, criar_servidor

from .monitor_amostras import PROCESSO_TJMG, pub


@pytest.fixture
def painel(tmp_path):
    banco = Banco(tmp_path / "monitor.db")
    servidor = criar_servidor(
        banco, porta=0,
        buscar_oab=lambda numero, uf, **_: [pub("pauta"), pub("prazo5")],
        buscar_processo=lambda numero, **_: [])
    fio = threading.Thread(target=servidor.serve_forever, daemon=True)
    fio.start()
    yield servidor
    servidor.shutdown()
    servidor.server_close()


def pedir(servidor, metodo, caminho, corpo=None, cabecalhos=None):
    con = http.client.HTTPConnection("127.0.0.1", servidor.server_port, timeout=10)
    padrao = {}
    if metodo == "POST":
        padrao = {"Content-Type": "application/json", "X-Painel": "1"}
    con.request(metodo, caminho, body=json.dumps(corpo if corpo is not None else {}) if metodo == "POST" else None,
                headers={**padrao, **(cabecalhos or {})})
    resposta = con.getresponse()
    bruto = resposta.read()
    con.close()
    tipo = resposta.getheader("Content-Type") or ""
    return resposta.status, (json.loads(bruto) if "json" in tipo else bruto.decode("utf-8")), resposta


def test_pagina_inicial_e_cabecalhos_de_seguranca(painel):
    status, html, resposta = pedir(painel, "GET", "/")
    assert status == 200 and "Monitor Jurídico" in html
    assert resposta.getheader("X-Content-Type-Options") == "nosniff"
    assert "frame-ancestors 'none'" in resposta.getheader("Content-Security-Policy")


def test_pagina_nunca_injeta_html_vindo_do_tribunal():
    fonte = Path(PAGINA).read_text(encoding="utf-8")
    assert "innerHTML" not in fonte and "insertAdjacentHTML" not in fonte and "document.write" not in fonte


def test_fluxo_completo_cadastro_busca_e_consulta(painel):
    status, r, _ = pedir(painel, "POST", "/api/monitorados",
                         {"tipo": "oab", "numero": "101332", "uf": "MG", "rotulo": "Advogado Um"})
    assert status == 200 and r["ok"]
    status, r, _ = pedir(painel, "POST", "/api/executar")
    assert r["ok"] and r["iniciou"]
    for _ in range(100):                                     # espera a rotina terminar
        if not painel.estado.executando:
            break
        threading.Event().wait(0.05)

    _, r, _ = pedir(painel, "GET", "/api/resumo")
    assert r["dado"]["total_publicacoes"] == 2 and r["dado"]["nao_lidas"] == 2
    assert r["execucao"]["rodando"] is False and r["execucao"]["ultimo"] == {"buscados": 1, "novos": 2, "erros": []}
    assert "[CONFIRMAR]" in r["aviso_prazo"] and r["categorias"]["intimacao"] == "Intimação"
    assert [p["data_fim"] for p in r["dado"]["prazos_proximos"]] == ["2026-10-20"]

    _, r, _ = pedir(painel, "GET", "/api/publicacoes?advogado=MG-101332&segmento=estadual&nao_lidas=1")
    assert len(r["dado"]) == 2
    id_ = next(p["id"] for p in r["dado"] if p["numero_processo"] == PROCESSO_TJMG)

    _, r, _ = pedir(painel, "GET", f"/api/publicacoes/{id_}")
    assert "pauta de julgamento" in r["dado"]["texto"] and r["dado"]["audiencia_em"] == "2026-10-22T09:00"
    assert pedir(painel, "POST", f"/api/publicacoes/{id_}/lida", {"lida": True})[1]["ok"]
    _, r, _ = pedir(painel, "GET", "/api/publicacoes?nao_lidas=1")
    assert len(r["dado"]) == 1

    prazo_id = next(p["id"] for p in painel.estado.banco.publicacoes() if p["prazo"])
    _, r, _ = pedir(painel, "POST", f"/api/prazos/{prazo_id}", {"status": "confirmado", "observacao": "ok"})
    assert r["ok"]
    status, r, _ = pedir(painel, "POST", f"/api/prazos/{prazo_id}", {"status": "inventado"})
    assert status == 400 and not r["ok"]


def test_cadastro_com_erro_volta_recado_claro(painel):
    status, r, _ = pedir(painel, "POST", "/api/monitorados", {"tipo": "processo", "numero": "123"})
    assert status == 400 and "inválido" in r["erro"]
    status, r, _ = pedir(painel, "POST", "/api/monitorados", {"tipo": "outro"})
    assert status == 400
    status, r, _ = pedir(painel, "POST", "/api/rota-que-nao-existe")
    assert status == 400


def test_remover_e_pausar(painel):
    id_ = pedir(painel, "POST", "/api/monitorados", {"tipo": "oab", "numero": "1", "uf": "MG"})[1]["dado"]["id"]
    assert pedir(painel, "POST", f"/api/monitorados/{id_}/ativo", {"ativo": False})[1]["ok"]
    assert pedir(painel, "GET", "/api/monitorados")[1]["dado"][0]["ativo"] == 0
    assert pedir(painel, "POST", f"/api/monitorados/{id_}/remover")[1]["ok"]
    assert pedir(painel, "GET", "/api/monitorados")[1]["dado"] == []


# ── proteções ────────────────────────────────────────────────────────────────

def test_recusa_pedido_sem_o_cabecalho_da_tela(painel):
    status, r, _ = pedir(painel, "POST", "/api/monitorados", {"tipo": "oab", "numero": "1", "uf": "MG"},
                         cabecalhos={"X-Painel": "0"})
    assert status == 403
    assert painel.estado.banco.listar_monitorados() == []


def test_recusa_pedido_que_nao_e_json(painel):
    status, _, _ = pedir(painel, "POST", "/api/executar", cabecalhos={"Content-Type": "text/plain"})
    assert status == 403


def test_recusa_pedido_de_outro_site(painel):
    status, _, _ = pedir(painel, "POST", "/api/executar", cabecalhos={"Origin": "https://site-malicioso.exemplo"})
    assert status == 403


def test_aceita_origem_da_propria_tela(painel):
    origem = f"http://localhost:{painel.server_port}"
    status, r, _ = pedir(painel, "POST", "/api/lidas", cabecalhos={"Origin": origem})
    assert status == 200 and r["ok"]


def test_recusa_host_desconhecido_contra_dns_rebinding(painel):
    for metodo, caminho in (("GET", "/api/resumo"), ("GET", "/"), ("POST", "/api/executar")):
        status, _, _ = pedir(painel, metodo, caminho, cabecalhos={"Host": "atacante.exemplo"})
        assert status == 403, (metodo, caminho)


def test_corpo_malformado_ou_grande_demais(painel):
    con = http.client.HTTPConnection("127.0.0.1", painel.server_port, timeout=10)
    con.request("POST", "/api/monitorados", body=b"{nao e json",
                headers={"Content-Type": "application/json", "X-Painel": "1"})
    assert con.getresponse().status == 400
    con.close()
    con = http.client.HTTPConnection("127.0.0.1", painel.server_port, timeout=10)
    con.request("POST", "/api/monitorados", body=b"[1,2]",
                headers={"Content-Type": "application/json", "X-Painel": "1"})
    assert con.getresponse().status == 400
    con.close()


def test_filtro_numerico_invalido_nao_derruba(painel):
    status, r, _ = pedir(painel, "GET", "/api/publicacoes?limite=abc")
    assert status == 400 and not r["ok"]


def test_so_uma_rotina_por_vez(painel):
    estado = painel.estado
    liberar = threading.Event()
    estado.buscar_oab = lambda *a, **k: (liberar.wait(5), [])[1]
    estado.banco.adicionar_oab("1", "MG")
    assert estado.executar_agora() is True
    assert estado.executar_agora() is False                  # a primeira ainda está presa
    liberar.set()
    for _ in range(100):
        if not estado.executando:
            break
        threading.Event().wait(0.05)
    assert estado.executando is False


def test_icone_da_aba_nao_gera_erro(painel):
    status, _, _ = pedir(painel, "GET", "/favicon.ico")
    assert status == 204
