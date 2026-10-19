#!/usr/bin/env python3
"""
Gera as páginas do tema em formato Elementor (JSON de seções, colunas e widgets do Elementor GRÁTIS):
  - inc/elementor/home.json  (página inicial, a partir de ferramentas/landing-fonte.html)
  - inc/elementor/demo.json  (página Demonstração)
Cada texto vira widget editável (Título, Editor de texto, Botão, Imagem, Acordeão). As tabelas e os indicadores
da demonstração são shortcodes ([radar_demo_kpis], [radar_demo_tabela]) para as datas ficarem sempre de hoje.
Uso:  python3 wordpress/ferramentas/gerar-elementor.py
"""
import json, os, re, hashlib
from bs4 import BeautifulSoup, NavigableString, Tag

AQUI = os.path.dirname(os.path.abspath(__file__))
TEMA = os.path.join(os.path.dirname(AQUI), 'radar-processual-tema')
SAIDA = os.path.join(TEMA, 'inc', 'elementor')
_n = [0]


def eid(seed=''):
    _n[0] += 1
    return hashlib.md5(f'{seed}{_n[0]}'.encode()).hexdigest()[:7]


# ── widgets ───────────────────────────────────────────────────────────────────
def widget(tipo, settings):
    return {'id': eid(tipo), 'elType': 'widget', 'widgetType': tipo, 'settings': settings, 'elements': []}


def heading(texto, tag='h2', css='', **extra):
    return widget('heading', {'title': texto, 'header_size': tag, '_css_classes': css, **extra})


def texto(html, css='', **extra):
    return widget('text-editor', {'editor': html, '_css_classes': css, **extra})


def botao(rotulo, url, css='', icone=None, inline=False, **extra):
    s = {'text': rotulo, 'link': {'url': url, 'is_external': '', 'nofollow': '', 'custom_attributes': ''}, '_css_classes': css, **extra}
    if icone:
        s['selected_icon'] = {'value': icone, 'library': 'fa-brands'}
        s['icon_align'] = 'left'
    if inline:
        s['_element_width'] = 'auto'
    return widget('button', s)


def imagem(url, alt, css=''):
    return widget('image', {'image': {'url': url, 'id': '', 'alt': alt, 'source': 'library'}, 'image_size': 'full', '_css_classes': css})


def acordeao(itens, css=''):
    return widget('accordion', {'tabs': [{'_id': eid('tab'), 'tab_title': t, 'tab_content': c} for t, c in itens], '_css_classes': css,
                                'selected_icon': {'value': 'fas fa-plus', 'library': 'fa-solid'}, 'selected_active_icon': {'value': 'fas fa-minus', 'library': 'fa-solid'}})


def shortcode(sc, css=''):
    return widget('shortcode', {'shortcode': sc, '_css_classes': css})


# ── estrutura ─────────────────────────────────────────────────────────────────
def coluna(widgets, largura=100, css=''):
    return {'id': eid('col'), 'elType': 'column', 'settings': {'_column_size': largura, '_inline_size': None, 'css_classes': css}, 'elements': widgets}


def secao(colunas, css='', sid=None, largura=1120):
    s = {'layout': 'boxed', 'content_width': {'unit': 'px', 'size': largura, 'sizes': []}, 'gap': 'no', 'css_classes': css}
    if sid:
        s['_element_id'] = sid
    return {'id': eid('sec'), 'elType': 'section', 'settings': s, 'elements': colunas}


# ── HTML → widgets ────────────────────────────────────────────────────────────
def interno(el):
    return ''.join(str(c) for c in el.children).strip()


def botao_de(a):
    cls = a.get('class', [])
    css = 'rd-btn ' + ('rd-btn-p' if 'p' in cls else 'rd-btn-s' if 's' in cls else 'rd-btn-o')
    return botao(a.get_text(' ', strip=True), a.get('href', '#'), css, inline=True)


def widgets_de(el):
    """Widgets de um bloco (coluna): título, texto, lista, botão, imagem..."""
    out = []
    for c in el.children:
        if isinstance(c, NavigableString):
            if c.strip():
                out.append(texto(f'<p>{c.strip()}</p>'))
            continue
        cls = c.get('class', [])
        if c.name == 'span' and 'n' in cls:
            out.append(heading(c.get_text(strip=True), 'p', 'rd-num'))
        elif c.name == 'span' and 'etq' in cls:
            out.append(heading(c.get_text(strip=True), 'p', 'rd-etq'))
        elif c.name in ('h2', 'h3'):
            out.append(heading(interno(c), c.name, 'rd-h3' if c.name == 'h3' else 'rd-title'))
        elif c.name == 'p' and 'rot' in cls:
            out.append(heading(c.get_text(strip=True), 'p', 'rd-rot'))
        elif c.name == 'p':
            out.append(texto(f'<p>{interno(c)}</p>', 'rd-sub' if 'sub' in cls else ''))
        elif c.name == 'ul':
            out.append(texto(str(c), 'rd-lista' + (' rd-nao' if 'nao' in cls else '')))
        elif c.name == 'a' and 'btn' in cls:
            out.append(botao_de(c))
        elif c.name == 'img':
            out.append(imagem(c.get('src'), c.get('alt', '')))
        elif c.name == 'div' and ('preco' in cls or 'chips' in cls):
            out.append(texto(str(c), 'rd-preco' if 'preco' in cls else 'rd-chips'))
        elif c.name == 'div' and ('vitrine' in cls or 'celular' in cls):
            img = c.find('img')
            out.append(imagem(img.get('src'), img.get('alt', ''), 'rd-janela' if 'vitrine' in cls else 'rd-celular'))
        elif c.name == 'div':
            out.extend(widgets_de(c))
        elif c.name == 'small':
            out.append(texto(f'<p>{interno(c)}</p>', 'rd-nota'))
    return out


def colunas_de(grupo, css_card='rd-card'):
    filhos = [c for c in grupo.children if isinstance(c, Tag)]
    gcls = ' '.join(grupo.get('class', []))
    por_linha = int(m.group(1)) if (m := re.search(r'\bg(\d)\b', gcls)) else len(filhos)        # grade g3 com 6 cartões = 2 linhas de 3
    larg = round(100 / por_linha, 2) if por_linha else 100
    cols = []
    for f in filhos:
        cls = f.get('class', [])
        css = css_card + (' rd-plano' if 'plano' in cls else '') + (' rd-dest' if 'dest' in cls else '') if 'card' in cls else ('rd-plain' if f.name == 'div' and not cls else ' '.join('rd-' + x for x in cls))
        cols.append(coluna(widgets_de(f), larg, css))
    return cols


def converter_secao(sec):
    """Uma <section class="sec"> vira uma ou mais seções do Elementor, que compartilham fundo e espaçamento."""
    cls = sec.get('class', [])
    alt = 'alt' in cls
    sid = sec.get('id')
    c = sec.find('div', recursive=False)
    largura = 820 if 'max-width:820' in (c.get('style') or '') else 1120
    blocos = []     # lista de colunas-de-seção (cada item: lista de colunas)
    bloco = []
    def fecha():
        nonlocal bloco
        if bloco:
            blocos.append(('texto', [coluna(bloco, 100, 'rd-plain')]))
            bloco = []
    detalhes = []
    for el in c.children:
        if not isinstance(el, Tag):
            continue
        ec = el.get('class', [])
        if el.name == 'details':
            detalhes.append((el.summary.get_text(strip=True), '<p>' + interno(el.p) + '</p>'))
        elif el.name == 'div' and any(k in ec for k in ('grade', 'planos', 'tribunais')):
            fecha()
            blocos.append(('grade', colunas_de(el)))
        elif el.name == 'div' and 'dois' in ec:
            fecha()
            blocos.append(('dois', colunas_de(el)))
        elif el.name == 'div' and 'card' in ec:
            fecha()
            blocos.append(('texto', [coluna(widgets_de(el), 100, 'rd-card rd-faixa-api')]))
        elif el.name == 'p' and 'rot' in ec:
            bloco.append(heading(el.get_text(strip=True), 'p', 'rd-rot'))
        elif el.name == 'h2':
            bloco.append(heading(interno(el), 'h2', 'rd-title'))
        elif el.name == 'p':
            bloco.append(texto(f'<p>{interno(el)}</p>', 'rd-sub' if 'sub' in ec else 'rd-nota' if 'nota' in ec else ''))
        elif el.name == 'ul':
            bloco.append(texto(str(el), 'rd-lista'))
        elif el.name == 'a' and 'btn' in ec:
            bloco.append(botao_de(el))
        # "rot" vira título pequeno
    if detalhes:
        fecha()
        blocos.append(('texto', [coluna([acordeao(detalhes, 'rd-faq')], 100, 'rd-plain')]))
    fecha()
    saida = []
    for i, (tipo, cols) in enumerate(blocos):
        css = 'rd-sec' + (' rd-alt' if alt else '') + f' rd-{tipo}' + (' rd-first' if i == 0 else '') + (' rd-last' if i == len(blocos) - 1 else '')
        if tipo == 'grade':
            css += f' rd-n{len(cols)}'
        saida.append(secao(cols, css, sid if i == 0 else None, largura))
    return saida


def converter_html(caminho):
    html = open(caminho, encoding='utf-8').read()
    soup = BeautifulSoup(html, 'html.parser')
    resultado = []
    for sec in soup.find_all('section', recursive=False):
        cls = sec.get('class', [])
        if 'sec' in cls:
            resultado.extend(converter_secao(sec))
        elif 'faixa-cta' in cls:
            ps = sec.find('p'); a = sec.find('a')
            resultado.append(secao([coluna([heading(ps.get_text(strip=True), 'h3', 'rd-faixa-t')], 70, 'rd-plain'),
                                    coluna([botao_de(a)], 30, 'rd-plain rd-dir')], 'rd-faixa-cta'))
        elif 'final' in cls:
            resultado.append(secao([coluna(widgets_de(sec.find('div', recursive=False)), 100, 'rd-plain')], 'rd-final'))
    return resultado


# ── blocos feitos à mão (início, prova, demonstração) ─────────────────────────
def hero():
    esq = [
        heading('Monitoramento de processos para escritórios de advocacia', 'p', 'rd-selo'),
        heading('Seus processos vigiados todos os dias, com <span class="rd-hl">o que fazer e até quando.</span>', 'h1', 'rd-h1'),
        texto('<p>Acompanhe o andamento completo de cada processo e as publicações em nome dos seus advogados, de qualquer área do Direito. O Radar atualiza no horário que você escolher (por exemplo, 5h30), avisa a equipe e mostra o prazo estimado.</p>', 'rd-lead'),
        botao('Falar com um consultor', '{{whatsapp}}', 'rd-btn rd-btn-zap', icone='fab fa-whatsapp', inline=True, link={'url': '{{whatsapp}}', 'is_external': 'on', 'nofollow': '', 'custom_attributes': ''}),
        botao('Ver os preços', '#planos', 'rd-btn rd-link-sec', inline=True),
        texto('<p>14 dias grátis, sem cartão · Atendimento direto no WhatsApp <a class="zap-num" href="{{whatsapp}}" target="_blank" rel="noopener">{{whatsapp_exibido}}</a> · Falha de consulta nunca vira “sem novidade”</p>', 'rd-nota'),
    ]
    dir_ = [imagem('{{theme:img/acoes-uf.png}}', 'Tela Ações por UF do Radar Processual: ações por estado, Justiça Estadual, Federal e do Trabalho, por advogado', 'rd-janela'),
            texto('<p>Sistema Radar Processual · tela Ações por UF (dados fictícios de exemplo)</p>', 'rd-legenda')]
    return secao([coluna(esq, 52, 'rd-plain'), coluna(dir_, 48, 'rd-plain')], 'rd-hero')


def prova():
    return secao([coluna([heading('Construído sobre fontes oficiais', 'p', 'rd-selo-prova')], 22, 'rd-plain'),
                  coluna([texto('<p><strong>Diário de Justiça Eletrônico Nacional (DJEN/Comunica)</strong> e <strong>Datajud (CNJ)</strong>. O leitor das publicações foi conferido com retorno real do DJEN: partes, advogados, órgão, datas e o texto inteiro de dezenas de tribunais.</p>')], 78, 'rd-plain')], 'rd-prova')


def por_dentro():
    topo = [heading('Veja por dentro', 'p', 'rd-rot'), heading('O dia de um escritório, em uma tela', 'h2', 'rd-title'),
            texto('<p>Escritório modelo com dados fictícios: o que foi atualizado, o que não deu para acessar e o que fazer primeiro.</p>', 'rd-sub')]
    meio = [shortcode('[radar_demo_kpis]'), shortcode('[radar_demo_tabela tipo="acoes" limite="4"]'),
            botao('Ver a demonstração completa', '{{home:/demonstracao/}}', 'rd-btn rd-btn-p')]
    return [secao([coluna(topo, 100, 'rd-plain')], 'rd-sec rd-alt rd-texto rd-first', 'por-dentro'),
            secao([coluna(meio, 100, 'rd-plain')], 'rd-sec rd-alt rd-texto rd-last')]


def pagina_demo():
    aviso = texto('<p><strong>Demonstração com dados fictícios.</strong> O escritório, os clientes, os advogados e os números de processo abaixo são inventados e servem só para mostrar como o Radar Processual organiza o dia.</p>', 'rd-aviso')
    topo = [heading('Escritório modelo', 'p', 'rd-rot'), heading('Prado & Associados Advocacia', 'h1', 'rd-h1 rd-h1-demo'),
            texto('<p>Plano Profissional · atualização todos os dias às 05:30 · os números abaixo são calculados com a data de hoje.</p>', 'rd-sub'),
            botao('Testar grátis por 14 dias', '{{radar:entrar#/cadastro}}', 'rd-btn rd-btn-p', inline=True),
            botao('Falar com um consultor', '{{whatsapp}}', 'rd-btn rd-btn-zap', icone='fab fa-whatsapp', inline=True)]
    nav = [botao('1. Carteira de processos', '#carteira', 'rd-btn rd-btn-o', inline=True), botao('2. Ações e prazos', '#acoes', 'rd-btn rd-btn-o', inline=True),
           botao('3. Publicações do dia', '#publicacoes', 'rd-btn rd-btn-o', inline=True)]

    def bloco(sid, titulo, sub, tipo):
        return secao([coluna([heading(titulo, 'h2', 'rd-title'), texto(f'<p>{sub}</p>', 'rd-sub'), shortcode(f'[radar_demo_tabela tipo="{tipo}"]')], 100, 'rd-plain')], 'rd-sec rd-demo-bloco rd-texto', sid)

    fim = [heading('Quer ver com os seus processos?', 'h2', 'rd-title rd-centro'),
           texto('<p>Crie a conta do escritório, cadastre os primeiros processos e veja o Radar trabalhando. 14 dias grátis, sem cartão.</p>', 'rd-sub rd-centro'),
           botao('Testar grátis por 14 dias', '{{radar:entrar#/cadastro}}', 'rd-btn rd-btn-p', inline=True),
           botao('Falar com um consultor', '{{whatsapp}}', 'rd-btn rd-btn-zap', icone='fab fa-whatsapp', inline=True),
           botao('Ver os preços', '{{home:/#planos}}', 'rd-btn rd-btn-s', inline=True)]
    return [secao([coluna([aviso], 100, 'rd-plain')], 'rd-demo-aviso'),
            secao([coluna(topo, 100, 'rd-plain')], 'rd-sec rd-texto rd-demo-topo'),
            secao([coluna([shortcode('[radar_demo_kpis]')], 100, 'rd-plain')], 'rd-sec rd-texto'),
            secao([coluna(nav, 100, 'rd-plain')], 'rd-sec rd-texto rd-demo-nav'),
            bloco('carteira', '1. Carteira de processos', 'Todos os processos do escritório, de várias áreas do Direito, atualizados no horário escolhido. O que não foi acessado aparece com o motivo, nunca como “sem novidade”.', 'carteira'),
            bloco('acoes', '2. Ações e prazos', 'O sistema lê as publicações e diz o que fazer, quem age, até quando (prazo estimado em dias úteis) e o impacto. Tudo ordenado por urgência.', 'acoes'),
            bloco('publicacoes', '3. Publicações e movimentações do dia', 'O que chegou do Diário de Justiça Eletrônico Nacional e do Datajud (CNJ) nas últimas 24 horas, já ligado ao processo certo.', 'publicacoes'),
            secao([coluna(fim, 100, 'rd-plain rd-caixa')], 'rd-sec rd-texto rd-demo-fim')]


def main():
    os.makedirs(SAIDA, exist_ok=True)
    corpo = converter_html(os.path.join(AQUI, 'landing-fonte.html'))
    # ordem: hero, prova, por dentro, [como ... final]
    home = [hero(), prova()] + por_dentro() + corpo
    json.dump(home, open(os.path.join(SAIDA, 'home.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    json.dump(pagina_demo(), open(os.path.join(SAIDA, 'demo.json'), 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
    print('home.json:', len(home), 'seções | demo.json:', len(pagina_demo()), 'seções')


if __name__ == '__main__':
    main()
