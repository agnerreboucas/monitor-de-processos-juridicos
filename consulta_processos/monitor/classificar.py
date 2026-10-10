"""Lê o teor de uma publicação e tira dela o que o advogado precisa ver.

Três coisas, todas por regra simples e visível (sem IA):

- a categoria do ato (intimação, citação, sentença, edital...);
- a data de uma audiência ou sessão de julgamento, quando o texto marca uma;
- um prazo *sugerido*, quando o texto diz "prazo de 15 dias".

O prazo é sempre SUGESTÃO. A data final é uma estimativa que só desconta fins de
semana e feriados nacionais fixos: ela não sabe de feriado local, ponto
facultativo, suspensão de prazo nem do recesso forense. Quem confirma é o
advogado, e a tela mostra isso em todo prazo ([CONFIRMAR]).
"""
from __future__ import annotations

import re
from dataclasses import dataclass
from datetime import date, datetime, timedelta

from ..modelos import Publicacao

CATEGORIAS = {
    "distribuicao": "Distribuição",
    "citacao": "Citação",
    "edital": "Edital",
    "sentenca": "Sentença",
    "acordao": "Acórdão",
    "decisao": "Decisão",
    "despacho": "Despacho",
    "intimacao": "Intimação",
    "outro": "Outro",
}

# Categorias que costumam abrir prazo para a parte. Edital fica de fora: o prazo
# dele corre antes de o prazo da parte começar, e confundir os dois dá prazo errado.
_ABREM_PRAZO = {"citacao", "sentenca", "acordao", "decisao", "despacho", "intimacao"}

AVISO_PRAZO = ("[CONFIRMAR] Estimativa: desconta só fins de semana e feriados nacionais fixos. "
               "Não considera feriado local, suspensão de prazo nem recesso.")

# 1/1, 21/4, 1/5, 7/9, 12/10, 2/11, 15/11, 20/11, 25/12
_FERIADOS_FIXOS = {(1, 1), (4, 21), (5, 1), (9, 7), (10, 12), (11, 2), (11, 15), (11, 20), (12, 25)}

_NUMEROS_POR_EXTENSO = {
    "um": 1, "dois": 2, "tres": 3, "três": 3, "quatro": 4, "cinco": 5, "seis": 6, "sete": 7,
    "oito": 8, "nove": 9, "dez": 10, "quinze": 15, "vinte": 20, "trinta": 30, "quarenta": 40,
    "sessenta": 60,
}

_MESES = {
    "janeiro": 1, "fevereiro": 2, "março": 3, "marco": 3, "abril": 4, "maio": 5, "junho": 6,
    "julho": 7, "agosto": 8, "setembro": 9, "outubro": 10, "novembro": 11, "dezembro": 12,
}


@dataclass
class PrazoSugerido:
    dias: int
    uteis: bool
    trecho: str                 # o pedaço do texto que gerou a sugestão
    data_fim: date | None = None


# ── categoria ────────────────────────────────────────────────────────────────

def classificar(pub: Publicacao) -> str:
    """Devolve a chave da categoria (ver `CATEGORIAS`)."""
    comunicacao = (pub.tipo_comunicacao or "").lower()
    documento = (pub.tipo_documento or "").lower()
    if "distribui" in comunicacao or "distribui" in documento:
        return "distribuicao"
    # Edital vem antes de citação: "edital de citação" tem dois prazos em sequência
    # (o do edital e, depois, o da parte) e uma estimativa só erraria o começo.
    if "edital" in comunicacao or "edital" in documento:
        return "edital"
    if "cita" in comunicacao or "cita" in documento:
        return "citacao"
    if "senten" in documento:
        return "sentenca"
    if "acórdão" in documento or "acordao" in documento or "ementa" in documento:
        return "acordao"
    if "decis" in documento:
        return "decisao"
    if "despacho" in documento:
        return "despacho"
    if "intima" in comunicacao or "intima" in documento:
        return "intimacao"
    return "outro"


# ── audiência ────────────────────────────────────────────────────────────────

_EVENTO = re.compile(r"audi[eê]ncia|sess[aã]o de julgamento|pauta de julgamento", re.IGNORECASE)
_DATA_NUMERICA = re.compile(r"(\d{1,2})/(\d{1,2})/(\d{4})")
_DATA_EXTENSO = re.compile(r"(\d{1,2})\s+de\s+([a-zçã]+)\s+de\s+(\d{4})", re.IGNORECASE)
_HORA = re.compile(r"(?:[àa]s\s*)?(\d{1,2})\s*(?:h|:)\s*(\d{2})?", re.IGNORECASE)


def _montar_data(dia: int, mes: int, ano: int) -> date | None:
    try:
        return date(ano, mes, dia)
    except ValueError:
        return None


def _primeira_data(trecho: str) -> tuple[date, int] | None:
    """A primeira data do trecho (numérica ou por extenso) e onde ela termina."""
    achados = []
    m = _DATA_NUMERICA.search(trecho)
    if m and (d := _montar_data(int(m.group(1)), int(m.group(2)), int(m.group(3)))):
        achados.append((m.start(), d, m.end()))
    m = _DATA_EXTENSO.search(trecho)
    if m and (mes := _MESES.get(m.group(2).lower())):
        if d := _montar_data(int(m.group(1)), mes, int(m.group(3))):
            achados.append((m.start(), d, m.end()))
    if not achados:
        return None
    _, data, fim = min(achados, key=lambda a: a[0])
    return data, fim


def extrair_audiencia(texto: str, a_partir_de: date | None = None) -> datetime | None:
    """Data (e hora, se houver) de audiência ou sessão de julgamento citada no texto.

    Procura uma data perto da palavra "audiência"/"sessão de julgamento". Datas
    anteriores a `a_partir_de` são ignoradas: audiência já passada não é pauta.
    Devolve a primeira que servir, ou None.
    """
    if not texto:
        return None
    for evento in _EVENTO.finditer(texto):
        janela = texto[evento.end(): evento.end() + 220]
        achou = _primeira_data(janela)
        if not achou:
            continue
        data, fim = achou
        if a_partir_de and data < a_partir_de:
            continue
        hora = _HORA.search(janela[fim: fim + 40])
        horas, minutos = 0, 0
        if hora and 0 <= int(hora.group(1)) <= 23:
            horas, minutos = int(hora.group(1)), int(hora.group(2) or 0)
            if minutos > 59:
                horas, minutos = 0, 0
        return datetime(data.year, data.month, data.day, horas, minutos)
    return None


# ── prazo ────────────────────────────────────────────────────────────────────

_PRAZO = re.compile(
    r"prazo\s+(?:legal\s+|comum\s+)?(?:de|:|=)?\s*(?:at[eé]\s+)?"
    r"(?P<num>\d{1,3}|[a-zçãé]+)\s*(?:\([^)]{1,40}\)\s*)?"
    r"(?P<unid>dias|horas)(?P<tipo>\s+(?:[uú]teis|corridos))?",
    re.IGNORECASE)


def _numero(bruto: str) -> int | None:
    bruto = bruto.lower()
    if bruto.isdigit():
        return int(bruto)
    return _NUMEROS_POR_EXTENSO.get(bruto)


def _dia_util(dia: date) -> bool:
    return dia.weekday() < 5 and (dia.month, dia.day) not in _FERIADOS_FIXOS


def proximo_dia_util(dia: date) -> date:
    """O primeiro dia útil depois de `dia`."""
    dia += timedelta(days=1)
    while not _dia_util(dia):
        dia += timedelta(days=1)
    return dia


def somar_dias_uteis(inicio: date, dias: int) -> date:
    """Conta `dias` dias úteis depois de `inicio` (o próprio dia não conta)."""
    atual = inicio
    restantes = dias
    while restantes > 0:
        atual = proximo_dia_util(atual)
        restantes -= 1
    return atual


def calcular_fim(disponibilizacao: date, dias: int, uteis: bool = True) -> date:
    """Data final estimada de um prazo.

    Regra comum do processo eletrônico: a publicação vale no primeiro dia útil
    depois da disponibilização, e o prazo começa a contar no dia útil seguinte.
    """
    publicacao = proximo_dia_util(disponibilizacao)
    if uteis:
        return somar_dias_uteis(publicacao, dias)
    fim = publicacao + timedelta(days=dias)
    while not _dia_util(fim):          # prazo que acaba em dia sem expediente vai para o próximo
        fim += timedelta(days=1)
    return fim


def sugerir_prazo(pub: Publicacao, categoria: str | None = None) -> PrazoSugerido | None:
    """Prazo em dias citado no texto do ato, ou None.

    Só olha categorias que abrem prazo para a parte, e ignora prazo em horas.
    """
    categoria = categoria or classificar(pub)
    if categoria not in _ABREM_PRAZO or not pub.texto or not pub.data_disponibilizacao:
        return None
    for achado in _PRAZO.finditer(pub.texto):
        if achado.group("unid").lower() != "dias":
            continue
        dias = _numero(achado.group("num"))
        if not dias or dias > 180:
            continue
        tipo = (achado.group("tipo") or "").strip().lower()
        uteis = not tipo.startswith("corridos")
        return PrazoSugerido(
            dias=dias, uteis=uteis, trecho=achado.group(0).strip(),
            data_fim=calcular_fim(pub.data_disponibilizacao, dias, uteis))
    return None
