"""Rotas do painel: login, clientes, chaves, uso e auditoria.

A tela (/admin) é uma página estática que conversa com /admin/api em JSON.
Sessão em cookie HttpOnly + SameSite=Strict, e toda alteração exige o cabeçalho
X-CSRF-Token da sessão. As rotas não aparecem na documentação pública da API.
"""
from __future__ import annotations

import hmac
import ipaddress
import logging
from importlib import resources

from fastapi import APIRouter, Depends, FastAPI, Path, Query, Request, Response
from fastapi.responses import FileResponse, JSONResponse
from pydantic import BaseModel, Field

from ..rest import ErroAPI, LimiteDeTaxa
from .banco import Banco

logger = logging.getLogger(__name__)

COOKIE = "rj_sessao"
ESTATICO = resources.files(__package__) / "estatico"
ARQUIVOS = {"app.js": "text/javascript; charset=utf-8", "app.css": "text/css; charset=utf-8"}

PADRAO_EMAIL = r"^[A-Za-z0-9._%+'-]{1,64}@[A-Za-z0-9-]{1,63}(\.[A-Za-z0-9-]{1,63})*\.[A-Za-z]{2,24}$"


class LoginEntrada(BaseModel):
    email: str = Field(..., max_length=254)
    senha: str = Field(..., max_length=256)


class ClienteEntrada(BaseModel):
    nome: str = Field(..., min_length=1, max_length=120)
    email: str = Field(..., max_length=254, pattern=PADRAO_EMAIL)
    empresa: str = Field("", max_length=120)
    plano: str = Field("", max_length=40)
    valor_mensal_centavos: int = Field(0, ge=0, le=100_000_000)
    limite_mensal: int = Field(1000, ge=0, le=100_000_000)
    limite_por_minuto: int = Field(60, ge=1, le=10_000)
    ativo: bool = True
    observacoes: str = Field("", max_length=2000)


class ClienteAlteracao(BaseModel):
    nome: str | None = Field(None, min_length=1, max_length=120)
    email: str | None = Field(None, max_length=254, pattern=PADRAO_EMAIL)
    empresa: str | None = Field(None, max_length=120)
    plano: str | None = Field(None, max_length=40)
    valor_mensal_centavos: int | None = Field(None, ge=0, le=100_000_000)
    limite_mensal: int | None = Field(None, ge=0, le=100_000_000)
    limite_por_minuto: int | None = Field(None, ge=1, le=10_000)
    ativo: bool | None = None
    observacoes: str | None = Field(None, max_length=2000)


class ChaveEntrada(BaseModel):
    nome: str = Field("", max_length=60)


def _ip(request: Request) -> str:
    return request.client.host if request.client else "-"


def _redes(texto: str) -> list:
    redes = []
    for item in (texto or "").replace(";", ",").split(","):
        item = item.strip()
        if item:
            try:
                redes.append(ipaddress.ip_network(item, strict=False))
            except ValueError:
                logger.warning("[painel] faixa de IP inválida em PAINEL_IPS_PERMITIDOS: %r", item)
    return redes


def montar_painel(app: FastAPI, banco: Banco, *, cookie_seguro: bool = True,
                  ips_permitidos: str = "") -> None:
    redes = _redes(ips_permitidos)
    # Login: 10 tentativas por IP e 5 por e-mail a cada 15 minutos
    tentativas_ip = LimiteDeTaxa(10, janela=15 * 60)
    tentativas_email = LimiteDeTaxa(5, janela=15 * 60)

    def restringir_ip(request: Request) -> None:
        """Com PAINEL_IPS_PERMITIDOS definido, quem está fora nem vê que o painel existe."""
        if not redes:
            return
        try:
            ip = ipaddress.ip_address(_ip(request))
        except ValueError:
            raise ErroAPI(404, "rota_inexistente", "Not Found")
        if not any(ip in rede for rede in redes):
            raise ErroAPI(404, "rota_inexistente", "Not Found")

    def admin_logado(request: Request) -> dict:
        restringir_ip(request)
        sessao = banco.sessao(request.cookies.get(COOKIE, ""))
        if sessao is None:
            raise ErroAPI(401, "sessao_expirada", "Faça login novamente.")
        if request.method not in ("GET", "HEAD"):
            enviado = request.headers.get("X-CSRF-Token", "")
            if not hmac.compare_digest(enviado.encode(), sessao["csrf"].encode()):
                raise ErroAPI(403, "csrf_invalido", "Recarregue a página e tente de novo.")
        request.state.cliente = f"admin:{sessao['email']}"
        return sessao

    def _cookie(resposta: Response, valor: str, max_age: int | None) -> None:
        resposta.set_cookie(COOKIE, valor, max_age=max_age, path="/admin", httponly=True,
                            secure=cookie_seguro, samesite="strict")

    rotas = APIRouter(prefix="/admin", include_in_schema=False)

    # ── página ──
    @rotas.get("")
    @rotas.get("/")
    def pagina(request: Request):
        restringir_ip(request)
        return FileResponse(str(ESTATICO / "painel.html"), media_type="text/html; charset=utf-8")

    @rotas.get("/estatico/{nome}")
    def estatico(request: Request, nome: str = Path(..., max_length=20)):
        restringir_ip(request)
        if nome not in ARQUIVOS:           # lista fixa: nada de caminho vindo da URL
            raise ErroAPI(404, "rota_inexistente", "Not Found")
        return FileResponse(str(ESTATICO / nome), media_type=ARQUIVOS[nome])

    # ── sessão ──
    @rotas.post("/api/login")
    def login(dados: LoginEntrada, request: Request):
        restringir_ip(request)
        ip, email = _ip(request), dados.email.strip().lower()
        for limite, quem in ((tentativas_ip, ip), (tentativas_email, email)):
            barrado, espera = limite.bloqueado(quem)
            if barrado:
                banco.evento("login_bloqueado", alvo=email, ip=ip)
                raise ErroAPI(429, "muitas_tentativas",
                              f"Muitas tentativas. Tente de novo em {max(1, espera // 60)} min.",
                              tentar_em=espera)
        admin = banco.autenticar_admin(email, dados.senha)
        if admin is None:
            tentativas_ip.registrar(ip)
            tentativas_email.registrar(email)
            banco.evento("login_falhou", alvo=email, ip=ip)
            raise ErroAPI(401, "login_invalido", "E-mail ou senha incorretos.")
        valor, csrf = banco.criar_sessao(admin["id"], ip)
        banco.evento("login", admin=admin, ip=ip)
        resposta = JSONResponse({"ok": True, "email": admin["email"], "nome": admin["nome"], "csrf": csrf})
        _cookie(resposta, valor, max_age=12 * 60 * 60)
        return resposta

    @rotas.post("/api/sair")
    def sair(request: Request, sessao: dict = Depends(admin_logado)):
        banco.encerrar_sessao(request.cookies.get(COOKIE, ""))
        banco.evento("logout", admin=sessao, ip=_ip(request))
        resposta = JSONResponse({"ok": True})
        _cookie(resposta, "", max_age=0)
        return resposta

    @rotas.get("/api/sessao")
    def sessao_atual(sessao: dict = Depends(admin_logado)):
        return {"ok": True, "email": sessao["email"], "nome": sessao["nome"], "csrf": sessao["csrf"]}

    # ── visão geral ──
    @rotas.get("/api/resumo")
    def resumo(_s: dict = Depends(admin_logado)):
        return {"ok": True, **banco.resumo()}

    # ── clientes ──
    def _cliente_ou_404(cliente_id: int) -> dict:
        cliente = banco.cliente(cliente_id)
        if cliente is None:
            raise ErroAPI(404, "cliente_inexistente", "Cliente não encontrado.")
        return cliente

    @rotas.get("/api/clientes")
    def clientes(_s: dict = Depends(admin_logado)):
        return {"ok": True, "clientes": banco.listar_clientes()}

    @rotas.post("/api/clientes", status_code=201)
    def criar_cliente(dados: ClienteEntrada, request: Request, sessao: dict = Depends(admin_logado)):
        entrada = dados.model_dump()
        entrada["ativo"] = int(entrada["ativo"])
        cliente_id = banco.criar_cliente(entrada)
        banco.evento("cliente_criado", admin=sessao, alvo=f"cliente:{cliente_id}",
                     detalhe=f"{dados.nome} · {dados.plano} · {dados.limite_mensal}/mês", ip=_ip(request))
        return {"ok": True, "cliente": banco.cliente(cliente_id)}

    @rotas.get("/api/clientes/{cliente_id}")
    def cliente(cliente_id: int = Path(..., ge=1), dias: int = Query(30, ge=7, le=90),
                _s: dict = Depends(admin_logado)):
        dados = _cliente_ou_404(cliente_id)
        return {"ok": True, "cliente": dados, "chaves": banco.listar_chaves(cliente_id),
                "serie": banco.serie_diaria(cliente_id, dias),
                "uso": banco.uso_recente(cliente_id, 100)}

    @rotas.patch("/api/clientes/{cliente_id}")
    def alterar_cliente(dados: ClienteAlteracao, request: Request, cliente_id: int = Path(..., ge=1),
                        sessao: dict = Depends(admin_logado)):
        _cliente_ou_404(cliente_id)
        mudancas = dados.model_dump(exclude_none=True)
        if "ativo" in mudancas:
            mudancas["ativo"] = int(mudancas["ativo"])
        banco.atualizar_cliente(cliente_id, mudancas)
        acao = "cliente_alterado"
        if mudancas.keys() == {"ativo"}:
            acao = "cliente_reativado" if mudancas["ativo"] else "cliente_suspenso"
        banco.evento(acao, admin=sessao, alvo=f"cliente:{cliente_id}",
                     detalhe=", ".join(f"{k}={v}" for k, v in mudancas.items() if k != "observacoes"),
                     ip=_ip(request))
        return {"ok": True, "cliente": banco.cliente(cliente_id)}

    # ── chaves ──
    @rotas.post("/api/clientes/{cliente_id}/chaves", status_code=201)
    def gerar_chave(dados: ChaveEntrada, request: Request, cliente_id: int = Path(..., ge=1),
                    sessao: dict = Depends(admin_logado)):
        _cliente_ou_404(cliente_id)
        texto, info = banco.criar_chave(cliente_id, dados.nome)
        banco.evento("chave_gerada", admin=sessao, alvo=f"cliente:{cliente_id}",
                     detalhe=f"{info['prefixo']}… {dados.nome}", ip=_ip(request))
        # A única vez em que a chave aparece inteira
        return {"ok": True, "chave": texto, "info": info}

    @rotas.delete("/api/clientes/{cliente_id}/chaves/{chave_id}")
    def revogar_chave(request: Request, cliente_id: int = Path(..., ge=1), chave_id: int = Path(..., ge=1),
                      sessao: dict = Depends(admin_logado)):
        if not banco.revogar_chave(cliente_id, chave_id):
            raise ErroAPI(404, "chave_inexistente", "Chave não encontrada ou já revogada.")
        banco.evento("chave_revogada", admin=sessao, alvo=f"cliente:{cliente_id}",
                     detalhe=f"chave:{chave_id}", ip=_ip(request))
        return {"ok": True}

    # ── uso e auditoria ──
    @rotas.get("/api/uso")
    def uso(limite: int = Query(200, ge=1, le=500), _s: dict = Depends(admin_logado)):
        return {"ok": True, "uso": banco.uso_recente(None, limite)}

    @rotas.get("/api/eventos")
    def eventos(limite: int = Query(200, ge=1, le=1000), _s: dict = Depends(admin_logado)):
        return {"ok": True, "eventos": banco.eventos(limite)}

    app.include_router(rotas)
