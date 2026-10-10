"""Painel administrativo: clientes, chaves, cotas e histórico de uso da API.

    consulta-processos admin criar voce@empresa.com.br   # primeiro acesso
    consulta-processos api                               # painel em /admin
"""
from .banco import Banco

__all__ = ["Banco"]
