"""Linha de comando.

    consulta-processos processo 1000254-20.2025.8.13.0079
    consulta-processos processo 1000254-20.2025.8.13.0079 --json > processo.json
    consulta-processos oab 123456 MG --tribunal TJMG
    consulta-processos publicacoes 123456 MG --dias 7
    consulta-processos api --porta 8000
    consulta-processos monitor painel
"""
from __future__ import annotations

import argparse
import json
import logging
import sys
from datetime import date, timedelta

from . import __version__
from .api import buscar_por_oab, consultar_processo, publicacoes_por_oab
from .erros import ConsultaError
from .modelos import Processo


def _saida_em_utf8() -> None:
    """Evita quebrar no console do Windows, que por padrão não é UTF-8.

    Nomes de parte, "×" e acentos derrubariam o comando com UnicodeEncodeError
    num `cmd` comum. Caracteres que o console não souber desenhar viram "?".
    """
    for fluxo in (sys.stdout, sys.stderr):
        try:
            fluxo.reconfigure(encoding="utf-8", errors="replace")
        except (AttributeError, ValueError):       # saída redirecionada para outro lugar
            pass


def _imprimir_processo(processo: Processo, movimentos: int) -> None:
    print(f"\n{processo.numero}  ·  {processo.tribunal}  ·  {processo.segmento}")
    print(f"{processo.titulo}")
    if processo.classe:
        print(f"Classe: {processo.classe}")
    if processo.assunto:
        print(f"Assunto: {processo.assunto}")
    if processo.orgao_julgador:
        print(f"Órgão: {processo.orgao_julgador}")
    if processo.valor_causa:
        # Formato brasileiro: 1.234.567,89
        valor = f"{processo.valor_causa:,.2f}".replace(",", "@").replace(".", ",").replace("@", ".")
        print(f"Valor da causa: R$ {valor}")
    if processo.advogados:
        print("Advogados: " + "; ".join(str(a) for a in processo.advogados[:8]))
    print(f"Fontes: {', '.join(f.value for f in processo.fontes)}")

    if processo.movimentacoes:
        print(f"\nMovimentações ({len(processo.movimentacoes)}), as {movimentos} mais recentes:")
        for mov in processo.movimentacoes[:movimentos]:
            print(f"  {mov.data:%d/%m/%Y}  {mov.tipo.value:<15} {mov.descricao[:90]}")
    if processo.publicacoes:
        print(f"\nPublicações no diário: {len(processo.publicacoes)}")


def _cmd_processo(args) -> int:
    processo = consultar_processo(args.numero, com_publicacoes=not args.sem_publicacoes)
    if processo is None:
        print(f"Processo não encontrado nas fontes públicas: {args.numero}", file=sys.stderr)
        return 1
    if args.json:
        print(json.dumps(processo.to_dict(), ensure_ascii=False, indent=2))
    else:
        _imprimir_processo(processo, args.movimentos)
    return 0


def _cmd_oab(args) -> int:
    processos = buscar_por_oab(args.numero, args.uf, tribunal=args.tribunal, dias=args.dias)
    if args.json:
        print(json.dumps([p.to_dict() for p in processos], ensure_ascii=False, indent=2))
        return 0
    print(f"{len(processos)} processo(s) para a OAB {args.uf.upper()} {args.numero}")
    for processo in processos:
        print(f"  {processo.numero}  {processo.tribunal:<7} {processo.titulo[:80]}")
    return 0


def _cmd_publicacoes(args) -> int:
    fim = date.today()
    inicio = fim - timedelta(days=args.dias)
    publicacoes = publicacoes_por_oab(args.numero, args.uf, inicio=inicio, fim=fim,
                                      paginas=args.paginas)
    if args.json:
        print(json.dumps([p.to_dict() for p in publicacoes], ensure_ascii=False, indent=2))
        return 0
    print(f"{len(publicacoes)} publicação(ões) para a OAB {args.uf.upper()} {args.numero} "
          f"entre {inicio:%d/%m/%Y} e {fim:%d/%m/%Y}")
    for pub in publicacoes:
        quando = pub.data_disponibilizacao.strftime("%d/%m/%Y") if pub.data_disponibilizacao else "—"
        print(f"  {quando}  {pub.numero_processo:<27} {pub.tipo_comunicacao[:40]:<40} {pub.orgao[:40]}")
    return 0


def _cmd_web(args) -> int:
    from .web import servir
    return servir(args.porta, abrir=not args.sem_abrir)


def _cmd_api(args) -> int:
    try:
        from .rest import servir
    except ImportError:
        print('A API precisa do FastAPI: pip install "consulta-processos[api]"', file=sys.stderr)
        return 2
    return servir(args.host, args.porta)


def _cmd_nova_chave(args) -> int:
    import secrets
    chave = secrets.token_urlsafe(32)
    print(chave)
    print(f"\nNo .env (permissão 600): API_CHAVES={args.cliente}:{chave}\n"
          "Entregue a chave ao cliente por canal seguro e guarde no cofre.", file=sys.stderr)
    return 0


def _cmd_admin(args) -> int:
    """Cria administrador do painel ou troca a senha. A senha é digitada, nunca vai na linha de comando."""
    import getpass

    from .painel.banco import Banco
    from .rest import ConfigAPI

    caminho = ConfigAPI.do_ambiente().banco or "dados/api.db"
    banco = Banco(caminho)
    print("Senha: 12+ caracteres, com 3 tipos entre minúsculas, maiúsculas, números e símbolos.")
    senha = getpass.getpass("Senha: ")
    if senha != getpass.getpass("Repita a senha: "):
        print("As senhas não conferem.", file=sys.stderr)
        return 1
    try:
        if args.acao == "criar":
            banco.criar_admin(args.email, senha, nome=args.nome)
            banco.evento("admin_criado", alvo=args.email.lower(), detalhe="pela linha de comando")
            print(f"Administrador {args.email} criado em {caminho}. Entre em /admin.")
        else:
            if not banco.trocar_senha_admin(args.email, senha):
                print(f"Administrador não encontrado: {args.email}", file=sys.stderr)
                return 1
            banco.evento("admin_senha_trocada", alvo=args.email.lower(), detalhe="pela linha de comando")
            print("Senha trocada. As sessões abertas foram encerradas.")
    except ValueError as e:
        print(str(e), file=sys.stderr)
        return 1
    except Exception as e:             # e-mail repetido cai aqui (UNIQUE)
        if "UNIQUE" in str(e):
            print(f"Já existe administrador com o e-mail {args.email}.", file=sys.stderr)
            return 1
        raise
    return 0


def _banco_do_monitor():
    from .monitor import Banco
    return Banco()


def _cmd_monitor(args) -> int:
    from .monitor import ErroDeCadastro, executar
    from .monitor.painel import servir as servir_painel
    banco = _banco_do_monitor()
    try:
        if args.acao == "adicionar-oab":
            banco.adicionar_oab(args.numero, args.uf, args.nome)
            print(f"Acompanhando a OAB {args.uf.upper()} {args.numero}. Rode: consulta-processos monitor executar")
        elif args.acao == "adicionar-processo":
            banco.adicionar_processo(args.numero, args.nome)
            print(f"Acompanhando o processo {args.numero}. Rode: consulta-processos monitor executar")
        elif args.acao == "listar":
            itens = banco.listar_monitorados()
            if not itens:
                print("Nada acompanhado ainda.")
            for m in itens:
                alvo = f"OAB {m['oab_uf']} {m['oab_numero']}" if m["tipo"] == "oab" else m["numero_processo"]
                estado = "ativo" if m["ativo"] else "pausado"
                print(f"  {m['id']:>3}  {alvo:<28} {estado:<8} {m['rotulo']}"
                      f"{'  ERRO: ' + m['ultimo_erro'] if m['ultimo_erro'] else ''}")
        elif args.acao == "remover":
            print("Removido." if banco.remover_monitorado(args.id) else "Não encontrei esse número.")
        elif args.acao == "executar":
            r = executar(banco, pausa=args.pausa)
            print(f"{r.buscados} item(ns) consultado(s), {r.novos} publicação(ões) nova(s).")
            for erro in r.erros:
                print(f"  ! {erro}", file=sys.stderr)
            return 0 if r.ok else 3
        elif args.acao == "painel":
            return servir_painel(args.porta, abrir=not args.sem_abrir, a_cada_minutos=args.a_cada,
                                 banco=banco)
    except ErroDeCadastro as e:
        print(f"Erro: {e}", file=sys.stderr)
        return 2
    return 0


def main(argv: list[str] | None = None) -> int:
    _saida_em_utf8()
    parser = argparse.ArgumentParser(
        prog="consulta-processos",
        description="Busca e consulta de processos judiciais nas fontes públicas (Datajud e DJEN).",
    )
    parser.add_argument("--version", action="version", version=f"consulta-processos {__version__}")
    parser.add_argument("-v", "--verbose", action="store_true", help="mostra o que está acontecendo")
    # Repetido nos subcomandos para que `-v` funcione antes ou depois do comando
    comum = argparse.ArgumentParser(add_help=False)
    comum.add_argument("-v", "--verbose", action="store_true", help=argparse.SUPPRESS)
    sub = parser.add_subparsers(dest="comando", required=True)

    p = sub.add_parser("processo", parents=[comum], help="consulta um processo pelo número")
    p.add_argument("numero", help="número CNJ, com ou sem pontuação")
    p.add_argument("--json", action="store_true", help="devolve JSON")
    p.add_argument("--movimentos", type=int, default=10, help="quantas movimentações mostrar")
    p.add_argument("--sem-publicacoes", action="store_true", help="não consulta o diário")
    p.set_defaults(func=_cmd_processo)

    o = sub.add_parser("oab", parents=[comum], help="processos de um advogado (Datajud)")
    o.add_argument("numero", help="número da inscrição")
    o.add_argument("uf", help="UF da inscrição, ex.: MG")
    o.add_argument("--tribunal", default="", help="limita a um tribunal, ex.: TJMG")
    o.add_argument("--dias", type=int, default=30, help="janela de publicações (padrão: 30)")
    o.add_argument("--json", action="store_true")
    o.set_defaults(func=_cmd_oab)

    d = sub.add_parser("publicacoes", parents=[comum], help="publicações de um advogado no diário (DJEN)")
    d.add_argument("numero", help="número da inscrição")
    d.add_argument("uf", help="UF da inscrição")
    d.add_argument("--dias", type=int, default=7, help="janela em dias (padrão: 7)")
    d.add_argument("--paginas", type=int, default=1, help="páginas a buscar (0 = todas)")
    d.add_argument("--json", action="store_true")
    d.set_defaults(func=_cmd_publicacoes)

    w = sub.add_parser("web", parents=[comum], help="abre a tela de consulta no navegador")
    w.add_argument("--porta", type=int, default=8765, help="porta do servidor (padrão: 8765)")
    w.add_argument("--sem-abrir", action="store_true", help="não abre o navegador sozinho")
    w.set_defaults(func=_cmd_web)

    a = sub.add_parser("api", parents=[comum], help="sobe a API REST (FastAPI)")
    a.add_argument("--host", default="127.0.0.1", help="endereço de escuta (padrão: 127.0.0.1)")
    a.add_argument("--porta", type=int, default=8000, help="porta (padrão: 8000)")
    a.set_defaults(func=_cmd_api)

    k = sub.add_parser("nova-chave", parents=[comum], help="gera uma chave de acesso para a API")
    k.add_argument("cliente", nargs="?", default="cliente", help="nome do cliente dono da chave")
    k.set_defaults(func=_cmd_nova_chave)

    ad = sub.add_parser("admin", parents=[comum], help="administradores do painel /admin")
    ad.add_argument("acao", choices=["criar", "senha"], help="criar administrador ou trocar a senha")
    ad.add_argument("email", help="e-mail de login")
    ad.add_argument("--nome", default="", help="nome de quem vai usar")
    ad.set_defaults(func=_cmd_admin)

    mo = sub.add_parser("monitor", parents=[comum], help="acompanha OABs e processos (painel, prazos, alertas)")
    ms = mo.add_subparsers(dest="acao", required=True)
    m = ms.add_parser("adicionar-oab", help="passa a acompanhar uma OAB")
    m.add_argument("numero"); m.add_argument("uf"); m.add_argument("--nome", default="", help="nome do advogado")
    m = ms.add_parser("adicionar-processo", help="passa a acompanhar um processo")
    m.add_argument("numero"); m.add_argument("--nome", default="", help="apelido do processo")
    ms.add_parser("listar", help="mostra o que está sendo acompanhado")
    m = ms.add_parser("remover", help="para de acompanhar (veja o número em 'listar')")
    m.add_argument("id", type=int)
    m = ms.add_parser("executar", help="busca o que saiu e grava o que é novo (rotina diária)")
    m.add_argument("--pausa", type=float, default=0.0, help="segundos de pausa entre itens")
    m = ms.add_parser("painel", help="abre a tela do monitor no navegador")
    m.add_argument("--porta", type=int, default=8766)
    m.add_argument("--sem-abrir", action="store_true")
    m.add_argument("--a-cada", type=float, default=0.0, metavar="MINUTOS",
                   help="busca sozinha a cada N minutos enquanto a tela estiver aberta")
    mo.set_defaults(func=_cmd_monitor)

    args = parser.parse_args(argv)
    logging.basicConfig(level=logging.INFO if args.verbose else logging.WARNING,
                        format="%(levelname)s %(message)s")
    try:
        return args.func(args)
    except ConsultaError as e:
        print(f"Erro: {e}", file=sys.stderr)
        return 2
    except KeyboardInterrupt:
        return 130


if __name__ == "__main__":
    raise SystemExit(main())
