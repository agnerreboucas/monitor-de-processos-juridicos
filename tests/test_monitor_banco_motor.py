"""Banco e rotina do monitor, com buscas falsas (nada sai para a rede)."""
from datetime import date

import pytest

from consulta_processos.erros import FonteIndisponivel
from consulta_processos.monitor import Banco, ErroDeCadastro, executar
from consulta_processos.monitor.motor import JANELA_INICIAL_DIAS, SOBREPOSICAO_DIAS

from .monitor_amostras import PROCESSO_TJMG, PROCESSO_TJRO, PROCESSO_TRT8, pub

HOJE = date(2026, 10, 10)


@pytest.fixture
def banco(tmp_path):
    return Banco(tmp_path / "monitor.db")


class BuscaFalsa:
    """Substitui as buscas reais e anota com que janela foi chamada."""

    def __init__(self, respostas=None, erro=None):
        self.respostas = respostas or {}
        self.erro = erro
        self.chamadas = []

    def oab(self, numero, uf, *, inicio, fim, paginas, config):
        self.chamadas.append(("oab", numero, uf, inicio, fim, paginas))
        if self.erro:
            raise self.erro
        return self.respostas.get(("oab", numero, uf), [])

    def processo(self, numero, *, inicio, fim, config):
        self.chamadas.append(("processo", numero, inicio, fim))
        if self.erro:
            raise self.erro
        return self.respostas.get(("processo", numero), [])


# ── cadastro ─────────────────────────────────────────────────────────────────

def test_arquivo_do_banco_so_para_o_dono(banco):
    assert oct(banco.caminho.stat().st_mode & 0o777) == "0o600"


def test_cadastro_de_oab_normaliza_e_recusa_duplicata(banco):
    banco.adicionar_oab("123.456", "mg", "Dra. Fulana")
    (m,) = banco.listar_monitorados()
    assert (m["oab_numero"], m["oab_uf"], m["rotulo"], m["ativo"]) == ("123456", "MG", "Dra. Fulana", 1)
    with pytest.raises(ErroDeCadastro, match="já está"):
        banco.adicionar_oab("123456", "MG")


@pytest.mark.parametrize("numero,uf", [("", "MG"), ("abc", "MG"), ("123", ""), ("123", "MGX"), ("123", "1A")])
def test_oab_invalida(banco, numero, uf):
    with pytest.raises(ErroDeCadastro):
        banco.adicionar_oab(numero, uf)


def test_cadastro_de_processo_guarda_formatado_e_recusa_invalido(banco):
    banco.adicionar_processo("00229291620168130297")
    assert banco.listar_monitorados()[0]["numero_processo"] == PROCESSO_TJMG
    with pytest.raises(ErroDeCadastro, match="inválido"):
        banco.adicionar_processo("1234")
    with pytest.raises(ErroDeCadastro, match="já está"):
        banco.adicionar_processo(PROCESSO_TJMG)


def test_pausar_e_remover(banco):
    id_ = banco.adicionar_oab("1", "MG")
    assert banco.definir_ativo(id_, False)
    assert banco.listar_monitorados(so_ativos=True) == []
    assert banco.remover_monitorado(id_)
    assert not banco.remover_monitorado(id_)


def test_tentativa_de_injecao_em_texto_nao_quebra(banco):
    id_ = banco.adicionar_oab("1", "MG", "x'); DROP TABLE monitorados;--")
    assert banco.listar_monitorados()[0]["id"] == id_
    assert banco.publicacoes(busca="'; DROP TABLE publicacoes;--") == []


# ── rotina ───────────────────────────────────────────────────────────────────

def test_primeira_busca_usa_janela_inicial_e_grava_o_que_veio(banco):
    banco.adicionar_oab("101332", "MG", "Advogado Um")
    busca = BuscaFalsa({("oab", "101332", "MG"): [pub("pauta"), pub("prazo5")]})
    r = executar(banco, hoje=HOJE, buscar_oab=busca.oab, buscar_processo=busca.processo)
    assert (r.buscados, r.novos, r.erros) == (1, 2, [])
    _, _, _, inicio, fim, paginas = busca.chamadas[0]
    assert (fim, paginas) == (HOJE, 0)                       # 0 = todas as páginas
    assert (HOJE - inicio).days == JANELA_INICIAL_DIAS
    assert len(banco.publicacoes()) == 2


def test_segunda_busca_recua_a_partir_da_ultima_e_nao_duplica(banco):
    id_ = banco.adicionar_oab("101332", "MG")
    busca = BuscaFalsa({("oab", "101332", "MG"): [pub("pauta")]})
    executar(banco, hoje=date(2026, 10, 9), buscar_oab=busca.oab, buscar_processo=busca.processo)
    # a "última busca" é gravada com o relógio real; fixo uma data para o teste ser exato
    with banco._conexao() as con:
        con.execute("UPDATE monitorados SET ultima_busca='2026-10-06T10:00:00Z' WHERE id=?", (id_,))
    r = executar(banco, hoje=HOJE, buscar_oab=busca.oab, buscar_processo=busca.processo)
    assert r.novos == 0                                       # a mesma publicação voltou: não é nova
    assert busca.chamadas[1][3] == date(2026, 10, 6 - SOBREPOSICAO_DIAS)
    assert len(banco.publicacoes()) == 1


def test_publicacao_que_vem_de_oab_e_de_processo_fica_uma_so(banco):
    banco.adicionar_oab("101332", "MG")
    banco.adicionar_processo(PROCESSO_TJMG)
    busca = BuscaFalsa({("oab", "101332", "MG"): [pub("pauta")], ("processo", PROCESSO_TJMG): [pub("pauta")]})
    r = executar(banco, hoje=HOJE, buscar_oab=busca.oab, buscar_processo=busca.processo)
    assert r.novos == 1
    assert len(banco.publicacoes()) == 1
    assert len(banco.publicacoes(advogado="MG-101332")) == 1


def test_fonte_fora_do_ar_anota_erro_nao_avanca_a_busca_e_segue_com_os_outros(banco):
    banco.adicionar_oab("1", "MG")
    banco.adicionar_oab("2", "SP")

    def oab(numero, uf, **_):
        if numero == "1":
            raise FonteIndisponivel("Comunica", "caiu")
        return [pub("pauta")]

    r = executar(banco, hoje=HOJE, buscar_oab=oab, buscar_processo=lambda *a, **k: [])
    assert (r.buscados, r.novos) == (1, 1)
    assert len(r.erros) == 1 and "OAB MG 1" in r.erros[0]
    com_erro, bom = banco.listar_monitorados()
    assert com_erro["ultima_busca"] is None and "caiu" in com_erro["ultimo_erro"]
    assert bom["ultima_busca"] and bom["ultimo_erro"] == ""
    ultima = banco.ultima_execucao()
    assert ultima["terminada_em"] and ultima["novos"] == 1 and len(ultima["erros"]) == 1


def test_item_pausado_nao_e_consultado(banco):
    id_ = banco.adicionar_oab("1", "MG")
    banco.definir_ativo(id_, False)
    busca = BuscaFalsa()
    r = executar(banco, hoje=HOJE, buscar_oab=busca.oab, buscar_processo=busca.processo)
    assert r.buscados == 0 and busca.chamadas == []


def test_publicacao_sem_identificador_nao_derruba_as_outras(banco):
    banco.adicionar_oab("1", "MG")
    ruim = pub("pauta")
    ruim.id_externo = ""
    r = executar(banco, hoje=HOJE, buscar_oab=lambda *a, **k: [ruim, pub("prazo5")],
                 buscar_processo=lambda *a, **k: [])
    assert r.novos == 1 and len(r.erros) == 1


# ── o que fica guardado ──────────────────────────────────────────────────────

@pytest.fixture
def cheio(banco):
    banco.adicionar_oab("101332", "MG", "Advogado Um")
    busca = BuscaFalsa({("oab", "101332", "MG"): [pub(k) for k in
                        ("pauta", "prazo5", "audiencia", "distribuicao", "sentenca", "edital_stm")]})
    executar(banco, hoje=HOJE, buscar_oab=busca.oab, buscar_processo=busca.processo)
    return banco


def test_local_do_processo_sai_do_numero(cheio):
    por_numero = {p["numero_processo"]: p for p in cheio.publicacoes()}
    tjmg = por_numero[PROCESSO_TJMG]
    assert (tjmg["tribunal"], tjmg["uf"], tjmg["segmento"], tjmg["categoria"]) == ("TJMG", "MG", 8, "intimacao")
    trt = por_numero[PROCESSO_TRT8]
    assert (trt["tribunal"], trt["uf"], trt["segmento"]) == ("TRT8", "", 5)      # trabalhista: sem UF única


def test_filtros(cheio):
    assert {p["tribunal"] for p in cheio.publicacoes(segmento="estadual")} == {"TJMG", "TJRO", "TJMS", "TJTO"}
    assert [p["tribunal"] for p in cheio.publicacoes(segmento="trabalho")] == ["TRT8"]
    assert [p["tribunal"] for p in cheio.publicacoes(segmento="outros")] == ["STM"]
    assert [p["tribunal"] for p in cheio.publicacoes(uf="ro")] == ["TJRO"]
    assert [p["categoria"] for p in cheio.publicacoes(categoria="sentenca")] == ["sentenca"]
    assert len(cheio.publicacoes(busca="Castanhal")) == 1          # nome do órgão
    assert len(cheio.publicacoes(busca="SEGURADORA")) == 1         # nome de parte
    assert len(cheio.publicacoes(advogado="MG-101332")) == 6
    assert cheio.publicacoes(advogado="SP-999") == []
    assert len(cheio.publicacoes(limite=2)) == 2


def test_lida_e_nao_lida(cheio):
    assert len(cheio.publicacoes(nao_lidas=True)) == 6
    primeira = cheio.publicacoes()[0]
    assert cheio.marcar_lida(primeira["id"])
    assert len(cheio.publicacoes(nao_lidas=True)) == 5
    assert cheio.marcar_todas_lidas() == 5
    assert cheio.publicacoes(nao_lidas=True) == []


def test_lista_traz_trecho_e_detalhe_traz_teor_inteiro(cheio):
    pub_id = cheio.publicacoes(busca="Castanhal")[0]["id"]
    assert len(cheio.publicacoes(busca="Castanhal")[0]["texto"]) <= 400
    detalhe = cheio.obter_publicacao(pub_id)
    assert "AUDIÊNCIA UNA" in detalhe["texto"]
    assert cheio.obter_publicacao(99999) is None


def test_prazo_e_audiencia_gravados(cheio):
    tjro = cheio.publicacoes(uf="RO")[0]
    assert tjro["prazo"]["dias"] == 5 and tjro["prazo"]["data_fim"] == "2026-10-20"
    assert tjro["prazo"]["status"] == "a_confirmar"
    assert cheio.publicacoes(uf="MG")[0]["audiencia_em"] == "2026-10-22T09:00"
    assert cheio.publicacoes(segmento="outros")[0]["prazo"] is None        # edital


def test_atualizar_prazo(cheio):
    pub_id = cheio.publicacoes(uf="RO")[0]["id"]
    assert cheio.atualizar_prazo(pub_id, status="confirmado", observacao="  conferi no PJe ",
                                 data_fim="2026-10-21")
    prazo = cheio.obter_publicacao(pub_id)["prazo"]
    assert (prazo["status"], prazo["observacao"], prazo["data_fim"]) == ("confirmado", "conferi no PJe", "2026-10-21")
    with pytest.raises(ErroDeCadastro):
        cheio.atualizar_prazo(pub_id, status="inventado")
    with pytest.raises(ErroDeCadastro):
        cheio.atualizar_prazo(pub_id, data_fim="21/10/2026")
    assert not cheio.atualizar_prazo(pub_id)                       # nada para mudar
    assert not cheio.atualizar_prazo(99999, status="cumprido")     # publicação sem prazo


# ── resumo da tela inicial ───────────────────────────────────────────────────

def test_resumo(cheio):
    r = cheio.resumo(hoje=HOJE)
    assert r["nao_lidas"] == r["total_publicacoes"] == 6
    assert [p["data_fim"] for p in r["prazos_proximos"]] == ["2026-10-20"]
    assert r["prazos_vencidos"] == [] and r["alertas_criticos"] == []
    assert [a["audiencia_em"] for a in r["audiencias"]] == [
        "2026-10-22T09:00", "2026-12-02T12:30", "2027-03-11T10:35"]
    assert r["por_uf"] == {"MG": 1, "RO": 1, "MS": 1, "TO": 1}
    assert r["por_segmento"] == {"estadual": 4, "federal": 0, "trabalho": 1, "outros": 1}
    assert r["por_advogado"][0]["rotulo"] == "Advogado Um" and r["por_advogado"][0]["publicacoes"] == 6
    assert "[CONFIRMAR]" in r["aviso_prazo"]


def test_prazo_vencido_e_critico(cheio):
    r = cheio.resumo(hoje=date(2026, 10, 22))
    assert [p["data_fim"] for p in r["prazos_vencidos"]] == ["2026-10-20"]
    assert len(r["alertas_criticos"]) == 1
    cheio.atualizar_prazo(cheio.publicacoes(uf="RO")[0]["id"], status="cumprido")
    r = cheio.resumo(hoje=date(2026, 10, 22))
    assert r["prazos_vencidos"] == [] and r["alertas_criticos"] == []


def test_processo_parado(banco):
    banco.adicionar_processo(PROCESSO_TJMG, "Caso A")
    banco.adicionar_processo(PROCESSO_TJRO, "Caso B")
    busca = BuscaFalsa({("processo", PROCESSO_TJMG): [pub("pauta")]})        # publicado 2026-10-09
    executar(banco, hoje=HOJE, buscar_oab=busca.oab, buscar_processo=busca.processo)
    parados = banco.resumo(hoje=HOJE)["parados"]
    assert [p["rotulo"] for p in parados] == ["Caso B"] and parados[0]["ultima_publicacao"] is None
    parados = banco.resumo(hoje=date(2027, 1, 1))["parados"]               # mais de 60 dias depois
    assert {p["rotulo"] for p in parados} == {"Caso A", "Caso B"}
