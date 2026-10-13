'use strict';
// Tudo que vem do servidor (e do tribunal) entra na página como TEXTO, nunca como HTML.
const app = document.getElementById('app');
let sessao = null;
let csrf = '';

function h(tag, attrs, ...filhos) {
  const el = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (k === 'class') el.className = v;
    else if (k.startsWith('on')) el.addEventListener(k.slice(2), v);
    else if (v !== false && v != null) el.setAttribute(k, v === true ? '' : v);
  }
  for (const f of filhos.flat()) if (f != null && f !== false) el.append(f.nodeType ? f : document.createTextNode(String(f)));
  return el;
}
async function api(metodo, caminho, corpo, consulta) {
  const url = caminho + (consulta ? '?' + new URLSearchParams(consulta) : '');
  const opcoes = { method: metodo, headers: {}, credentials: 'same-origin' };
  if (metodo !== 'GET') { opcoes.headers = { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf }; opcoes.body = JSON.stringify(corpo || {}); }
  const r = await fetch(url, opcoes);
  const json = await r.json().catch(() => ({ ok: false, error: 'Resposta inesperada do servidor.' }));
  if (r.status === 401 && sessao && sessao.authenticated && json.code === 'auth' && caminho !== '/api/login') { await carregarSessao(); location.hash = '#/entrar'; }
  return { status: r.status, ...json };
}
async function carregarSessao() {
  const r = await api('GET', '/api/session');
  sessao = r.data; csrf = sessao.csrf;
  desenharTopo();
}
const dataBR = (iso) => !iso ? '—' : iso.slice(8, 10) + '/' + iso.slice(5, 7) + '/' + iso.slice(0, 4);
const dataHoraBR = (iso) => !iso ? 'nunca' : dataBR(iso) + ' ' + iso.slice(11, 16);
const pode = (perm) => !!(sessao && sessao.tenant && sessao.tenant.permissions.includes(perm));
const aviso = (texto, tipo) => h('div', { class: 'msg ' + (tipo || '') }, texto);
const link = (url) => /^https:\/\//i.test(url || '') ? url : null;

function estadoMonitoramento(p) {
  if (p.status === 'paused') return h('span', { class: 'etq' }, 'Pausado');
  if (p.status === 'archived') return h('span', { class: 'etq' }, 'Arquivado');
  if (p.last_error) return h('span', { class: 'etq verm', title: p.last_error }, 'Falha na consulta');
  if (!p.last_success_at) return h('span', { class: 'etq amar' }, 'Aguardando 1ª consulta');
  return h('span', { class: 'etq verde' }, 'Monitorado');
}

async function baixar(caminho, nome, consulta) {
  const r = await fetch(caminho + (consulta ? '?' + new URLSearchParams(consulta) : ''), { credentials: 'same-origin' });
  if (!r.ok) { const j = await r.json().catch(() => ({})); return j.error || 'Não foi possível gerar o arquivo.'; }
  const url = URL.createObjectURL(await r.blob());
  const a = h('a', { href: url, download: nome }); document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
  return null;
}

// ── ícones, menu lateral e cabeçalho ─────────────────────────────────────────
const ICONES = {
  home: 'M3 11l9-8 9 8v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z',
  folder: 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
  file: 'M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z M14 3v5h5 M9 13h6 M9 17h6',
  bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9 M13.7 21a2 2 0 0 1-3.4 0',
  alert: 'M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z M12 9v4 M12 17h.01',
  map: 'M9 4L3 6v14l6-2 6 2 6-2V4l-6 2z M9 4v14 M15 6v14',
  users: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2 M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8 M23 21v-2a4 4 0 0 0-3-3.9 M16 3.1a4 4 0 0 1 0 7.8',
  user: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2 M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
  card: 'M3 5h18a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z M1 10h22',
  shield: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
  clock: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20 M12 6v6l4 2',
  key: 'M21 2l-2 2m-7.6 7.6a5.5 5.5 0 1 1-7.8 7.8 5.5 5.5 0 0 1 7.8-7.8zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4',
  search: 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16 M21 21l-4.3-4.3',
  radar: 'M19.1 4.9A10 10 0 1 0 21 12 M15.5 8.5A5 5 0 1 0 17 12 M12 12l7-7',
  pulse: 'M22 12h-4l-3 9L9 3l-3 9H2',
  menu: 'M3 6h18 M3 12h18 M3 18h18',
};
function icone(nome) {
  const NS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(NS, 'svg');
  svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '2');
  svg.setAttribute('stroke-linecap', 'round'); svg.setAttribute('stroke-linejoin', 'round'); svg.setAttribute('aria-hidden', 'true');
  for (const d of (ICONES[nome] || '').split(' M').map((x, i) => (i ? 'M' + x : x))) { const p = document.createElementNS(NS, 'path'); p.setAttribute('d', d); svg.append(p); }
  return svg;
}
document.getElementById('marca').replaceChildren(icone('radar'), h('span', {}, 'Radar ', h('b', {}, 'Processual')));
document.getElementById('abrir-menu').append(icone('menu'));
document.getElementById('abrir-menu').addEventListener('click', () => document.body.classList.toggle('menu-aberto'));
window.addEventListener('hashchange', () => document.body.classList.remove('menu-aberto'));

let selos = { alertas: 0, notif: 0 };
const TITULOS = [
  [/^\/?$/, 'Radar <b>Processual</b>', 'Visão Geral'], [/^\/processos/, 'Processos', 'Acompanhamento processual'], [/^\/publicacoes/, 'Publicações', 'Diário oficial por OAB'],
  [/^\/uf/, 'Ações por UF', 'Por advogado (OAB)'], [/^\/alertas/, 'Alertas OAB', 'Possível inscrição suplementar'], [/^\/oabs/, 'Advogados (OAB)', 'Cadastro de OABs monitoradas'],
  [/^\/notificacoes/, 'Notificações', 'Movimentações capturadas'], [/^\/equipe/, 'Equipe', 'Pessoas do escritório'], [/^\/plano/, 'Plano', 'Assinatura e limites'],
  [/^\/conta/, 'Minha conta', 'Dados, senha e privacidade'], [/^\/admin/, 'Administração', 'Plataforma'],
];
// A página de apresentação é a porta de entrada: ao sair do sistema, volta para ela.
function irParaApresentacao() { if (window.irParaApresentacaoDemo) window.irParaApresentacaoDemo(); else location.href = '/'; }
// Prévia da tela de login para quem já está logado como administrador (o login real fica em /entrar).
const emPreviaLogin = () => window.__PREVIA_LOGIN === true || /[?&]previa=login\b/.test(location.search);
function fecharPreviaLogin() { if (window.irParaPainelDemo) window.irParaPainelDemo(); else location.href = '/entrar'; }
function mostrarPreviaLogin() {
  document.getElementById('topo').hidden = true; document.getElementById('cabecalho').hidden = true; document.getElementById('faixa').hidden = true; document.body.classList.remove('logado');
  telaEntrar();
  app.prepend(h('div', { class: 'previa-login' }, 'Prévia da página de login (você está logado como administrador). ', h('button', { onclick: fecharPreviaLogin }, 'Fechar prévia e voltar ao painel')));
}
function desenharTopo() {
  const topo = document.getElementById('topo'), cab = document.getElementById('cabecalho');
  const logado = sessao && sessao.authenticated && (sessao.tenant || sessao.platform_admin);
  topo.hidden = !logado; cab.hidden = !logado;
  document.body.classList.toggle('logado', !!logado);
  if (!logado) return;
  const atual = location.hash || '#/';
  const item = (href, nome, ic, extra) => h('a', { href, class: ((href === '#/' ? atual === '#/' : atual.startsWith(href)) ? 'ativo ' : '') + (extra && extra.alerta ? 'alerta' : '') },
    icone(ic), nome, extra && extra.selo ? h('span', { class: 'selo' }, extra.selo) : null);
  const grupo = (t) => h('div', { class: 'grupo' }, t);
  const partes = [];
  if (!sessao.tenant) partes.push(grupo('Plataforma'), item('#/admin', 'Administração', 'shield'), item('#/uso', 'Usuários e uso', 'users'), item('#/apis', 'API e clientes', 'key'), h('a', { href: '/', target: '_blank', rel: 'noopener' }, icone('home'), 'Página de apresentação'), h('a', { href: '/entrar?previa=login', target: '_blank', rel: 'noopener' }, icone('user'), 'Página de login'));
  else {
    partes.push(item('#/', 'Dashboard', 'home'), grupo('Processual'), item('#/acoes', 'Ações e prazos', 'clock'), item('#/consulta', 'Consultar processo', 'search'), item('#/processos', 'Processos', 'folder'),
      ...(pode('oab.view') ? [item('#/publicacoes', 'Publicações', 'file')] : []), item('#/notificacoes', 'Notificações', 'bell', { selo: selos.notif || null }));
    if (pode('oab.view')) partes.push(grupo('Advogados'), item('#/uf', 'Ações por UF', 'map'),
      item('#/alertas', 'Alertas OAB', 'alert', { alerta: selos.alertas > 0, selo: selos.alertas || null }), item('#/oabs', 'Advogados (OAB)', 'users'));
    partes.push(grupo('Escritório'), item('#/equipe', 'Equipe', 'users'), item('#/plano', 'Plano', 'card'), item('#/conta', 'Minha conta', 'user'));
    if (sessao.platform_admin) partes.push(grupo('Plataforma'), item('#/admin', 'Administração', 'shield'), item('#/uso', 'Usuários e uso', 'users'), item('#/apis', 'API e clientes', 'key'), h('a', { href: '/', target: '_blank', rel: 'noopener' }, icone('home'), 'Página de apresentação'), h('a', { href: '/entrar?previa=login', target: '_blank', rel: 'noopener' }, icone('user'), 'Página de login'));
  }
  document.getElementById('menu').replaceChildren(...partes);
  document.getElementById('quem').replaceChildren(h('a', { href: '#/conta', title: 'Minha conta' }, sessao.user.name));
  const caminho = atual.slice(1).split('?')[0];
  const [, t, sub] = TITULOS.find(([re]) => re.test(caminho)) || [0, 'Radar <b>Processual</b>', ''];
  document.getElementById('titulo-t').innerHTML = t;       // texto fixo do próprio sistema, nunca dado de usuário
  document.getElementById('titulo-s').textContent = sub;
  document.getElementById('sino').replaceChildren(...[icone('bell'), selos.notif ? h('span', { class: 'selo' }, selos.notif) : null].filter(Boolean));
}
async function atualizarSelos() {
  if (!sessao || !sessao.authenticated || !sessao.tenant || !pode('process.view')) return;
  const r = await api('GET', '/api/dashboard');
  if (!r.ok) return;
  const novo = { alertas: r.data.oab_alerts_unseen || 0, notif: r.data.unread_notifications || 0 };
  if (novo.alertas !== selos.alertas || novo.notif !== selos.notif) { selos = novo; desenharTopo(); }
}
document.getElementById('sair').addEventListener('click', async () => { await api('POST', '/api/logout'); await carregarSessao(); irParaApresentacao(); });

// ── telas de entrada ─────────────────────────────────────────────────────────
function campo(rotulo, nome, tipo, extra) { return h('label', {}, rotulo, h('input', { name: nome, type: tipo || 'text', required: true, ...(extra || {}) })); }
// tela de entrada em duas colunas: apresentação à esquerda, formulário à direita
function layoutAuth(titulo, subtitulo, ...conteudo) {
  return h('div', { class: 'auth' },
    h('section', { class: 'auth-lado' },
      h('a', { class: 'marca', href: '/' }, icone('radar'), h('span', {}, 'Radar ', h('b', {}, 'Processual'))),
      h('div', { class: 'auth-texto' },
        h('h2', {}, 'Monitoramento processual para escritórios de advocacia'),
        h('ul', {}, h('li', {}, 'Consulta o Diário de Justiça Eletrônico Nacional todos os dias, por processo e por OAB.'),
          h('li', {}, 'Avisa a equipe no painel e por e-mail quando sai movimentação nova.'),
          h('li', {}, 'Se uma consulta falha, o sistema mostra a falha. Nunca finge que não houve novidade.'),
          h('li', {}, 'Cada escritório enxerga somente os próprios dados.'))),
      h('p', { class: 'suave' }, 'Apoio operacional de acompanhamento. Não substitui a conferência nas fontes oficiais.')),
    h('section', { class: 'auth-form' }, h('div', { class: 'auth-caixa' },
      h('h1', {}, titulo), subtitulo ? h('p', { class: 'suave' }, subtitulo) : null, ...conteudo,
      h('p', { class: 'suave auth-rodape' }, h('a', { href: '/' }, '← Voltar para o site'), ' · ', h('a', { href: '/app/termos.html' }, 'Termos'), ' · ', h('a', { href: '/app/privacidade.html' }, 'Privacidade')))));
}
function formulario(titulo, campos, botao, aoEnviar, rodape, subtitulo) {
  const msg = h('div');
  const form = h('form', { class: 'empilhado', onsubmit: async (e) => {
    e.preventDefault();
    const dados = Object.fromEntries(new FormData(form));
    msg.replaceChildren(); const b = form.querySelector('button'); b.disabled = true;
    const erro = await aoEnviar(dados);
    b.disabled = false;
    if (erro) msg.replaceChildren(aviso(erro, 'erro'));
  } }, ...campos, h('button', { class: 'principal' }, botao), msg);
  return layoutAuth(titulo, subtitulo, form, rodape || null);
}
function telaEntrar() {
  app.replaceChildren(formulario('Entrar', [campo('E-mail', 'email', 'email', { autocomplete: 'username' }), campo('Senha', 'password', 'password', { autocomplete: 'current-password' })], 'Entrar',
    async (d) => {
      const r = await api('POST', '/api/login', d);
      if (!r.ok) return r.error;
      await carregarSessao();
      if (emPreviaLogin()) return fecharPreviaLogin();
      if (!sessao.tenant && sessao.tenants.length > 1) return telaEscolherEscritorio();
      location.hash = '#/'; rota();
    },
    h('p', { class: 'suave' }, h('a', { href: '#/esqueci' }, 'Esqueci a senha'), ' · ', h('a', { href: '#/cadastro' }, 'Criar conta do escritório (teste grátis)')), 'Acesse o painel do seu escritório.'));
}
function telaEscolherEscritorio() {
  app.replaceChildren(layoutAuth('Escolha o escritório', 'Sua conta participa de mais de um escritório.',
    ...sessao.tenants.map((t) => h('p', {}, h('button', { class: 'principal', onclick: async () => { await api('POST', '/api/tenant/select', { tenant_id: t.tenant_id }); await carregarSessao(); location.hash = '#/'; rota(); } }, t.name + ' (' + t.role + ')')))));
}
function telaCadastro() {
  app.replaceChildren(formulario('Criar conta do escritório', [campo('Nome do escritório', 'office_name'), campo('Seu nome', 'name'), campo('E-mail', 'email', 'email'), campo('Senha (mínimo 10 caracteres)', 'password', 'password', { minlength: 10, autocomplete: 'new-password' }),
    h('label', { class: 'linha' }, h('input', { type: 'checkbox', name: 'accept_terms', required: true }), 'Li e aceito os ', h('a', { href: '/app/termos.html', target: '_blank', rel: 'noopener' }, 'Termos de Uso'), ' e a ', h('a', { href: '/app/privacidade.html', target: '_blank', rel: 'noopener' }, 'Política de Privacidade'))], 'Criar conta',
    async (d) => { const r = await api('POST', '/api/register', { ...d, accept_terms: d.accept_terms === 'on' }); if (!r.ok) return r.error; await carregarSessao(); location.hash = '#/'; rota(); },
    h('p', { class: 'suave' }, h('a', { href: '#/entrar' }, 'Já tenho conta')), 'Teste grátis por 14 dias. Não pede cartão de crédito.'));
}
function telaEsqueci() {
  app.replaceChildren(formulario('Redefinir senha', [campo('E-mail', 'email', 'email')], 'Enviar instruções',
    async (d) => { const r = await api('POST', '/api/password/forgot', d); app.querySelector('form').replaceWith(aviso(r.message || r.error, r.ok ? 'ok' : 'erro')); },
    h('p', { class: 'suave' }, h('a', { href: '#/entrar' }, 'Voltar'))));
}
function telaRedefinir(token) {
  app.replaceChildren(formulario('Definir nova senha', [campo('Nova senha (mínimo 10 caracteres)', 'password', 'password', { minlength: 10, autocomplete: 'new-password' })], 'Salvar senha',
    async (d) => { const r = await api('POST', '/api/password/reset', { token, password: d.password }); if (!r.ok) return r.error; location.hash = '#/entrar'; }));
}

// ── painel ───────────────────────────────────────────────────────────────────
const kpi = (titulo, valor, cor, ic, href, rodape) => h('a', { class: 'cartao kpi', href }, h('span', { class: 'rot' }, titulo), h('span', { class: 'chip ' + cor }, icone(ic)),
  h('span', { class: 'num', style: cor === 'verm' && valor ? 'color:var(--verm)' : '' }, String(valor)), h('span', { class: 'rod' }, rodape + ' →'));

async function telaPainel() {
  const veOab = pode('oab.view');
  const [r, st, pubs, feed] = await Promise.all([api('GET', '/api/dashboard'), veOab ? api('GET', '/api/oab-stats') : null,
    veOab ? api('GET', '/api/publications', null, { new_only: '1', per_page: '1' }) : null, api('GET', '/api/feed', null, { days: '120' })]);
  const acoesUrgentes = feed && feed.ok ? feed.data.filter((m) => m.action_status === 'open').map((m) => analisarItem(m)).filter((x) => ['vencido', 'hoje', 'ate3', 'ate7'].includes(x.urgencia)).length : 0;
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'));
  const d = r.data;
  selos = { alertas: d.oab_alerts_unseen || 0, notif: d.unread_notifications || 0 }; desenharTopo();
  const kpis = [kpi('Processos ativos', d.processes_active, 'azul', 'folder', '#/processos', 'Ver todos'),
    kpi('Notificações não lidas', d.unread_notifications, 'ambar', 'bell', '#/notificacoes', 'Ver notificações'),
    kpi('Movimentações (7 dias)', d.recent_movements.length, 'roxo', 'pulse', '#/notificacoes', 'Últimos 7 dias'),
    kpi('Ações com prazo até 7 dias', acoesUrgentes, 'amar', 'clock', '#/acoes', 'Ver ações e prazos'),
    kpi('Falha de consulta', d.with_error, 'verm', 'alert', '#/processos?with_error=1', 'Ver processos'),
    ...(veOab ? [kpi('Publicações novas', pubs && pubs.ok ? pubs.data.total : 0, 'azul', 'file', '#/publicacoes?new_only=1', 'Ver publicações'),
      kpi('Alertas OAB', d.oab_alerts_unseen, 'verm', 'alert', '#/alertas', 'Ver central de alertas')] : []),
    kpi('Sem atualização', d.stale, 'cinza', 'clock', '#/processos?stale_days=7', 'Sem consulta há 7+ dias'),
    kpi('Aguardando 1ª consulta', d.never_checked, 'ambar', 'clock', '#/processos', 'Ver processos')];
  const bloco = h('div');
  const montar = (dados) => bloco.replaceChildren(blocoUf(dados, async (ano) => { const x = await api('GET', '/api/oab-stats', null, { year: ano }); if (x.ok) montar(x.data); }));
  if (veOab && st && st.ok) montar(st.data);
  app.replaceChildren(
    h('div', { class: 'linha entre' }, h('h1', {}, 'Dashboard'), pode('process.create') ? h('a', { href: '#/processos' }, h('button', { class: 'principal' }, 'Cadastrar processo')) : null),
    h('div', { class: 'grade' }, ...kpis), bloco,
    h('div', { class: 'cartao' }, h('h2', {}, 'Movimentações dos últimos 7 dias'),
      d.recent_movements.length ? d.recent_movements.map((m) => h('div', { class: 'mov' },
        h('a', { href: '#/processos/' + m.process_id }, m.cnj), ' ', h('span', { class: 'etq' }, m.tribunal), ' ',
        h('span', { class: 'suave' }, 'movimentação de ' + dataBR(m.occurred_at) + ' · capturada em ' + dataHoraBR(m.captured_at)),
        h('pre', {}, (m.description || '').slice(0, 280)))) : h('p', { class: 'vazio' }, 'Nenhuma movimentação nova nos últimos 7 dias.')),
    h('p', { class: 'suave' }, 'Última consulta bem-sucedida: ' + dataHoraBR(d.last_success_at)));
}

// ── processos ────────────────────────────────────────────────────────────────
async function telaProcessos(consulta) {
  const filtros = { q: '', status: '', with_error: '', stale_days: '', ...consulta };
  const corpoTabela = h('tbody');
  const total = h('span', { class: 'suave' });
  const carregar = async () => {
    const q = Object.fromEntries(Object.entries(filtros).filter(([, v]) => v));
    const r = await api('GET', '/api/processes', null, q);
    if (!r.ok) return corpoTabela.replaceChildren(h('tr', {}, h('td', { colspan: 6 }, r.error)));
    total.textContent = r.data.total + ' processo(s)';
    corpoTabela.replaceChildren(...(r.data.items.length ? r.data.items.map((p) =>
      h('tr', { class: 'clicavel', onclick: () => { location.hash = '#/processos/' + p.id; } },
        h('td', {}, p.cnj), h('td', {}, p.client_label || '—'), h('td', {}, p.tribunal), h('td', {}, p.responsible_name || '—'),
        h('td', {}, dataHoraBR(p.last_success_at)), h('td', {}, estadoMonitoramento(p))))
      : [h('tr', {}, h('td', { colspan: 6, class: 'vazio' }, 'Nenhum processo encontrado.'))]));
  };
  const busca = h('input', { type: 'search', placeholder: 'Buscar por número ou cliente…', value: filtros.q, oninput: (e) => { filtros.q = e.target.value; carregar(); } });
  const status = h('select', { onchange: (e) => { filtros.status = e.target.value; carregar(); } },
    ...[['', 'Ativos e pausados'], ['active', 'Ativos'], ['paused', 'Pausados'], ['archived', 'Arquivados']].map(([v, t]) => h('option', { value: v }, t)));
  const msg = h('div');
  const novo = pode('process.create') ? h('form', { class: 'linha', onsubmit: async (e) => {
    e.preventDefault(); const f = e.target;
    const r = await api('POST', '/api/processes', { cnj: f.cnj.value, client_label: f.client_label.value });
    msg.replaceChildren(aviso(r.ok ? 'Processo cadastrado. A primeira consulta será feita em instantes.' : r.error, r.ok ? 'ok' : 'erro'));
    if (r.ok) { f.reset(); carregar(); }
  } }, h('input', { name: 'cnj', placeholder: 'Número CNJ (0000000-00.0000.0.00.0000)', required: true, size: 34 }), h('input', { name: 'client_label', placeholder: 'Cliente (opcional)' }), h('button', { class: 'principal' }, 'Cadastrar')) : null;
  const relatorio = h('div');
  const arquivo = h('input', { type: 'file', accept: '.csv,.txt', hidden: true, onchange: async (e) => {
    const f = e.target.files[0]; if (!f) return;
    relatorio.replaceChildren(aviso('Importando…'));
    const x = await api('POST', '/api/processes/import', { csv: await f.text() });
    e.target.value = '';
    if (!x.ok) return relatorio.replaceChildren(aviso(x.error, 'erro'));
    relatorio.replaceChildren(aviso(x.data.created + ' processo(s) importado(s)' + (x.data.skipped.length ? ' e ' + x.data.skipped.length + ' linha(s) não importada(s):' : '.'), x.data.skipped.length ? 'amarelo' : 'ok'),
      ...x.data.skipped.slice(0, 50).map((s) => h('div', { class: 'suave' }, 'Linha ' + s.line + (s.cnj ? ' (' + s.cnj + ')' : '') + ': ' + s.reason)));
    carregar();
  } });
  const importar = pode('process.create') ? h('div', { class: 'linha' }, arquivo,
    h('button', { onclick: () => arquivo.click() }, 'Importar planilha (CSV)'), h('span', { class: 'suave' }, 'Uma coluna com o número do processo e, se quiser, outra com o cliente. Até 500 linhas.')) : null;
  const exportar = h('button', { onclick: async () => { const e = await baixar('/api/export/processes', 'processos.csv'); if (e) msg.replaceChildren(aviso(e, 'erro')); } }, 'Exportar CSV');
  app.replaceChildren(h('h1', {}, 'Processos'),
    novo ? h('div', { class: 'cartao' }, h('h2', {}, 'Cadastrar processo'), novo, msg, h('div', { style: 'height:10px' }), importar, relatorio) : null,
    h('div', { class: 'cartao' }, h('div', { class: 'filtros' }, busca, status, total, exportar),
      h('div', { style: 'overflow-x:auto' }, h('table', {}, h('thead', {}, h('tr', {}, ...['Número', 'Cliente', 'Tribunal', 'Responsável', 'Última consulta com sucesso', 'Situação'].map((t) => h('th', {}, t)))), corpoTabela))));
  carregar();
}


// ── consulta avulsa: o número digitado, com a capa e TODO o andamento ────────
const NOME_FONTE = { datajud: 'Datajud', djen: 'Diário (DJEN)' };
const ESTADO_FONTE = { ok: ['verde', 'consultada'], error: ['verm', 'FALHOU'], not_configured: ['amar', 'não configurada'], not_found: ['amar', 'processo não encontrado'] };

async function telaConsulta(consulta) {
  const campo = h('input', { type: 'text', id: 'cnj-consulta', placeholder: '0000000-00.0000.0.00.0000', value: consulta.cnj || '', autocomplete: 'off', inputmode: 'numeric', style: 'min-width:min(100%,320px)' });
  const resultado = h('div');
  const buscar = async () => {
    const numero = campo.value.trim();
    if (!numero) return resultado.replaceChildren(aviso('Digite o número do processo.', 'erro'));
    history.replaceState(null, '', '#/consulta?cnj=' + encodeURIComponent(numero));
    resultado.replaceChildren(h('p', { class: 'suave' }, 'Consultando o Datajud e o Diário… pode levar alguns segundos.'));
    const r = await api('GET', '/api/lookup', null, { cnj: numero });
    if (!r.ok) return resultado.replaceChildren(aviso(r.error, 'erro'));
    desenharConsulta(resultado, r.data);
  };
  campo.addEventListener('keydown', (e) => { if (e.key === 'Enter') buscar(); });
  app.replaceChildren(
    h('h1', {}, 'Consultar processo'),
    h('div', { class: 'cartao' },
      h('p', { class: 'suave' }, 'Digite o número CNJ para ver a capa e todo o andamento. A consulta não grava nada; para acompanhar o processo e receber avisos, cadastre-o depois.'),
      h('div', { class: 'linha' }, campo, h('button', { class: 'principal', onclick: buscar }, 'Consultar'))),
    resultado);
  if (consulta.cnj) buscar();
}

function desenharConsulta(alvo, d) {
  const msg = h('div');
  const caso = d.case;
  const fontes = h('div', { class: 'cartao' }, h('h2', {}, 'De onde veio'),
    ...d.sources.slice().sort((a, b) => (a.code === 'djen' ? -1 : 1) - (b.code === 'djen' ? -1 : 1)).map((f) => { const [cor, rotulo] = ESTADO_FONTE[f.status] || ['', f.status];
      return h('div', { class: 'linha', style: 'margin:4px 0' }, h('strong', {}, f.name), h('span', { class: 'etq ' + cor }, rotulo),
        f.status === 'ok' ? h('span', { class: 'suave' }, f.count + ' registro(s)') : null, f.message ? h('span', { class: 'suave' }, f.message) : null); }),
    d.sources.some((f) => f.status === 'error' || f.status === 'not_configured')
      ? aviso('O quadro abaixo está INCOMPLETO: pelo menos uma fonte não respondeu. Isso não quer dizer que o processo não tenha andamento.', 'erro') : null);
  const capa = h('div', { class: 'cartao' },
    h('div', { class: 'linha entre' }, h('h1', {}, d.cnj),
      d.monitored_id ? h('a', { href: '#/processos/' + d.monitored_id }, h('button', {}, 'Já acompanhado — abrir')) :
        pode('process.create') ? h('button', { class: 'principal', onclick: async () => {
          const x = await api('POST', '/api/processes', { cnj: d.cnj });
          if (x.ok) location.hash = '#/processos/' + x.data.id; else msg.replaceChildren(aviso(x.error, 'erro'));
        } }, 'Acompanhar este processo') : null),
    h('p', { class: 'suave' }, d.tribunal + ' · ' + d.segment + (d.uf ? ' · ' + d.uf : '')),
    (caso || d.parties.length || d.lawyers.length) ? h('dl', { class: 'capa' },
      ...[['Classe', caso ? caso.classe : ''], ['Assunto', caso ? (caso.assuntos || []).join('; ') : ''], ['Órgão julgador', caso ? caso.orgao : ''], ['Grau', caso ? caso.grau : ''],
        ['Distribuído em', caso && caso.ajuizamento ? dataBR(caso.ajuizamento) : ''], ['Última atualização no Datajud', caso && caso.atualizado ? dataBR(caso.atualizado) : ''],
        ['Sigilo', caso && caso.sigilo > 0 ? 'Nível ' + caso.sigilo + ' (dados limitados)' : ''],
        ['Órgão (Diário)', !caso || !caso.orgao ? ((d.timeline.find((t) => t.source === 'djen' && t.orgao) || {}).orgao || '') : ''],
        ['Parte(s)', d.parties.map((x) => x.nome + (x.polo ? ' (' + (x.polo === 'A' ? 'ativo' : 'passivo') + ')' : '')).join('; ')],
        ['Advogado(s)', d.lawyers.map((x) => x.nome + (x.oab ? ' — OAB ' + x.uf + ' ' + x.oab : '')).join('; ')]].filter(([, v]) => v).flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, v)])) : null,
    msg);
  const busca = h('input', { type: 'search', placeholder: 'Buscar no andamento…', 'aria-label': 'Buscar no andamento' });
  const filtro = h('select', { 'aria-label': 'Fonte' }, h('option', { value: '' }, 'Todas as fontes'), h('option', { value: 'datajud' }, 'Só Datajud'), h('option', { value: 'djen' }, 'Só Diário (DJEN)'));
  const lista = h('div'); const total = h('span', { class: 'suave' });
  const desenhar = () => {
    const q = busca.value.trim().toLowerCase();
    const itens = d.timeline.filter((t) => (!filtro.value || t.source === filtro.value) && (!q || (t.text + ' ' + t.kind).toLowerCase().includes(q)));
    total.textContent = itens.length + ' de ' + d.timeline.length + ' registro(s)';
    lista.replaceChildren(...(itens.length ? itens.map((t) => {
      const longo = t.text.length > 500;
      return h('div', { class: 'mov' },
        h('div', { class: 'linha' }, h('strong', {}, dataBR(t.date) + (t.time ? ' ' + t.time : '')), h('span', { class: 'etq azul' }, NOME_FONTE[t.source] || t.source), t.kind ? h('span', { class: 'etq' }, t.kind) : null,
          link(t.url) ? h('a', { href: link(t.url), target: '_blank', rel: 'noopener noreferrer' }, 'Inteiro teor') : null),
        t.source === 'djen' && (t.orgao || t.meio) ? h('div', { class: 'suave' }, [t.orgao && 'Órgão: ' + t.orgao, t.tipo_documento && 'Documento: ' + t.tipo_documento, t.meio && 'Meio: ' + t.meio].filter(Boolean).join(' · ')) : null,
        t.source === 'djen' ? h('details', { class: 'leitura-det' }, h('summary', {}, 'Leitura automática: o que fazer, quem, prazo, impacto e riscos'),
          cartaoLeitura({ description: t.text, occurred_at: t.date, kind: t.kind, metadata: { orgao: t.orgao, partes: t.partes, advogados: t.advogados } }, analisarItem({ description: t.text, occurred_at: t.date, kind: t.kind, metadata: { orgao: t.orgao, partes: t.partes, advogados: t.advogados } }), null)) : null,
        longo ? h('details', {}, h('summary', {}, t.text.slice(0, 220).replace(/\s+/g, ' ') + '… (ver tudo)'), h('pre', {}, t.text)) : h('pre', {}, t.text));
    }) : [h('p', { class: 'vazio' }, d.timeline.length ? 'Nada com esse filtro.' : 'Nenhum andamento retornado pelas fontes consultadas.')]));
  };
  busca.addEventListener('input', desenhar); filtro.addEventListener('change', desenhar); desenhar();
  alvo.replaceChildren(capa, fontes, h('div', { class: 'cartao' }, h('h2', {}, 'Andamento completo'), h('div', { class: 'filtros' }, busca, filtro, total), lista));
}


// ── Ações e prazos: leitura automática das publicações ───────────────────────
// A leitura é feita aqui no navegador, com regras simples (public/app/analise.js). É triagem, não parecer jurídico.
const hojeISO = () => { const d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); };
const COR_URG = { vencido: 'verm', hoje: 'verm', ate3: 'amar', ate7: 'amar', depois: 'azul', sem_prazo: '' };
const ROT_IMPACTO = { favoravel: ['verde', 'Favorável ao seu cliente'], desfavoravel: ['verm', 'Desfavorável ao seu cliente'], atencao: ['amar', 'Atenção'], depende: ['azul', 'Depende de quem você representa'], neutro: ['', 'Neutro / informativo'] };
const LADO_ROT = { A: 'autor/reclamante', P: 'réu/reclamado' };
const analisarItem = (it) => window.Analise.analisar({ texto: it.description || '', cnj: it.cnj, data: it.occurred_at, tipo: it.kind, metadata: it.metadata || {} }, { hoje: hojeISO(), ladoCliente: it.client_side || null });
const prazoTexto = (a) => a.vence ? dataBR(a.vence) + (a.hora ? ' ' + a.hora : '') + (a.dias === 0 ? ' (hoje)' : a.dias > 0 ? ' (em ' + a.dias + ' dia' + (a.dias === 1 ? '' : 's') + ')' : ' (venceu há ' + (-a.dias) + ' dia' + (a.dias === -1 ? '' : 's') + ')') : (a.prazoTexto || 'sem prazo identificado');
const pilulaUrgencia = (u, prox) => h('span', { class: 'etq ' + COR_URG[u] }, window.Analise.ROTULO_URGENCIA[u] + (prox && prox.vence && u !== 'sem_prazo' ? ' · ' + dataBR(prox.vence) : ''));

// painel de leitura de UMA publicação. `it`: { id?, process_id?, client_side?, description, occurred_at, kind, source_url, metadata, action_status }
function cartaoLeitura(it, r, depois) {
  const [corI, rotI] = ROT_IMPACTO[r.impacto.grau] || ['', r.impacto.rotulo];
  const meta = it.metadata || {}, ator = r.atores;
  const linhas = [];
  linhas.push(['O que fazer', h('div', {}, r.acoes.map((a) => h('div', { class: 'item-acao' },
    h('strong', {}, a.rotulo), ' ', h('span', { class: 'etq ' + (a.dias != null && a.dias <= 3 ? 'verm' : '') }, prazoTexto(a)), a.estimado ? h('span', { class: 'etq', title: 'Contagem automática em dias úteis, sem feriados locais' }, 'estimado') : null,
    h('div', { class: 'suave' }, 'Quem: ' + (a.quem || []).join(', ')), h('div', {}, 'Como: ' + a.como)))) ]);
  linhas.push(['Impacto', h('div', {}, h('span', { class: 'etq ' + corI }, rotI), ' ', r.impacto.motivo,
    !it.client_side && it.process_id && pode('process.update') ? h('div', { class: 'suave' }, 'Diga de que lado seu escritório atua neste processo para ver o que é favorável ou não: ',
      ...['A', 'P'].map((l) => h('button', { onclick: async () => { const x = await api('PATCH', '/api/processes/' + it.process_id, { client_side: l }); if (x.ok) depois && depois(); } }, 'Represento o ' + LADO_ROT[l]))) : null,
    it.client_side ? h('div', { class: 'suave' }, 'Você atua pelo ' + LADO_ROT[it.client_side] + ' neste processo.') : null)]);
  if (r.riscos.length) linhas.push(['Riscos', h('ul', {}, r.riscos.map((x) => h('li', {}, x.texto, x.lado ? h('span', { class: 'suave' }, ' (para ' + (it.client_side ? (x.lado === it.client_side ? 'o seu cliente' : 'a parte contrária') : 'o ' + LADO_ROT[x.lado]) + ')') : null)))]);
  const atores = [];
  if (ator.orgao) atores.push('Juízo/órgão: ' + ator.orgao); if (ator.juiz) atores.push('Juiz(a): ' + ator.juiz); if (ator.relator) atores.push('Relator(a): ' + ator.relator); if (ator.perito) atores.push('Perito: ' + ator.perito);
  if (r.intimados.length) atores.push('Intimado(s): ' + r.intimados.join('; '));
  if ((meta.partes || []).length) atores.push('Partes: ' + meta.partes.map((x) => x.nome + (x.polo ? ' (' + (x.polo === 'A' ? 'ativo' : 'passivo') + ')' : '')).join('; '));
  if ((meta.advogados || []).length) atores.push('Advogados: ' + meta.advogados.map((x) => x.nome + (x.oab ? ' — OAB ' + x.uf + ' ' + x.oab : '')).join('; '));
  if (atores.length) linhas.push(['Atores', h('ul', {}, atores.map((x) => h('li', {}, x)))]);
  if (r.acoes.some((a) => a.tipoPrazo === 'dias' || a.tipoPrazo === 'padrao')) linhas.push(['Como contamos o prazo', h('span', { class: 'suave' }, 'Disponibilizado em ' + dataBR(it.occurred_at) + '; publicação considerada em ' + dataBR(r.publicacao) + ' (1º dia útil seguinte); o prazo começa a contar no dia útil seguinte. Dias úteis, sem feriados nacionais, Sexta-feira Santa, Corpus Christi e recesso de 20/12 a 20/01. Feriados locais e suspensões do tribunal NÃO são considerados: confira o prazo no ato antes de confiar na data.')]);
  linhas.push(['Trecho-chave', h('blockquote', {}, r.trecho)]);
  const acoesBotoes = it.id && pode('process.update') ? h('div', { class: 'linha' },
    it.action_status !== 'done' ? h('button', { class: 'principal', onclick: async () => { const x = await api('POST', '/api/movements/' + it.id + '/action', { status: 'done' }); if (x.ok) depois && depois(); } }, 'Marcar como tratado') : null,
    it.action_status !== 'ignored' ? h('button', { onclick: async () => { const x = await api('POST', '/api/movements/' + it.id + '/action', { status: 'ignored' }); if (x.ok) depois && depois(); } }, 'Não requer ação') : null,
    it.action_status && it.action_status !== 'open' ? h('button', { onclick: async () => { const x = await api('POST', '/api/movements/' + it.id + '/action', { status: 'open' }); if (x.ok) depois && depois(); } }, 'Reabrir') : null) : null;
  return h('div', { class: 'leitura' },
    h('div', { class: 'linha' }, h('strong', {}, r.ato.rotulo), h('span', { class: 'etq' }, 'confiança da leitura: ' + r.confianca), pilulaUrgencia(r.urgencia, r.proximo)),
    h('dl', { class: 'capa' }, ...linhas.flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, v)])),
    acoesBotoes,
    h('details', {}, h('summary', {}, 'Ver o texto inteiro da publicação'), h('pre', {}, it.description || '')),
    h('p', { class: 'suave' }, 'Leitura automática do texto. Pode errar ou deixar passar algo: não substitui a conferência do advogado nem o controle de prazos.'));
}

async function telaAcoes() {
  const estado = { dias: 120, urg: '', sit: 'abertas', acao: '', quem: '', imp: '', proc: '', trib: '', q: '', ordem: 'urgencia' };
  const lista = h('div'), kpis = h('div', { class: 'kpis' }), filtros = h('div', { class: 'filtros' });
  const ordemUrg = { vencido: 0, hoje: 1, ate3: 2, ate7: 3, depois: 4, sem_prazo: 5 };
  let itens = [];
  const sel = (rotulo, chave, opcoes) => { const e = h('select', { 'aria-label': rotulo, onchange: () => { estado[chave] = e.value; desenhar(); } }, h('option', { value: '' }, rotulo), ...opcoes.map(([v, t]) => h('option', { value: v }, t))); return e; };
  async function carregar() {
    lista.replaceChildren(h('p', { class: 'suave' }, 'Lendo as publicações…'));
    const r = await api('GET', '/api/feed', null, { days: String(estado.dias) });
    if (!r.ok) return lista.replaceChildren(aviso(r.error, 'erro'));
    itens = r.data.map((it) => ({ it, r: analisarItem(it) }));
    montarFiltros(); desenhar();
  }
  function montarFiltros() {
    const unicos = (f) => [...new Map(itens.map(f).filter(Boolean)).entries()].sort((a, b) => String(a[1]).localeCompare(String(b[1])));
    const busca = h('input', { type: 'search', placeholder: 'Buscar no texto, cliente ou número…', 'aria-label': 'Buscar', value: estado.q, oninput: (e) => { estado.q = e.target.value; desenhar(); } });
    const periodo = h('select', { 'aria-label': 'Período', onchange: (e) => { estado.dias = Number(e.target.value); carregar(); } }, ...[[30, 'Últimos 30 dias'], [120, 'Últimos 120 dias'], [365, 'Último ano']].map(([v, t]) => h('option', { value: v, selected: v === estado.dias }, t)));
    filtros.replaceChildren(busca,
      sel('Situação: abertas', 'sit', [['tratadas', 'Já tratadas'], ['ignoradas', 'Sem ação necessária'], ['todas', 'Todas']]),
      sel('Qualquer urgência', 'urg', Object.entries(window.Analise.ROTULO_URGENCIA)),
      sel('Qualquer ação', 'acao', unicos(({ r }) => r.proximo || r.acoes[0] ? [(r.proximo || r.acoes[0]).id, (r.proximo || r.acoes[0]).rotulo.replace(/ até a audiência| às .*/, '')] : null)),
      sel('Quem tem de agir', 'quem', [['A', 'Parte autora / reclamante'], ['P', 'Parte ré / reclamada'], ['AP', 'Ambas as partes'], ['perito', 'Perito'], ['juizo', 'Secretaria / juízo']]),
      sel('Qualquer impacto', 'imp', Object.entries(ROT_IMPACTO).map(([k, v]) => [k, v[1]])),
      sel('Todos os processos', 'proc', unicos(({ it }) => [String(it.process_id), it.cnj + (it.client_label ? ' — ' + it.client_label : '')])),
      sel('Todos os tribunais', 'trib', unicos(({ it }) => [it.tribunal, it.tribunal])), periodo);
  }
  const passa = ({ it, r }) => {
    const sit = it.action_status;
    if (estado.sit === 'abertas' && sit !== 'open') return false; if (estado.sit === 'tratadas' && sit !== 'done') return false; if (estado.sit === 'ignoradas' && sit !== 'ignored') return false;
    if (estado.urg && r.urgencia !== estado.urg) return false;
    if (estado.acao && !r.acoes.some((a) => a.id === estado.acao)) return false;
    if (estado.quem) { const f = estado.quem; if (!r.acoes.some((a) => f === 'perito' ? a.quem.includes('perito') : f === 'juizo' ? a.quem.some((x) => /secretaria|Minist/.test(x)) : a.lado === f || a.lado === 'AP')) return false; }
    if (estado.imp && r.impacto.grau !== estado.imp) return false;
    if (estado.proc && String(it.process_id) !== estado.proc) return false;
    if (estado.trib && it.tribunal !== estado.trib) return false;
    if (estado.q && !((it.description || '') + ' ' + it.cnj + ' ' + (it.client_label || '')).toLowerCase().includes(estado.q.toLowerCase())) return false;
    return true;
  };
  function desenhar() {
    const abertos = itens.filter(({ it }) => it.action_status === 'open');
    const cont = (u) => abertos.filter(({ r }) => r.urgencia === u).length;
    kpis.replaceChildren(...[['vencido', 'Vencidos', 'verm'], ['hoje', 'Vencem hoje', 'verm'], ['ate3', 'Até 3 dias', 'amar'], ['ate7', 'Até 7 dias', 'amar'], ['depois', 'Mais de 7 dias', 'azul'], ['sem_prazo', 'Sem prazo identificado', '']].map(([u, t, c]) =>
      h('button', { class: 'kpi-mini ' + c + (estado.urg === u ? ' ativo' : ''), onclick: () => { estado.urg = estado.urg === u ? '' : u; if (!estado.urg || estado.sit !== 'abertas') estado.sit = 'abertas'; montarFiltros(); desenhar(); } }, h('span', { class: 'num' }, String(cont(u))), h('span', {}, t))));
    const vis = itens.filter(passa).sort((a, b) => estado.ordem === 'data' ? (b.it.occurred_at || '').localeCompare(a.it.occurred_at || '')
      : (ordemUrg[a.r.urgencia] - ordemUrg[b.r.urgencia]) || ((a.r.proximo && a.r.proximo.vence) || '9').localeCompare((b.r.proximo && b.r.proximo.vence) || '9'));
    lista.replaceChildren(h('p', { class: 'suave' }, vis.length + ' publicação(ões) · ordenadas por ',
      h('select', { 'aria-label': 'Ordem', onchange: (e) => { estado.ordem = e.target.value; desenhar(); } }, h('option', { value: 'urgencia', selected: estado.ordem === 'urgencia' }, 'urgência'), h('option', { value: 'data', selected: estado.ordem === 'data' }, 'data de publicação'))),
      ...(vis.length ? vis.map(({ it, r }) => linhaAcao(it, r, carregar)) : [h('p', { class: 'vazio' }, itens.length ? 'Nenhuma publicação com esses filtros.' : 'Nenhuma publicação do Diário no período. Cadastre processos para o sistema acompanhar.')]));
  }
  app.replaceChildren(h('h1', {}, 'Ações e prazos'),
    aviso('Leitura automática do texto das publicações (Diário). Os prazos são ESTIMADOS em dias úteis, sem feriados locais: confira sempre o ato original antes de agir ou de confiar numa data.', 'aviso'),
    kpis, h('div', { class: 'cartao' }, filtros), lista);
  carregar();
}

function linhaAcao(it, r, recarregar) {
  const prox = r.proximo || r.acoes[0], [corI, rotI] = ROT_IMPACTO[r.impacto.grau] || ['', ''];
  const painel = h('div', { hidden: true }); let aberto = false;
  const alternar = () => { aberto = !aberto; painel.hidden = !aberto; if (aberto && !painel.childNodes.length) painel.append(cartaoLeitura(it, r, recarregar)); botao.textContent = aberto ? 'Fechar' : 'Detalhes'; };
  const botao = h('button', { onclick: alternar }, 'Detalhes');
  return h('div', { class: 'cartao acao u-' + r.urgencia + (it.action_status !== 'open' ? ' feita' : '') },
    h('div', { class: 'acao-topo' },
      h('div', { class: 'acao-prazo' }, pilulaUrgencia(r.urgencia, null), h('div', { class: 'data' }, prox && prox.vence ? dataBR(prox.vence) + (prox.hora ? ' ' + prox.hora : '') : '—'), h('div', { class: 'suave' }, prox && prox.estimado ? 'prazo estimado' : prox && prox.vence ? 'data marcada' : '')),
      h('div', { class: 'acao-meio' },
        h('strong', {}, prox ? prox.rotulo : r.ato.rotulo), h('div', { class: 'suave' }, r.ato.rotulo + ' · publicado em ' + dataBR(it.occurred_at)),
        h('div', {}, h('a', { href: '#/processos/' + it.process_id }, it.cnj), ' ', h('span', { class: 'etq' }, it.tribunal), it.client_label ? h('span', { class: 'suave' }, ' ' + it.client_label) : null),
        h('div', { class: 'linha' }, h('span', { class: 'suave' }, 'Quem: ' + (prox ? prox.quem.join(', ') : '—')), h('span', { class: 'etq ' + corI }, rotI),
          r.riscos.length ? h('span', { class: 'etq amar' }, r.riscos.length + ' risco(s)') : null, r.acoes.length > 1 ? h('span', { class: 'etq' }, r.acoes.length + ' ações') : null,
          it.action_status === 'done' ? h('span', { class: 'etq verde' }, 'tratada') : it.action_status === 'ignored' ? h('span', { class: 'etq' }, 'sem ação necessária') : null)),
      botao), painel);
}

// resumo das ações abertas de um processo (topo da página do processo)
function resumoAcoesProcesso(p, movimentos, depois) {
  const abertos = movimentos.filter((m) => m.source === 'djen').map((m) => ({ it: { ...m, process_id: p.id, client_side: p.client_side, action_status: m.action_status || 'open' }, r: null }))
    .map((x) => ({ ...x, r: analisarItem(x.it) })).filter((x) => x.it.action_status === 'open' && x.r.urgencia !== 'sem_prazo')
    .sort((a, b) => ((a.r.proximo && a.r.proximo.vence) || '9').localeCompare((b.r.proximo && b.r.proximo.vence) || '9')).slice(0, 5);
  const lado = h('div', { class: 'linha' }, h('span', { class: 'suave' }, 'Seu escritório atua pelo: '),
    h('select', { 'aria-label': 'Quem você representa', disabled: !pode('process.update'), onchange: async (e) => { const x = await api('PATCH', '/api/processes/' + p.id, { client_side: e.target.value }); if (x.ok) depois(); } },
      h('option', { value: '' }, 'não informado'), h('option', { value: 'A', selected: p.client_side === 'A' }, 'autor / reclamante'), h('option', { value: 'P', selected: p.client_side === 'P' }, 'réu / reclamado')),
    h('span', { class: 'suave' }, '(com isso o impacto de cada ato aparece como favorável ou desfavorável)'));
  return h('div', {}, lado, abertos.length ? h('div', {}, h('h2', {}, 'Ações com data neste processo'), ...abertos.map(({ it, r }) => h('div', { class: 'linha', style: 'margin:4px 0' }, pilulaUrgencia(r.urgencia, r.proximo), h('strong', {}, r.proximo.rotulo), h('span', { class: 'suave' }, 'publicado em ' + dataBR(it.occurred_at))))) : null);
}

// partes, advogados e órgão reunidos das publicações do Diário já guardadas
function capaDosMovimentos(movs) {
  const partes = new Map(), advs = new Map(); let orgao = '', classe = '';
  for (const m of movs) { const md = m.metadata || {};
    if (!orgao && md.orgao) orgao = md.orgao; if (!classe && md.classe) classe = md.classe;
    for (const x of md.partes || []) partes.set(x.polo + '|' + x.nome, x);
    for (const x of md.advogados || []) advs.set(x.uf + x.oab + '|' + x.nome, x); }
  const linhas = [['Órgão', orgao], ['Classe', classe],
    ['Parte(s)', [...partes.values()].map((x) => x.nome + (x.polo ? ' (' + (x.polo === 'A' ? 'ativo' : 'passivo') + ')' : '')).join('; ')],
    ['Advogado(s)', [...advs.values()].map((x) => x.nome + (x.oab ? ' — OAB ' + x.uf + ' ' + x.oab : '')).join('; ')]].filter(([, v]) => v);
  return linhas.length ? h('dl', { class: 'capa' }, ...linhas.flatMap(([k, v]) => [h('dt', {}, k), h('dd', {}, v)])) : null;
}
const linhaOrigem = (md) => md && (md.orgao || md.meio) ? h('div', { class: 'suave' }, [md.orgao && 'Órgão: ' + md.orgao, md.tipo_documento && 'Documento: ' + md.tipo_documento, md.meio && 'Meio: ' + md.meio].filter(Boolean).join(' · ')) : null;

async function telaProcesso(id) {
  const r = await api('GET', '/api/processes/' + id);
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'), h('p', {}, h('a', { href: '#/processos' }, '← Voltar')));
  const { process: p, movements, events } = r.data;
  const msg = h('div');
  const acao = (rotulo, caminho, opcoes) => h('button', { class: opcoes && opcoes.perigo ? 'perigo' : '', onclick: async () => {
    if (opcoes && opcoes.confirmar && !confirm(opcoes.confirmar)) return;
    const x = await api('POST', '/api/processes/' + id + caminho);
    if (x.ok) telaProcesso(id); else msg.replaceChildren(aviso(x.error, 'erro'));
  } }, rotulo);
  app.replaceChildren(
    h('p', {}, h('a', { href: '#/processos' }, '← Processos')),
    h('div', { class: 'cartao' },
      h('div', { class: 'linha entre' }, h('h1', {}, p.cnj), estadoMonitoramento(p)),
      h('p', { class: 'suave' }, p.tribunal + ' · cliente: ' + (p.client_label || '—') + ' · incluído em ' + dataBR(p.created_at)),
      h('p', {}, 'Última consulta bem-sucedida: ', h('strong', {}, dataHoraBR(p.last_success_at)), ' · última tentativa: ', dataHoraBR(p.last_attempt_at)),
      p.last_error ? aviso('Última tentativa falhou: ' + p.last_error + ' — este processo NÃO está atualizado.', 'erro') : null,
      p.notes ? h('p', {}, h('span', { class: 'suave' }, 'Observações: '), p.notes) : null,
      capaDosMovimentos(movements),
      resumoAcoesProcesso(p, movements, () => telaProcesso(id)),
      pode('process.update') ? h('div', { class: 'linha' },
        p.status === 'active' ? acao('Consultar agora', '/check') : null,
        p.status === 'active' ? acao('Pausar', '/pause') : null,
        p.status === 'paused' ? acao('Retomar', '/resume') : null,
        pode('process.archive') && p.status !== 'archived' ? acao('Arquivar', '/archive', { perigo: true, confirmar: 'Arquivar para de monitorar e libera uma vaga do plano. O histórico continua guardado. Continuar?' }) : null) : null,
      msg),
    h('div', { class: 'cartao' }, h('h2', {}, 'Histórico de movimentações'),
      movements.length ? movements.map((m) => h('div', { class: 'mov' },
        h('div', { class: 'linha' }, h('strong', {}, 'Movimentação de ' + dataBR(m.occurred_at)), h('span', { class: 'suave' }, 'capturada em ' + dataHoraBR(m.captured_at)),
          h('span', { class: 'etq azul' }, NOME_FONTE[m.source] || m.source), m.kind ? h('span', { class: 'etq' }, m.kind) : null, m.is_baseline ? h('span', { class: 'etq' }, 'histórico inicial') : null,
          link(m.source_url) ? h('a', { href: link(m.source_url), target: '_blank', rel: 'noopener noreferrer' }, 'Inteiro teor') : null),
        linhaOrigem(m.metadata),
        m.source === 'djen' ? h('details', { class: 'leitura-det' }, h('summary', {}, 'Leitura automática: o que fazer, quem, prazo, impacto e riscos'),
          cartaoLeitura({ ...m, process_id: p.id, client_side: p.client_side, action_status: m.action_status || 'open' }, analisarItem({ ...m, client_side: p.client_side }), () => telaProcesso(id))) : null,
        h('pre', {}, m.description)))
        : h('p', { class: 'vazio' }, p.last_success_at ? 'A fonte não retornou movimentações para este processo.' : 'Ainda sem histórico: a primeira consulta não foi concluída.')),
    h('div', { class: 'cartao' }, h('h2', {}, 'Tentativas de consulta'),
      events.length ? h('table', {}, h('thead', {}, h('tr', {}, ...['Quando', 'Resultado', 'Detalhe'].map((t) => h('th', {}, t)))),
        h('tbody', {}, ...events.map((e) => h('tr', {}, h('td', {}, dataHoraBR(e.finished_at)),
          h('td', {}, h('span', { class: 'etq ' + (e.outcome === 'new_movements' ? 'verde' : e.outcome === 'no_news' ? '' : 'verm') }, { new_movements: 'Novidade', no_news: 'Sem novidade', source_error: 'Falha da fonte', invalid_response: 'Resposta inválida' }[e.outcome])),
          h('td', {}, e.message || ''))))) : h('p', { class: 'vazio' }, 'Nenhuma tentativa registrada ainda.')));
}

// ── notificações, equipe, plano ──────────────────────────────────────────────
async function telaNotificacoes() {
  const r = await api('GET', '/api/notifications');
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'));
  app.replaceChildren(h('h1', {}, 'Notificações'),
    h('p', { class: 'suave' }, 'Um alerta indica que o sistema capturou uma movimentação nova. Não significa intimação nem prazo calculado.'),
    h('div', { class: 'cartao' }, r.data.items.length ? r.data.items.map((n) => h('div', { class: 'mov' },
      h('div', { class: 'linha' }, n.status !== 'read' ? h('span', { class: 'etq azul' }, 'nova') : null, h('a', { href: '#/processos/' + n.process_id }, n.cnj),
        h('span', { class: 'etq' }, n.tribunal), h('span', { class: 'suave' }, 'movimentação de ' + dataBR(n.occurred_at) + ' · capturada em ' + dataHoraBR(n.captured_at)),
        n.status !== 'read' ? h('button', { class: 'link', onclick: async () => { await api('POST', '/api/notifications/' + n.id + '/read'); telaNotificacoes(); } }, 'marcar como lida') : null),
      h('pre', {}, (n.description || '').slice(0, 400)))) : h('p', { class: 'vazio' }, 'Nenhuma notificação.')));
}

async function telaEquipe(recado) {
  const r = await api('GET', '/api/team');
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'));
  const msg = h('div', {}, recado || null);   // o recado sobrevive à recarga da lista
  const papel = { office_admin: 'Administrador', lawyer: 'Advogado(a)', assistant: 'Assistente' };
  const convite = pode('members.manage') ? h('form', { class: 'linha', onsubmit: async (e) => {
    e.preventDefault(); const f = e.target;
    const x = await api('POST', '/api/team/invite', { name: f.name.value, email: f.email.value, role: f.role.value });
    const texto = x.ok ? (x.data.email_sent ? 'Convite enviado por e-mail.' : 'Convite criado, mas o e-mail não pôde ser enviado. Avise a pessoa por outro meio.') : x.error;
    if (x.ok) return telaEquipe(aviso(texto, 'ok'));
    msg.replaceChildren(aviso(texto, 'erro'));
  } }, h('input', { name: 'name', placeholder: 'Nome', required: true }), h('input', { name: 'email', type: 'email', placeholder: 'E-mail', required: true }),
    h('select', { name: 'role' }, h('option', { value: 'lawyer' }, 'Advogado(a)'), h('option', { value: 'assistant' }, 'Assistente'), h('option', { value: 'office_admin' }, 'Administrador')),
    h('button', { class: 'principal' }, 'Convidar')) : null;
  app.replaceChildren(h('h1', {}, 'Equipe'),
    convite ? h('div', { class: 'cartao' }, h('h2', {}, 'Convidar pessoa'), convite, msg) : null,
    h('div', { class: 'cartao' }, h('table', {}, h('thead', {}, h('tr', {}, ...['Nome', 'E-mail', 'Papel', 'Situação', ''].map((t) => h('th', {}, t)))),
      h('tbody', {}, ...r.data.map((m) => h('tr', {}, h('td', {}, m.name), h('td', {}, m.email), h('td', {}, papel[m.role]),
        h('td', {}, h('span', { class: 'etq ' + (m.status === 'active' ? 'verde' : m.status === 'invited' ? 'amar' : '') }, { active: 'Ativo', invited: 'Convite pendente', disabled: 'Desativado' }[m.status])),
        h('td', {}, pode('members.manage') && m.status !== 'disabled' && m.user_id !== sessao.user.id ? h('button', { class: 'perigo', onclick: async () => {
          if (!confirm('Desativar o acesso de ' + m.name + '?')) return; const x = await api('POST', '/api/team/' + m.user_id + '/disable'); if (!x.ok) alert(x.error); telaEquipe(); } }, 'Desativar') : null)))))));
}

async function telaPlano() {
  const [r, bill] = await Promise.all([api('GET', '/api/plan'), api('GET', '/api/billing')]);
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'));
  const p = r.data, e = bill.ok ? bill.data : null, msg = h('div'), admin = pode('members.manage');
  const reaisC = (c) => (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  const uso = (rotulo, usado, max) => h('div', { class: 'cartao' }, h('h2', {}, rotulo), h('div', { class: 'numero' }, max >= 100000 ? usado + ' (sem limite)' : usado + ' / ' + max), max >= 100000 ? null : h('div', { class: 'barra' }, h('i', { style: 'width:' + Math.min(100, Math.round((usado / Math.max(1, max)) * 100)) + '%' })));
  const porBusca = p.billing_model === 'per_search';
  const horario = h('input', { type: 'time', id: 'horario-buscas', value: e && e.search_time ? e.search_time : '', disabled: !admin, 'aria-label': 'Horário das buscas automáticas' });
  const teto = h('input', { type: 'text', id: 'teto-gasto', inputmode: 'decimal', placeholder: 'sem teto', value: e && e.spend_cap_cents ? String(e.spend_cap_cents / 100).replace('.', ',') : '', disabled: !admin, 'aria-label': 'Teto de gasto por mês (R$)', size: 10 });
  const salvar = async (corpo, ok) => { const x = await api('PATCH', '/api/billing/settings', corpo); if (x.ok) { telaPlano(); } else msg.replaceChildren(aviso(x.error, 'erro')); };
  const pedido = p.open_request;
  const blocoContratar = h('div', { class: 'cartao' }, h('h2', {}, 'Como contratar e pagar'),
    p.status === 'trialing' ? h('p', {}, 'Você está no período de teste: as buscas não são cobradas até ' + dataBR(p.current_period_end) + '.') : null,
    p.payment_info ? h('pre', { style: 'white-space:pre-wrap;font:inherit' }, p.payment_info) : h('p', { class: 'suave' }, 'As instruções de pagamento serão informadas pelo suporte.'),
    p.support_contact ? h('p', { class: 'suave' }, 'Dúvidas ou ajuda: ' + p.support_contact) : null,
    pedido ? aviso('Pedido de contratação enviado em ' + dataBR(pedido.created_at) + '. Assim que o pagamento for confirmado, o acesso é liberado.', 'ok')
      : admin ? h('div', { class: 'linha' }, h('button', { class: 'principal', onclick: async () => { const x = await api('POST', '/api/plan-request', { plan: p.plan_code }); if (x.ok) telaPlano(); else msg.replaceChildren(aviso(x.error, 'erro')); } }, 'Quero contratar / já paguei'), h('span', { class: 'suave' }, 'Avisa o responsável pelo sistema.')) : null);
  app.replaceChildren(h('h1', {}, 'Plano e assinatura'),
    h('div', { class: 'cartao' }, h('h2', {}, 'Plano contratado'), h('div', { class: 'numero' }, p.plan_name),
      h('p', { class: 'suave' }, 'Situação: ' + { trialing: 'período de teste', active: 'ativa', past_due: 'pagamento em atraso', suspended: 'suspensa', cancelled: 'cancelada' }[p.status] +
        (porBusca ? ' · ' + reaisC(p.price_per_search_cents) + ' por busca bem-sucedida; buscas que falham não são cobradas' : ' · cada processo é consultado no mínimo a cada ' + (p.min_interval_minutes / 60) + ' h'))),
    e ? h('div', { class: 'cartao' }, h('h2', {}, 'Extrato de ' + e.month.split('-').reverse().join('/')),
      h('div', { class: 'kpis' }, h('div', { class: 'kpi-mini azul' }, h('span', { class: 'num' }, String(e.ok)), h('span', {}, 'buscas bem-sucedidas')), h('div', { class: 'kpi-mini' }, h('span', { class: 'num' }, String(e.failed)), h('span', {}, 'falharam (não cobradas)')),
        h('div', { class: 'kpi-mini verde' }, h('span', { class: 'num' }, reaisC(e.amount_cents)), h('span', {}, e.trial ? 'a cobrar (teste grátis)' : 'a cobrar neste mês'))),
      h('p', { class: 'suave' }, 'Processos: ' + e.by_kind.process.ok + ' · OABs: ' + e.by_kind.oab.ok + ' · Consultas avulsas: ' + e.by_kind.lookup.ok + (e.trial && e.would_be_cents ? ' · fora do teste seriam ' + reaisC(e.would_be_cents) : '')),
      porBusca ? h('div', { class: 'linha' }, h('label', { for: 'teto-gasto' }, 'Teto de gasto por mês (R$):'), teto,
        h('button', { disabled: !admin, onclick: () => salvar({ spend_cap_cents: teto.value.trim() === '' ? null : Math.round(Number(teto.value.replace(',', '.')) * 100) }) }, 'Salvar teto'),
        h('span', { class: 'suave' }, 'Ao atingir o teto, as buscas pausam até o mês seguinte.')) : null) : null,
    h('div', { class: 'cartao' }, h('h2', {}, 'Buscas automáticas'),
      h('p', {}, 'Escolha o horário em que o sistema faz todas as buscas do escritório (processos e OABs), uma vez por dia. Horário de Brasília.'),
      h('div', { class: 'linha' }, h('label', { for: 'horario-buscas' }, 'Todos os dias às'), horario,
        h('button', { class: 'principal', disabled: !admin, onclick: () => { if (!horario.value) return msg.replaceChildren(aviso('Escolha um horário.', 'erro')); salvar({ search_time: horario.value }); } }, 'Salvar horário'),
        e && e.search_time ? h('button', { disabled: !admin, onclick: () => salvar({ search_time: '' }) }, 'Voltar ao intervalo do plano') : null),
      h('p', { class: 'suave' }, e && e.search_time ? 'Hoje: todas as buscas rodam às ' + e.search_time + '.' : 'Hoje: cada processo é buscado conforme o intervalo do plano.' + (porBusca ? ' Para controlar o gasto, prefira um horário fixo.' : '')), msg),
    blocoContratar,
    h('div', { class: 'grade' }, uso('Processos', p.processes_used, p.max_processes), uso('Usuários', p.users_used, p.max_users), uso('OABs', p.oabs_used, p.max_oabs)));
}

// ── OABs, publicações e ações por UF ─────────────────────────────────────────
const UFS = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SE','SP','TO'];
const GRUPOS = { estadual: 'Estadual', federal: 'Federal', trabalho: 'Trabalho', outros: 'Outras' };

async function telaOabs(recado) {
  const r = await api('GET', '/api/oabs');
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'));
  const msg = h('div', {}, recado || null);
  const gerir = pode('oab.manage');
  const suplementares = h('select', { name: 'supplementary_ufs', multiple: true, size: 6, title: 'Segure Ctrl para marcar várias' }, ...UFS.map((u) => h('option', { value: u }, u)));
  const novo = gerir ? h('form', { class: 'linha', onsubmit: async (e) => {
    e.preventDefault(); const f = e.target;
    const x = await api('POST', '/api/oabs', { oab_number: f.oab_number.value, oab_uf: f.oab_uf.value, lawyer_name: f.lawyer_name.value,
      supplementary_ufs: [...f.supplementary_ufs.selectedOptions].map((o) => o.value) });
    if (x.ok) return telaOabs(aviso('OAB cadastrada. A primeira busca traz as publicações desde 1º de janeiro, sem gerar alertas.', 'ok'));
    msg.replaceChildren(aviso(x.error, 'erro'));
  } }, h('input', { name: 'lawyer_name', placeholder: 'Nome do advogado(a)', required: true }),
    h('input', { name: 'oab_number', placeholder: 'Nº da OAB', required: true, size: 9 }),
    h('select', { name: 'oab_uf', required: true }, ...UFS.map((u) => h('option', { value: u }, u))),
    h('label', { class: 'suave' }, 'Inscrições suplementares que já tem: ', suplementares),
    h('button', { class: 'principal' }, 'Cadastrar OAB')) : null;
  const situacao = (o) => o.status === 'paused' ? h('span', { class: 'etq' }, 'Pausada')
    : o.last_error ? h('span', { class: 'etq verm', title: o.last_error }, 'Falha na consulta')
    : !o.last_success_at ? h('span', { class: 'etq amar' }, 'Aguardando 1ª consulta') : h('span', { class: 'etq verde' }, 'Monitorada');
  app.replaceChildren(h('h1', {}, 'Advogados (OAB)'),
    h('p', { class: 'suave' }, 'O sistema consulta o diário oficial (DJEN) pelo número da OAB e guarda as publicações em nome do advogado.'),
    novo ? h('div', { class: 'cartao' }, h('h2', {}, 'Cadastrar OAB'), novo, msg) : null,
    h('div', { class: 'cartao', style: 'overflow-x:auto' }, h('table', { class: 'larga' }, h('thead', {}, h('tr', {}, ...['Advogado(a)', 'OAB', 'Suplementares', 'Publicações', 'Última consulta', 'Situação', ''].map((t) => h('th', {}, t)))),
      h('tbody', {}, ...(r.data.length ? r.data.map((o) => h('tr', {}, h('td', {}, o.lawyer_name), h('td', {}, o.oab_number + '/' + o.oab_uf),
        h('td', {}, o.supplementary_ufs.join(', ') || '—'), h('td', {}, String(o.publications)), h('td', {}, dataHoraBR(o.last_success_at)), h('td', {}, situacao(o)),
        h('td', {}, gerir ? h('div', { class: 'linha' },
          o.status === 'active' ? h('button', { onclick: async () => { await api('POST', '/api/oabs/' + o.id + '/check'); telaOabs(aviso('Consulta agendada para os próximos minutos.', 'ok')); } }, 'Consultar agora') : null,
          h('button', { onclick: async () => { await api('POST', '/api/oabs/' + o.id + (o.status === 'paused' ? '/resume' : '/pause')); telaOabs(); } }, o.status === 'paused' ? 'Retomar' : 'Pausar'),
          h('button', { onclick: async () => {
            const atual = o.supplementary_ufs.join(',');
            const novo = prompt('UFs onde ' + o.lawyer_name + ' JÁ tem inscrição suplementar (separe por vírgula; vazio = nenhuma):', atual);
            if (novo === null) return;
            const x = await api('PATCH', '/api/oabs/' + o.id, { supplementary_ufs: novo.split(',').map((u) => u.trim()).filter(Boolean) });
            telaOabs(x.ok ? aviso('Inscrições atualizadas.', 'ok') : aviso(x.error, 'erro'));
          } }, 'Suplementares'),
          h('button', { class: 'perigo', onclick: async () => { if (!confirm('Arquivar a OAB de ' + o.lawyer_name + '? As publicações já guardadas continuam.')) return; await api('POST', '/api/oabs/' + o.id + '/archive'); telaOabs(); } }, 'Arquivar')) : null)))
        : [h('tr', {}, h('td', { colspan: 7, class: 'vazio' }, 'Nenhuma OAB cadastrada.'))])))));
}

async function telaPublicacoes(consulta) {
  const filtros = { q: '', oab: '', uf: '', group: '', year: '', new_only: '', page: '1', ...consulta };
  const lista = h('div');
  const total = h('span', { class: 'suave' });
  const msg = h('div');
  const oabs = (await api('GET', '/api/oabs')).data || [];
  const carregar = async () => {
    const q = Object.fromEntries(Object.entries(filtros).filter(([, v]) => v));
    const r = await api('GET', '/api/publications', null, q);
    if (!r.ok) return lista.replaceChildren(aviso(r.error, 'erro'));
    total.textContent = r.data.total + ' publicação(ões)';
    lista.replaceChildren(...(r.data.items.length ? r.data.items.map((p) => h('div', { class: 'mov' },
      h('div', { class: 'linha' }, p.is_baseline ? null : h('span', { class: 'etq azul' }, 'nova'), h('strong', {}, p.cnj), h('span', { class: 'etq' }, p.tribunal),
        h('span', { class: 'etq' }, GRUPOS[p.group]), h('span', { class: 'etq' }, p.uf || 'UF indeterminada'),
        h('span', { class: 'suave' }, 'publicada em ' + dataBR(p.published_at) + ' · ' + p.lawyer_name + ' (OAB ' + p.oab_number + '/' + p.oab_uf + ')'),
        link(p.source_url) ? h('a', { href: link(p.source_url), target: '_blank', rel: 'noopener noreferrer' }, 'Fonte oficial') : null,
        p.is_monitored ? h('span', { class: 'etq verde' }, 'já monitorado') : (pode('process.create') ? h('button', { class: 'link', onclick: async () => {
          const x = await api('POST', '/api/publications/' + p.id + '/monitor');
          msg.replaceChildren(aviso(x.ok ? 'Processo ' + p.cnj + ' cadastrado para monitoramento.' : x.error, x.ok ? 'ok' : 'erro')); if (x.ok) carregar();
        } }, 'Monitorar este processo') : null)),
      h('pre', {}, (p.excerpt || '').slice(0, 400)))) : [h('p', { class: 'vazio' }, 'Nenhuma publicação encontrada.')]));
  };
  const selecao = (nome, opcoes) => h('select', { onchange: (e) => { filtros[nome] = e.target.value; filtros.page = '1'; carregar(); } },
    ...opcoes.map(([v, t]) => h('option', { value: v, selected: String(filtros[nome]) === String(v) }, t)));
  app.replaceChildren(h('h1', {}, 'Publicações por OAB'),
    h('p', { class: 'suave' }, 'O que o diário oficial publicou em nome dos advogados cadastrados. Nem toda publicação é prazo: leia o texto na fonte oficial.'),
    h('div', { class: 'cartao' }, h('div', { class: 'filtros' },
      h('input', { type: 'search', placeholder: 'Buscar por número ou texto…', value: filtros.q, oninput: (e) => { filtros.q = e.target.value; filtros.page = '1'; carregar(); } }),
      selecao('oab', [['', 'Todos os advogados'], ...oabs.map((o) => [o.id, o.lawyer_name])]),
      selecao('uf', [['', 'Todas as UFs'], ...UFS.map((u) => [u, u])]),
      selecao('group', [['', 'Todas as justiças'], ...Object.entries(GRUPOS)]),
      selecao('new_only', [['', 'Todas'], ['1', 'Só as novas']]), total,
      h('button', { onclick: async () => { const e = await baixar('/api/export/publications', 'publicacoes.csv', filtros.year ? { year: filtros.year } : null); if (e) msg.replaceChildren(aviso(e, 'erro')); } }, 'Exportar CSV')), msg, lista));
  carregar();
}

const SUBGRUPOS = [['estadual', 'JE', 'sub-je', 'var(--azul)'], ['federal', 'JF', 'sub-jf', 'var(--verde)'], ['trabalho', 'JT', 'sub-jt', 'var(--roxo)']];

// bloco "Ações por UF": indicadores, alerta, tabela por advogado (JE/JF/JT) e barras por estado
function blocoUf(d, aoMudarAno) {
  const anos = [...new Set([d.year, ...d.years])].sort((a, b) => b - a);
  const risco = new Set(d.alerts.map((a) => a.oab_id + '|' + a.uf));
  const maximo = Math.max(1, ...d.rows.map((x) => x.total));
  const tile = (r, v, cls) => h('div', { class: 'tile' }, h('div', { class: 'r' }, r), h('div', { class: 'v ' + (cls || '') }, String(v)));
  const topo = h('div', { class: 'cab' }, h('h3', {}, 'Ações por UF ', h('span', {}, '· por advogado (OAB) · Estadual (JE) × Federal (JF) × Trabalho (JT)')),
    h('label', { class: 'linha suave' }, 'Ano ', h('select', { onchange: (e) => aoMudarAno(Number(e.target.value)) }, ...anos.map((a) => h('option', { value: a, selected: a === d.year }, String(a))))));
  const tiles = h('div', { class: 'tiles' }, tile('Total de ações', d.totals.total), tile('Estadual (JE)', d.totals.estadual, 'sub-je'), tile('Federal (JF)', d.totals.federal, 'sub-jf'),
    tile('Trabalho (JT)', d.totals.trabalho, 'sub-jt'), tile('Estados (UF)', d.totals.states));
  const banner = d.alerts.length ? h('div', { class: 'msg alerta-uf' }, icone('alert'), h('div', {},
    h('strong', {}, d.alerts.length + ' situação(ões) com mais de ' + d.threshold + ' ações no ano'), ' numa UF onde o advogado não tem inscrição na OAB: ', h('strong', {}, 'pode exigir inscrição suplementar'), '. ',
    h('a', { href: '#/alertas' }, 'Ver detalhes'))) : null;
  const cabAdv = d.oabs.map((o) => h('th', { colspan: 3, class: 'adv manter' }, o.name, h('small', {}, 'OAB ' + o.uf + ' ' + o.number)));
  const tabela = h('table', { class: 'larga' }, h('thead', {},
    h('tr', {}, h('th', { rowspan: 2, class: 'manter' }, 'UF'), ...cabAdv, h('th', { rowspan: 2, class: 'manter' }, 'Total UF')),
    h('tr', {}, ...d.oabs.flatMap(() => SUBGRUPOS.map(([, t, c]) => h('th', { class: c + ' manter' }, t))))),
    h('tbody', {}, ...(d.rows.length ? d.rows.map((row) => h('tr', {}, h('td', { class: 'manter' }, row.uf),
      ...d.oabs.flatMap((o) => { const v = row.by_oab[o.id]; const r = risco.has(o.id + '|' + row.uf);
        return SUBGRUPOS.map(([g]) => { const n = v ? v[g] : 0; return h('td', { class: 'manter ' + (r ? 'risco' : n ? '' : 'zero') }, n ? String(n) : '·'); }); }),
      h('td', { class: 'tot manter' }, String(row.total))))
      : [h('tr', {}, h('td', { colspan: 2 + d.oabs.length * 3, class: 'vazio' }, 'Sem publicações em ' + d.year + '. Cadastre uma OAB e aguarde a primeira consulta.'))])));
  const grafico = h('div', { class: 'grafico' }, h('h4', {}, 'Estados com mais ações', h('span', { class: 'leg' }, ...SUBGRUPOS.flatMap(([, t, , cor]) => [h('i', { style: 'background:' + cor }), t]))),
    ...d.rows.slice(0, 12).map((row) => h('div', { class: 'bar-linha' }, h('span', {}, row.uf),
      h('div', { class: 'bar-trilho' }, ...SUBGRUPOS.map(([g, , , cor]) => h('i', { style: 'width:' + (row.groups[g] / maximo * 100) + '%;background:' + cor, title: row.groups[g] + ' ' + g }))), h('b', {}, String(row.total)))),
    h('div', { class: 'por-adv' }, h('h4', {}, 'Total por advogado'), ...d.oabs.map((o) => h('div', { class: 'l' }, h('span', {}, o.name), h('strong', {}, String(d.rows.reduce((t, r) => t + (r.by_oab[o.id] ? r.by_oab[o.id].total : 0), 0)))))));
  return h('div', { class: 'cartao secao-uf' }, topo, tiles, banner,
    h('div', { class: 'uf-corpo' }, h('div', { class: 'uf-tabela' }, tabela), grafico),
    h('p', { class: 'suave' }, d.notice + (d.totals.outros ? ' Inclui ' + d.totals.outros + ' em outras justiças.' : '') +
      (d.undetermined ? ' ' + d.undetermined + ' processo(s) estão em tribunais que cobrem mais de um estado (por exemplo TRF1, TRF4, TRT8) e não entram na tabela nem no alerta, porque a UF não pode ser afirmada.' : '')));
}

async function telaUf(ano) {
  const r = await api('GET', '/api/oab-stats', null, ano ? { year: ano } : null);
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'));
  app.replaceChildren(h('h1', {}, 'Ações por UF'), blocoUf(r.data, (a) => telaUf(a)));
}

async function telaAlertas(ano) {
  const r = await api('GET', '/api/oab-stats', null, ano ? { year: ano } : null);
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'));
  const d = r.data;
  const alertas = d.alerts.map((a) => h('div', { class: 'msg alerta-uf' }, icone('alert'), h('div', {},
    h('strong', {}, a.lawyer_name + ' (OAB ' + a.oab_number + '/' + a.oab_uf + ')'), ' tem ' + a.process_count + ' processos diferentes com publicação em ' + a.alert_year + ' no(a) ', h('strong', {}, a.uf),
    ', onde não consta inscrição suplementar. O Estatuto da Advocacia (art. 10, §2º) considera habitual a atuação em mais de ' + d.threshold + ' causas por ano em uma Seccional. ',
    h('span', { class: 'suave' }, 'Indicativo: confira com a Seccional. '),
    !a.seen_at ? h('button', { class: 'link', onclick: async () => { await api('POST', '/api/oab-alerts/' + a.id + '/seen'); await atualizarSelos(); telaAlertas(d.year); } }, 'marcar como visto') : h('span', { class: 'etq' }, 'visto'))));
  app.replaceChildren(h('div', { class: 'linha entre' }, h('h1', {}, 'Alertas OAB'),
    h('label', { class: 'linha suave' }, 'Ano ', h('select', { onchange: (e) => telaAlertas(e.target.value) }, ...[...new Set([d.year, ...d.years])].sort((a, b) => b - a).map((a) => h('option', { value: a, selected: a === d.year }, String(a)))))),
    h('p', { class: 'suave' }, d.notice), ...(alertas.length ? alertas : [h('div', { class: 'cartao' }, h('p', { class: 'vazio' }, 'Nenhum alerta em ' + d.year + '. Os alertas aparecem quando um advogado passa de ' + d.threshold + ' processos no ano numa UF sem inscrição.'))]));
}

// ── faixa de aviso da assinatura ─────────────────────────────────────────────
async function desenharFaixa() {
  const faixa = document.getElementById('faixa');
  faixa.hidden = true;
  if (!pode('plan.view')) return;
  const r = await api('GET', '/api/plan');
  if (!r.ok || !r.data.banner || !r.data.banner.message || r.data.banner.level === 'ok') return;
  faixa.className = 'msg ' + ({ erro: 'erro', amarelo: 'amarelo', info: '' }[r.data.banner.level] || '');
  faixa.replaceChildren(r.data.banner.message + ' ', h('a', { href: '#/plano' }, 'Ver plano'));
  faixa.hidden = false;
}

// ── administração do SaaS (só o dono) ────────────────────────────────────────
const reais = (c) => (c / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const SITUACAO = { trialing: 'Em teste', active: 'Ativa', past_due: 'Vencida', suspended: 'Suspensa', cancelled: 'Cancelada' };
const COR = { trialing: 'azul', active: 'verde', past_due: 'amar', suspended: 'verm', cancelled: '' };


// ── API comercial: clientes, planos, chaves e uso (só administrador da plataforma) ──
const num = (n) => Number(n || 0).toLocaleString('pt-BR');
const ST_API = { active: ['verde', 'Ativo'], suspended: ['verm', 'Suspenso'], cancelled: ['', 'Cancelado'] };
const barraUso = (usado, cota) => { const pct = cota ? Math.min(100, Math.round((usado / cota) * 100)) : 0;
  return h('div', {}, h('div', { class: 'suave' }, num(usado) + ' de ' + num(cota) + ' consultas (' + pct + '%)'), h('div', { class: 'barra' }, h('div', { style: 'height:100%;width:' + pct + '%;background:' + (pct >= 100 ? 'var(--verm)' : pct >= 80 ? 'var(--amar)' : 'var(--verde)') }))); };
const copiar = async (texto, aviso_) => { try { await navigator.clipboard.writeText(texto); aviso_.textContent = 'Copiado.'; } catch (e) { aviso_.textContent = 'Não consegui copiar sozinho: selecione o texto e use Ctrl+C.'; } };

async function telaApiPainel() {
  const [ov, planos, clientes] = await Promise.all([api('GET', '/api/admin/api/overview'), api('GET', '/api/admin/api/plans'), api('GET', '/api/admin/api/clients')]);
  if (!ov.ok) return app.replaceChildren(aviso(ov.error, 'erro'));
  const o = ov.data; let planoEmEdicao = null; const filtro = { q: '', status: '' };
  const kpis = h('div', { class: 'kpis' }, ...[['Clientes ativos', num(o.clients.active), ''], ['Chaves ativas', num(o.keys_active), ''], ['Consultas no mês', num(o.billable_month), 'azul'],
    ['Receita mensal estimada', reais(o.mrr_cents), 'verde'], ['Erros no mês', num(o.errors_month), o.errors_month ? 'amar' : '']].map(([t, v, c]) => h('div', { class: 'kpi-mini ' + c }, h('span', { class: 'num' }, v), h('span', {}, t))));
  const tabelaClientes = h('div'), tabelaPlanos = h('div'), formCliente = h('div', { hidden: true }), formPlano = h('div', { hidden: true });
  async function recarregar() { const [p, c] = await Promise.all([api('GET', '/api/admin/api/plans'), api('GET', '/api/admin/api/clients', null, Object.fromEntries(Object.entries(filtro).filter(([, v]) => v)))]); planos.data = p.data; clientes.data = c.data; desenhar(); }
  function desenharPlanos() {
    tabelaPlanos.replaceChildren(h('div', { style: 'overflow-x:auto' }, h('table', {}, h('thead', {}, h('tr', {}, ...['Plano', 'Preço', 'Cota', 'Limite/min', 'Clientes', ''].map((t) => h('th', {}, t)))),
      h('tbody', {}, ...planos.data.map((pl) => h('tr', {}, h('td', {}, pl.name, Number(pl.active) ? null : h('span', { class: 'etq' }, 'desativado')), h('td', {}, pl.billing_model === 'per_search' ? reais(pl.price_per_search_cents) + ' por busca' : reais(pl.price_cents) + '/mês'), h('td', {}, pl.billing_model === 'per_search' ? 'sem cota (teto de gasto)' : num(pl.monthly_quota) + '/mês'), h('td', {}, num(pl.rate_per_minute)), h('td', {}, num(pl.clients)),
        h('td', {}, h('button', { onclick: () => { planoEmEdicao = pl; desenharFormPlano(); } }, 'Editar'))))))));
  }
  function desenharFormPlano() {
    const pl = planoEmEdicao, msg = h('div');
    const f = h('form', { class: 'empilhado', onsubmit: async (e) => { e.preventDefault(); const d = Object.fromEntries(new FormData(f));
      const corpo = { code: d.code, name: d.name, billing_model: d.billing_model, price_cents: Math.round(Number(String(d.preco || '0').replace(',', '.')) * 100), price_per_search_cents: Math.round(Number(String(d.preco_busca || '0').replace(',', '.')) * 100), monthly_quota: Number(d.monthly_quota), rate_per_minute: Number(d.rate_per_minute), active: d.active === 'on' };
      const r = pl ? await api('PATCH', '/api/admin/api/plans/' + pl.id, corpo) : await api('POST', '/api/admin/api/plans', corpo);
      if (!r.ok) return msg.replaceChildren(aviso(r.error, 'erro')); formPlano.hidden = true; recarregar(); } },
      h('h3', {}, pl ? 'Editar plano ' + pl.name : 'Novo plano'),
      pl ? null : campo('Código (letras minúsculas, ex.: ouro)', 'code', 'text', { pattern: '[a-z0-9_-]{2,40}' }), campo('Nome', 'name', 'text', { value: pl ? pl.name : '' }),
      h('label', {}, 'Como cobrar', h('select', { name: 'billing_model' }, h('option', { value: 'monthly', selected: !pl || pl.billing_model !== 'per_search' }, 'Mensalidade com cota'), h('option', { value: 'per_search', selected: !!pl && pl.billing_model === 'per_search' }, 'Por busca (só as bem-sucedidas)'))),
      campo('Preço por mês (R$) — plano mensal', 'preco', 'text', { value: pl ? String(pl.price_cents / 100).replace('.', ',') : '0', inputmode: 'decimal', required: false }),
      campo('Preço por busca (R$) — plano por busca', 'preco_busca', 'text', { value: pl ? String((pl.price_per_search_cents || 0) / 100).replace('.', ',') : '0,30', inputmode: 'decimal', required: false }),
      campo('Consultas por mês', 'monthly_quota', 'number', { min: 1, value: pl ? pl.monthly_quota : '' }), campo('Consultas por minuto (por chave)', 'rate_per_minute', 'number', { min: 1, value: pl ? pl.rate_per_minute : '' }),
      h('label', { class: 'linha' }, h('input', { type: 'checkbox', name: 'active', checked: pl ? !!Number(pl.active) : true }), 'Plano ativo (pode ser vendido a novos clientes)'),
      h('div', { class: 'linha' }, h('button', { class: 'principal' }, 'Salvar plano'), h('button', { type: 'button', onclick: () => { formPlano.hidden = true; } }, 'Cancelar')), msg);
    formPlano.replaceChildren(h('div', { class: 'cartao' }, f)); formPlano.hidden = false;
  }
  function desenharFormCliente() {
    const msg = h('div'), ativos = planos.data.filter((x) => Number(x.active));
    const f = h('form', { class: 'empilhado', onsubmit: async (e) => { e.preventDefault(); const d = Object.fromEntries(new FormData(f));
      const r = await api('POST', '/api/admin/api/clients', { ...d, plan_id: Number(d.plan_id) }); if (!r.ok) return msg.replaceChildren(aviso(r.error, 'erro')); location.hash = '#/apis/cliente/' + r.data.client.id; } },
      h('h3', {}, 'Novo cliente da API'), campo('Nome do cliente', 'name'), campo('E-mail', 'email', 'email'), campo('CPF ou CNPJ (opcional)', 'document', 'text', { required: false, inputmode: 'numeric' }), campo('Empresa (opcional)', 'company', 'text', { required: false }),
      h('label', {}, 'Plano', h('select', { name: 'plan_id', required: true }, ...ativos.map((x) => h('option', { value: x.id }, x.name + ' — ' + reais(x.price_cents) + '/mês · ' + num(x.monthly_quota) + ' consultas')))),
      h('label', {}, 'Observações (só você vê)', h('textarea', { name: 'notes', rows: 2 })),
      h('div', { class: 'linha' }, h('button', { class: 'principal' }, 'Cadastrar cliente'), h('button', { type: 'button', onclick: () => { formCliente.hidden = true; } }, 'Cancelar')), msg);
    formCliente.replaceChildren(h('div', { class: 'cartao' }, f)); formCliente.hidden = false;
  }
  function desenharClientes() {
    const busca = h('input', { type: 'search', placeholder: 'Buscar cliente, e-mail ou documento…', 'aria-label': 'Buscar', value: filtro.q, oninput: (e) => { filtro.q = e.target.value; clearTimeout(busca.t); busca.t = setTimeout(recarregar, 300); } });
    const st = h('select', { 'aria-label': 'Situação', onchange: (e) => { filtro.status = e.target.value; recarregar(); } }, h('option', { value: '' }, 'Todas as situações'), ...Object.entries(ST_API).map(([k, v]) => h('option', { value: k, selected: filtro.status === k }, v[1])));
    const itens = clientes.data.items;
    tabelaClientes.replaceChildren(h('div', { class: 'filtros' }, busca, st, h('span', { class: 'suave' }, itens.length + ' cliente(s)')),
      h('div', { style: 'overflow-x:auto' }, h('table', {}, h('thead', {}, h('tr', {}, ...['Cliente', 'Plano', 'Uso no mês', 'Chaves', 'Último uso', 'Situação'].map((t) => h('th', {}, t)))),
        h('tbody', {}, ...(itens.length ? itens.map((c) => h('tr', { class: 'clicavel', onclick: () => { location.hash = '#/apis/cliente/' + c.id; } },
          h('td', {}, h('strong', {}, c.name), h('div', { class: 'suave' }, c.email)), h('td', {}, c.plan_name, h('div', { class: 'suave' }, reais(c.price_cents))), h('td', { style: 'min-width:190px' }, barraUso(Number(c.used_month), Number(c.quota)), h('div', { class: 'suave' }, 'A cobrar: ' + reais(c.amount_cents) + (c.billing_model === 'per_search' ? ' (' + num(c.used_month) + ' buscas × ' + reais(c.price_per_search_cents) + ')' : ' (mensalidade)'))),
          h('td', {}, num(c.keys_active)), h('td', {}, c.last_used_at ? dataHoraBR(c.last_used_at) : 'nunca'), h('td', {}, h('span', { class: 'etq ' + ST_API[c.status][0] }, ST_API[c.status][1]))))
          : [h('tr', {}, h('td', { colspan: 6, class: 'vazio' }, 'Nenhum cliente ainda. Clique em “Novo cliente” para cadastrar o primeiro.'))])))));
  }
  function desenhar() { desenharPlanos(); desenharClientes(); }
  const topo = h('div', { class: 'linha entre' }, h('h1', {}, 'API e clientes'), h('div', { class: 'linha' },
    h('a', { href: '/app/api-docs.html', target: '_blank', rel: 'noopener' }, h('button', {}, 'Documentação da API')),
    h('button', { onclick: async () => { const e = await baixar('/api/admin/api/usage.csv', 'api-uso.csv', {}); if (e) alert(e); } }, 'Baixar uso do mês (CSV)'),
    h('button', { class: 'principal', onclick: desenharFormCliente }, 'Novo cliente')));
  app.replaceChildren(topo, h('p', { class: 'suave' }, 'Cadastre seus clientes, escolha o plano, gere a chave (token) e acompanhe o consumo. A cobrança é feita por você: use o CSV de uso no fechamento do mês.'), kpis, formCliente,
    h('div', { class: 'cartao' }, h('h2', {}, 'Clientes'), tabelaClientes), h('div', { class: 'cartao' }, h('div', { class: 'linha entre' }, h('h2', {}, 'Planos'), h('button', { onclick: () => { planoEmEdicao = null; desenharFormPlano(); } }, 'Novo plano')), tabelaPlanos), formPlano,
    aviso('Os preços e cotas dos planos iniciais são exemplos: ajuste ao seu modelo. Antes de vender, confirme os termos de uso do DJEN e do Datajud para uso comercial e tenha contrato e política de privacidade próprios.', 'aviso'));
  desenhar();
}

async function telaApiCliente(id) {
  const r = await api('GET', '/api/admin/api/clients/' + id);
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'), h('p', {}, h('a', { href: '#/apis' }, '← Voltar')));
  const planos = await api('GET', '/api/admin/api/plans');
  const { client: c, plan, keys, days, quota, used_month: usado, by_endpoint: porRota } = r.data;
  const msg = h('div'), painelToken = h('div'), formEdit = h('div', { hidden: true });
  const acao = (rotulo, status, perigo) => h('button', { class: perigo ? 'perigo' : '', onclick: async () => { const x = await api('PATCH', '/api/admin/api/clients/' + id, { status }); if (x.ok) telaApiCliente(id); else msg.replaceChildren(aviso(x.error, 'erro')); } }, rotulo);
  const max = Math.max(1, ...days.map((d) => d.requests));
  const grafico = h('div', { class: 'dias', role: 'img', 'aria-label': 'Consultas por dia nos últimos 30 dias' }, ...days.map((d) => h('div', { class: 'dia', title: dataBR(d.day) + ': ' + d.billable + ' consultas ok, ' + d.errors + ' erro(s), ' + d.requests + ' chamadas' },
    h('div', { class: 'col', style: 'height:' + Math.max(d.requests ? 3 : 0, Math.round((d.requests / max) * 100)) + '%' }, h('div', { class: 'col-erro', style: 'height:' + (d.requests ? Math.round((d.errors / d.requests) * 100) : 0) + '%' })))));
  async function novaChave(e) {
    e.preventDefault(); const d = Object.fromEntries(new FormData(e.target));
    const x = await api('POST', '/api/admin/api/clients/' + id + '/keys', { label: d.label, expires_in_days: d.validade ? Number(d.validade) : null });
    if (!x.ok) return msg.replaceChildren(aviso(x.error, 'erro'));
    const token = x.data.token, aviso_ = h('span', { class: 'suave' }), caixa = h('code', { class: 'token' }, token);
    painelToken.replaceChildren(h('div', { class: 'cartao token-painel' }, h('h3', {}, 'Chave criada: ' + x.data.key.label),
      aviso('Copie e entregue ao cliente AGORA. Por segurança, o sistema guarda só uma impressão digital da chave e não consegue mostrá-la de novo. Se perder, gere outra e revogue esta.', 'aviso'),
      caixa, h('div', { class: 'linha' }, h('button', { class: 'principal', onclick: () => copiar(token, aviso_) }, 'Copiar chave'), aviso_),
      h('p', { class: 'suave' }, 'Teste rápido (no servidor do cliente):'), h('pre', {}, 'curl -H "Authorization: Bearer ' + token + '" \\\n  "' + location.origin + '/v1/uso"')));
    e.target.reset(); setTimeout(() => telaApiChavesAtualizar(), 0);
  }
  const corpoChaves = h('tbody');
  function telaApiChavesAtualizar() { api('GET', '/api/admin/api/clients/' + id).then((n) => { if (n.ok) desenharChaves(n.data.keys); }); }
  function desenharChaves(lista) {
    corpoChaves.replaceChildren(...(lista.length ? lista.map((k) => { const revogada = !!k.revoked_at, expirada = k.expires_at && k.expires_at < new Date().toISOString().slice(0, 19).replace('T', ' ');
      return h('tr', {}, h('td', {}, k.label), h('td', {}, h('code', {}, k.prefix + '••••••••')), h('td', {}, dataBR(k.created_at)), h('td', {}, k.last_used_at ? dataHoraBR(k.last_used_at) : 'nunca'), h('td', {}, k.expires_at ? dataBR(k.expires_at) : 'não expira'),
        h('td', {}, revogada ? h('span', { class: 'etq verm' }, 'revogada') : expirada ? h('span', { class: 'etq amar' }, 'expirada') : h('span', { class: 'etq verde' }, 'ativa')),
        h('td', {}, revogada ? null : h('button', { class: 'perigo', onclick: async () => { const x = await api('POST', '/api/admin/api/keys/' + k.id + '/revoke'); if (x.ok) telaApiChavesAtualizar(); else msg.replaceChildren(aviso(x.error, 'erro')); } }, 'Revogar'))); })
      : [h('tr', {}, h('td', { colspan: 7, class: 'vazio' }, 'Nenhuma chave. Gere a primeira abaixo.'))]));
  }
  desenharChaves(keys);
  const f = h('form', { class: 'empilhado', onsubmit: async (e) => { e.preventDefault(); const d = Object.fromEntries(new FormData(e.target));
      const x = await api('PATCH', '/api/admin/api/clients/' + id, { name: d.name, email: d.email, document: d.document, company: d.company, notes: d.notes, plan_id: Number(d.plan_id), quota_override: d.quota_override === '' ? null : Number(d.quota_override), spend_cap_cents: d.spend_cap.trim() === '' ? null : Math.round(Number(d.spend_cap.replace(',', '.')) * 100) });
      if (!x.ok) return msg.replaceChildren(aviso(x.error, 'erro')); telaApiCliente(id); } },
    campo('Nome', 'name', 'text', { value: c.name }), campo('E-mail', 'email', 'email', { value: c.email }), campo('CPF ou CNPJ', 'document', 'text', { value: c.document || '', required: false }), campo('Empresa', 'company', 'text', { value: c.company || '', required: false }),
    h('label', {}, 'Plano', h('select', { name: 'plan_id' }, ...planos.data.map((x) => h('option', { value: x.id, selected: Number(x.id) === Number(c.plan_id) }, x.name + ' — ' + reais(x.price_cents) + '/mês')))),
    campo('Cota especial por mês (plano mensal; em branco = a do plano)', 'quota_override', 'number', { value: c.quota_override || '', required: false, min: 1 }),
    campo('Teto de gasto por mês em R$ (plano por busca; em branco = sem teto)', 'spend_cap', 'text', { value: c.spend_cap_cents ? String(c.spend_cap_cents / 100).replace('.', ',') : '', required: false, inputmode: 'decimal' }),
    h('label', {}, 'Observações (só você vê)', h('textarea', { name: 'notes', rows: 3 }, c.notes || '')), h('button', { class: 'principal' }, 'Salvar alterações'));
  formEdit.replaceChildren(h('div', { class: 'cartao' }, f));
  app.replaceChildren(h('p', {}, h('a', { href: '#/apis' }, '← API e clientes')),
    h('div', { class: 'cartao' }, h('div', { class: 'linha entre' }, h('h1', {}, c.name), h('span', { class: 'etq ' + ST_API[c.status][0] }, ST_API[c.status][1])),
      h('p', { class: 'suave' }, c.email + (c.company ? ' · ' + c.company : '') + (c.document ? ' · ' + c.document : '') + ' · cliente desde ' + dataBR(c.created_at)),
      h('p', {}, h('strong', {}, plan.name), plan.billing_model === 'per_search' ? ' — ' + reais(plan.price_per_search_cents) + ' por busca bem-sucedida (falhas não são cobradas)' + (c.spend_cap_cents ? ' · teto de ' + reais(c.spend_cap_cents) + '/mês' : ' · sem teto de gasto') : ' — ' + reais(plan.price_cents) + '/mês · ' + num(quota) + ' consultas/mês' + (c.quota_override ? ' (cota especial)' : ''), ' · ' + num(plan.rate_per_minute) + ' por minuto, por chave'),
      h('p', {}, 'A cobrar neste mês: ', h('strong', {}, reais(r.data.amount_cents))),
      barraUso(usado, quota),
      h('div', { class: 'linha', style: 'margin-top:10px' }, h('button', { onclick: () => { formEdit.hidden = !formEdit.hidden; } }, 'Editar dados e plano'),
        c.status === 'active' ? acao('Suspender acesso', 'suspended', true) : acao('Reativar', 'active'), c.status !== 'cancelled' ? acao('Cancelar cliente', 'cancelled', true) : null), msg),
    formEdit,
    h('div', { class: 'cartao' }, h('h2', {}, 'Uso nos últimos 30 dias'), grafico, h('p', { class: 'suave' }, 'Altura = chamadas do dia; a parte vermelha são os erros.'),
      porRota.length ? h('table', {}, h('thead', {}, h('tr', {}, ...['Consulta', 'Ok no mês', 'Erros no mês'].map((t) => h('th', {}, t)))), h('tbody', {}, ...porRota.map((x) => h('tr', {}, h('td', {}, x.endpoint), h('td', {}, num(x.billable)), h('td', {}, num(x.errors)))))) : h('p', { class: 'suave' }, 'Ainda sem consultas neste mês.')),
    h('div', { class: 'cartao' }, h('h2', {}, 'Chaves (tokens)'), h('div', { style: 'overflow-x:auto' }, h('table', {}, h('thead', {}, h('tr', {}, ...['Nome', 'Chave', 'Criada', 'Último uso', 'Validade', 'Situação', ''].map((t) => h('th', {}, t)))), corpoChaves)),
      painelToken,
      c.status === 'active' ? h('form', { class: 'linha', style: 'margin-top:12px', onsubmit: novaChave }, h('input', { name: 'label', required: true, maxlength: 80, placeholder: 'Nome da chave (ex.: Produção)', 'aria-label': 'Nome da chave' }),
        h('select', { name: 'validade', 'aria-label': 'Validade' }, h('option', { value: '' }, 'Não expira'), h('option', { value: '30' }, 'Expira em 30 dias'), h('option', { value: '90' }, 'Expira em 90 dias'), h('option', { value: '365' }, 'Expira em 1 ano')),
        h('button', { class: 'principal' }, 'Gerar nova chave')) : aviso('Reative o cliente para gerar chaves.', 'aviso')),
    h('div', { class: 'cartao' }, h('h2', {}, 'O que enviar ao cliente'), h('p', {}, 'A chave e o link da documentação: ', h('a', { href: '/app/api-docs.html', target: '_blank', rel: 'noopener' }, location.origin + '/app/api-docs.html'), '. A descrição técnica (OpenAPI) está em ', h('a', { href: '/v1/openapi.json', target: '_blank', rel: 'noopener' }, location.origin + '/v1/openapi.json'), '.')));
}


// ── Usuários e estatísticas de uso (só o dono do SaaS) ───────────────────────
const ST_USER = { active: ['verde', 'Ativo'], disabled: ['verm', 'Bloqueado'] };
const graficoDias = (serie, rotulo, campoOk, campoRuim) => {
  const max = Math.max(1, ...serie.map((d) => d[campoOk] + (campoRuim ? d[campoRuim] : 0)));
  return h('div', { class: 'dias', role: 'img', 'aria-label': rotulo }, ...serie.map((d) => { const total = d[campoOk] + (campoRuim ? d[campoRuim] : 0);
    return h('div', { class: 'dia', title: dataBR(d.day) + ': ' + d[campoOk] + (campoRuim ? ' ok, ' + d[campoRuim] + ' falha(s)' : '') },
      h('div', { class: 'col', style: 'height:' + Math.max(total ? 3 : 0, Math.round((total / max) * 100)) + '%' }, campoRuim ? h('div', { class: 'col-erro', style: 'height:' + (total ? Math.round((d[campoRuim] / total) * 100) : 0) + '%' }) : null)); }));
};

async function telaUso() {
  const estado = { dias: 30, q: '', status: '', inativos: '' };
  const [ov, us] = await Promise.all([api('GET', '/api/admin/usage', null, { days: String(estado.dias) }), api('GET', '/api/admin/users')]);
  if (!ov.ok) return app.replaceChildren(aviso(ov.error, 'erro'));
  let o = ov.data, usuarios = us.data;
  const topo = h('div'), listaUsuarios = h('div');
  const kpi_ = (t, v, c, rod) => h('div', { class: 'kpi-mini ' + (c || '') }, h('span', { class: 'num' }, v), h('span', {}, t), rod ? h('span', { class: 'suave' }, rod) : null);
  function desenharTopo_() {
    const t = o.tenants, u = o.users;
    topo.replaceChildren(
      h('div', { class: 'kpis' }, kpi_('Escritórios', num(t.total), '', num(t.trialing || 0) + ' em teste · ' + num(t.active || 0) + ' ativos'),
        kpi_('Usuários', num(u.total), '', num(u.active_7d) + ' entraram em 7 dias · ' + num(u.never_logged) + ' nunca entraram'),
        kpi_('Buscas ok (' + o.days + ' dias)', num(o.searches.ok), 'azul', num(o.searches.failed) + ' falharam (não cobradas)'),
        kpi_('A cobrar neste mês', reais(o.revenue_month_cents), 'verde', 'buscas bem-sucedidas × preço'),
        kpi_('Chamadas da API no mês', num(o.api.requests_month), '')),
      h('div', { class: 'grade2' },
        h('div', { class: 'cartao' }, h('h2', {}, 'Buscas por dia'), graficoDias(o.series, 'Buscas por dia', 'ok', 'failed'), h('p', { class: 'suave' }, 'Azul = bem-sucedidas; vermelho = falhas. Altura = total do dia.')),
        h('div', { class: 'cartao' }, h('h2', {}, 'Novos cadastros por dia'), graficoDias(o.series, 'Novos cadastros por dia', 'signups'), h('p', { class: 'suave' }, num(o.series.reduce((a, d) => a + d.signups, 0)) + ' novo(s) escritório(s) no período.'))),
      h('div', { class: 'cartao' }, h('div', { class: 'linha entre' }, h('h2', {}, 'Escritórios que mais usam'),
        h('select', { 'aria-label': 'Período', onchange: async (e) => { estado.dias = Number(e.target.value); const r = await api('GET', '/api/admin/usage', null, { days: String(estado.dias) }); if (r.ok) { o = r.data; desenharTopo_(); } } },
          ...[[7, '7 dias'], [30, '30 dias'], [90, '90 dias']].map(([v, n]) => h('option', { value: v, selected: v === estado.dias }, 'Últimos ' + n)))),
        o.top_clients !== undefined ? null : null,
        o.top_tenants.length ? h('table', {}, h('thead', {}, h('tr', {}, ...['Escritório', 'Buscas ok', 'Falhas'].map((x) => h('th', {}, x)))),
          h('tbody', {}, ...o.top_tenants.map((x) => h('tr', { class: 'clicavel', onclick: () => { location.hash = '#/admin/escritorio/' + x.id; } }, h('td', {}, x.name), h('td', {}, num(x.ok)), h('td', {}, num(x.failed))))))
          : h('p', { class: 'vazio' }, 'Ainda sem buscas no período.')));
  }
  async function recarregarUsuarios() {
    const q = Object.fromEntries(Object.entries(estado).filter(([k, v]) => v && k !== 'dias'));
    const r = await api('GET', '/api/admin/users', null, { q: estado.q, status: estado.status, inactive: estado.inativos });
    if (r.ok) { usuarios = r.data; desenharUsuarios(); }
  }
  function desenharUsuarios() {
    const busca = h('input', { type: 'search', placeholder: 'Buscar nome, e-mail ou escritório…', 'aria-label': 'Buscar usuário', value: estado.q, oninput: (e) => { estado.q = e.target.value; clearTimeout(busca.t); busca.t = setTimeout(recarregarUsuarios, 300); } });
    const st = h('select', { 'aria-label': 'Situação', onchange: (e) => { estado.status = e.target.value; recarregarUsuarios(); } }, h('option', { value: '' }, 'Todas as situações'), h('option', { value: 'active', selected: estado.status === 'active' }, 'Ativos'), h('option', { value: 'disabled', selected: estado.status === 'disabled' }, 'Bloqueados'));
    const ina = h('select', { 'aria-label': 'Atividade', onchange: (e) => { estado.inativos = e.target.value; recarregarUsuarios(); } }, h('option', { value: '' }, 'Qualquer atividade'), h('option', { value: '30', selected: estado.inativos === '30' }, 'Sem entrar há 30 dias ou mais'));
    listaUsuarios.replaceChildren(h('div', { class: 'filtros' }, busca, st, ina, h('span', { class: 'suave' }, usuarios.total + ' usuário(s)')),
      h('div', { style: 'overflow-x:auto' }, h('table', {}, h('thead', {}, h('tr', {}, ...['Usuário', 'Escritório(s) e papel', 'Situação', 'Último acesso', 'Cadastro'].map((x) => h('th', {}, x)))),
        h('tbody', {}, ...(usuarios.items.length ? usuarios.items.map((u) => h('tr', { class: 'clicavel', onclick: () => { location.hash = '#/uso/usuario/' + u.id; } },
          h('td', {}, h('strong', {}, u.name), Number(u.is_platform_admin) ? h('span', { class: 'etq azul' }, 'dono') : null, h('div', { class: 'suave' }, u.email)), h('td', {}, u.offices || '—'),
          h('td', {}, h('span', { class: 'etq ' + ST_USER[u.status][0] }, ST_USER[u.status][1])), h('td', {}, u.last_login_at ? dataHoraBR(u.last_login_at) : 'nunca'), h('td', {}, dataBR(u.created_at))))
          : [h('tr', {}, h('td', { colspan: 5, class: 'vazio' }, 'Nenhum usuário com esses filtros.'))])))));
  }
  app.replaceChildren(h('h1', {}, 'Usuários e uso'),
    h('p', { class: 'suave' }, 'Visão do todo e de cada escritório e usuário: quem entra, quanto usa e quanto há a cobrar. Só números e cadastro: o conteúdo dos processos nunca aparece aqui.'),
    topo, h('div', { class: 'cartao' }, h('h2', {}, 'Usuários'), listaUsuarios));
  desenharTopo_(); desenharUsuarios();
}

async function telaUsoUsuario(id, recado) {
  const r = await api('GET', '/api/admin/users/' + id);
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'), h('p', {}, h('a', { href: '#/uso' }, '← Voltar')));
  const { user: u, offices, actions_30d: acoes, recent } = r.data, msg = h('div', {}, recado || null);
  const mudar = async (status) => { const x = await api('POST', '/api/admin/users/' + id + '/status', { status }); if (x.ok) telaUsoUsuario(id, aviso(status === 'disabled' ? 'Acesso bloqueado. Quem estiver logado perde o acesso no próximo clique.' : 'Acesso liberado.', 'ok')); else msg.replaceChildren(aviso(x.error, 'erro')); };
  const NOME_ACAO = (a) => a.replace(/\./g, ' › ');
  app.replaceChildren(h('p', {}, h('a', { href: '#/uso' }, '← Usuários e uso')),
    h('div', { class: 'cartao' }, h('div', { class: 'linha entre' }, h('h1', {}, u.name), h('span', { class: 'etq ' + ST_USER[u.status][0] }, ST_USER[u.status][1])),
      h('p', { class: 'suave' }, u.email + ' · cadastro em ' + dataBR(u.created_at) + ' · último acesso: ' + (u.last_login_at ? dataHoraBR(u.last_login_at) : 'nunca') + (u.terms_accepted_at ? ' · aceitou os termos em ' + dataBR(u.terms_accepted_at) : '')),
      msg, Number(u.is_platform_admin) ? aviso('Administrador da plataforma: gerido pelo servidor.', 'aviso')
        : h('div', { class: 'linha' }, u.status === 'active' ? h('button', { class: 'perigo', onclick: () => mudar('disabled') }, 'Bloquear acesso') : h('button', { class: 'principal', onclick: () => mudar('active') }, 'Liberar acesso'))),
    h('div', { class: 'cartao' }, h('h2', {}, 'Escritórios'), offices.length ? h('table', {}, h('thead', {}, h('tr', {}, ...['Escritório', 'Papel', 'Situação no escritório'].map((x) => h('th', {}, x)))),
      h('tbody', {}, ...offices.map((o) => h('tr', { class: 'clicavel', onclick: () => { location.hash = '#/admin/escritorio/' + o.id; } }, h('td', {}, o.name), h('td', {}, { office_admin: 'Administrador', lawyer: 'Advogado(a)', assistant: 'Assistente' }[o.role] || o.role), h('td', {}, o.status))))) : h('p', { class: 'vazio' }, 'Sem escritório.')),
    h('div', { class: 'cartao' }, h('h2', {}, 'O que fez nos últimos 30 dias'), acoes.length ? h('table', {}, h('thead', {}, h('tr', {}, h('th', {}, 'Ação'), h('th', {}, 'Vezes'))), h('tbody', {}, ...acoes.map((a) => h('tr', {}, h('td', {}, NOME_ACAO(a.action)), h('td', {}, num(a.n)))))) : h('p', { class: 'vazio' }, 'Nenhuma ação registrada.')),
    h('div', { class: 'cartao' }, h('h2', {}, 'Últimos eventos'), recent.length ? recent.map((a) => h('div', { class: 'mov suave' }, dataHoraBR(a.created_at) + ' · ' + NOME_ACAO(a.action) + (a.entity_type ? ' · ' + a.entity_type : ''))) : h('p', { class: 'vazio' }, 'Sem eventos.')));
}

// uso individual de um escritório, para a página do escritório na administração
async function blocoUsoEscritorio(id, alvo) {
  const r = await api('GET', '/api/admin/usage/tenant/' + id, null, { days: '30' });
  if (!r.ok) return alvo.replaceChildren(aviso(r.error, 'erro'));
  const { series, statement: e, counts, members, last_activity: ultima } = r.data;
  alvo.replaceChildren(h('h2', {}, 'Uso do escritório (30 dias)'),
    h('div', { class: 'kpis' }, h('div', { class: 'kpi-mini azul' }, h('span', { class: 'num' }, num(e.ok)), h('span', {}, 'buscas ok no mês')), h('div', { class: 'kpi-mini' }, h('span', { class: 'num' }, num(e.failed)), h('span', {}, 'falhas (não cobradas)')),
      h('div', { class: 'kpi-mini verde' }, h('span', { class: 'num' }, reais(e.amount_cents)), h('span', {}, e.trial ? 'a cobrar (em teste)' : 'a cobrar no mês')), h('div', { class: 'kpi-mini' }, h('span', { class: 'num' }, num(counts.processes)), h('span', {}, 'processos ativos')), h('div', { class: 'kpi-mini' }, h('span', { class: 'num' }, num(counts.oabs)), h('span', {}, 'OABs'))),
    graficoDias(series, 'Buscas por dia do escritório', 'ok', 'failed'),
    h('p', { class: 'suave' }, 'Modelo: ' + (e.model === 'per_search' ? reais(e.price_per_search_cents) + ' por busca' : 'mensalidade') + ' · horário das buscas: ' + (e.search_time || 'pelo intervalo do plano') + (e.spend_cap_cents ? ' · teto de ' + reais(e.spend_cap_cents) : '') + ' · última atividade: ' + (ultima ? dataHoraBR(ultima) : '—')),
    h('table', {}, h('thead', {}, h('tr', {}, ...['Pessoa', 'Papel', 'Último acesso', 'Ações em 30 dias'].map((x) => h('th', {}, x)))),
      h('tbody', {}, ...members.map((m) => h('tr', { class: 'clicavel', onclick: () => { location.hash = '#/uso/usuario/' + m.id; } }, h('td', {}, m.name, h('div', { class: 'suave' }, m.email)), h('td', {}, m.role), h('td', {}, m.last_login_at ? dataHoraBR(m.last_login_at) : 'nunca'), h('td', {}, num(m.actions_30d)))))));
}

// administração: pedidos de contratação e "pronto para vender?" no topo
async function telaAdminCompleta(consulta) {
  await telaAdmin(consulta);
  const [pedidos, prontidao] = await Promise.all([api('GET', '/api/admin/requests'), api('GET', '/api/admin/readiness')]);
  const COR_PRONTO = { ok: 'verde', warn: 'amar', fail: 'verm' }, ROT_PRONTO = { ok: 'ok', warn: 'atenção', fail: 'falta' };
  const cartaoPedidos = pedidos.ok && pedidos.data.length ? h('div', { class: 'cartao' }, h('h2', {}, 'Pedidos de contratação (' + pedidos.data.length + ')'),
    h('p', { class: 'suave' }, 'Combine o pagamento com o cliente e, depois de receber, renove a assinatura e marque o pedido como atendido.'),
    h('table', {}, h('thead', {}, h('tr', {}, ...['Escritório', 'Plano pedido', 'Quem pediu', 'Quando', ''].map((x) => h('th', {}, x)))),
      h('tbody', {}, ...pedidos.data.map((q) => h('tr', {}, h('td', {}, h('a', { href: '#/admin/escritorio/' + q.tenant_id }, q.tenant_name)), h('td', {}, q.plan_name || q.plan_code, q.message ? h('div', { class: 'suave' }, q.message) : null), h('td', {}, (q.requester || '') + ' ' + (q.requester_email || '')), h('td', {}, dataHoraBR(q.created_at)),
        h('td', { class: 'linha' }, h('button', { class: 'principal', onclick: async () => { const x = await api('POST', '/api/admin/requests/' + q.id + '/done'); if (x.ok) telaAdminCompleta(consulta); } }, 'Atendido'), h('button', { onclick: async () => { const x = await api('POST', '/api/admin/requests/' + q.id + '/dismissed'); if (x.ok) telaAdminCompleta(consulta); } }, 'Descartar'))))))) : null;
  const falta = prontidao.ok ? prontidao.data.filter((c) => c.status === 'fail').length : 0;
  const cartaoPronto = prontidao.ok ? h('details', { class: 'cartao', open: falta > 0 }, h('summary', {}, h('strong', {}, 'Pronto para vender? '), falta ? h('span', { class: 'etq verm' }, falta + ' pendência(s)') : h('span', { class: 'etq verde' }, 'tudo certo')),
    h('div', {}, ...prontidao.data.map((c) => h('div', { class: 'linha', style: 'margin:8px 0;align-items:flex-start' }, h('span', { class: 'etq ' + COR_PRONTO[c.status] }, ROT_PRONTO[c.status]), h('div', {}, h('strong', {}, c.label), h('div', { class: 'suave' }, c.detail)))))) : null;
  const barra = h('div', { class: 'linha' }, h('a', { href: '#/uso' }, h('button', {}, 'Usuários e uso')), h('button', { onclick: async () => { const e = await baixar('/api/admin/billing.csv', 'cobranca.csv', {}); if (e) alert(e); } }, 'Cobrança do mês (CSV)'));
  app.prepend(...[barra, cartaoPedidos, cartaoPronto].filter(Boolean));
}

async function telaAdmin(consulta) {
  const filtros = { q: '', status: '', ...consulta };
  const [ov, planos] = await Promise.all([api('GET', '/api/admin/overview'), api('GET', '/api/admin/plans')]);
  if (!ov.ok) return app.replaceChildren(aviso(ov.error, 'erro'));
  const o = ov.data;
  const corpo = h('tbody'); const total = h('span', { class: 'suave' });
  const carregar = async () => {
    const q = Object.fromEntries(Object.entries(filtros).filter(([, v]) => v));
    const r = await api('GET', '/api/admin/tenants', null, q);
    if (!r.ok) return corpo.replaceChildren(h('tr', {}, h('td', { colspan: 7 }, r.error)));
    total.textContent = r.data.total + ' escritório(s)';
    corpo.replaceChildren(...(r.data.items.length ? r.data.items.map((t) => h('tr', { class: 'clicavel', onclick: () => { location.hash = '#/admin/escritorio/' + t.id; } },
      h('td', {}, t.name, t.tenant_status === 'suspended' ? h('span', { class: 'etq verm' }, 'acesso bloqueado') : null), h('td', {}, t.plan_name),
      h('td', {}, h('span', { class: 'etq ' + COR[t.subscription_status] }, SITUACAO[t.subscription_status])), h('td', {}, dataBR(t.current_period_end)),
      h('td', {}, t.users + ' / ' + t.processes + ' / ' + t.oabs), h('td', {}, dataBR(t.created_at)), h('td', {}, t.last_activity ? dataHoraBR(t.last_activity) : '—')))
      : [h('tr', {}, h('td', { colspan: 7, class: 'vazio' }, 'Nenhum escritório encontrado.'))]));
  };
  const card = (t, v, cor) => h('div', { class: 'cartao' }, h('h2', {}, t), h('div', { class: 'numero', style: cor ? 'color:var(--' + cor + ')' : '' }, v));
  const rodada = o.last_worker_run;
  app.replaceChildren(h('h1', {}, 'Administração da plataforma'),
    o.worker_stale ? aviso('O robô não registrou nenhuma rodada nos últimos 20 minutos. Confira o cron do servidor.', 'erro') : null,
    h('div', { class: 'grade' }, card('Escritórios', o.tenants), card('Assinaturas ativas', o.subscriptions.active || 0, 'verde'), card('Em teste', o.subscriptions.trialing || 0, 'azul'),
      card('Vencidas ou suspensas', (o.subscriptions.past_due || 0) + (o.subscriptions.suspended || 0), (o.subscriptions.past_due || 0) + (o.subscriptions.suspended || 0) ? 'amar' : ''),
      card('Receita mensal (ativas)', reais(o.monthly_revenue_cents)), card('Usuários', o.users), card('Processos monitorados', o.processes_active), card('OABs monitoradas', o.oabs_active)),
    h('div', { class: 'cartao' }, h('h2', {}, 'Saúde do monitoramento'),
      h('p', {}, 'Últimas 24 h: ', h('strong', {}, o.checks_ok_24h + ' consultas ok'), ' · ', h('strong', { style: o.checks_failed_24h ? 'color:var(--verm)' : '' }, o.checks_failed_24h + ' com falha'),
        ' · processos com erro agora: ', h('strong', {}, o.processes_with_error), ' · OABs com erro: ', h('strong', {}, o.oabs_with_error), ' · na fila: ', h('strong', {}, o.queued_jobs)),
      h('p', { class: 'suave' }, rodada ? 'Última rodada do robô: ' + dataHoraBR(rodada.started_at) : 'O robô ainda não registrou nenhuma rodada.')),
    h('div', { class: 'cartao' }, h('div', { class: 'filtros' },
      h('input', { type: 'search', placeholder: 'Buscar por nome do escritório ou e-mail…', value: filtros.q, oninput: (e) => { filtros.q = e.target.value; carregar(); } }),
      h('select', { onchange: (e) => { filtros.status = e.target.value; carregar(); } }, h('option', { value: '' }, 'Todas as situações'), ...Object.entries(SITUACAO).map(([v, t]) => h('option', { value: v }, t))), total),
      h('div', { style: 'overflow-x:auto' }, h('table', { class: 'larga' }, h('thead', {}, h('tr', {}, ...['Escritório', 'Plano', 'Assinatura', 'Vence em', 'Usuários / processos / OABs', 'Criado em', 'Última atividade'].map((t) => h('th', {}, t)))), corpo))),
    h('p', { class: 'suave' }, 'Aqui aparecem só números e dados cadastrais. O conteúdo dos processos de cada escritório não é exibido nesta tela.'));
  carregar();
}

async function telaAdminEscritorio(id, recado) {
  const [r, planos] = await Promise.all([api('GET', '/api/admin/tenants/' + id), api('GET', '/api/admin/plans')]);
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'), h('p', {}, h('a', { href: '#/admin' }, '← Voltar')));
  const { tenant: t, members, audit } = r.data;
  const msg = h('div', {}, recado || null);
  const chamar = async (caminho, corpo, texto) => { const x = await api('POST', '/api/admin/tenants/' + id + caminho, corpo); if (x.ok) telaAdminEscritorio(id, aviso(texto, 'ok')); else msg.replaceChildren(aviso(x.error, 'erro')); };
  const dias = h('input', { type: 'number', min: 1, max: 3660, value: 30, style: 'width:90px' });
  const plano = h('select', {}, ...(planos.data || []).map((p) => h('option', { value: p.code, selected: p.code === t.plan_code }, p.name + ' (' + reais(p.price_cents) + ')')));
  const nota = h('input', { value: t.admin_note || '', placeholder: 'Anotação interna (só o dono vê)', size: 40, maxlength: 500 });
  app.replaceChildren(h('p', {}, h('a', { href: '#/admin' }, '← Administração')),
    h('div', { class: 'cartao' }, h('div', { class: 'linha entre' }, h('h1', {}, t.name), h('span', { class: 'etq ' + COR[t.subscription_status] }, SITUACAO[t.subscription_status])),
      h('p', { class: 'suave' }, 'Plano ' + t.plan_name + ' · vence em ' + dataBR(t.current_period_end) + ' · usa ' + t.users + ' usuário(s), ' + t.processes + ' processo(s), ' + t.oabs + ' OAB(s)' + (t.suspended_reason ? ' · motivo: ' + t.suspended_reason : '')),
      t.tenant_status === 'suspended' ? aviso('O acesso deste escritório está bloqueado.', 'erro') : null, msg,
      h('div', { class: 'linha' }, h('label', { class: 'linha suave' }, 'Renovar por ', dias, ' dias'), h('button', { class: 'principal', onclick: () => chamar('/renew', { days: Number(dias.value) }, 'Assinatura renovada.') }, 'Renovar')),
      h('div', { class: 'linha', style: 'margin-top:10px' }, plano, h('button', { onclick: () => chamar('/plan', { plan: plano.value }, 'Plano alterado.') }, 'Trocar plano')),
      h('div', { class: 'linha', style: 'margin-top:10px' }, nota, h('button', { onclick: () => chamar('/note', { note: nota.value }, 'Anotação salva.') }, 'Salvar anotação')),
      h('div', { class: 'linha', style: 'margin-top:14px' },
        t.subscription_status !== 'suspended' ? h('button', { class: 'perigo', onclick: () => { if (confirm('Suspender a assinatura? As consultas pausam, os dados ficam.')) chamar('/status', { status: 'suspended', reason: 'Suspensa pelo administrador' }, 'Assinatura suspensa.'); } }, 'Suspender assinatura') : null,
        t.tenant_status === 'active' ? h('button', { class: 'perigo', onclick: () => { if (confirm('Bloquear o acesso de todos deste escritório?')) chamar('/access', { status: 'suspended' }, 'Acesso bloqueado.'); } }, 'Bloquear acesso')
          : h('button', { onclick: () => chamar('/access', { status: 'active' }, 'Acesso liberado.') }, 'Liberar acesso'))),
    h('div', { class: 'cartao', style: 'overflow-x:auto' }, h('h2', {}, 'Pessoas'), h('table', { class: 'larga' }, h('thead', {}, h('tr', {}, ...['Nome', 'E-mail', 'Papel', 'Situação', 'Último acesso'].map((x) => h('th', {}, x)))),
      h('tbody', {}, ...members.map((m) => h('tr', {}, h('td', {}, m.name), h('td', {}, m.email), h('td', {}, { office_admin: 'Administrador', lawyer: 'Advogado(a)', assistant: 'Assistente' }[m.role] || m.role), h('td', {}, { active: 'Ativo', invited: 'Convite pendente', disabled: 'Desativado' }[m.status] || m.status), h('td', {}, dataHoraBR(m.last_login_at))))))),
    (() => { const alvo = h('div', { class: 'cartao' }); blocoUsoEscritorio(id, alvo); return alvo; })(),
    h('div', { class: 'cartao' }, h('h2', {}, 'Últimos eventos do escritório'), audit.length ? audit.map((a) => h('div', { class: 'mov suave' }, dataHoraBR(a.created_at) + ' · ' + a.action)) : h('p', { class: 'vazio' }, 'Sem eventos.')));
}

// ── minha conta ──────────────────────────────────────────────────────────────
async function telaConta(recado) {
  const r = await api('GET', '/api/account');
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'));
  const c = r.data;
  const msgPerfil = h('div', {}, recado || null), msgSenha = h('div'), msgPriv = h('div');
  const perfil = h('form', { class: 'empilhado', onsubmit: async (e) => {
    e.preventDefault(); const f = e.target;
    const x = await api('PATCH', '/api/account', { name: f.name.value, email_notifications: f.email_notifications.checked });
    if (x.ok) { await carregarSessao(); return telaConta(aviso('Dados salvos.', 'ok')); }
    msgPerfil.replaceChildren(aviso(x.error, 'erro'));
  } }, h('label', {}, 'Nome', h('input', { name: 'name', value: c.name, required: true })),
    h('label', {}, 'E-mail de acesso', h('input', { value: c.email, disabled: true })),
    h('label', { class: 'linha' }, h('input', { type: 'checkbox', name: 'email_notifications', checked: c.email_notifications }), 'Receber aviso de nova movimentação por e-mail (o aviso no painel continua sempre)'),
    h('button', { class: 'principal' }, 'Salvar'), msgPerfil);
  const senha = h('form', { class: 'empilhado', onsubmit: async (e) => {
    e.preventDefault(); const f = e.target;
    const x = await api('POST', '/api/account/password', { current: f.current.value, new: f.nova.value });
    if (x.ok) { f.reset(); msgSenha.replaceChildren(aviso('Senha alterada.', 'ok')); } else msgSenha.replaceChildren(aviso(x.error, 'erro'));
  } }, campo('Senha atual', 'current', 'password', { autocomplete: 'current-password' }), campo('Nova senha (mínimo 10 caracteres)', 'nova', 'password', { minlength: 10, autocomplete: 'new-password' }),
    h('button', { class: 'principal' }, 'Trocar senha'), msgSenha);
  app.replaceChildren(h('h1', {}, 'Minha conta'),
    h('div', { class: 'cartao' }, h('h2', {}, 'Dados e avisos'), perfil),
    h('div', { class: 'cartao' }, h('h2', {}, 'Senha'), senha),
    pode('privacy.manage') ? h('div', { class: 'cartao' }, h('h2', {}, 'Dados do escritório (LGPD)'),
      h('p', { class: 'suave' }, 'Baixe uma cópia completa dos dados ou exclua o escritório de forma definitiva. Veja a ', h('a', { href: '/app/privacidade.html', target: '_blank', rel: 'noopener' }, 'Política de Privacidade'), '.'),
      h('div', { class: 'linha' }, h('button', { onclick: async () => { const e = await baixar('/api/privacy/export', 'meus-dados.json'); if (e) msgPriv.replaceChildren(aviso(e, 'erro')); } }, 'Baixar cópia dos dados (JSON)')),
      h('details', { style: 'margin-top:12px' }, h('summary', {}, 'Excluir o escritório definitivamente'),
        h('form', { class: 'empilhado', style: 'margin-top:8px', onsubmit: async (e) => {
          e.preventDefault(); const f = e.target;
          const x = await api('POST', '/api/privacy/erase', { confirm_name: f.confirm_name.value, password: f.password.value });
          if (!x.ok) return msgPriv.replaceChildren(aviso(x.error, 'erro'));
          await carregarSessao(); location.hash = '#/entrar'; rota();
        } }, aviso('Isto apaga processos, movimentações, publicações, OABs e todas as pessoas do escritório. Não tem volta.', 'erro'),
          campo('Digite o nome do escritório para confirmar', 'confirm_name'), campo('Sua senha', 'password', 'password', { autocomplete: 'current-password' }),
          h('button', { class: 'perigo' }, 'Excluir tudo para sempre'))), msgPriv) : null,
    c.terms_accepted_at ? h('p', { class: 'suave' }, 'Termos de uso aceitos em ' + dataBR(c.terms_accepted_at) + ' (versão ' + c.terms_version + ').') : null);
}

// ── roteador ─────────────────────────────────────────────────────────────────
async function rota() {
  const [caminho, qs] = (location.hash || '#/').slice(1).split('?');
  const consulta = Object.fromEntries(new URLSearchParams(qs || ''));
  if (emPreviaLogin()) return mostrarPreviaLogin();
  desenharTopo();
  const publicas = ['/entrar', '/cadastro', '/esqueci', '/redefinir'];
  if (!sessao.authenticated) {
    if (caminho === '/cadastro') return telaCadastro();
    if (caminho === '/esqueci') return telaEsqueci();
    if (caminho === '/redefinir') return telaRedefinir(consulta.token || '');
    return telaEntrar();
  }
  if (publicas.includes(caminho) && caminho !== '/redefinir') { location.hash = '#/'; return; }
  if (caminho === '/conta') { document.getElementById('faixa').hidden = true; return telaConta(); }
  let a;
  if (sessao.platform_admin && caminho === '/uso') { document.getElementById('faixa').hidden = true; return telaUso(); }
  if (sessao.platform_admin && (a = caminho.match(/^\/uso\/usuario\/(\d+)$/))) { document.getElementById('faixa').hidden = true; return telaUsoUsuario(Number(a[1])); }
  if (sessao.platform_admin && caminho === '/apis') { document.getElementById('faixa').hidden = true; return telaApiPainel(); }
  if (sessao.platform_admin && (a = caminho.match(/^\/apis\/cliente\/(\d+)$/))) { document.getElementById('faixa').hidden = true; return telaApiCliente(Number(a[1])); }
  if (sessao.platform_admin && caminho === '/admin') { document.getElementById('faixa').hidden = true; return telaAdminCompleta(consulta); }
  if (sessao.platform_admin && (a = caminho.match(/^\/admin\/escritorio\/(\d+)$/))) { document.getElementById('faixa').hidden = true; return telaAdminEscritorio(Number(a[1])); }
  if (!sessao.tenant && sessao.platform_admin && sessao.tenants.length === 0) { location.hash = '#/admin'; return; }
  if (!sessao.tenant) return sessao.tenants.length > 1 ? telaEscolherEscritorio() : telaEntrar();
  desenharFaixa(); atualizarSelos();
  let m;
  if (caminho === '/' || caminho === '') return telaPainel();
  if (caminho === '/acoes') return telaAcoes();
  if (caminho === '/consulta') return telaConsulta(consulta);
  if (caminho === '/processos') return telaProcessos(consulta);
  if ((m = caminho.match(/^\/processos\/(\d+)$/))) return telaProcesso(Number(m[1]));
  if (caminho === '/oabs' && pode('oab.view')) return telaOabs();
  if (caminho === '/publicacoes' && pode('oab.view')) return telaPublicacoes(consulta);
  if (caminho === '/uf' && pode('oab.view')) return telaUf();
  if (caminho === '/alertas' && pode('oab.view')) return telaAlertas();
  if (caminho === '/notificacoes') return telaNotificacoes();
  if (caminho === '/equipe') return telaEquipe();
  if (caminho === '/plano') return telaPlano();

  location.hash = '#/';
}
window.addEventListener('hashchange', rota);
carregarSessao().then(rota);
