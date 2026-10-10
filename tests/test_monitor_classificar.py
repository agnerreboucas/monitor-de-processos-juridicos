"""Categoria, audiência e prazo sugerido. Regras simples, então testadas caso a caso."""
from datetime import date, datetime

from consulta_processos.modelos import Publicacao
from consulta_processos.monitor.classificar import (
    calcular_fim, classificar, extrair_audiencia, proximo_dia_util, somar_dias_uteis, sugerir_prazo)

from .monitor_amostras import pub


def _pub(texto, tipo_doc="Intimação", tipo_com="Intimação", data=date(2026, 10, 9)):
    return Publicacao(id_externo="x", data_disponibilizacao=data, tipo_comunicacao=tipo_com,
                      tipo_documento=tipo_doc, texto=texto)


def test_categorias_das_amostras():
    esperado = {"pauta": "intimacao", "prazo5": "intimacao", "audiencia": "intimacao",
                "distribuicao": "distribuicao", "sentenca": "sentenca",
                "edital_stm": "edital", "edital_citacao": "edital"}
    assert {k: classificar(pub(k)) for k in esperado} == esperado


def test_citacao_simples_e_acordao():
    assert classificar(_pub("", tipo_doc="Citação", tipo_com="Citação")) == "citacao"
    assert classificar(_pub("", tipo_doc="Ementa de Acórdão")) == "acordao"
    assert classificar(_pub("", tipo_doc="Decisão", tipo_com="Intimação")) == "decisao"
    assert classificar(_pub("", tipo_doc="Despacho")) == "despacho"
    assert classificar(_pub("", tipo_doc="Certidão", tipo_com="")) == "outro"


# ── audiência ────────────────────────────────────────────────────────────────

def test_sessao_de_julgamento_com_hora():
    assert extrair_audiencia(pub("pauta").texto) == datetime(2026, 10, 22, 9, 0)


def test_audiencia_com_hora_depois_da_data():
    assert extrair_audiencia(pub("audiencia").texto) == datetime(2027, 3, 11, 10, 35)


def test_audiencia_com_data_por_extenso_e_hora_com_h():
    assert extrair_audiencia(pub("edital_stm").texto) == datetime(2026, 12, 2, 12, 30)


def test_audiencia_sem_hora_fica_a_meia_noite():
    assert extrair_audiencia("Designo audiência para 05/11/2026.") == datetime(2026, 11, 5, 0, 0)


def test_audiencia_passada_e_ignorada():
    texto = "A audiência de 01/02/2020 foi cancelada."
    assert extrair_audiencia(texto, a_partir_de=date(2026, 10, 9)) is None
    assert extrair_audiencia(texto) == datetime(2020, 2, 1)


def test_sem_audiencia_ou_data_impossivel():
    assert extrair_audiencia("") is None
    assert extrair_audiencia(pub("sentenca").texto) is None
    assert extrair_audiencia("audiência no dia 31/02/2027") is None


# ── dias úteis ───────────────────────────────────────────────────────────────

def test_proximo_dia_util_pula_fim_de_semana_e_feriado():
    assert proximo_dia_util(date(2026, 10, 9)) == date(2026, 10, 13)    # sex → seg 12/10 é feriado → ter
    assert proximo_dia_util(date(2026, 10, 13)) == date(2026, 10, 14)


def test_somar_dias_uteis():
    assert somar_dias_uteis(date(2026, 10, 13), 5) == date(2026, 10, 20)
    assert somar_dias_uteis(date(2026, 10, 13), 0) == date(2026, 10, 13)


def test_calcular_fim_conta_a_partir_da_publicacao():
    # disponibilizado sex 09/10; publicação ter 13/10 (12/10 é feriado); 5 dias úteis → 20/10
    assert calcular_fim(date(2026, 10, 9), 5) == date(2026, 10, 20)


def test_dias_corridos_que_caem_em_fim_de_semana_vao_para_o_proximo_dia_util():
    # publicação ter 13/10 + 5 corridos = domingo 18/10 → segunda 19/10
    assert calcular_fim(date(2026, 10, 9), 5, uteis=False) == date(2026, 10, 19)


# ── prazo sugerido ───────────────────────────────────────────────────────────

def test_prazo_numerico():
    s = sugerir_prazo(pub("prazo5"))
    assert (s.dias, s.uteis, s.data_fim) == (5, True, date(2026, 10, 20))
    assert "prazo de 05 dias" in s.trecho


def test_prazo_com_numero_por_extenso_entre_parenteses_e_corridos():
    s = sugerir_prazo(_pub("Intime-se para, no prazo de 10 (dez) dias corridos, pagar."))
    assert (s.dias, s.uteis) == (10, False)


def test_prazo_so_por_extenso():
    assert sugerir_prazo(_pub("no prazo de cinco dias úteis")).dias == 5


def test_prazo_em_horas_e_ignorado():
    assert sugerir_prazo(_pub("pague no prazo de 48 horas")) is None


def test_prazo_absurdo_ou_inexistente():
    assert sugerir_prazo(_pub("prazo de 900 dias")) is None
    assert sugerir_prazo(_pub("manifeste-se no prazo legal")) is None
    assert sugerir_prazo(pub("sentenca")) is None


def test_edital_nao_gera_prazo_da_parte():
    # "prazo de 15 dias" no edital corre depois do prazo do próprio edital: não estimar.
    assert sugerir_prazo(pub("edital_stm")) is None
    assert sugerir_prazo(pub("edital_citacao")) is None


def test_distribuicao_nao_gera_prazo():
    assert sugerir_prazo(_pub("prazo de 5 dias", tipo_com="Lista de distribuição", tipo_doc="Outros")) is None


def test_sem_data_de_disponibilizacao_nao_sugere():
    assert sugerir_prazo(_pub("prazo de 5 dias", data=None)) is None
