"""Aviso por e-mail: o que é novo, os prazos que se aproximam e as audiências de amanhã.

Um e-mail-resumo por rodada (não um por publicação), em texto puro. Regras:

- **publicações novas:** cada uma entra num e-mail uma vez só;
- **prazos:** lembrados uma vez por dia enquanto faltarem `dias_prazo` dias ou menos
  (e os vencidos, até alguém marcar como cumprido ou descartado);
- **audiências e sessões:** avisadas uma vez, na véspera (e no dia, se for a primeira vez).

Se o envio falhar, nada é marcado como avisado: a próxima rodada tenta de novo.
Se não houver nada a avisar, nenhum e-mail sai.

Configuração por variáveis de ambiente (veja `.env.exemplo`): `SMTP_HOST`,
`SMTP_PORTA`, `SMTP_USUARIO`, `SMTP_SENHA`, `SMTP_REMETENTE`, `SMTP_SEGURANCA`.
A senha nunca vai para o banco nem para o log.
"""
from __future__ import annotations

import logging
import os
import re
import smtplib
import ssl
from collections.abc import Callable
from dataclasses import dataclass
from datetime import date, timedelta
from email.message import EmailMessage
from email.utils import formatdate

from ..config import carregar_env
from .banco import Banco
from .classificar import AVISO_PRAZO, CATEGORIAS

logger = logging.getLogger(__name__)

DIAS_PRAZO_PADRAO = 3
LIMITE_PUBLICACOES_NO_EMAIL = 50
TRECHO = 240


@dataclass
class ConfigEmail:
    host: str = ""
    porta: int = 587
    usuario: str = ""
    senha: str = ""
    remetente: str = ""
    seguranca: str = "starttls"        # starttls | ssl | nenhuma
    timeout: float = 20.0

    @classmethod
    def do_ambiente(cls) -> ConfigEmail:
        carregar_env()
        try:
            porta = int(os.environ.get("SMTP_PORTA") or 587)
        except ValueError:
            porta = 587
        usuario = (os.environ.get("SMTP_USUARIO") or "").strip()
        return cls(
            host=(os.environ.get("SMTP_HOST") or "").strip(), porta=porta, usuario=usuario,
            senha=os.environ.get("SMTP_SENHA") or "",
            remetente=(os.environ.get("SMTP_REMETENTE") or usuario).strip(),
            seguranca=(os.environ.get("SMTP_SEGURANCA") or "starttls").strip().lower())

    @property
    def configurado(self) -> bool:
        return bool(self.host and self.remetente and "@" in self.remetente
                    and not re.search(r"[\r\n]", self.remetente))


@dataclass
class ResultadoAviso:
    enviado: bool = False
    motivo: str = ""                         # por que não enviou (ou o erro)
    destinatarios: int = 0
    publicacoes: int = 0
    prazos: int = 0
    audiencias: int = 0
    assunto: str = ""


def enviar_email(cfg: ConfigEmail, destinatarios: list[str], assunto: str, texto: str) -> None:
    """Envia um e-mail de texto. Levanta `smtplib.SMTPException` ou `OSError` se falhar."""
    msg = EmailMessage()
    msg["Subject"] = assunto
    msg["From"] = cfg.remetente
    msg["To"] = ", ".join(destinatarios)
    msg["Date"] = formatdate(localtime=True)
    msg.set_content(texto)
    contexto = ssl.create_default_context()
    if cfg.seguranca == "ssl":
        servidor = smtplib.SMTP_SSL(cfg.host, cfg.porta, timeout=cfg.timeout, context=contexto)
    else:
        servidor = smtplib.SMTP(cfg.host, cfg.porta, timeout=cfg.timeout)
    with servidor:
        if cfg.seguranca == "starttls":
            servidor.starttls(context=contexto)
        if cfg.usuario:
            servidor.login(cfg.usuario, cfg.senha)
        servidor.send_message(msg)


# ── texto do e-mail ──────────────────────────────────────────────────────────

def _data(iso: str | None) -> str:
    return f"{iso[8:10]}/{iso[5:7]}/{iso[:4]}" if iso else "—"


def _data_hora(iso: str | None) -> str:
    if not iso:
        return "—"
    hora = iso[11:16]
    return _data(iso) + (f" {hora}" if hora and hora != "00:00" else "")


def _limpo(texto: str, limite: int = TRECHO) -> str:
    texto = re.sub(r"\s+", " ", texto or "").strip()
    return texto if len(texto) <= limite else texto[: limite - 1].rstrip() + "…"


def _link(url: str) -> list[str]:
    return [f"   {url}"] if (url or "").startswith("https://") else []


def montar_texto(publicacoes: list[dict], prazos: list[dict], audiencias: list[dict],
                 hoje: date) -> tuple[str, str]:
    """(assunto, corpo). O assunto só leva contagens: nunca nome de parte nem de processo."""
    partes = []
    if publicacoes:
        partes.append(f"{len(publicacoes)} publicação(ões) nova(s)")
    if prazos:
        partes.append(f"{len(prazos)} prazo(s) a vencer")
    if audiencias:
        partes.append(f"{len(audiencias)} audiência(s)")
    assunto = "Monitor Jurídico: " + ", ".join(partes)

    linhas = [f"Monitor Jurídico — resumo de {hoje:%d/%m/%Y}", ""]
    if prazos:
        linhas += ["PRAZOS", AVISO_PRAZO, ""]
        for z in prazos:
            quando = date.fromisoformat(z["data_fim"])
            faltam = (quando - hoje).days
            situacao = (f"VENCIDO há {-faltam} dia(s)" if faltam < 0
                        else "vence HOJE" if faltam == 0 else f"vence em {faltam} dia(s)")
            linhas += [f"• {_data(z['data_fim'])} ({situacao}) — {z['numero_processo']} ({z['tribunal']})",
                       f"   {CATEGORIAS.get(z['categoria'], '')} · prazo sugerido: {z['dias']} "
                       f"dia(s) {'úteis' if z['uteis'] else 'corridos'} · {z['status'].replace('_', ' ')}",
                       *_link(z.get("link", ""))]
        linhas.append("")
    if audiencias:
        linhas += ["AUDIÊNCIAS E SESSÕES", "[CONFIRMAR] A data foi lida do texto da publicação.", ""]
        for p in audiencias:
            linhas += [f"• {_data_hora(p['audiencia_em'])} — {p['numero_processo']} ({p['tribunal']})",
                       f"   {p['orgao']}", *_link(p.get("link", ""))]
        linhas.append("")
    if publicacoes:
        linhas += ["PUBLICAÇÕES NOVAS", ""]
        for p in publicacoes[:LIMITE_PUBLICACOES_NO_EMAIL]:
            linhas += [f"• {_data(p['data_disponibilizacao'])} — {p['numero_processo']} ({p['tribunal']})",
                       f"   {CATEGORIAS.get(p['categoria'], p['categoria'])} · {p['orgao']}",
                       f"   {_limpo(p['texto'])}", *_link(p.get("link", ""))]
        restantes = len(publicacoes) - LIMITE_PUBLICACOES_NO_EMAIL
        if restantes > 0:
            linhas.append(f"… e mais {restantes} publicação(ões). Veja todas no painel.")
        linhas.append("")
    linhas += ["—", "Este aviso é um apoio e não substitui a conferência do processo. "
                    "Prazos e datas devem ser confirmados no próprio tribunal."]
    return assunto, "\n".join(linhas) + "\n"


# ── a rotina de aviso ────────────────────────────────────────────────────────

def avisar(banco: Banco, cfg: ConfigEmail | None = None, *, hoje: date | None = None,
           dias_prazo: int = DIAS_PRAZO_PADRAO,
           enviar: Callable[[ConfigEmail, list[str], str, str], None] = enviar_email) -> ResultadoAviso:
    """Junta o que há para avisar e manda um e-mail-resumo. Veja o topo do módulo."""
    cfg = cfg or ConfigEmail.do_ambiente()
    hoje = hoje or date.today()
    destinatarios = [d["email"] for d in banco.listar_destinatarios(so_ativos=True)]
    r = ResultadoAviso(destinatarios=len(destinatarios))
    if not cfg.configurado:
        r.motivo = "E-mail não configurado (defina SMTP_HOST e SMTP_REMETENTE no .env)."
        return r
    if not destinatarios:
        r.motivo = "Nenhum e-mail cadastrado para receber avisos."
        return r

    publicacoes = banco.publicacoes_para_avisar()
    prazos = [z for z in banco.prazos_a_vencer(hoje, dias_prazo)
              if not banco.aviso_enviado(f"prazo:{z['publicacao_id']}:{hoje.isoformat()}")]
    audiencias = [a for a in banco.audiencias_entre(hoje, hoje + timedelta(days=1))
                  if not banco.aviso_enviado(f"audiencia:{a['id']}:{a['audiencia_em']}")]
    r.publicacoes, r.prazos, r.audiencias = len(publicacoes), len(prazos), len(audiencias)
    if not (publicacoes or prazos or audiencias):
        r.motivo = "Nada novo para avisar."
        return r

    r.assunto, corpo = montar_texto(publicacoes, prazos, audiencias, hoje)
    try:
        enviar(cfg, destinatarios, r.assunto, corpo)
    except (smtplib.SMTPException, OSError) as e:
        logger.warning("monitor: e-mail não enviado: %s", type(e).__name__)
        r.motivo = f"Falha ao enviar o e-mail ({type(e).__name__}). Tentarei de novo na próxima rodada."
        return r
    banco.registrar_avisos(
        publicacoes=[p["id"] for p in publicacoes],
        chaves=[f"prazo:{z['publicacao_id']}:{hoje.isoformat()}" for z in prazos]
        + [f"audiencia:{a['id']}:{a['audiencia_em']}" for a in audiencias])
    r.enviado = True
    return r


__all__ = ["ConfigEmail", "ResultadoAviso", "avisar", "enviar_email", "montar_texto"]
