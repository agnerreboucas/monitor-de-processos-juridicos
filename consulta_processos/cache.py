"""Cache em memória com prazo de validade.

O Datajud leva de 20 segundos a mais de 2 minutos em horário cheio. Consultar o
mesmo processo de novo — o caso mais comum numa tela aberta o dia todo — não
precisa pagar esse tempo outra vez.

Duas garantias:
  • quem pede a mesma chave ao mesmo tempo espera a primeira consulta em vez de
    disparar outra (o Datajud responde 429 para requisições seguidas);
  • erro não entra no cache: a próxima consulta tenta a fonte de novo.

Fica só na memória do processo: nada vai para o disco e tudo some ao fechar.
"""
from __future__ import annotations

import copy
import threading
import time
from collections import OrderedDict
from collections.abc import Callable
from typing import Any


class CacheTTL:
    def __init__(self, maximo: int = 500):
        self.maximo = maximo
        self._dados: OrderedDict[str, tuple[float, Any]] = OrderedDict()
        self._travas: dict[str, threading.Lock] = {}
        self._trava = threading.Lock()

    def _trava_da_chave(self, chave: str) -> threading.Lock:
        with self._trava:
            return self._travas.setdefault(chave, threading.Lock())

    def _ler(self, chave: str) -> tuple[bool, Any]:
        with self._trava:
            item = self._dados.get(chave)
            if item is None:
                return False, None
            vence_em, valor = item
            if time.monotonic() >= vence_em:
                del self._dados[chave]
                return False, None
            self._dados.move_to_end(chave)
        # cópia: quem recebe pode alterar o objeto sem estragar o guardado
        return True, copy.deepcopy(valor)

    def _gravar(self, chave: str, valor: Any, validade: float) -> None:
        with self._trava:
            self._dados[chave] = (time.monotonic() + validade, copy.deepcopy(valor))
            self._dados.move_to_end(chave)
            while len(self._dados) > self.maximo:
                antiga, _ = self._dados.popitem(last=False)
                self._travas.pop(antiga, None)

    def obter(self, chave: str, validade: float, buscar: Callable[[], Any]) -> Any:
        """Devolve o valor guardado ou chama `buscar` (uma vez só por chave)."""
        achou, valor = self._ler(chave)
        if achou:
            return valor
        with self._trava_da_chave(chave):
            achou, valor = self._ler(chave)       # outro pedido pode ter buscado enquanto esperávamos
            if achou:
                return valor
            valor = buscar()                      # exceção sobe sem ser guardada
            self._gravar(chave, valor, validade)
            return copy.deepcopy(valor)

    def limpar(self) -> None:
        with self._trava:
            self._dados.clear()
            self._travas.clear()

    def __len__(self) -> int:
        return len(self._dados)
