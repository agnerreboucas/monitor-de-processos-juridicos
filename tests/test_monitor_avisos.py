"""Aviso por e-mail do monitor. O envio é trocado por um falso: nada sai para a rede."""
import smtplib
from datetime import date

import pytest

from consulta_processos import cli
from consulta_processos.monitor import Banco, ConfigEmail, ErroDeCadastro, avisar, executar
from consulta_processos.monitor import avisos

from .monitor_amostras import PROCESSO_TJMG, PROCESSO_TJRO, pub

CFG = ConfigEmail(host="smtp.exemplo", remetente="monitor@exemplo.com.br")
HOJE = date(2026, 10, 10)


class Caixa:
    """Guarda o que seria enviado."""

    def __init__(self, falhar=0):
        self.enviados = []
        self.falhar = falhar

    def __call__(self, cfg, destinatarios, assunto, texto):
        if self.falhar:
            self.falhar -= 1
            raise smtplib.SMTPServerDisconnected("caiu")
        self.enviados.append((list(destinatarios), assunto, texto))


@pytest.fixture
def banco(tmp_path):
    b = Banco(tmp_path / "monitor.db")
    b.adicionar_oab("101332", "MG", "Advogado Um")
    b.adicionar_destinatario("dra@escritorio.com.br", "Dra. Fulana")
    executar(b, hoje=HOJE, buscar_oab=lambda *a, **k: [pub("pauta"), pub("prazo5"), pub("sentenca")],
             buscar_processo=lambda *a, **k: [])
    return b


# ── cadastro de destinatários ────────────────────────────────────────────────

def test_cadastro_de_email(tmp_path):
    b = Banco(tmp_path / "x.db")
    b.adicionar_destinatario("  Ana@Escritorio.com.br ", "Ana\nBcc: x")
    (d,) = b.listar_destinatarios()
    assert d["email"] == "Ana@Escritorio.com.br" and "\n" not in d["nome"]
    with pytest.raises(ErroDeCadastro, match="já está"):
        b.adicionar_destinatario("ana@escritorio.com.br")            # maiúscula/minúscula não distingue
    assert b.remover_destinatario(d["id"]) and b.listar_destinatarios() == []


@pytest.mark.parametrize("ruim", ["", "sem-arroba", "a@b", "a b@c.com", "a@b.com\nBcc: z@w.com",
                                  "a@b.com, c@d.com", "<a@b.com>", "a@b.com;c@d.com", "x" * 250 + "@a.com"])
def test_email_invalido_ou_com_injecao_de_cabecalho(tmp_path, ruim):
    with pytest.raises(ErroDeCadastro):
        Banco(tmp_path / "x.db").adicionar_destinatario(ruim)


# ── quando envia e quando não ────────────────────────────────────────────────

def test_sem_servidor_configurado_nao_envia_nem_marca(banco):
    caixa = Caixa()
    r = avisar(banco, ConfigEmail(), hoje=HOJE, enviar=caixa)
    assert not r.enviado and "não configurado" in r.motivo and caixa.enviados == []
    assert len(banco.publicacoes_para_avisar()) == 3                  # nada foi marcado


def test_sem_destinatario(tmp_path):
    r = avisar(Banco(tmp_path / "x.db"), CFG, hoje=HOJE, enviar=Caixa())
    assert not r.enviado and "Nenhum e-mail" in r.motivo


def test_destinatario_pausado_nao_recebe(banco):
    banco.listar_destinatarios()
    with banco._conexao() as con:
        con.execute("UPDATE destinatarios SET ativo=0")
    assert "Nenhum e-mail" in avisar(banco, CFG, hoje=HOJE, enviar=Caixa()).motivo


def test_nada_novo_nao_manda_email(tmp_path):
    caixa = Caixa()
    b = Banco(tmp_path / "x.db")
    b.adicionar_destinatario("a@b.com")
    r = avisar(b, CFG, hoje=HOJE, enviar=caixa)
    assert not r.enviado and r.motivo == "Nada novo para avisar." and caixa.enviados == []


# ── conteúdo ─────────────────────────────────────────────────────────────────

def test_resumo_das_publicacoes_novas(banco):
    caixa = Caixa()
    r = avisar(banco, CFG, hoje=HOJE, enviar=caixa)
    assert r.enviado and (r.publicacoes, r.prazos, r.audiencias) == (3, 0, 0)
    (destinos, assunto, corpo), = caixa.enviados
    assert destinos == ["dra@escritorio.com.br"]
    assert assunto == "Monitor Jurídico: 3 publicação(ões) nova(s)"
    assert PROCESSO_TJMG in corpo and PROCESSO_TJRO in corpo and "PUBLICAÇÕES NOVAS" in corpo
    assert "não substitui a conferência" in corpo


def test_assunto_nunca_leva_numero_nem_nome(banco):
    caixa = Caixa()
    avisar(banco, CFG, hoje=HOJE, enviar=caixa)
    assunto = caixa.enviados[0][1]
    assert PROCESSO_TJMG not in assunto and "SEGURADORA" not in assunto and "AUTOR" not in assunto


def test_trecho_do_teor_e_curto(banco):
    texto = avisos._limpo("palavra " * 200)
    assert len(texto) <= avisos.TRECHO and texto.endswith("…")


def test_mais_de_50_publicacoes_viram_resumo():
    itens = [{"data_disponibilizacao": "2026-10-09", "numero_processo": f"P{i}", "tribunal": "TJMG",
              "categoria": "intimacao", "orgao": "Vara", "texto": "x", "link": ""} for i in range(60)]
    _, corpo = avisos.montar_texto(itens, [], [], HOJE)
    assert corpo.count("• ") == 50 and "e mais 10 publicação(ões)" in corpo


def test_link_so_se_for_https():
    assert avisos._link("https://tribunal.exemplo/x") == ["   https://tribunal.exemplo/x"]
    assert avisos._link("javascript:alert(1)") == [] and avisos._link("http://x") == [] and avisos._link("") == []


# ── não repete, e não perde ──────────────────────────────────────────────────

def test_nao_avisa_duas_vezes_a_mesma_publicacao(banco):
    caixa = Caixa()
    avisar(banco, CFG, hoje=HOJE, enviar=caixa)
    r = avisar(banco, CFG, hoje=HOJE, enviar=caixa)
    assert not r.enviado and len(caixa.enviados) == 1


def test_falha_no_envio_nao_marca_nada_e_a_proxima_rodada_manda(banco):
    caixa = Caixa(falhar=1)
    r = avisar(banco, CFG, hoje=HOJE, enviar=caixa)
    assert not r.enviado and "Falha ao enviar" in r.motivo and "SMTPServerDisconnected" in r.motivo
    assert len(banco.publicacoes_para_avisar()) == 3
    r = avisar(banco, CFG, hoje=HOJE, enviar=caixa)
    assert r.enviado and r.publicacoes == 3 and len(caixa.enviados) == 1


def test_erro_inesperado_nao_e_engolido(banco):
    def bug(*a):
        raise ValueError("bug meu")
    with pytest.raises(ValueError):
        avisar(banco, CFG, hoje=HOJE, enviar=bug)


def test_publicacao_nova_depois_entra_so_ela(banco):
    caixa = Caixa()
    avisar(banco, CFG, hoje=HOJE, enviar=caixa)
    executar(banco, hoje=HOJE, buscar_oab=lambda *a, **k: [pub("audiencia")], buscar_processo=lambda *a, **k: [])
    r = avisar(banco, CFG, hoje=HOJE, enviar=caixa)
    assert r.publicacoes == 1 and len(caixa.enviados) == 2


# ── prazos e audiências ──────────────────────────────────────────────────────

def test_prazo_dentro_da_janela_e_lembrado_uma_vez_por_dia(banco):
    caixa = Caixa()
    avisar(banco, CFG, hoje=HOJE, enviar=caixa)                          # publicações novas; prazo (20/10) ainda longe
    assert caixa.enviados[0][1].count("prazo") == 0
    dia = date(2026, 10, 19)                                             # falta 1 dia
    r = avisar(banco, CFG, hoje=dia, enviar=caixa)
    assert r.enviado and (r.prazos, r.publicacoes) == (1, 0)
    corpo = caixa.enviados[-1][2]
    assert "vence em 1 dia(s)" in corpo and "[CONFIRMAR]" in corpo and "20/10/2026" in corpo
    assert not avisar(banco, CFG, hoje=dia, enviar=caixa).enviado        # mesmo dia: não repete
    r = avisar(banco, CFG, hoje=date(2026, 10, 20), enviar=caixa)         # dia seguinte: lembra de novo
    assert r.enviado and "vence HOJE" in caixa.enviados[-1][2]
    r = avisar(banco, CFG, hoje=date(2026, 10, 22), enviar=caixa)         # vencido e ninguém resolveu
    assert r.enviado and "VENCIDO há 2 dia(s)" in caixa.enviados[-1][2]


def test_prazo_cumprido_para_de_ser_lembrado(banco):
    caixa = Caixa()
    avisar(banco, CFG, hoje=HOJE, enviar=caixa)
    banco.atualizar_prazo(banco.publicacoes(uf="RO")[0]["id"], status="cumprido")
    assert not avisar(banco, CFG, hoje=date(2026, 10, 19), enviar=caixa).enviado


def test_audiencia_e_avisada_na_vespera_uma_vez(banco):
    caixa = Caixa()
    avisar(banco, CFG, hoje=HOJE, enviar=caixa)
    r = avisar(banco, CFG, hoje=date(2026, 10, 21), enviar=caixa)        # sessão é 22/10 09:00
    assert r.enviado and r.audiencias == 1
    assert "AUDIÊNCIAS E SESSÕES" in caixa.enviados[-1][2] and "22/10/2026 09:00" in caixa.enviados[-1][2]
    r = avisar(banco, CFG, hoje=date(2026, 10, 22), enviar=caixa)
    assert r.audiencias == 0                      # a audiência não é avisada de novo
    assert r.prazos == 1                          # (o prazo vencido segue sendo lembrado: outro teste)


def test_audiencia_longe_nao_e_avisada(banco):
    caixa = Caixa()
    avisar(banco, CFG, hoje=HOJE, enviar=caixa)
    assert not avisar(banco, CFG, hoje=date(2026, 10, 15), enviar=caixa).enviado


# ── banco antigo ─────────────────────────────────────────────────────────────

def test_banco_da_versao_anterior_nao_dispara_aviso_do_que_ja_existia(tmp_path):
    caminho = tmp_path / "antigo.db"
    b = Banco(caminho)
    b.adicionar_oab("1", "MG")
    executar(b, hoje=HOJE, buscar_oab=lambda *a, **k: [pub("pauta")], buscar_processo=lambda *a, **k: [])
    with b._conexao() as con:
        con.execute("ALTER TABLE publicacoes DROP COLUMN avisada")     # como era na v0.2.0
    novo = Banco(caminho)                                              # abre e migra
    assert novo.publicacoes_para_avisar() == [] and len(novo.publicacoes()) == 1


# ── o envio de verdade (SMTP trocado por um falso) ───────────────────────────

class SMTPFalso:
    chamadas = []

    def __init__(self, host, porta, timeout=None, context=None):
        self.chamadas.append(("conectar", type(self).__name__, host, porta))

    def __enter__(self):
        return self

    def __exit__(self, *a):
        return False

    def starttls(self, context=None):
        self.chamadas.append(("starttls",))

    def login(self, usuario, senha):
        self.chamadas.append(("login", usuario, senha))

    def send_message(self, msg):
        self.chamadas.append(("enviar", msg["Subject"], msg["From"], msg["To"], msg.get_content()))


class SMTPSSLFalso(SMTPFalso):
    pass


def test_envio_starttls_com_login(monkeypatch):
    SMTPFalso.chamadas = []
    monkeypatch.setattr(smtplib, "SMTP", SMTPFalso)
    cfg = ConfigEmail(host="smtp.exemplo", porta=587, usuario="u", senha="s", remetente="m@e.com")
    avisos.enviar_email(cfg, ["a@b.com", "c@d.com"], "Assunto", "Corpo ç ã\n")
    c = SMTPFalso.chamadas
    assert [x[0] for x in c] == ["conectar", "starttls", "login", "enviar"]
    assert c[2] == ("login", "u", "s")
    assert c[3][1:4] == ("Assunto", "m@e.com", "a@b.com, c@d.com") and "ç ã" in c[3][4]


def test_envio_ssl_sem_starttls(monkeypatch):
    SMTPSSLFalso.chamadas = []
    monkeypatch.setattr(smtplib, "SMTP_SSL", SMTPSSLFalso)
    cfg = ConfigEmail(host="h", porta=465, remetente="m@e.com", seguranca="ssl")
    avisos.enviar_email(cfg, ["a@b.com"], "A", "B\n")
    assert [x[0] for x in SMTPSSLFalso.chamadas] == ["conectar", "enviar"]      # sem login: usuário vazio


def test_config_do_ambiente(monkeypatch):
    for nome in ("SMTP_HOST", "SMTP_PORTA", "SMTP_USUARIO", "SMTP_SENHA", "SMTP_REMETENTE", "SMTP_SEGURANCA"):
        monkeypatch.delenv(nome, raising=False)
    assert not ConfigEmail.do_ambiente().configurado
    monkeypatch.setenv("SMTP_HOST", "smtp.x.com")
    monkeypatch.setenv("SMTP_USUARIO", "robo@x.com")
    monkeypatch.setenv("SMTP_PORTA", "abc")                                     # porta ruim volta ao padrão
    cfg = ConfigEmail.do_ambiente()
    assert cfg.configurado and cfg.remetente == "robo@x.com" and cfg.porta == 587


def test_remetente_com_quebra_de_linha_nao_conta_como_configurado():
    assert not ConfigEmail(host="h", remetente="a@b.com\nBcc: x@y.com").configurado


# ── linha de comando ─────────────────────────────────────────────────────────

@pytest.fixture
def ambiente(tmp_path, monkeypatch):
    monkeypatch.setenv("MONITOR_BANCO", str(tmp_path / "cli.db"))
    for nome in ("SMTP_HOST", "SMTP_USUARIO", "SMTP_REMETENTE", "SMTP_SENHA"):
        monkeypatch.delenv(nome, raising=False)
    monkeypatch.chdir(tmp_path)                           # sem .env por perto


def test_cli_cadastra_lista_e_remove_email(ambiente, capsys):
    assert cli.main(["monitor", "email", "adicionar", "dra@escritorio.com.br", "--nome", "Dra"]) == 0
    assert cli.main(["monitor", "email", "listar"]) == 0
    saida = capsys.readouterr().out
    assert "dra@escritorio.com.br" in saida and "NÃO configurado" in saida
    assert cli.main(["monitor", "email", "remover", "1"]) == 0
    assert cli.main(["monitor", "email", "adicionar", "ruim"]) == 2


def test_cli_avisar_sem_servidor_explica(ambiente, capsys):
    cli.main(["monitor", "email", "adicionar", "dra@escritorio.com.br"])
    assert cli.main(["monitor", "avisar"]) == 0
    assert "E-mail não configurado" in capsys.readouterr().out


def test_cli_testar_sem_configurar_recusa(ambiente, capsys):
    assert cli.main(["monitor", "email", "testar"]) == 2
    assert "Configure" in capsys.readouterr().err
