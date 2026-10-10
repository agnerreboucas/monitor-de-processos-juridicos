"""Painel do monitor: a tela do escritório (prazos, audiências, publicações).

Servidor local, só da sua máquina (127.0.0.1), sem dependência além da biblioteca.
Para ver pela rede, ponha atrás de um proxy com senha: esta tela não tem login.

Proteções contra outro site abrir a tela e mexer nos dados:
- só atende quando o endereço digitado é localhost/127.0.0.1 (contra DNS rebinding);
- pedidos que alteram dados exigem JSON, o cabeçalho `X-Painel` e, se o navegador
  mandar `Origin`, que seja a própria tela.
"""
from __future__ import annotations

import json
import logging
import re
import threading
import time
import webbrowser
from collections.abc import Callable
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from ..config import Config
from ..erros import ConsultaError
from ..fontes.comunica import publicacoes_do_processo, publicacoes_por_oab
from . import motor
from .banco import Banco, ErroDeCadastro
from .classificar import AVISO_PRAZO, CATEGORIAS

logger = logging.getLogger(__name__)

PAGINA = Path(__file__).with_name("estatico") / "painel.html"
LIMITE_CORPO = 64 * 1024


class Estado:
    """O que o servidor compartilha: banco, configuração e a rotina em andamento."""

    def __init__(self, banco: Banco, config: Config | None = None, *,
                 buscar_oab: Callable = publicacoes_por_oab,
                 buscar_processo: Callable = publicacoes_do_processo, pausa: float = 0.0):
        self.banco, self.config, self.pausa = banco, config, pausa
        self.buscar_oab, self.buscar_processo = buscar_oab, buscar_processo
        self._trava = threading.Lock()
        self.executando = False
        self.ultimo: dict | None = None

    def executar_agora(self, esperar: bool = False) -> bool:
        """Dispara a rotina em segundo plano. False se já havia uma rodando."""
        with self._trava:
            if self.executando:
                return False
            self.executando = True
        fio = threading.Thread(target=self._rodar, daemon=True)
        fio.start()
        if esperar:
            fio.join()
        return True

    def _rodar(self) -> None:
        try:
            r = motor.executar(self.banco, config=self.config, buscar_oab=self.buscar_oab,
                               buscar_processo=self.buscar_processo, pausa=self.pausa)
            self.ultimo = {"buscados": r.buscados, "novos": r.novos, "erros": r.erros}
        except Exception:  # noqa: BLE001
            logger.exception("monitor: a rotina falhou")
            self.ultimo = {"buscados": 0, "novos": 0, "erros": ["A rotina falhou. Veja o terminal."]}
        finally:
            with self._trava:
                self.executando = False


def _manipulador(estado: Estado):
    class Manipulador(BaseHTTPRequestHandler):
        server_version = "monitor-juridico"

        def log_message(self, formato, *args):
            logger.debug("%s - %s", self.address_string(), formato % args)

        # ── utilidades ───────────────────────────────────────────────────────
        def _enviar(self, codigo: int, corpo: bytes, tipo: str) -> None:
            self.send_response(codigo)
            self.send_header("Content-Type", tipo)
            self.send_header("Content-Length", str(len(corpo)))
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Referrer-Policy", "no-referrer")
            self.send_header("Content-Security-Policy",
                             "default-src 'self'; style-src 'self' 'unsafe-inline'; "
                             "script-src 'self' 'unsafe-inline'; frame-ancestors 'none'")
            self.end_headers()
            self.wfile.write(corpo)

        def _json(self, dado: dict, codigo: int = 200) -> None:
            self._enviar(codigo, json.dumps(dado, ensure_ascii=False).encode("utf-8"),
                         "application/json; charset=utf-8")

        def _host_confiavel(self) -> bool:
            porta = self.server.server_port
            return (self.headers.get("Host") or "").lower() in {
                f"localhost:{porta}", f"127.0.0.1:{porta}", "localhost", "127.0.0.1"}

        def _pedido_confiavel(self) -> bool:
            if self.headers.get("X-Painel") != "1":
                return False
            if "application/json" not in (self.headers.get("Content-Type") or ""):
                return False
            origem = self.headers.get("Origin")
            if origem:
                porta = self.server.server_port
                return origem.lower() in {f"http://localhost:{porta}", f"http://127.0.0.1:{porta}"}
            return True

        def _corpo(self) -> dict:
            tamanho = int(self.headers.get("Content-Length") or 0)
            if tamanho > LIMITE_CORPO:
                raise ErroDeCadastro("Pedido grande demais.")
            bruto = self.rfile.read(tamanho) or b"{}"
            try:
                dado = json.loads(bruto)
            except ValueError:
                raise ErroDeCadastro("Pedido malformado.") from None
            if not isinstance(dado, dict):
                raise ErroDeCadastro("Pedido malformado.")
            return dado

        # ── GET ──────────────────────────────────────────────────────────────
        def do_GET(self):  # noqa: N802
            if not self._host_confiavel():
                self._enviar(403, b"endereco nao permitido", "text/plain; charset=utf-8")
                return
            url = urlparse(self.path)
            consulta = {k: v[0] for k, v in parse_qs(url.query).items()}
            try:
                if url.path in ("/", "/index.html"):
                    self._enviar(200, PAGINA.read_bytes(), "text/html; charset=utf-8")
                elif url.path == "/favicon.ico":
                    self._enviar(204, b"", "image/x-icon")
                elif url.path == "/api/resumo":
                    self._json({"ok": True, "dado": estado.banco.resumo(),
                                "execucao": {"rodando": estado.executando, "ultimo": estado.ultimo},
                                "categorias": CATEGORIAS, "aviso_prazo": AVISO_PRAZO})
                elif url.path == "/api/monitorados":
                    self._json({"ok": True, "dado": estado.banco.listar_monitorados()})
                elif url.path == "/api/publicacoes":
                    lista = estado.banco.publicacoes(
                        advogado=consulta.get("advogado", ""), uf=consulta.get("uf", ""),
                        segmento=consulta.get("segmento", ""), categoria=consulta.get("categoria", ""),
                        busca=consulta.get("q", ""), nao_lidas=consulta.get("nao_lidas") == "1",
                        limite=int(consulta.get("limite") or 100))
                    self._json({"ok": True, "dado": lista})
                elif (m := re.fullmatch(r"/api/publicacoes/(\d+)", url.path)):
                    pub = estado.banco.obter_publicacao(int(m.group(1)))
                    if pub:
                        self._json({"ok": True, "dado": pub})
                    else:
                        self._json({"ok": False, "erro": "Publicação não encontrada."}, 404)
                else:
                    self._enviar(404, b"nao encontrado", "text/plain; charset=utf-8")
            except ValueError:
                self._json({"ok": False, "erro": "Filtro inválido."}, 400)

        # ── POST ─────────────────────────────────────────────────────────────
        def do_POST(self):  # noqa: N802
            if not self._host_confiavel() or not self._pedido_confiavel():
                self._json({"ok": False, "erro": "Pedido não permitido."}, 403)
                return
            caminho = urlparse(self.path).path
            try:
                corpo = self._corpo()
                self._json(self._tratar(caminho, corpo))
            except ErroDeCadastro as e:
                self._json({"ok": False, "erro": str(e)}, 400)
            except ConsultaError as e:
                self._json({"ok": False, "erro": str(e)}, 502)
            except Exception:  # noqa: BLE001
                logger.exception("falha ao atender %s", caminho)
                self._json({"ok": False, "erro": "Algo deu errado. Veja o terminal."}, 500)

        def _tratar(self, caminho: str, corpo: dict) -> dict:
            banco = estado.banco
            if caminho == "/api/monitorados":
                if corpo.get("tipo") == "oab":
                    id_ = banco.adicionar_oab(str(corpo.get("numero", "")), str(corpo.get("uf", "")),
                                              str(corpo.get("rotulo", "")))
                elif corpo.get("tipo") == "processo":
                    id_ = banco.adicionar_processo(str(corpo.get("numero", "")),
                                                   str(corpo.get("rotulo", "")))
                else:
                    raise ErroDeCadastro("Escolha OAB ou processo.")
                return {"ok": True, "dado": {"id": id_}}
            if caminho == "/api/executar":
                return {"ok": True, "iniciou": estado.executar_agora()}
            if caminho == "/api/lidas":
                return {"ok": True, "dado": {"marcadas": banco.marcar_todas_lidas()}}
            if (m := re.fullmatch(r"/api/monitorados/(\d+)/(remover|ativo)", caminho)):
                id_ = int(m.group(1))
                if m.group(2) == "remover":
                    achou = banco.remover_monitorado(id_)
                else:
                    achou = banco.definir_ativo(id_, bool(corpo.get("ativo", True)))
                return {"ok": achou}
            if (m := re.fullmatch(r"/api/publicacoes/(\d+)/lida", caminho)):
                return {"ok": banco.marcar_lida(int(m.group(1)), bool(corpo.get("lida", True)))}
            if (m := re.fullmatch(r"/api/prazos/(\d+)", caminho)):
                achou = banco.atualizar_prazo(
                    int(m.group(1)), status=corpo.get("status"), observacao=corpo.get("observacao"),
                    data_fim=corpo.get("data_fim"))
                return {"ok": achou}
            raise ErroDeCadastro("Endereço desconhecido.")

    return Manipulador


def criar_servidor(banco: Banco, config: Config | None = None, *, porta: int = 8766,
                   host: str = "127.0.0.1", **opcoes) -> ThreadingHTTPServer:
    estado = Estado(banco, config, **opcoes)
    servidor = ThreadingHTTPServer((host, porta), _manipulador(estado))
    servidor.estado = estado  # type: ignore[attr-defined]
    return servidor


def _agendar(estado: Estado, minutos: float) -> None:
    def laco():
        while True:
            time.sleep(minutos * 60)
            estado.executar_agora()
    threading.Thread(target=laco, daemon=True).start()


def servir(porta: int = 8766, *, abrir: bool = True, config: Config | None = None,
           a_cada_minutos: float = 0, banco: Banco | None = None) -> int:
    """Sobe o painel em http://localhost:<porta>."""
    banco = banco or Banco()
    try:
        servidor = criar_servidor(banco, config, porta=porta)
    except OSError as e:
        print(f"Não consegui usar a porta {porta} ({e}). "
              f"Tente outra: consulta-processos monitor painel --porta {porta + 1}")
        return 1
    endereco = f"http://localhost:{porta}"
    if a_cada_minutos > 0:
        _agendar(servidor.estado, a_cada_minutos)  # type: ignore[attr-defined]
    print(f"Monitor aberto em {endereco}  (banco: {banco.caminho})")
    if a_cada_minutos > 0:
        print(f"Busca automática a cada {a_cada_minutos:g} minuto(s) enquanto esta janela estiver aberta.")
    print("Para fechar, volte aqui e pressione Ctrl+C.\n")
    if abrir:
        threading.Timer(0.6, lambda: webbrowser.open(endereco)).start()
    try:
        servidor.serve_forever()
    except KeyboardInterrupt:
        print("\nencerrado")
    finally:
        servidor.server_close()
    return 0


__all__ = ["Estado", "criar_servidor", "servir"]
