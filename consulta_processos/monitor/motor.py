"""A rotina do monitor: busca o que saiu, separa o que é novo e grava.

Rode uma vez por dia (cron, Agendador de Tarefas) ou deixe a tela chamar:

    consulta-processos monitor executar

Como evita perder publicação: cada busca recua `SOBREPOSICAO_DIAS` dias a partir
da última busca que deu certo. O diário às vezes publica com atraso, e a
repetição não duplica nada porque cada publicação tem identificador único.

Se uma fonte falhar, o monitoramento daquele item fica com o erro anotado e a
"última busca" NÃO avança — na próxima rodada a janela cobre o buraco.
"""
from __future__ import annotations

import logging
import time
from collections.abc import Callable
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta

from ..config import Config
from ..erros import ConsultaError
from ..fontes.comunica import publicacoes_do_processo, publicacoes_por_oab
from ..modelos import Publicacao
from .banco import Banco
from .classificar import classificar, extrair_audiencia, sugerir_prazo

logger = logging.getLogger(__name__)

JANELA_INICIAL_DIAS = 15      # primeira busca de cada item
SOBREPOSICAO_DIAS = 3         # recuo a partir da última busca bem-sucedida


@dataclass
class Resultado:
    buscados: int = 0                 # itens acompanhados que foram consultados
    novos: int = 0                    # publicações que ainda não estavam no banco
    novas: list[int] = field(default_factory=list)
    erros: list[str] = field(default_factory=list)

    @property
    def ok(self) -> bool:
        return not self.erros


def _inicio_da_janela(ultima_busca: str | None, hoje: date) -> date:
    if not ultima_busca:
        return hoje - timedelta(days=JANELA_INICIAL_DIAS)
    try:
        ultima = datetime.strptime(ultima_busca[:10], "%Y-%m-%d").date()
    except ValueError:
        return hoje - timedelta(days=JANELA_INICIAL_DIAS)
    return min(ultima, hoje) - timedelta(days=SOBREPOSICAO_DIAS)


def _descrever(item: dict) -> str:
    if item["tipo"] == "oab":
        return f"OAB {item['oab_uf']} {item['oab_numero']}"
    return f"processo {item['numero_processo']}"


def executar(banco: Banco, *, config: Config | None = None, hoje: date | None = None,
             buscar_oab: Callable[..., list[Publicacao]] = publicacoes_por_oab,
             buscar_processo: Callable[..., list[Publicacao]] = publicacoes_do_processo,
             pausa: float = 0.0) -> Resultado:
    """Consulta tudo o que está ativo no banco e grava o que for novo.

    `buscar_oab` e `buscar_processo` podem ser trocadas (os testes usam respostas
    gravadas, sem rede).
    """
    hoje = hoje or date.today()
    resultado = Resultado()
    execucao = banco.iniciar_execucao()
    try:
        itens = banco.listar_monitorados(so_ativos=True)
        for posicao, item in enumerate(itens):
            if posicao and pausa:
                time.sleep(pausa)      # respeite o serviço público
            inicio = _inicio_da_janela(item["ultima_busca"], hoje)
            try:
                if item["tipo"] == "oab":
                    publicacoes = buscar_oab(item["oab_numero"], item["oab_uf"], inicio=inicio,
                                             fim=hoje, paginas=0, config=config)
                else:
                    publicacoes = buscar_processo(item["numero_processo"], inicio=inicio,
                                                  fim=hoje, config=config)
            except ConsultaError as e:
                mensagem = f"{_descrever(item)}: {e}"
                logger.warning("monitor: %s", mensagem)
                resultado.erros.append(mensagem)
                banco.registrar_busca(item["id"], ok=False, erro=str(e))
                continue
            resultado.buscados += 1
            for pub in publicacoes:
                try:
                    novo = _guardar(banco, pub, item["id"], hoje)
                except Exception as e:  # noqa: BLE001 — uma publicação ruim não pode parar as outras
                    logger.exception("monitor: publicação %s não gravada", pub.id_externo)
                    resultado.erros.append(f"publicação {pub.id_externo}: {e}")
                    continue
                if novo is not None:
                    resultado.novos += 1
                    resultado.novas.append(novo)
            banco.registrar_busca(item["id"], ok=True)
    finally:
        banco.concluir_execucao(execucao, buscados=resultado.buscados, novos=resultado.novos,
                                erros=resultado.erros)
    return resultado


def _guardar(banco: Banco, pub: Publicacao, monitorado_id: int, hoje: date) -> int | None:
    """Classifica e grava. Devolve o id se a publicação for nova, senão None."""
    categoria = classificar(pub)
    audiencia = extrair_audiencia(pub.texto, a_partir_de=pub.data_disponibilizacao or hoje)
    prazo = sugerir_prazo(pub, categoria)
    pub_id, nova = banco.gravar_publicacao(pub, monitorado_id, categoria=categoria,
                                           audiencia=audiencia, prazo=prazo)
    return pub_id if nova else None
