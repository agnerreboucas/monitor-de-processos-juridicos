"""Banco do monitor em SQLite: o que acompanhar, o que já saiu e os prazos.

Mesmo cuidado do banco do painel de clientes: parâmetro vinculado (`?`) em toda
consulta, uma conexão por operação, modo WAL e arquivo com permissão 600.

O arquivo fica em `dados/monitor.db` (ou `MONITOR_BANCO`). Guarda o teor de
intimações, que pode ter dado pessoal: trate o arquivo (e o backup dele) como
trata os autos.
"""
from __future__ import annotations

import json
import os
import re
import sqlite3
from collections.abc import Iterator
from contextlib import contextmanager
from datetime import date, datetime, timedelta, timezone
from pathlib import Path

from ..cnj import CNJ, UFS_POR_CODIGO
from ..modelos import Publicacao
from .classificar import PrazoSugerido

STATUS_PRAZO = ("a_confirmar", "confirmado", "cumprido", "descartado")
SEGMENTOS = {"estadual": (8,), "federal": (4,), "trabalho": (5,), "outros": (1, 2, 3, 6, 7, 9)}
DIAS_PARADO = 60

ESQUEMA = """
CREATE TABLE IF NOT EXISTS monitorados (
    id INTEGER PRIMARY KEY,
    tipo TEXT NOT NULL CHECK (tipo IN ('oab', 'processo')),
    oab_numero TEXT NOT NULL DEFAULT '',
    oab_uf TEXT NOT NULL DEFAULT '',
    numero_processo TEXT NOT NULL DEFAULT '',
    rotulo TEXT NOT NULL DEFAULT '',
    ativo INTEGER NOT NULL DEFAULT 1,
    criado_em TEXT NOT NULL,
    ultima_busca TEXT,
    ultimo_erro TEXT NOT NULL DEFAULT '',
    UNIQUE (tipo, oab_numero, oab_uf, numero_processo)
);
CREATE TABLE IF NOT EXISTS publicacoes (
    id INTEGER PRIMARY KEY,
    id_externo TEXT NOT NULL UNIQUE,
    numero_processo TEXT NOT NULL DEFAULT '',
    tribunal TEXT NOT NULL DEFAULT '',
    segmento INTEGER NOT NULL DEFAULT 0,
    uf TEXT NOT NULL DEFAULT '',
    data_disponibilizacao TEXT,
    tipo_comunicacao TEXT NOT NULL DEFAULT '',
    tipo_documento TEXT NOT NULL DEFAULT '',
    orgao TEXT NOT NULL DEFAULT '',
    classe TEXT NOT NULL DEFAULT '',
    categoria TEXT NOT NULL DEFAULT 'outro',
    texto TEXT NOT NULL DEFAULT '',
    link TEXT NOT NULL DEFAULT '',
    partes TEXT NOT NULL DEFAULT '[]',
    advogados TEXT NOT NULL DEFAULT '[]',
    audiencia_em TEXT,
    lida INTEGER NOT NULL DEFAULT 0,
    primeira_vez_em TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_pub_data ON publicacoes (data_disponibilizacao);
CREATE INDEX IF NOT EXISTS ix_pub_processo ON publicacoes (numero_processo);
CREATE INDEX IF NOT EXISTS ix_pub_audiencia ON publicacoes (audiencia_em);
CREATE TABLE IF NOT EXISTS vinculos (
    publicacao_id INTEGER NOT NULL REFERENCES publicacoes(id) ON DELETE CASCADE,
    monitorado_id INTEGER NOT NULL REFERENCES monitorados(id) ON DELETE CASCADE,
    PRIMARY KEY (publicacao_id, monitorado_id)
);
CREATE TABLE IF NOT EXISTS prazos (
    id INTEGER PRIMARY KEY,
    publicacao_id INTEGER NOT NULL UNIQUE REFERENCES publicacoes(id) ON DELETE CASCADE,
    dias INTEGER NOT NULL,
    uteis INTEGER NOT NULL DEFAULT 1,
    trecho TEXT NOT NULL DEFAULT '',
    data_fim TEXT,
    status TEXT NOT NULL DEFAULT 'a_confirmar',
    observacao TEXT NOT NULL DEFAULT '',
    atualizado_em TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS execucoes (
    id INTEGER PRIMARY KEY,
    iniciada_em TEXT NOT NULL,
    terminada_em TEXT,
    buscados INTEGER NOT NULL DEFAULT 0,
    novos INTEGER NOT NULL DEFAULT 0,
    erros TEXT NOT NULL DEFAULT '[]'
);
"""


class ErroDeCadastro(ValueError):
    """Dado de entrada inválido (mensagem já pronta para mostrar na tela)."""


def _agora() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def caminho_padrao() -> Path:
    return Path(os.environ.get("MONITOR_BANCO") or "dados/monitor.db")


def local_do_processo(numero: str) -> tuple[str, int, str]:
    """(tribunal, segmento, UF) que o próprio número do processo informa.

    A UF só existe quando o tribunal é de um estado (Justiça Estadual, Eleitoral
    e Militar estadual). Federal e do Trabalho cobrem várias UFs: ficam sem UF.
    """
    cnj = CNJ(numero)
    if not cnj.valido:
        return "", 0, ""
    uf = UFS_POR_CODIGO.get(cnj.tribunal_codigo, "") if cnj.segmento in (6, 8, 9) else ""
    return cnj.tribunal, cnj.segmento, uf


class Banco:
    def __init__(self, caminho: str | os.PathLike | None = None):
        self.caminho = Path(caminho) if caminho else caminho_padrao()
        novo = not self.caminho.exists()
        self.caminho.parent.mkdir(parents=True, exist_ok=True)
        with self._conexao() as con:
            con.execute("PRAGMA journal_mode=WAL")
            con.executescript(ESQUEMA)
        if novo:
            try:
                os.chmod(self.caminho, 0o600)
            except OSError:
                pass

    @contextmanager
    def _conexao(self) -> Iterator[sqlite3.Connection]:
        con = sqlite3.connect(self.caminho, timeout=15, isolation_level=None)
        con.row_factory = sqlite3.Row
        con.execute("PRAGMA foreign_keys=ON")
        con.execute("PRAGMA busy_timeout=15000")
        try:
            yield con
        finally:
            con.close()

    @contextmanager
    def _transacao(self) -> Iterator[sqlite3.Connection]:
        with self._conexao() as con:
            con.execute("BEGIN IMMEDIATE")
            try:
                yield con
            except BaseException:
                con.execute("ROLLBACK")
                raise
            con.execute("COMMIT")

    # ── o que acompanhar ─────────────────────────────────────────────────────

    def adicionar_oab(self, numero: str, uf: str, rotulo: str = "") -> int:
        digitos = re.sub(r"\D", "", numero or "")
        uf = (uf or "").strip().upper()
        if not digitos:
            raise ErroDeCadastro("Informe o número da OAB (só números).")
        if not re.fullmatch(r"[A-Z]{2}", uf):
            raise ErroDeCadastro("Informe a UF da OAB com duas letras, ex.: MG.")
        return self._inserir_monitorado("oab", digitos, uf, "", rotulo)

    def adicionar_processo(self, numero: str, rotulo: str = "") -> int:
        cnj = CNJ(numero or "")
        if not cnj.valido:
            raise ErroDeCadastro("Número de processo inválido. Confira os dígitos (padrão CNJ).")
        return self._inserir_monitorado("processo", "", "", cnj.formatado, rotulo)

    def _inserir_monitorado(self, tipo: str, oab: str, uf: str, processo: str, rotulo: str) -> int:
        rotulo = (rotulo or "").strip()[:120]
        with self._transacao() as con:
            existente = con.execute(
                "SELECT id FROM monitorados WHERE tipo=? AND oab_numero=? AND oab_uf=? "
                "AND numero_processo=?", (tipo, oab, uf, processo)).fetchone()
            if existente:
                raise ErroDeCadastro("Isso já está na lista de acompanhamento.")
            cur = con.execute(
                "INSERT INTO monitorados (tipo, oab_numero, oab_uf, numero_processo, rotulo, criado_em) "
                "VALUES (?, ?, ?, ?, ?, ?)", (tipo, oab, uf, processo, rotulo, _agora()))
            return int(cur.lastrowid)

    def listar_monitorados(self, so_ativos: bool = False) -> list[dict]:
        sql = "SELECT * FROM monitorados"
        if so_ativos:
            sql += " WHERE ativo=1"
        with self._conexao() as con:
            return [dict(r) for r in con.execute(sql + " ORDER BY tipo, rotulo, id")]

    def remover_monitorado(self, id_: int) -> bool:
        """Para de acompanhar. As publicações já guardadas continuam no banco."""
        with self._transacao() as con:
            return con.execute("DELETE FROM monitorados WHERE id=?", (id_,)).rowcount > 0

    def definir_ativo(self, id_: int, ativo: bool) -> bool:
        with self._transacao() as con:
            return con.execute("UPDATE monitorados SET ativo=? WHERE id=?",
                               (1 if ativo else 0, id_)).rowcount > 0

    def registrar_busca(self, id_: int, *, ok: bool, erro: str = "") -> None:
        with self._transacao() as con:
            if ok:
                con.execute("UPDATE monitorados SET ultima_busca=?, ultimo_erro='' WHERE id=?",
                            (_agora(), id_))
            else:
                con.execute("UPDATE monitorados SET ultimo_erro=? WHERE id=?", (erro[:300], id_))

    # ── publicações ──────────────────────────────────────────────────────────

    def gravar_publicacao(self, pub: Publicacao, monitorado_id: int, *, categoria: str,
                          audiencia: datetime | None = None,
                          prazo: PrazoSugerido | None = None) -> tuple[int, bool]:
        """Grava uma publicação. Devolve (id, é_nova).

        Se ela já existia (outra busca a trouxe antes), só liga ao novo
        monitoramento: não duplica e não reabre o que já foi lido.
        """
        if not pub.id_externo:
            raise ErroDeCadastro("Publicação sem identificador.")
        tribunal, segmento, uf = local_do_processo(pub.numero_processo)
        numero = CNJ(pub.numero_processo).formatado if tribunal else pub.numero_processo
        with self._transacao() as con:
            linha = con.execute("SELECT id FROM publicacoes WHERE id_externo=?",
                                (pub.id_externo,)).fetchone()
            nova = linha is None
            if nova:
                cur = con.execute(
                    "INSERT INTO publicacoes (id_externo, numero_processo, tribunal, segmento, uf, "
                    "data_disponibilizacao, tipo_comunicacao, tipo_documento, orgao, classe, categoria, "
                    "texto, link, partes, advogados, audiencia_em, primeira_vez_em) "
                    "VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    (pub.id_externo, numero, tribunal, segmento, uf,
                     pub.data_disponibilizacao.isoformat() if pub.data_disponibilizacao else None,
                     pub.tipo_comunicacao, pub.tipo_documento, pub.orgao, pub.classe, categoria,
                     pub.texto, pub.link,
                     json.dumps([p.to_dict() for p in pub.partes], ensure_ascii=False),
                     json.dumps([a.to_dict() for a in pub.advogados], ensure_ascii=False),
                     audiencia.isoformat(timespec="minutes") if audiencia else None, _agora()))
                pub_id = int(cur.lastrowid)
                if prazo:
                    con.execute(
                        "INSERT INTO prazos (publicacao_id, dias, uteis, trecho, data_fim, atualizado_em) "
                        "VALUES (?,?,?,?,?,?)",
                        (pub_id, prazo.dias, 1 if prazo.uteis else 0, prazo.trecho,
                         prazo.data_fim.isoformat() if prazo.data_fim else None, _agora()))
            else:
                pub_id = int(linha["id"])
            con.execute("INSERT OR IGNORE INTO vinculos (publicacao_id, monitorado_id) VALUES (?, ?)",
                        (pub_id, monitorado_id))
            return pub_id, nova

    def marcar_lida(self, id_: int, lida: bool = True) -> bool:
        with self._transacao() as con:
            return con.execute("UPDATE publicacoes SET lida=? WHERE id=?",
                               (1 if lida else 0, id_)).rowcount > 0

    def marcar_todas_lidas(self) -> int:
        with self._transacao() as con:
            return con.execute("UPDATE publicacoes SET lida=1 WHERE lida=0").rowcount

    def obter_publicacao(self, id_: int) -> dict | None:
        with self._conexao() as con:
            linha = con.execute(_SQL_PUBLICACAO + " WHERE p.id=?", (id_,)).fetchone()
        return _publicacao(linha) if linha else None

    def publicacoes(self, *, advogado: str = "", uf: str = "", segmento: str = "",
                    categoria: str = "", busca: str = "", nao_lidas: bool = False,
                    limite: int = 100) -> list[dict]:
        """Publicações guardadas, da mais recente para a mais antiga.

        `advogado` é "UF-NUMERO" (ex.: "MG-123456"): filtra pelo acompanhamento de OAB
        que trouxe a publicação.
        """
        onde, valores = ["1=1"], []
        if advogado:
            uf_oab, _, numero = advogado.partition("-")
            onde.append("EXISTS (SELECT 1 FROM vinculos v JOIN monitorados m ON m.id=v.monitorado_id "
                        "WHERE v.publicacao_id=p.id AND m.tipo='oab' AND m.oab_uf=? AND m.oab_numero=?)")
            valores += [uf_oab.upper(), re.sub(r"\D", "", numero)]
        if uf:
            onde.append("p.uf=?")
            valores.append(uf.upper())
        if segmento in SEGMENTOS:
            codigos = SEGMENTOS[segmento]
            onde.append(f"p.segmento IN ({','.join('?' * len(codigos))})")
            valores += list(codigos)
        if categoria:
            onde.append("p.categoria=?")
            valores.append(categoria)
        if nao_lidas:
            onde.append("p.lida=0")
        if busca:
            termo = f"%{busca.strip()}%"
            onde.append("(p.texto LIKE ? OR p.numero_processo LIKE ? OR p.partes LIKE ? OR p.orgao LIKE ?)")
            valores += [termo] * 4
        limite = max(1, min(int(limite), 500))
        sql = (_SQL_PUBLICACAO + " WHERE " + " AND ".join(onde) +
               " ORDER BY p.data_disponibilizacao DESC, p.id DESC LIMIT ?")
        with self._conexao() as con:
            return [_publicacao(r, resumo=True) for r in con.execute(sql, [*valores, limite])]

    # ── prazos ───────────────────────────────────────────────────────────────

    def atualizar_prazo(self, publicacao_id: int, *, status: str | None = None,
                        observacao: str | None = None, data_fim: str | None = None) -> bool:
        sets, valores = [], []
        if status is not None:
            if status not in STATUS_PRAZO:
                raise ErroDeCadastro("Situação de prazo desconhecida.")
            sets.append("status=?")
            valores.append(status)
        if observacao is not None:
            sets.append("observacao=?")
            valores.append(observacao.strip()[:500])
        if data_fim is not None:
            try:
                date.fromisoformat(data_fim)
            except ValueError:
                raise ErroDeCadastro("Data inválida. Use o formato aaaa-mm-dd.") from None
            sets.append("data_fim=?")
            valores.append(data_fim)
        if not sets:
            return False
        sets.append("atualizado_em=?")
        valores += [_agora(), publicacao_id]
        with self._transacao() as con:
            return con.execute(f"UPDATE prazos SET {', '.join(sets)} WHERE publicacao_id=?",
                               valores).rowcount > 0

    # ── execuções ────────────────────────────────────────────────────────────

    def iniciar_execucao(self) -> int:
        with self._transacao() as con:
            return int(con.execute("INSERT INTO execucoes (iniciada_em) VALUES (?)",
                                   (_agora(),)).lastrowid)

    def concluir_execucao(self, id_: int, *, buscados: int, novos: int, erros: list[str]) -> None:
        with self._transacao() as con:
            con.execute("UPDATE execucoes SET terminada_em=?, buscados=?, novos=?, erros=? WHERE id=?",
                        (_agora(), buscados, novos, json.dumps(erros, ensure_ascii=False), id_))

    def ultima_execucao(self) -> dict | None:
        with self._conexao() as con:
            linha = con.execute("SELECT * FROM execucoes ORDER BY id DESC LIMIT 1").fetchone()
        if not linha:
            return None
        dado = dict(linha)
        dado["erros"] = json.loads(dado["erros"] or "[]")
        return dado

    # ── painel ───────────────────────────────────────────────────────────────

    def resumo(self, hoje: date | None = None) -> dict:
        """Tudo o que a tela inicial mostra, numa consulta só."""
        hoje = hoje or date.today()
        with self._conexao() as con:
            nao_lidas = con.execute("SELECT COUNT(*) FROM publicacoes WHERE lida=0").fetchone()[0]
            total = con.execute("SELECT COUNT(*) FROM publicacoes").fetchone()[0]
            prazos = [dict(r) for r in con.execute(
                _SQL_PRAZO + " WHERE z.status IN ('a_confirmar','confirmado') AND z.data_fim IS NOT NULL "
                "ORDER BY z.data_fim ASC LIMIT 100")]
            audiencias = [_publicacao(r, resumo=True) for r in con.execute(
                _SQL_PUBLICACAO + " WHERE p.audiencia_em >= ? ORDER BY p.audiencia_em ASC LIMIT 30",
                (hoje.isoformat(),))]
            recentes = [_publicacao(r, resumo=True) for r in con.execute(
                _SQL_PUBLICACAO + " ORDER BY p.data_disponibilizacao DESC, p.id DESC LIMIT 15")]
            por_uf = {r["uf"]: r["n"] for r in con.execute(
                "SELECT uf, COUNT(DISTINCT numero_processo) n FROM publicacoes WHERE uf<>'' "
                "GROUP BY uf ORDER BY n DESC")}
            por_segmento = _contar_segmentos(con)
            por_advogado = [dict(r) for r in con.execute(
                "SELECT m.oab_uf, m.oab_numero, m.rotulo, COUNT(DISTINCT p.numero_processo) processos, "
                "COUNT(p.id) publicacoes FROM monitorados m "
                "LEFT JOIN vinculos v ON v.monitorado_id=m.id "
                "LEFT JOIN publicacoes p ON p.id=v.publicacao_id "
                "WHERE m.tipo='oab' GROUP BY m.id ORDER BY publicacoes DESC")]
            parados = self._parados(con, hoje)

        vencidos = [p for p in prazos if p["data_fim"] < hoje.isoformat()]
        proximos = [p for p in prazos if p["data_fim"] >= hoje.isoformat()]
        limite_critico = (hoje + timedelta(days=5)).isoformat()
        criticos = [p for p in prazos if p["data_fim"] <= limite_critico]
        return {
            "hoje": hoje.isoformat(),
            "nao_lidas": nao_lidas,
            "total_publicacoes": total,
            "prazos_vencidos": vencidos,
            "prazos_proximos": proximos[:20],
            "alertas_criticos": criticos[:20],
            "audiencias": audiencias,
            "recentes": recentes,
            "por_uf": por_uf,
            "por_segmento": por_segmento,
            "por_advogado": por_advogado,
            "parados": parados,
            "ultima_execucao": self.ultima_execucao(),
            "aviso_prazo": "[CONFIRMAR] Datas de prazo são estimativas. Confirme no processo.",
        }

    def _parados(self, con: sqlite3.Connection, hoje: date) -> list[dict]:
        """Processos acompanhados sem publicação há `DIAS_PARADO` dias (ou nunca)."""
        limite = (hoje - timedelta(days=DIAS_PARADO)).isoformat()
        saida = []
        for m in con.execute("SELECT * FROM monitorados WHERE tipo='processo' AND ativo=1"):
            ultima = con.execute(
                "SELECT MAX(data_disponibilizacao) FROM publicacoes WHERE numero_processo=?",
                (m["numero_processo"],)).fetchone()[0]
            if ultima is None or ultima < limite:
                saida.append({"numero_processo": m["numero_processo"], "rotulo": m["rotulo"],
                              "ultima_publicacao": ultima})
        return saida


_SQL_PUBLICACAO = (
    "SELECT p.*, z.id AS prazo_id, z.dias AS prazo_dias, z.uteis AS prazo_uteis, "
    "z.trecho AS prazo_trecho, z.data_fim AS prazo_fim, z.status AS prazo_status, "
    "z.observacao AS prazo_obs FROM publicacoes p LEFT JOIN prazos z ON z.publicacao_id=p.id")

_SQL_PRAZO = (
    "SELECT z.publicacao_id, z.dias, z.uteis, z.trecho, z.data_fim, z.status, z.observacao, "
    "p.numero_processo, p.tribunal, p.orgao, p.tipo_comunicacao, p.categoria, "
    "p.data_disponibilizacao, p.link FROM prazos z JOIN publicacoes p ON p.id=z.publicacao_id")


def _contar_segmentos(con: sqlite3.Connection) -> dict:
    contagem = {}
    for nome, codigos in SEGMENTOS.items():
        marcas = ",".join("?" * len(codigos))
        contagem[nome] = con.execute(
            f"SELECT COUNT(DISTINCT numero_processo) FROM publicacoes WHERE segmento IN ({marcas})",
            list(codigos)).fetchone()[0]
    return contagem


def _publicacao(linha: sqlite3.Row, resumo: bool = False) -> dict:
    dado = dict(linha)
    dado["partes"] = json.loads(dado.get("partes") or "[]")
    dado["advogados"] = json.loads(dado.get("advogados") or "[]")
    dado["lida"] = bool(dado["lida"])
    dado["prazo"] = None
    if dado.get("prazo_id") is not None:
        dado["prazo"] = {
            "dias": dado["prazo_dias"], "uteis": bool(dado["prazo_uteis"]),
            "trecho": dado["prazo_trecho"], "data_fim": dado["prazo_fim"],
            "status": dado["prazo_status"], "observacao": dado["prazo_obs"],
        }
    for chave in [k for k in dado if k.startswith("prazo_")]:
        del dado[chave]
    if resumo:                                  # a lista não precisa carregar o teor inteiro
        dado["texto"] = dado["texto"][:400]
    return dado
