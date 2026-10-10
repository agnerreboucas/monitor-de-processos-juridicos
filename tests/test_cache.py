"""Cache em memória da tela. Sem rede."""
import threading
import time
from unittest.mock import patch

import pytest

from consulta_processos.cache import CacheTTL


def test_segunda_consulta_nao_chama_a_fonte():
    cache, chamadas = CacheTTL(), []
    buscar = lambda: chamadas.append(1) or "resultado"          # noqa: E731
    assert cache.obter("x", 60, buscar) == "resultado"
    assert cache.obter("x", 60, buscar) == "resultado"
    assert len(chamadas) == 1


def test_vencido_busca_de_novo():
    cache, chamadas = CacheTTL(), []
    buscar = lambda: chamadas.append(1) or len(chamadas)        # noqa: E731
    agora = time.monotonic()
    with patch("consulta_processos.cache.time.monotonic", return_value=agora):
        cache.obter("x", 10, buscar)
    with patch("consulta_processos.cache.time.monotonic", return_value=agora + 11):
        assert cache.obter("x", 10, buscar) == 2


def test_erro_nao_fica_guardado():
    cache = CacheTTL()
    with pytest.raises(RuntimeError):
        cache.obter("x", 60, lambda: (_ for _ in ()).throw(RuntimeError("fora do ar")))
    assert cache.obter("x", 60, lambda: "voltou") == "voltou"


def test_quem_recebe_pode_alterar_sem_estragar_o_guardado():
    cache = CacheTTL()
    primeira = cache.obter("x", 60, lambda: {"movimentos": [1]})
    primeira["movimentos"].append(2)
    assert cache.obter("x", 60, lambda: None) == {"movimentos": [1]}


def test_pedidos_simultaneos_consultam_a_fonte_uma_vez():
    """Dois cliques seguidos não podem virar duas consultas ao Datajud (HTTP 429)."""
    cache, chamadas = CacheTTL(), []

    def lenta():
        chamadas.append(1)
        time.sleep(0.2)
        return "ok"

    threads = [threading.Thread(target=cache.obter, args=("x", 60, lenta)) for _ in range(5)]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    assert len(chamadas) == 1


def test_respeita_o_tamanho_maximo():
    cache = CacheTTL(maximo=2)
    for chave in "abc":
        cache.obter(chave, 60, lambda c=chave: c)
    assert len(cache) == 2
