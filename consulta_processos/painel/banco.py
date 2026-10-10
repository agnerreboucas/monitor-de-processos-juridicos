"""Banco do painel em SQLite: administradores, sessões, clientes, chaves, uso e auditoria.

Toda consulta usa parâmetro vinculado (`?`), nunca texto montado. Uma conexão por
operação: SQLite em modo WAL aguenta bem a API e o painel ao mesmo tempo.

O arquivo fica em `dados/api.db` (ou `API_BANCO`), com permissão 600.
"""
from __future__ import annotations

import os
import sqlite3
import time
from collections.abc import Iterator
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from pathlib import Path
from zoneinfo import ZoneInfo

from . import seguranca

FUSO = ZoneInfo("America/Sao_Paulo")

ESQUEMA = """
CREATE TABLE IF NOT EXISTS admins (
    id INTEGER PRIMARY KEY,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    nome TEXT NOT NULL DEFAULT '',
    senha_hash TEXT NOT NULL,
    ativo INTEGER NOT NULL DEFAULT 1,
    criado_em TEXT NOT NULL,
    ultimo_login TEXT
);
CREATE TABLE IF NOT EXISTS sessoes (
    token_hash TEXT PRIMARY KEY,
    admin_id INTEGER NOT NULL REFERENCES admins(id) ON DELETE CASCADE,
    csrf TEXT NOT NULL,
    criada_em REAL NOT NULL,
    ultimo_uso REAL NOT NULL,
    expira_em REAL NOT NULL,
    ip TEXT NOT NULL DEFAULT ''
);
CREATE TABLE IF NOT EXISTS clientes (
    id INTEGER PRIMARY KEY,
    nome TEXT NOT NULL,
    email TEXT NOT NULL,
    empresa TEXT NOT NULL DEFAULT '',
    plano TEXT NOT NULL DEFAULT '',
    valor_mensal_centavos INTEGER NOT NULL DEFAULT 0,
    limite_mensal INTEGER NOT NULL DEFAULT 1000,
    limite_por_minuto INTEGER NOT NULL DEFAULT 60,
    ativo INTEGER NOT NULL DEFAULT 1,
    observacoes TEXT NOT NULL DEFAULT '',
    criado_em TEXT NOT NULL,
    atualizado_em TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS chaves (
    id INTEGER PRIMARY KEY,
    cliente_id INTEGER NOT NULL REFERENCES clientes(id) ON DELETE CASCADE,
    nome TEXT NOT NULL DEFAULT '',
    prefixo TEXT NOT NULL,
    hash TEXT NOT NULL UNIQUE,
    criada_em TEXT NOT NULL,
    revogada_em TEXT,
    ultimo_uso TEXT
);
CREATE TABLE IF NOT EXISTS uso (
    id INTEGER PRIMARY KEY,
    cliente_id INTEGER REFERENCES clientes(id) ON DELETE CASCADE,
    chave_id INTEGER,
    momento TEXT NOT NULL,
    metodo TEXT NOT NULL DEFAULT 'GET',
    rota TEXT NOT NULL DEFAULT '',
    caminho TEXT NOT NULL DEFAULT '',
    status INTEGER,
    ms INTEGER,
    ip TEXT NOT NULL DEFAULT '',
    cobravel INTEGER NOT NULL DEFAULT 0,
    cobrado INTEGER NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS uso_cliente_momento ON uso(cliente_id, momento);
CREATE INDEX IF NOT EXISTS uso_momento ON uso(momento);
CREATE TABLE IF NOT EXISTS eventos (
    id INTEGER PRIMARY KEY,
    momento TEXT NOT NULL,
    admin_id INTEGER,
    admin_email TEXT NOT NULL DEFAULT '',
    acao TEXT NOT NULL,
    alvo TEXT NOT NULL DEFAULT '',
    detalhe TEXT NOT NULL DEFAULT '',
    ip TEXT NOT NULL DEFAULT ''
);
CREATE INDEX IF NOT EXISTS eventos_momento ON eventos(momento);
"""

# Sessão: expira após 30 min parada e, de todo jeito, 12 h depois do login
SESSAO_OCIOSA = 30 * 60
SESSAO_MAXIMA = 12 * 60 * 60

CAMPOS_CLIENTE = ("nome", "email", "empresa", "plano", "valor_mensal_centavos", "limite_mensal",
                  "limite_por_minuto", "ativo", "observacoes")


def agora_iso() -> str:
    return datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def _iso(momento: datetime) -> str:
    return momento.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def inicio_do_mes(referencia: datetime | None = None) -> datetime:
    """Primeiro instante do mês no horário de Brasília: é quando a cota zera."""
    local = (referencia or datetime.now(timezone.utc)).astimezone(FUSO)
    return local.replace(day=1, hour=0, minute=0, second=0, microsecond=0)


def inicio_do_proximo_mes(referencia: datetime | None = None) -> datetime:
    inicio = inicio_do_mes(referencia)
    return (inicio + timedelta(days=32)).replace(day=1)


@dataclass
class ChaveValida:
    chave_id: int
    cliente_id: int
    cliente: str
    ativo: bool
    plano: str
    limite_mensal: int
    limite_por_minuto: int


class Banco:
    def __init__(self, caminho: str | os.PathLike):
        self.caminho = Path(caminho)
        self.caminho.parent.mkdir(parents=True, exist_ok=True)
        novo = not self.caminho.exists()
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
        """BEGIN IMMEDIATE: trava a escrita já no início, sem corrida entre leitura e gravação."""
        with self._conexao() as con:
            con.execute("BEGIN IMMEDIATE")
            try:
                yield con
                con.execute("COMMIT")
            except BaseException:
                con.execute("ROLLBACK")
                raise

    # ── administradores ───────────────────────────────────────────────────────

    def criar_admin(self, email: str, senha: str, nome: str = "") -> int:
        problema = seguranca.problema_na_senha(senha)
        if problema:
            raise ValueError(problema)
        with self._transacao() as con:
            cur = con.execute(
                "INSERT INTO admins (email, nome, senha_hash, criado_em) VALUES (?, ?, ?, ?)",
                (email.strip().lower(), nome.strip(), seguranca.hash_senha(senha), agora_iso()))
            return int(cur.lastrowid)

    def trocar_senha_admin(self, email: str, senha: str) -> bool:
        problema = seguranca.problema_na_senha(senha)
        if problema:
            raise ValueError(problema)
        with self._transacao() as con:
            cur = con.execute("UPDATE admins SET senha_hash = ? WHERE email = ?",
                              (seguranca.hash_senha(senha), email.strip().lower()))
            ok = cur.rowcount > 0
            if ok:      # senha nova derruba as sessões abertas
                con.execute("DELETE FROM sessoes WHERE admin_id = (SELECT id FROM admins WHERE email = ?)",
                            (email.strip().lower(),))
            return ok

    def autenticar_admin(self, email: str, senha: str) -> dict | None:
        with self._conexao() as con:
            linha = con.execute("SELECT * FROM admins WHERE email = ? AND ativo = 1",
                                (email.strip().lower(),)).fetchone()
        if not seguranca.conferir_senha(senha, linha["senha_hash"] if linha else None):
            return None
        with self._conexao() as con:
            con.execute("UPDATE admins SET ultimo_login = ? WHERE id = ?", (agora_iso(), linha["id"]))
        return {"id": linha["id"], "email": linha["email"], "nome": linha["nome"]}

    def contar_admins(self) -> int:
        with self._conexao() as con:
            return con.execute("SELECT COUNT(*) FROM admins").fetchone()[0]

    # ── sessões ───────────────────────────────────────────────────────────────

    def criar_sessao(self, admin_id: int, ip: str) -> tuple[str, str]:
        valor, csrf = seguranca.token(), seguranca.token()
        agora = time.time()
        with self._transacao() as con:
            con.execute("DELETE FROM sessoes WHERE expira_em < ? OR ultimo_uso < ?",
                        (agora, agora - SESSAO_OCIOSA))
            con.execute("INSERT INTO sessoes (token_hash, admin_id, csrf, criada_em, ultimo_uso, "
                        "expira_em, ip) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        (seguranca.hash_token(valor), admin_id, csrf, agora, agora,
                         agora + SESSAO_MAXIMA, ip))
        return valor, csrf

    def sessao(self, valor: str) -> dict | None:
        """Sessão válida (e renovada) ou None."""
        if not valor:
            return None
        agora = time.time()
        th = seguranca.hash_token(valor)
        with self._transacao() as con:
            linha = con.execute(
                "SELECT s.*, a.email, a.nome FROM sessoes s JOIN admins a ON a.id = s.admin_id "
                "WHERE s.token_hash = ? AND a.ativo = 1", (th,)).fetchone()
            if linha is None:
                return None
            if linha["expira_em"] < agora or linha["ultimo_uso"] < agora - SESSAO_OCIOSA:
                con.execute("DELETE FROM sessoes WHERE token_hash = ?", (th,))
                return None
            con.execute("UPDATE sessoes SET ultimo_uso = ? WHERE token_hash = ?", (agora, th))
        return {"admin_id": linha["admin_id"], "email": linha["email"], "nome": linha["nome"],
                "csrf": linha["csrf"]}

    def encerrar_sessao(self, valor: str) -> None:
        with self._conexao() as con:
            con.execute("DELETE FROM sessoes WHERE token_hash = ?", (seguranca.hash_token(valor),))

    # ── clientes ──────────────────────────────────────────────────────────────

    def _uso_do_mes_sql(self) -> str:
        return ("(SELECT COUNT(*) FROM uso u WHERE u.cliente_id = c.id AND u.cobrado = 1 "
                "AND u.momento >= :inicio)")

    def listar_clientes(self) -> list[dict]:
        inicio = _iso(inicio_do_mes())
        with self._conexao() as con:
            linhas = con.execute(
                f"SELECT c.*, {self._uso_do_mes_sql()} AS usado_mes, "
                "(SELECT COUNT(*) FROM chaves k WHERE k.cliente_id = c.id AND k.revogada_em IS NULL) "
                "AS chaves_ativas, "
                "(SELECT MAX(momento) FROM uso u WHERE u.cliente_id = c.id) AS ultimo_uso "
                "FROM clientes c ORDER BY c.ativo DESC, c.nome COLLATE NOCASE",
                {"inicio": inicio}).fetchall()
        return [dict(linha) for linha in linhas]

    def cliente(self, cliente_id: int) -> dict | None:
        inicio = _iso(inicio_do_mes())
        with self._conexao() as con:
            linha = con.execute(
                f"SELECT c.*, {self._uso_do_mes_sql()} AS usado_mes FROM clientes c WHERE c.id = :id",
                {"inicio": inicio, "id": cliente_id}).fetchone()
        return dict(linha) if linha else None

    def criar_cliente(self, dados: dict) -> int:
        valores = {k: dados[k] for k in CAMPOS_CLIENTE if k in dados}
        agora = agora_iso()
        valores.update(criado_em=agora, atualizado_em=agora)
        colunas = ", ".join(valores)
        marcas = ", ".join(f":{k}" for k in valores)
        with self._transacao() as con:
            cur = con.execute(f"INSERT INTO clientes ({colunas}) VALUES ({marcas})", valores)
            return int(cur.lastrowid)

    def atualizar_cliente(self, cliente_id: int, dados: dict) -> bool:
        valores = {k: dados[k] for k in CAMPOS_CLIENTE if k in dados}
        if not valores:
            return self.cliente(cliente_id) is not None
        valores["atualizado_em"] = agora_iso()
        # nomes de coluna vêm da lista fixa CAMPOS_CLIENTE, nunca da requisição
        atribuicoes = ", ".join(f"{k} = :{k}" for k in valores)
        with self._transacao() as con:
            cur = con.execute(f"UPDATE clientes SET {atribuicoes} WHERE id = :id",
                              {**valores, "id": cliente_id})
            return cur.rowcount > 0

    # ── chaves ────────────────────────────────────────────────────────────────

    def criar_chave(self, cliente_id: int, nome: str = "") -> tuple[str, dict]:
        """Gera a chave. O texto dela volta só aqui: no banco fica o hash."""
        texto = seguranca.nova_chave_api()
        with self._transacao() as con:
            cur = con.execute(
                "INSERT INTO chaves (cliente_id, nome, prefixo, hash, criada_em) VALUES (?, ?, ?, ?, ?)",
                (cliente_id, nome.strip(), seguranca.prefixo_visivel(texto),
                 seguranca.hash_token(texto), agora_iso()))
            linha = con.execute("SELECT id, cliente_id, nome, prefixo, criada_em, revogada_em, ultimo_uso "
                                "FROM chaves WHERE id = ?", (cur.lastrowid,)).fetchone()
        return texto, dict(linha)

    def listar_chaves(self, cliente_id: int) -> list[dict]:
        with self._conexao() as con:
            linhas = con.execute(
                "SELECT id, cliente_id, nome, prefixo, criada_em, revogada_em, ultimo_uso FROM chaves "
                "WHERE cliente_id = ? ORDER BY revogada_em IS NOT NULL, criada_em DESC",
                (cliente_id,)).fetchall()
        return [dict(linha) for linha in linhas]

    def revogar_chave(self, cliente_id: int, chave_id: int) -> bool:
        with self._transacao() as con:
            cur = con.execute("UPDATE chaves SET revogada_em = ? WHERE id = ? AND cliente_id = ? "
                              "AND revogada_em IS NULL", (agora_iso(), chave_id, cliente_id))
            return cur.rowcount > 0

    def chave_valida(self, texto: str) -> ChaveValida | None:
        with self._conexao() as con:
            linha = con.execute(
                "SELECT k.id AS chave_id, c.id AS cliente_id, c.nome, c.ativo, c.plano, c.limite_mensal, "
                "c.limite_por_minuto FROM chaves k JOIN clientes c ON c.id = k.cliente_id "
                "WHERE k.hash = ? AND k.revogada_em IS NULL", (seguranca.hash_token(texto),)).fetchone()
        if linha is None:
            return None
        return ChaveValida(chave_id=linha["chave_id"], cliente_id=linha["cliente_id"],
                           cliente=linha["nome"], ativo=bool(linha["ativo"]), plano=linha["plano"],
                           limite_mensal=linha["limite_mensal"],
                           limite_por_minuto=linha["limite_por_minuto"])

    # ── uso e cota ────────────────────────────────────────────────────────────

    def reservar(self, chave: ChaveValida, *, metodo: str, caminho: str, ip: str,
                 cobravel: bool) -> tuple[bool, int | None, int]:
        """Registra a chamada e, se for cobrável, reserva uma unidade da cota.

        Contagem e reserva na mesma transação: duas chamadas simultâneas não
        passam juntas da última unidade. Devolve (permitido, id do uso, usado no mês).
        """
        inicio = _iso(inicio_do_mes())
        agora = agora_iso()
        with self._transacao() as con:
            usado = con.execute("SELECT COUNT(*) FROM uso WHERE cliente_id = ? AND cobrado = 1 "
                                "AND momento >= ?", (chave.cliente_id, inicio)).fetchone()[0]
            if cobravel and usado >= chave.limite_mensal:
                con.execute("INSERT INTO uso (cliente_id, chave_id, momento, metodo, caminho, status, ms, ip,"
                            " cobravel, cobrado) VALUES (?, ?, ?, ?, ?, 402, 0, ?, 1, 0)",
                            (chave.cliente_id, chave.chave_id, agora, metodo, caminho[:200], ip))
                return False, None, usado
            cur = con.execute(
                "INSERT INTO uso (cliente_id, chave_id, momento, metodo, caminho, ip, cobravel, cobrado) "
                "VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                (chave.cliente_id, chave.chave_id, agora, metodo, caminho[:200], ip,
                 int(cobravel), int(cobravel)))
            con.execute("UPDATE chaves SET ultimo_uso = ? WHERE id = ?", (agora, chave.chave_id))
            return True, int(cur.lastrowid), usado + (1 if cobravel else 0)

    def finalizar(self, uso_id: int, *, status: int, ms: int, rota: str) -> None:
        """Fecha o registro. Só chamada bem-sucedida continua cobrada."""
        with self._conexao() as con:
            con.execute("UPDATE uso SET status = ?, ms = ?, rota = ?, "
                        "cobrado = CASE WHEN cobravel = 1 AND ? < 400 THEN 1 ELSE 0 END WHERE id = ?",
                        (status, ms, rota, status, uso_id))

    def usado_no_mes(self, cliente_id: int) -> int:
        with self._conexao() as con:
            return con.execute("SELECT COUNT(*) FROM uso WHERE cliente_id = ? AND cobrado = 1 "
                               "AND momento >= ?", (cliente_id, _iso(inicio_do_mes()))).fetchone()[0]

    def serie_diaria(self, cliente_id: int | None = None, dias: int = 30) -> list[dict]:
        """Chamadas por dia (horário de Brasília), com os dias sem uso preenchidos com zero."""
        hoje = datetime.now(timezone.utc).astimezone(FUSO).date()
        primeiro = hoje - timedelta(days=dias - 1)
        desde = _iso(datetime(primeiro.year, primeiro.month, primeiro.day, tzinfo=FUSO))
        filtro, params = ("AND cliente_id = ?", [desde, cliente_id]) if cliente_id else ("", [desde])
        with self._conexao() as con:
            linhas = con.execute(
                "SELECT date(momento, '-3 hours') AS dia, COUNT(*) AS chamadas, SUM(cobrado) AS cobradas, "
                "SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END) AS erros "
                f"FROM uso WHERE momento >= ? {filtro} GROUP BY dia", params).fetchall()
        por_dia = {linha["dia"]: linha for linha in linhas}
        serie = []
        for i in range(dias):
            dia = (primeiro + timedelta(days=i)).isoformat()
            linha = por_dia.get(dia)
            serie.append({"dia": dia, "chamadas": linha["chamadas"] if linha else 0,
                          "cobradas": (linha["cobradas"] or 0) if linha else 0,
                          "erros": (linha["erros"] or 0) if linha else 0})
        return serie

    def uso_recente(self, cliente_id: int | None = None, limite: int = 100) -> list[dict]:
        limite = max(1, min(limite, 500))
        sql = ("SELECT u.id, u.momento, u.metodo, u.rota, u.caminho, u.status, u.ms, u.ip, u.cobrado, "
               "u.cliente_id, c.nome AS cliente, k.prefixo AS chave FROM uso u "
               "LEFT JOIN clientes c ON c.id = u.cliente_id LEFT JOIN chaves k ON k.id = u.chave_id ")
        with self._conexao() as con:
            if cliente_id:
                linhas = con.execute(sql + "WHERE u.cliente_id = ? ORDER BY u.id DESC LIMIT ?",
                                     (cliente_id, limite)).fetchall()
            else:
                linhas = con.execute(sql + "ORDER BY u.id DESC LIMIT ?", (limite,)).fetchall()
        return [dict(linha) for linha in linhas]

    def resumo(self) -> dict:
        inicio = _iso(inicio_do_mes())
        with self._conexao() as con:
            clientes = con.execute(
                "SELECT COUNT(*) AS total, COALESCE(SUM(ativo), 0) AS ativos, "
                "COALESCE(SUM(CASE WHEN ativo = 1 THEN valor_mensal_centavos ELSE 0 END), 0) AS mrr "
                "FROM clientes").fetchone()
            mes = con.execute(
                "SELECT COUNT(*) AS chamadas, COALESCE(SUM(cobrado), 0) AS cobradas, "
                "COALESCE(SUM(CASE WHEN status >= 500 THEN 1 ELSE 0 END), 0) AS erros, "
                "CAST(COALESCE(AVG(CASE WHEN status < 400 THEN ms END), 0) AS INTEGER) AS ms_medio "
                "FROM uso WHERE momento >= ?", (inicio,)).fetchone()
            top = con.execute(
                "SELECT c.id, c.nome, c.empresa, c.limite_mensal, COUNT(u.id) AS usado FROM clientes c "
                "JOIN uso u ON u.cliente_id = c.id AND u.cobrado = 1 AND u.momento >= ? "
                "GROUP BY c.id ORDER BY usado DESC LIMIT 5", (inicio,)).fetchall()
        return {
            "clientes_total": clientes["total"], "clientes_ativos": clientes["ativos"],
            "mrr_centavos": clientes["mrr"],
            "mes": dict(mes), "top_clientes": [dict(t) for t in top],
            "serie": self.serie_diaria(None, 30),
            "mes_inicio": _iso(inicio_do_mes()), "mes_fim": _iso(inicio_do_proximo_mes()),
        }

    # ── auditoria ─────────────────────────────────────────────────────────────

    def evento(self, acao: str, *, admin: dict | None = None, alvo: str = "", detalhe: str = "",
               ip: str = "") -> None:
        with self._conexao() as con:
            con.execute("INSERT INTO eventos (momento, admin_id, admin_email, acao, alvo, detalhe, ip) "
                        "VALUES (?, ?, ?, ?, ?, ?, ?)",
                        (agora_iso(), (admin or {}).get("admin_id") or (admin or {}).get("id"),
                         (admin or {}).get("email", ""), acao, alvo[:200], detalhe[:500], ip))

    def eventos(self, limite: int = 200) -> list[dict]:
        with self._conexao() as con:
            linhas = con.execute("SELECT * FROM eventos ORDER BY id DESC LIMIT ?",
                                 (max(1, min(limite, 1000)),)).fetchall()
        return [dict(linha) for linha in linhas]
