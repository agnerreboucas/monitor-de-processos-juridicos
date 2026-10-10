"""API REST (FastAPI): autenticação, limite, formato da resposta e erros. Sem rede."""
from unittest.mock import patch

import pytest

pytest.importorskip("fastapi")
from fastapi.testclient import TestClient

from consulta_processos import CNJ
from consulta_processos.api import _juntar_publicacoes
from consulta_processos.cache import CacheTTL
from consulta_processos.config import Config
from consulta_processos.erros import FonteIndisponivel
from consulta_processos.fontes.comunica import montar_publicacao
from consulta_processos.fontes.datajud import montar_processo
from consulta_processos.rest import ConfigAPI, LimiteDeTaxa, criar_app, dinheiro_br, ler_chaves

from .test_comunica import ITEM
from .test_datajud import RESPOSTA

CHAVE = "k" * 40
NUMERO = "1000254-02.2025.8.13.0079"   # dígito verificador correto


def _processo():
    processo = montar_processo(RESPOSTA, CNJ("1000254-20.2025.8.13.0079"))
    processo.numero = NUMERO
    _juntar_publicacoes(processo, [montar_publicacao(ITEM)])
    return processo


def _cliente(limite=60, falhas=10):
    capi = ConfigAPI(chaves=ler_chaves(f"escritorio-teste:{CHAVE}"),
                     limite_por_minuto=limite, falhas_por_minuto=falhas)
    return TestClient(criar_app(capi, Config(), CacheTTL()), raise_server_exceptions=False)


def _get(cliente, caminho, chave=CHAVE):
    return cliente.get(caminho, headers={"X-API-Key": chave} if chave else {})


def test_sem_chave_recusa():
    r = _get(_cliente(), f"/v1/processos/{NUMERO}", chave=None)
    assert r.status_code == 401
    assert r.json()["erro"]["codigo"] == "nao_autorizado"
    assert r.headers["WWW-Authenticate"] == "ApiKey"


def test_chave_errada_recusa():
    r = _get(_cliente(), f"/v1/processos/{NUMERO}", chave="x" * 40)
    assert r.status_code == 401


def test_chave_fraca_e_ignorada():
    assert ler_chaves("a:curta,b:" + "z" * 32) == {"b": ler_chaves("b:" + "z" * 32)["b"]}


def test_forca_bruta_bloqueia_o_ip():
    cliente = _cliente(falhas=3)
    for _ in range(3):
        assert _get(cliente, "/v1/cnj/1", chave="y" * 40).status_code == 401
    r = _get(cliente, "/v1/cnj/1", chave=CHAVE)       # nem a chave certa passa enquanto bloqueado
    assert r.status_code == 429
    assert "Retry-After" in r.headers


def test_limite_por_cliente():
    cliente = _cliente(limite=2)
    assert _get(cliente, f"/v1/cnj/{NUMERO}").status_code == 200
    assert _get(cliente, f"/v1/cnj/{NUMERO}").status_code == 200
    r = _get(cliente, f"/v1/cnj/{NUMERO}")
    assert r.status_code == 429
    assert r.json()["erro"]["codigo"] == "limite_excedido"


def test_processo_completo_formatado():
    with patch("consulta_processos.rest.consultar_processo_detalhado", return_value=(_processo(), [])):
        # sem pontuação também vale
        r = _get(_cliente(), "/v1/processos/" + NUMERO.replace("-", "").replace(".", ""))
    assert r.status_code == 200
    corpo = r.json()
    p = corpo["processo"]
    assert corpo["ok"] is True
    assert p["numero"] == NUMERO
    assert p["numero_digitos"] == NUMERO.replace("-", "").replace(".", "")
    assert p["tribunal"] == "TJMG"
    assert set(p["fontes"]) == {"datajud", "comunica"}
    assert p["totais"]["movimentacoes"] == len(p["movimentacoes"])
    assert p["totais"]["publicacoes"] == 1
    assert p["polos"]["ativo"] and p["polos"]["passivo"]
    mov = p["movimentacoes"][0]
    assert len(mov["data_formatada"]) == 10 and mov["data_formatada"][2] == "/"
    assert p["data_ultima_movimentacao_formatada"] == "22/09/2026"
    assert p["publicacoes"][0]["texto"]


def test_numero_invalido_nao_consulta_fonte():
    with patch("consulta_processos.rest.consultar_processo_detalhado") as consulta:
        r = _get(_cliente(), "/v1/processos/1234567-00.2025.8.13.0079")
    assert r.status_code == 422
    assert r.json()["erro"]["codigo"] == "numero_invalido"
    consulta.assert_not_called()


def test_nao_encontrado():
    with patch("consulta_processos.rest.consultar_processo_detalhado", return_value=(None, [])):
        r = _get(_cliente(), f"/v1/processos/{NUMERO}")
    assert r.status_code == 404
    assert r.json()["erro"]["codigo"] == "processo_nao_encontrado"


def test_fontes_fora_do_ar_nao_vira_404():
    with patch("consulta_processos.rest.consultar_processo_detalhado",
               return_value=(None, ["O Datajud não respondeu"])):
        r = _get(_cliente(), f"/v1/processos/{NUMERO}")
    assert r.status_code == 503


def test_fonte_indisponivel_vira_503():
    with patch("consulta_processos.rest.consultar_processo_pelo_diario",
               side_effect=FonteIndisponivel("Comunica", status=502)):
        r = _get(_cliente(), f"/v1/processos/{NUMERO}?modo=rapido")
    assert r.status_code == 503
    assert r.headers["Retry-After"] == "30"


def test_erro_inesperado_nao_vaza_detalhe():
    with patch("consulta_processos.rest.consultar_processo_detalhado",
               side_effect=RuntimeError("senha=segredo /caminho/interno.py")):
        r = _get(_cliente(), f"/v1/processos/{NUMERO}")
    assert r.status_code == 500
    assert "segredo" not in r.text and "interno.py" not in r.text
    assert r.json()["id_requisicao"] == r.headers["X-Request-ID"]


def test_modo_desconhecido_recusado():
    r = _get(_cliente(), f"/v1/processos/{NUMERO}?modo=tudo")
    assert r.status_code == 422
    assert r.json()["erro"]["codigo"] == "entrada_invalida"


def test_digito_verificador():
    from consulta_processos.rest import digito_confere
    assert digito_confere(CNJ(NUMERO))
    assert digito_confere(CNJ("5053283-30.2024.8.13.0079"))      # número real do TJMG
    assert not digito_confere(CNJ("5053283-31.2024.8.13.0079"))


def test_cnj_decodifica():
    r = _get(_cliente(), f"/v1/cnj/{NUMERO}")
    assert r.json()["tribunal"] == "TJMG" and r.json()["ano"] == 2025


def test_oab_valida_entrada():
    cliente = _cliente()
    assert _get(cliente, "/v1/oab/XX/123456/processos").status_code == 422
    assert _get(cliente, "/v1/oab/MG/12;rm/processos").status_code in (404, 422)
    assert _get(cliente, "/v1/oab/MG/123456/publicacoes?dias=999").status_code == 422


def test_oab_publicacoes():
    with patch("consulta_processos.rest.comunica.publicacoes_por_oab",
               return_value=[montar_publicacao(ITEM)]) as busca:
        r = _get(_cliente(), "/v1/oab/mg/123456/publicacoes?dias=7")
    assert r.status_code == 200
    assert r.json()["oab"] == "OAB/MG 123456"
    assert r.json()["total"] == 1
    assert busca.call_args.args[:2] == ("123456", "MG")


def test_cabecalhos_de_seguranca():
    r = _get(_cliente(), "/saude", chave=None)
    assert r.status_code == 200
    assert r.headers["X-Content-Type-Options"] == "nosniff"
    assert r.headers["Cache-Control"] == "no-store"
    assert "default-src 'none'" in r.headers["Content-Security-Policy"]


def test_rota_inexistente_no_mesmo_formato():
    r = _get(_cliente(), "/nada")
    assert r.status_code == 404
    assert r.json()["ok"] is False


def test_limite_libera_depois_da_janela():
    limite = LimiteDeTaxa(1, janela=0.05)
    assert limite.registrar("a")[0]
    assert not limite.registrar("a")[0]
    import time
    time.sleep(0.06)
    assert limite.registrar("a")[0]


def test_dinheiro_br():
    assert dinheiro_br(1234567.8) == "R$ 1.234.567,80"
    assert dinheiro_br(None) == ""
