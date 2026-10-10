"""Senhas, tokens e chaves do painel. Só biblioteca padrão."""
from __future__ import annotations

import base64
import hashlib
import hmac
import re
import secrets

# scrypt: custo de memória alto torna ataque com GPU caro
_N, _R, _P = 2**14, 8, 1
TAMANHO_MINIMO_SENHA = 12
PREFIXO_CHAVE = "rj_live_"


def hash_senha(senha: str) -> str:
    sal = secrets.token_bytes(16)
    derivada = hashlib.scrypt(senha.encode("utf-8"), salt=sal, n=_N, r=_R, p=_P, dklen=32)
    b64 = base64.b64encode
    return f"scrypt${_N}${_R}${_P}${b64(sal).decode()}${b64(derivada).decode()}"


def conferir_senha(senha: str, guardado: str | None) -> bool:
    """Compara em tempo constante. Sem hash guardado, gasta o mesmo tempo e nega."""
    if not guardado:
        hash_senha(senha)          # mesmo custo: não revela se o e-mail existe
        return False
    try:
        _, n, r, p, sal, derivada = guardado.split("$")
        calculada = hashlib.scrypt(senha.encode("utf-8"), salt=base64.b64decode(sal),
                                   n=int(n), r=int(r), p=int(p), dklen=32)
    except (ValueError, TypeError):
        return False
    return hmac.compare_digest(calculada, base64.b64decode(derivada))


def problema_na_senha(senha: str) -> str | None:
    """Devolve o motivo de recusa, ou None se a senha serve."""
    if len(senha) < TAMANHO_MINIMO_SENHA:
        return f"A senha precisa de pelo menos {TAMANHO_MINIMO_SENHA} caracteres."
    tipos = sum(bool(re.search(p, senha)) for p in (r"[a-z]", r"[A-Z]", r"\d", r"[^\w\s]"))
    if tipos < 3:
        return "Use pelo menos três tipos: minúsculas, maiúsculas, números e símbolos."
    return None


def token() -> str:
    return secrets.token_urlsafe(32)


def hash_token(valor: str) -> str:
    """Token de alta entropia: SHA-256 basta (não é senha de gente)."""
    return hashlib.sha256(valor.encode("utf-8")).hexdigest()


def nova_chave_api() -> str:
    return PREFIXO_CHAVE + secrets.token_urlsafe(32)


def prefixo_visivel(chave: str) -> str:
    """O pedaço que aparece na tela para identificar a chave sem revelá-la."""
    return chave[: len(PREFIXO_CHAVE) + 6]
