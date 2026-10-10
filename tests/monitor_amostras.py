"""Publicações de exemplo no formato real da API Comunica/DJEN (nomes trocados).

Os números de processo são de verdade (têm dígito verificador válido), mas partes,
advogados e textos são fictícios ou resumidos: nenhum dado pessoal.
"""
from consulta_processos.fontes.comunica import montar_publicacao

PROCESSO_TJMG = "0022929-16.2016.8.13.0297"
PROCESSO_TJRO = "7064871-54.2025.8.22.0001"
PROCESSO_TRT8 = "0001457-85.2026.5.08.0106"
PROCESSO_TJMS = "5000250-61.2026.8.12.0022"
PROCESSO_TJTO = "0000905-64.2026.8.27.2718"
PROCESSO_STM = "7000133-21.2024.7.12.0012"
PROCESSO_TRF4 = "5002305-61.2022.4.04.7109"


def item(id_, numero, *, comunicacao="Intimação", documento="Intimação", texto="", orgao="Vara Exemplo",
         classe="PROCEDIMENTO COMUM CÍVEL", data="2026-10-09", advogados=(), partes=()):
    return {
        "id": id_, "data_disponibilizacao": data, "tipoComunicacao": comunicacao,
        "tipoDocumento": documento, "nomeOrgao": orgao, "nomeClasse": classe,
        "numero_processo": "".join(c for c in numero if c.isdigit()),
        "numeroprocessocommascara": numero, "texto": texto, "link": "https://tribunal.exemplo/doc",
        "destinatarios": [{"nome": n, "polo": p} for n, p in partes],
        "destinatarioadvogados": [
            {"advogado": {"nome": nome, "numero_oab": num, "uf_oab": uf}} for nome, num, uf in advogados],
    }


ITENS = {
    # sessão de julgamento marcada, sem prazo
    "pauta": item(1001, PROCESSO_TJMG, documento="Apelação", orgao="15ª Câmara Cível", classe="APELAÇÃO CÍVEL",
                  texto="Relator - Des. Fulano. Autos incluídos na pauta de julgamento de 22/10/2026, "
                        "às 09:00 horas. A sessão de julgamento será híbrida.",
                  partes=[("AUTOR EXEMPLO", "A"), ("SEGURADORA EXEMPLO S.A.", "P")],
                  advogados=[("ADVOGADO UM", "101332", "MG")]),
    # intimação com prazo de 5 dias (sexta 09/10/2026)
    "prazo5": item(1002, PROCESSO_TJRO, orgao="5ª Vara Cível",
                   texto="AUTOR - PROMOVER ANDAMENTO. Fica a parte AUTORA intimada a manifestar-se "
                         "nos termos da Decisão ID 1 no prazo de 05 dias.",
                   advogados=[("ADVOGADO UM", "101332", "MG")]),
    # despacho trabalhista que marca audiência
    "audiencia": item(1003, PROCESSO_TRT8, documento="Notificação", orgao="Vara do Trabalho de Castanhal",
                      classe="AÇÃO TRABALHISTA - RITO SUMARÍSSIMO",
                      texto="Considerando a decisão anterior, decido: Designar AUDIÊNCIA UNA para o dia "
                            " 11/03/2027 10:35 horas, por videoconferência. Notifique-se a reclamada."),
    "distribuicao": item(1004, PROCESSO_TJMS, comunicacao="Lista de distribuição", documento="Outros",
                         texto="Processo 5000250-61.2026.8.12.0022 distribuído para Vara Única na data de 06/10/2026."),
    "sentenca": item(1005, PROCESSO_TJTO, documento="Sentença",
                     texto="Indefiro a petição inicial e extingo o processo sem resolução do mérito."),
    # edital com data por extenso e prazo do edital (não vira prazo da parte)
    "edital_stm": item(1006, PROCESSO_STM, comunicacao="Edital", documento="Edital", orgao="Auditoria da 12ª CJM",
                       classe="AÇÃO PENAL MILITAR",
                       texto="Fica INTIMADO, com prazo de 15 (quinze) dias, o acusado para comparecer à sede "
                             "ou acessar a audiência virtual em 2 de dezembro de 2026, às 12h30, "
                             "para a inquirição das testemunhas."),
    "edital_citacao": item(1007, PROCESSO_TRF4, comunicacao="Citação", documento="EDITAL DE CITAÇÃO",
                           classe="MONITÓRIA",
                           texto="Promove a CITAÇÃO do réu para pagar ou embargar, no prazo de 15 dias. "
                                 "O prazo do edital de 30 dias correrá da publicação."),
}


def pub(chave: str):
    return montar_publicacao(ITENS[chave])
