"""API REST em FastAPI — o processo completo em JSON, pronto para vender como serviço.

    consulta-processos admin criar voce@empresa.com.br   # acesso ao painel
    consulta-processos api                               # sobe em http://127.0.0.1:8000

    curl -H "X-API-Key: <chave>" http://127.0.0.1:8000/v1/processos/5053283-30.2024.8.13.0079

A documentação interativa fica em /docs e o painel de clientes em /admin. Toda
rota de dados exige a chave no cabeçalho `X-API-Key`. As chaves dos clientes são
geradas no painel, com cota mensal e histórico de uso; só o hash delas fica no
banco. `API_CHAVES` no `.env` continua valendo para uso interno, sem cota.

Instalação: pip install "consulta-processos[api]"
"""
from __future__ import annotations

import hashlib
import hmac
import logging
import math
import re
import secrets
import threading
import time
import uuid
from collections import deque
from dataclasses import dataclass, field
from datetime import date, datetime, timedelta, timezone
from enum import Enum

from fastapi import Depends, FastAPI, Path, Query, Request, Security
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from fastapi.security import APIKeyHeader
from pydantic import BaseModel, Field
from starlette.concurrency import run_in_threadpool
from starlette.exceptions import HTTPException as StarletteHTTPException

from . import __version__
from .api import buscar_por_oab, consultar_processo_detalhado, consultar_processo_pelo_diario, existe
from .cache import CacheTTL
from .cnj import CNJ
from .config import Config, _env, _env_int, carregar_env, config_padrao
from .erros import ConsultaError, FonteIndisponivel, NumeroInvalido
from .fontes import comunica
from .modelos import Advogado, Movimentacao, Parte, Polo, Processo, Publicacao
from .painel.banco import Banco, ChaveValida, _iso, inicio_do_proximo_mes

logger = logging.getLogger(__name__)
auditoria = logging.getLogger("consulta_processos.auditoria")

# Chave curta demais é adivinhável; `nova-chave` gera 43 caracteres
TAMANHO_MINIMO_CHAVE = 32

UFS = {"AC", "AL", "AM", "AP", "BA", "CE", "DF", "ES", "GO", "MA", "MG", "MS", "MT", "PA", "PB",
       "PE", "PI", "PR", "RJ", "RN", "RO", "RR", "RS", "SC", "SE", "SP", "TO"}


# ── Configuração ─────────────────────────────────────────────────────────────

def _hash(chave: str) -> bytes:
    return hashlib.sha256(chave.encode("utf-8")).digest()


def gerar_chave() -> str:
    return secrets.token_urlsafe(32)


@dataclass
class ConfigAPI:
    """O que a API precisa além da configuração das fontes."""
    # cliente → sha256 da chave. A chave em si não fica guardada.
    chaves: dict[str, bytes] = field(default_factory=dict)
    limite_por_minuto: int = 60
    # Chave errada: a partir deste número por minuto o IP é barrado (força bruta)
    falhas_por_minuto: int = 10
    docs: bool = True
    # Banco do painel (clientes, chaves, cotas). Vazio = sem painel, só API_CHAVES.
    banco: str = ""
    # Cookie da sessão só por HTTPS. Desligue apenas em teste local sem TLS.
    cookie_seguro: bool = True
    # Faixas de IP que podem abrir o painel (ex.: 172.16.0.0/16). Vazio = qualquer um.
    painel_ips: str = ""

    @classmethod
    def do_ambiente(cls) -> ConfigAPI:
        carregar_env()
        return cls(
            chaves=ler_chaves(_env("API_CHAVES")),
            limite_por_minuto=max(1, _env_int("API_LIMITE_POR_MINUTO", 60)),
            falhas_por_minuto=max(1, _env_int("API_FALHAS_POR_MINUTO", 10)),
            docs=_env("API_DOCS", "1") not in ("0", "false", "nao", "não"),
            banco=_env("API_BANCO", "dados/api.db"),
            cookie_seguro=_env("API_COOKIE_SEGURO", "1") not in ("0", "false", "nao", "não"),
            painel_ips=_env("PAINEL_IPS_PERMITIDOS"),
        )


def ler_chaves(texto: str) -> dict[str, bytes]:
    """`cliente-a:chave1,cliente-b:chave2` → {cliente: hash}. Ignora chave fraca."""
    chaves: dict[str, bytes] = {}
    for item in re.split(r"[,;\n]", texto or ""):
        item = item.strip()
        if not item:
            continue
        cliente, _, chave = item.partition(":")
        cliente, chave = cliente.strip(), chave.strip()
        if not cliente or not chave:
            logger.warning("[api] entrada de API_CHAVES sem o formato cliente:chave foi ignorada")
            continue
        if len(chave) < TAMANHO_MINIMO_CHAVE:
            logger.warning("[api] chave do cliente %r tem menos de %d caracteres e foi ignorada",
                           cliente, TAMANHO_MINIMO_CHAVE)
            continue
        chaves[cliente] = _hash(chave)
    return chaves


# ── Limite de requisições ────────────────────────────────────────────────────

class LimiteDeTaxa:
    """Janela deslizante de 60 segundos, em memória, por identificador."""

    def __init__(self, por_minuto: int, janela: float = 60.0):
        self.por_minuto = por_minuto
        self.janela = janela
        self._eventos: dict[str, deque[float]] = {}
        self._trava = threading.Lock()

    def _limpar(self, fila: deque[float], agora: float) -> None:
        while fila and agora - fila[0] >= self.janela:
            fila.popleft()

    def registrar(self, quem: str, limite: int | None = None) -> tuple[bool, int]:
        """Conta um evento. Devolve (permitido, segundos até liberar)."""
        maximo = limite or self.por_minuto
        agora = time.monotonic()
        with self._trava:
            fila = self._eventos.setdefault(quem, deque())
            self._limpar(fila, agora)
            if len(fila) >= maximo:
                return False, max(1, math.ceil(self.janela - (agora - fila[0])))
            fila.append(agora)
            if len(self._eventos) > 10_000:        # não deixa crescer sem fim
                for chave in [k for k, f in self._eventos.items() if not f]:
                    del self._eventos[chave]
            return True, 0

    def bloqueado(self, quem: str) -> tuple[bool, int]:
        """Só consulta, sem contar."""
        agora = time.monotonic()
        with self._trava:
            fila = self._eventos.get(quem)
            if not fila:
                return False, 0
            self._limpar(fila, agora)
            if len(fila) >= self.por_minuto:
                return True, max(1, math.ceil(self.janela - (agora - fila[0])))
            return False, 0


# ── Erros no formato da API ──────────────────────────────────────────────────

class ErroAPI(Exception):
    def __init__(self, status: int, codigo: str, mensagem: str, *, tentar_em: int | None = None):
        self.status, self.codigo, self.mensagem, self.tentar_em = status, codigo, mensagem, tentar_em
        super().__init__(mensagem)


def _corpo_erro(request: Request, codigo: str, mensagem: str, detalhes: list | None = None) -> dict:
    erro: dict = {"codigo": codigo, "mensagem": mensagem}
    if detalhes:
        erro["detalhes"] = detalhes
    return {"ok": False, "erro": erro, "id_requisicao": getattr(request.state, "id", None)}


# ── Modelos de resposta ──────────────────────────────────────────────────────

class AdvogadoOut(BaseModel):
    nome: str
    oab_numero: str = ""
    oab_uf: str = ""
    oab: str = Field("", description="Inscrição pronta para exibir, ex.: 'OAB/MG 123456'")


class ParteOut(BaseModel):
    nome: str
    polo: Polo
    advogados: list[AdvogadoOut] = []


class MovimentacaoOut(BaseModel):
    data: datetime
    data_formatada: str = Field(description="dd/mm/aaaa")
    tipo: str
    descricao: str
    complemento: str = ""
    orgao: str = ""
    fonte: str
    link: str = ""


class PublicacaoOut(BaseModel):
    id: str
    data_disponibilizacao: date | None
    data_formatada: str = ""
    tipo_comunicacao: str = ""
    tipo_documento: str = ""
    orgao: str = ""
    numero_processo: str = ""
    classe: str = ""
    texto: str = Field("", description="Teor do ato, já sem HTML")
    link: str = ""
    partes: list[ParteOut] = []
    advogados: list[AdvogadoOut] = []


class PolosOut(BaseModel):
    ativo: list[ParteOut] = []
    passivo: list[ParteOut] = []
    terceiros: list[ParteOut] = []


class TotaisOut(BaseModel):
    partes: int
    advogados: int
    movimentacoes: int
    publicacoes: int


class ProcessoOut(BaseModel):
    numero: str
    numero_digitos: str
    titulo: str = Field(description="'Autor × Réu', como na capa dos autos")
    tribunal: str = ""
    segmento: str = ""
    grau: str = ""
    classe: str = ""
    assunto: str = ""
    orgao_julgador: str = ""
    comarca: str = ""
    link: str = ""
    valor_causa: float | None = None
    valor_causa_formatado: str = ""
    data_ajuizamento: datetime | None = None
    data_ajuizamento_formatada: str = ""
    data_ultima_movimentacao: date | None = None
    data_ultima_movimentacao_formatada: str = ""
    polos: PolosOut
    advogados: list[AdvogadoOut] = []
    movimentacoes: list[MovimentacaoOut] = []
    publicacoes: list[PublicacaoOut] = []
    totais: TotaisOut
    fontes: list[str] = []


class RespostaProcesso(BaseModel):
    ok: bool = True
    consultado_em: datetime
    tempo_ms: int
    avisos: list[str] = []
    processo: ProcessoOut


class RespostaExiste(BaseModel):
    ok: bool = True
    numero: str
    existe: bool
    fonte: str = ""


class RespostaCNJ(BaseModel):
    ok: bool = True
    numero: str
    numero_digitos: str
    valido: bool
    tribunal: str = ""
    segmento: str = ""
    ano: int = 0
    origem_codigo: int = 0


class ResumoProcessoOut(BaseModel):
    numero: str
    titulo: str
    tribunal: str = ""
    classe: str = ""
    orgao_julgador: str = ""
    data_ultima_movimentacao: date | None = None
    data_ultima_movimentacao_formatada: str = ""
    publicacoes: int = 0


class RespostaOABProcessos(BaseModel):
    ok: bool = True
    oab: str
    periodo_dias: int
    total: int
    processos: list[ResumoProcessoOut]


class RespostaOABPublicacoes(BaseModel):
    ok: bool = True
    oab: str
    inicio: date
    fim: date
    total: int
    publicacoes: list[PublicacaoOut]


class RespostaConta(BaseModel):
    ok: bool = True
    cliente: str
    plano: str = ""
    limite_mensal: int | None = Field(None, description="None = sem cota (chave interna)")
    usado_no_mes: int
    restante: int | None = None
    reinicia_em: datetime
    limite_por_minuto: int


class ErroOut(BaseModel):
    codigo: str
    mensagem: str


class RespostaErro(BaseModel):
    ok: bool = False
    erro: ErroOut
    id_requisicao: str | None = None


# ── Formatação ───────────────────────────────────────────────────────────────

def data_br(valor: date | datetime | None) -> str:
    return valor.strftime("%d/%m/%Y") if valor else ""


def dinheiro_br(valor: float | None) -> str:
    if valor is None:
        return ""
    texto = f"{valor:,.2f}".replace(",", "@").replace(".", ",").replace("@", ".")
    return f"R$ {texto}"


def _advogado(adv: Advogado) -> AdvogadoOut:
    oab = f"OAB/{adv.oab_uf} {adv.oab_numero}".strip() if adv.oab_numero else ""
    return AdvogadoOut(nome=adv.nome, oab_numero=adv.oab_numero, oab_uf=adv.oab_uf, oab=oab)


def _parte(parte: Parte) -> ParteOut:
    return ParteOut(nome=parte.nome, polo=parte.polo, advogados=[_advogado(a) for a in parte.advogados])


def _valor(enum_ou_texto) -> str:
    return enum_ou_texto.value if isinstance(enum_ou_texto, Enum) else str(enum_ou_texto)


def _movimentacao(mov: Movimentacao) -> MovimentacaoOut:
    return MovimentacaoOut(data=mov.data, data_formatada=data_br(mov.data), tipo=_valor(mov.tipo),
                           descricao=mov.descricao, complemento=mov.complemento, orgao=mov.orgao,
                           fonte=_valor(mov.fonte), link=mov.link)


def _publicacao(pub: Publicacao) -> PublicacaoOut:
    return PublicacaoOut(
        id=pub.id_externo, data_disponibilizacao=pub.data_disponibilizacao,
        data_formatada=data_br(pub.data_disponibilizacao), tipo_comunicacao=pub.tipo_comunicacao,
        tipo_documento=pub.tipo_documento, orgao=pub.orgao, numero_processo=pub.numero_processo,
        classe=pub.classe, texto=pub.texto, link=pub.link,
        partes=[_parte(p) for p in pub.partes], advogados=[_advogado(a) for a in pub.advogados])


def formatar_processo(processo: Processo) -> ProcessoOut:
    """O processo da biblioteca no formato público da API."""
    polos = PolosOut(
        ativo=[_parte(p) for p in processo.partes if p.polo == Polo.ATIVO],
        passivo=[_parte(p) for p in processo.partes if p.polo == Polo.PASSIVO],
        terceiros=[_parte(p) for p in processo.partes if p.polo == Polo.TERCEIRO],
    )
    return ProcessoOut(
        numero=processo.numero,
        numero_digitos=re.sub(r"\D", "", processo.numero),
        titulo=processo.titulo,
        tribunal=processo.tribunal, segmento=processo.segmento, grau=processo.grau,
        classe=processo.classe, assunto=processo.assunto, orgao_julgador=processo.orgao_julgador,
        comarca=processo.comarca, link=processo.link,
        valor_causa=processo.valor_causa, valor_causa_formatado=dinheiro_br(processo.valor_causa),
        data_ajuizamento=processo.data_ajuizamento,
        data_ajuizamento_formatada=data_br(processo.data_ajuizamento),
        data_ultima_movimentacao=processo.data_ultima_movimentacao,
        data_ultima_movimentacao_formatada=data_br(processo.data_ultima_movimentacao),
        polos=polos,
        advogados=[_advogado(a) for a in processo.advogados],
        movimentacoes=[_movimentacao(m) for m in processo.movimentacoes],
        publicacoes=[_publicacao(p) for p in processo.publicacoes],
        totais=TotaisOut(partes=len(processo.partes), advogados=len(processo.advogados),
                         movimentacoes=len(processo.movimentacoes),
                         publicacoes=len(processo.publicacoes)),
        fontes=[_valor(f) for f in processo.fontes],
    )


# ── Validação de entrada ─────────────────────────────────────────────────────

def digito_confere(cnj: CNJ) -> bool:
    """Dígito verificador do número CNJ (módulo 97, Resolução CNJ 65/2008).

    Pega número digitado errado antes de gastar uma consulta nas fontes.
    """
    d = cnj.digitos
    base = int(d[0:7] + d[9:20] + "00")
    return 98 - (base % 97) == int(d[7:9])


def _cnj_valido(numero: str) -> CNJ:
    cnj = CNJ(numero)
    if not cnj.valido or not digito_confere(cnj):
        raise ErroAPI(422, "numero_invalido",
                      "Número fora do padrão CNJ (NNNNNNN-DD.AAAA.J.TR.OOOO) "
                      "ou com dígito verificador errado.")
    return cnj


def _oab_valida(uf: str, numero: str) -> tuple[str, str]:
    uf = uf.upper()
    if uf not in UFS:
        raise ErroAPI(422, "uf_invalida", f"UF desconhecida: {uf}")
    return uf, numero.upper()


# ── Aplicação ────────────────────────────────────────────────────────────────

_cabecalho_chave = APIKeyHeader(name="X-API-Key", auto_error=False,
                                description="Chave de acesso do cliente")

CABECALHOS_SEGURANCA = {
    "X-Content-Type-Options": "nosniff",
    "X-Frame-Options": "DENY",
    "Referrer-Policy": "no-referrer",
    "Cache-Control": "no-store",
    "Cross-Origin-Resource-Policy": "same-origin",
    "Permissions-Policy": "geolocation=(), camera=(), microphone=()",
}
# O JSON não carrega nada; a página /docs carrega o Swagger da CDN
CSP_JSON = "default-src 'none'; frame-ancestors 'none'"
CSP_DOCS = ("default-src 'none'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; "
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; img-src 'self' data: "
            "https://fastapi.tiangolo.com; connect-src 'self'; frame-ancestors 'none'")
ROTAS_DOCS = ("/docs", "/redoc", "/openapi.json")
# O painel só carrega os próprios arquivos, nada de fora
CSP_PAINEL = ("default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
              "connect-src 'self'; font-src 'self'; base-uri 'none'; form-action 'self'; "
              "frame-ancestors 'none'")


@dataclass
class Identidade:
    """Quem está chamando: um cliente do painel ou uma chave interna do .env."""
    cliente: str
    limite_por_minuto: int
    chave: ChaveValida | None = None

RESPOSTAS_ERRO = {
    401: {"model": RespostaErro, "description": "Chave ausente ou inválida"},
    422: {"model": RespostaErro, "description": "Entrada inválida"},
    402: {"model": RespostaErro, "description": "Cota mensal do plano esgotada"},
    403: {"model": RespostaErro, "description": "Conta suspensa"},
    429: {"model": RespostaErro, "description": "Limite de requisições atingido"},
    503: {"model": RespostaErro, "description": "Fonte do CNJ fora do ar; tente de novo"},
}


def criar_app(config_api: ConfigAPI | None = None, config: Config | None = None,
              cache: CacheTTL | None = None, banco: Banco | None = None) -> FastAPI:
    capi = config_api or ConfigAPI.do_ambiente()
    cfg = config or config_padrao()
    cache = cache if cache is not None else CacheTTL()
    if banco is None and capi.banco:
        banco = Banco(capi.banco)
    limite_cliente = LimiteDeTaxa(capi.limite_por_minuto)
    limite_falhas = LimiteDeTaxa(capi.falhas_por_minuto)

    if not capi.chaves and banco is None:
        logger.warning("[api] sem banco e sem API_CHAVES: toda rota de dados vai responder 401.")

    app = FastAPI(
        title="Robô Jurídico — API de processos",
        version=__version__,
        description=("Consulta processos judiciais nas fontes públicas do CNJ (DataJud e DJEN) e "
                     "devolve tudo junto, formatado. Envie a chave no cabeçalho `X-API-Key`."),
        docs_url="/docs" if capi.docs else None,
        redoc_url="/redoc" if capi.docs else None,
        openapi_url="/openapi.json" if capi.docs else None,
    )

    # ── middleware: id da requisição, auditoria e cabeçalhos ──
    @app.middleware("http")
    async def _envelope(request: Request, call_next):
        request.state.id = uuid.uuid4().hex[:16]
        request.state.cliente = "-"
        request.state.uso_id = None
        request.state.cota = None
        inicio = time.perf_counter()
        try:
            resposta = await call_next(request)
        except Exception:
            # erro não tratado: a chamada não pode ficar cobrada
            await _fechar_uso(request, 500, int((time.perf_counter() - inicio) * 1000))
            raise
        ms = int((time.perf_counter() - inicio) * 1000)
        await _fechar_uso(request, resposta.status_code, ms)
        if request.state.cota is not None:
            limite, usado, cobravel = request.state.cota
            if cobravel and resposta.status_code >= 400:
                usado -= 1            # não foi cobrada
            resposta.headers["X-Cota-Limite"] = str(limite)
            resposta.headers["X-Cota-Usada"] = str(usado)
            resposta.headers["X-Cota-Restante"] = str(max(0, limite - usado))
        resposta.headers["X-Request-ID"] = request.state.id
        for nome, valor in CABECALHOS_SEGURANCA.items():
            resposta.headers.setdefault(nome, valor)
        caminho = request.url.path
        docs = caminho in ROTAS_DOCS or caminho.startswith("/docs")
        painel = caminho == "/admin" or caminho.startswith("/admin/")
        resposta.headers["Content-Security-Policy"] = (CSP_DOCS if docs else CSP_PAINEL if painel
                                                       else CSP_JSON)
        auditoria.info("id=%s cliente=%s ip=%s %s %s status=%d ms=%d",
                       request.state.id, request.state.cliente,
                       request.client.host if request.client else "-",
                       request.method, request.url.path, resposta.status_code, ms)
        return resposta

    async def _fechar_uso(request: Request, status: int, ms: int) -> None:
        uso_id = getattr(request.state, "uso_id", None)
        if uso_id and banco is not None:
            rota = getattr(request.scope.get("route"), "path", "")
            await run_in_threadpool(banco.finalizar, uso_id, status=status, ms=ms, rota=rota)

    # ── erros: sempre no mesmo formato, nunca com stack trace ──
    @app.exception_handler(ErroAPI)
    async def _erro_api(request: Request, e: ErroAPI):
        cabecalhos = {"Retry-After": str(e.tentar_em)} if e.tentar_em else None
        if e.status == 401:
            cabecalhos = {**(cabecalhos or {}), "WWW-Authenticate": "ApiKey"}
        return JSONResponse(_corpo_erro(request, e.codigo, e.mensagem), status_code=e.status,
                            headers=cabecalhos)

    @app.exception_handler(RequestValidationError)
    async def _erro_validacao(request: Request, e: RequestValidationError):
        detalhes = [{"campo": ".".join(str(p) for p in erro.get("loc", [])[1:]),
                     "mensagem": erro.get("msg", "")} for erro in e.errors()]
        return JSONResponse(_corpo_erro(request, "entrada_invalida", "Parâmetros inválidos.", detalhes),
                            status_code=422)

    @app.exception_handler(StarletteHTTPException)
    async def _erro_http(request: Request, e: StarletteHTTPException):
        codigos = {404: "rota_inexistente", 405: "metodo_nao_permitido"}
        return JSONResponse(_corpo_erro(request, codigos.get(e.status_code, "erro_http"), str(e.detail)),
                            status_code=e.status_code)

    @app.exception_handler(ConsultaError)
    async def _erro_consulta(request: Request, e: ConsultaError):
        logger.warning("[api] consulta falhou id=%s: %s", getattr(request.state, "id", "-"), e)
        return JSONResponse(_corpo_erro(request, "falha_na_fonte",
                                        "A fonte do CNJ devolveu uma resposta inesperada."),
                            status_code=502)

    @app.exception_handler(Exception)
    async def _erro_inesperado(request: Request, e: Exception):
        id_req = getattr(request.state, "id", "-")
        logger.exception("[api] erro inesperado id=%s", id_req)
        # Este caminho passa por fora do middleware: os cabeçalhos vão aqui mesmo
        return JSONResponse(_corpo_erro(request, "erro_interno",
                                        "Erro interno. Informe o id_requisicao ao suporte."),
                            status_code=500,
                            headers={**CABECALHOS_SEGURANCA, "X-Request-ID": id_req,
                                     "Content-Security-Policy": CSP_JSON})

    # ── autenticação, limite por minuto e cota do mês ──
    def autenticar(request: Request, chave: str | None = Security(_cabecalho_chave)) -> Identidade:
        ip = request.client.host if request.client else "-"
        barrado, espera = limite_falhas.bloqueado(ip)
        if barrado:
            raise ErroAPI(429, "muitas_tentativas",
                          "Muitas tentativas com chave inválida. Aguarde.", tentar_em=espera)
        identidade: Identidade | None = None
        if chave and len(chave) <= 200:
            do_banco = banco.chave_valida(chave) if banco is not None else None
            if do_banco is not None:
                identidade = Identidade(do_banco.cliente, do_banco.limite_por_minuto, do_banco)
            else:
                recebido = _hash(chave)
                for nome, esperado in capi.chaves.items():
                    if hmac.compare_digest(recebido, esperado):
                        identidade = Identidade(nome, capi.limite_por_minuto)
        if identidade is None:
            limite_falhas.registrar(ip)
            auditoria.warning("id=%s chave inválida ip=%s", request.state.id, ip)
            raise ErroAPI(401, "nao_autorizado", "Envie uma chave válida no cabeçalho X-API-Key.")
        request.state.cliente = identidade.cliente
        if identidade.chave is not None and not identidade.chave.ativo:
            raise ErroAPI(403, "conta_suspensa", "Conta suspensa. Fale com o suporte.")
        quem = (f"cliente:{identidade.chave.cliente_id}" if identidade.chave
                else f"interna:{identidade.cliente}")
        permitido, espera = limite_cliente.registrar(quem, identidade.limite_por_minuto)
        if not permitido:
            raise ErroAPI(429, "limite_excedido",
                          f"Limite de {identidade.limite_por_minuto} requisições por minuto atingido.",
                          tentar_em=espera)
        return identidade

    def _registrar(request: Request, identidade: Identidade, cobravel: bool) -> Identidade:
        """Anota a chamada no histórico e, se cobrável, desconta da cota do mês."""
        chave = identidade.chave
        if chave is None or banco is None:
            return identidade            # chave interna: sem cota
        ip = request.client.host if request.client else "-"
        permitido, uso_id, usado = banco.reservar(chave, metodo=request.method, caminho=request.url.path,
                                                  ip=ip, cobravel=cobravel)
        request.state.cota = (chave.limite_mensal, usado, cobravel and permitido)
        if not permitido:
            raise ErroAPI(402, "cota_esgotada",
                          f"A cota de {chave.limite_mensal} consultas do mês acabou. "
                          "Ela renova no dia 1º; para ampliar agora, fale com o suporte.")
        request.state.uso_id = uso_id
        return identidade

    def cobrar(request: Request, identidade: Identidade = Depends(autenticar)) -> Identidade:
        return _registrar(request, identidade, cobravel=True)

    def sem_custo(request: Request, identidade: Identidade = Depends(autenticar)) -> Identidade:
        return _registrar(request, identidade, cobravel=False)

    def _fonte_fora(e: FonteIndisponivel) -> ErroAPI:
        logger.warning("[api] %s", e)
        return ErroAPI(503, "fonte_indisponivel",
                       f"A fonte {e.fonte} não respondeu. Tente de novo em instantes.", tentar_em=30)

    # ── rotas ──
    @app.get("/saude", tags=["Sistema"], summary="Verifica se a API está no ar")
    def saude() -> dict:
        return {"ok": True, "versao": __version__}

    @app.get("/v1/processos/{numero}", response_model=RespostaProcesso, tags=["Processos"],
             summary="Processo completo pelo número CNJ", responses={404: {"model": RespostaErro},
                                                                      **RESPOSTAS_ERRO})
    def processo(
        numero: str = Path(..., max_length=30, description="Número CNJ, com ou sem pontuação",
                           examples=["5053283-30.2024.8.13.0079"]),
        publicacoes: bool = Query(True, description="Inclui as publicações do DJEN"),
        modo: str = Query("completo", pattern="^(completo|rapido)$",
                          description="`completo` junta DataJud + DJEN; `rapido` usa só o DJEN "
                                      "(responde em segundos, sem o histórico interno)"),
        _cliente: Identidade = Depends(cobrar),
    ) -> RespostaProcesso:
        cnj = _cnj_valido(numero)
        inicio = time.perf_counter()
        avisos: list[str] = []
        try:
            if modo == "rapido":
                achado = consultar_processo_pelo_diario(cnj.formatado, config=cfg, cache=cache)
            else:
                achado, avisos = consultar_processo_detalhado(cnj.formatado, com_publicacoes=publicacoes,
                                                              config=cfg, cache=cache)
        except FonteIndisponivel as e:
            raise _fonte_fora(e)
        except NumeroInvalido:
            raise ErroAPI(422, "numero_invalido", "Número fora do padrão CNJ.")
        if achado is None:
            if avisos:      # as fontes não responderam: não dá para dizer que não existe
                raise ErroAPI(503, "fonte_indisponivel", " ".join(avisos), tentar_em=30)
            raise ErroAPI(404, "processo_nao_encontrado",
                          "O processo não aparece no DataJud nem no DJEN. Se foi distribuído agora, "
                          "tente de novo mais tarde; processo em segredo de justiça não é publicado.")
        return RespostaProcesso(consultado_em=datetime.now(timezone.utc),
                                tempo_ms=int((time.perf_counter() - inicio) * 1000),
                                avisos=avisos, processo=formatar_processo(achado))

    @app.get("/v1/processos/{numero}/existe", response_model=RespostaExiste, tags=["Processos"],
             summary="Só verifica se o processo já aparece em alguma fonte", responses=RESPOSTAS_ERRO)
    def processo_existe(numero: str = Path(..., max_length=30),
                        _cliente: Identidade = Depends(sem_custo)) -> RespostaExiste:
        cnj = _cnj_valido(numero)
        achou, fonte = existe(cnj.formatado, config=cfg)
        return RespostaExiste(numero=cnj.formatado, existe=achou, fonte=fonte)

    @app.get("/v1/cnj/{numero}", response_model=RespostaCNJ, tags=["Utilitários"],
             summary="Decodifica o número CNJ sem consultar nenhuma fonte", responses=RESPOSTAS_ERRO)
    def decodificar(numero: str = Path(..., max_length=30),
                    _cliente: Identidade = Depends(sem_custo)) -> RespostaCNJ:
        cnj = CNJ(numero)
        if not cnj.valido:
            return RespostaCNJ(numero=numero, numero_digitos=re.sub(r"\D", "", numero), valido=False)
        if not digito_confere(cnj):
            return RespostaCNJ(numero=cnj.formatado, numero_digitos=cnj.digitos, valido=False)
        return RespostaCNJ(numero=cnj.formatado, numero_digitos=cnj.digitos, valido=True,
                           tribunal=cnj.tribunal, segmento=cnj.nome_segmento, ano=cnj.ano,
                           origem_codigo=cnj.origem_codigo)

    @app.get("/v1/oab/{uf}/{numero}/processos", response_model=RespostaOABProcessos, tags=["OAB"],
             summary="Processos em que a OAB foi publicada no DJEN no período", responses=RESPOSTAS_ERRO)
    def oab_processos(
        uf: str = Path(..., min_length=2, max_length=2, examples=["MG"]),
        numero: str = Path(..., pattern=r"^[0-9]{1,7}[A-Za-z]?$", examples=["123456"]),
        dias: int = Query(30, ge=1, le=365),
        tribunal: str = Query("", max_length=10, pattern=r"^[A-Za-z0-9-]*$"),
        _cliente: Identidade = Depends(cobrar),
    ) -> RespostaOABProcessos:
        uf, numero = _oab_valida(uf, numero)
        try:
            processos = buscar_por_oab(numero, uf, tribunal=tribunal, dias=dias, config=cfg)
        except FonteIndisponivel as e:
            raise _fonte_fora(e)
        resumos = [ResumoProcessoOut(
            numero=p.numero, titulo=p.titulo, tribunal=p.tribunal, classe=p.classe,
            orgao_julgador=p.orgao_julgador, data_ultima_movimentacao=p.data_ultima_movimentacao,
            data_ultima_movimentacao_formatada=data_br(p.data_ultima_movimentacao),
            publicacoes=len(p.publicacoes)) for p in processos]
        return RespostaOABProcessos(oab=f"OAB/{uf} {numero}", periodo_dias=dias,
                                    total=len(resumos), processos=resumos)

    @app.get("/v1/oab/{uf}/{numero}/publicacoes", response_model=RespostaOABPublicacoes, tags=["OAB"],
             summary="Publicações do DJEN da OAB no período (controle de prazos)", responses=RESPOSTAS_ERRO)
    def oab_publicacoes(
        uf: str = Path(..., min_length=2, max_length=2, examples=["MG"]),
        numero: str = Path(..., pattern=r"^[0-9]{1,7}[A-Za-z]?$", examples=["123456"]),
        dias: int = Query(7, ge=1, le=90),
        paginas: int = Query(1, ge=1, le=20, description="Páginas de 100 publicações"),
        _cliente: Identidade = Depends(cobrar),
    ) -> RespostaOABPublicacoes:
        uf, numero = _oab_valida(uf, numero)
        fim = date.today()
        inicio = fim - timedelta(days=dias)
        try:
            pubs = comunica.publicacoes_por_oab(numero, uf, inicio=inicio, fim=fim,
                                                paginas=paginas, config=cfg)
        except FonteIndisponivel as e:
            raise _fonte_fora(e)
        return RespostaOABPublicacoes(oab=f"OAB/{uf} {numero}", inicio=inicio, fim=fim,
                                      total=len(pubs), publicacoes=[_publicacao(p) for p in pubs])

    @app.get("/v1/conta", response_model=RespostaConta, tags=["Conta"],
             summary="Seu plano e o consumo do mês", responses=RESPOSTAS_ERRO)
    def conta(identidade: Identidade = Depends(autenticar)) -> RespostaConta:
        reinicia = datetime.fromisoformat(_iso(inicio_do_proximo_mes()).replace("Z", "+00:00"))
        chave = identidade.chave
        if chave is None or banco is None:
            return RespostaConta(cliente=identidade.cliente, plano="interno", usado_no_mes=0,
                                 reinicia_em=reinicia, limite_por_minuto=identidade.limite_por_minuto)
        usado = banco.usado_no_mes(chave.cliente_id)
        return RespostaConta(cliente=chave.cliente, plano=chave.plano, limite_mensal=chave.limite_mensal,
                             usado_no_mes=usado, restante=max(0, chave.limite_mensal - usado),
                             reinicia_em=reinicia, limite_por_minuto=chave.limite_por_minuto)

    if banco is not None:
        from .painel.rotas import montar_painel
        montar_painel(app, banco, cookie_seguro=capi.cookie_seguro, ips_permitidos=capi.painel_ips)

    return app


def servir(host: str = "127.0.0.1", porta: int = 8000) -> int:
    """Sobe a API com o uvicorn."""
    import uvicorn

    logging.getLogger("consulta_processos").setLevel(logging.INFO)
    capi = ConfigAPI.do_ambiente()
    banco = Banco(capi.banco) if capi.banco else None
    if banco is not None and banco.contar_admins() == 0:
        print("Nenhum administrador cadastrado. Crie o seu antes de abrir o painel:\n"
              "  consulta-processos admin criar voce@empresa.com.br")
    app = criar_app(capi, banco=banco)
    print(f"API em http://{host}:{porta}  ·  documentação /docs  ·  painel /admin")
    # Atrás do HAProxy, o IP real do cliente vem no X-Forwarded-For: só confie
    # nele quando vier do proxy (API_PROXY_CONFIAVEL, ex.: 172.16.100.1,172.16.102.1)
    confiaveis = _env("API_PROXY_CONFIAVEL", "127.0.0.1")
    uvicorn.run(app, host=host, port=porta, proxy_headers=True, forwarded_allow_ips=confiaveis,
                server_header=False, log_level="info")
    return 0


__all__ = ["ConfigAPI", "ErroAPI", "LimiteDeTaxa", "criar_app", "formatar_processo", "gerar_chave",
           "ler_chaves", "servir"]
