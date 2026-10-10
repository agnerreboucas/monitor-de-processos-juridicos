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

// ── topo ─────────────────────────────────────────────────────────────────────
function desenharTopo() {
  const topo = document.getElementById('topo');
  const logado = sessao && sessao.authenticated && sessao.tenant;
  topo.hidden = !logado;
  if (!logado) return;
  const itens = [['#/', 'Painel'], ['#/processos', 'Processos'],
    ...(pode('oab.view') ? [['#/uf', 'Ações por UF'], ['#/publicacoes', 'Publicações'], ['#/oabs', 'Advogados (OAB)']] : []),
    ['#/notificacoes', 'Notificações'], ['#/equipe', 'Equipe'], ['#/plano', 'Plano']];
  const atual = location.hash || '#/';
  document.getElementById('menu').replaceChildren(...itens.map(([href, nome]) =>
    h('a', { href, class: (href === '#/' ? atual === '#/' : atual.startsWith(href)) ? 'ativo' : '' }, nome)));
  document.getElementById('quem').textContent = sessao.user.name;
}
document.getElementById('sair').addEventListener('click', async () => { await api('POST', '/api/logout'); await carregarSessao(); location.hash = '#/entrar'; });

// ── telas de entrada ─────────────────────────────────────────────────────────
function campo(rotulo, nome, tipo, extra) { return h('label', {}, rotulo, h('input', { name: nome, type: tipo || 'text', required: true, ...(extra || {}) })); }
function formulario(titulo, campos, botao, aoEnviar, rodape) {
  const msg = h('div');
  const form = h('form', { class: 'empilhado', onsubmit: async (e) => {
    e.preventDefault();
    const dados = Object.fromEntries(new FormData(form));
    msg.replaceChildren(); const b = form.querySelector('button'); b.disabled = true;
    const erro = await aoEnviar(dados);
    b.disabled = false;
    if (erro) msg.replaceChildren(aviso(erro, 'erro'));
  } }, ...campos, h('button', { class: 'principal' }, botao), msg);
  return h('div', { class: 'cartao estreito' }, h('h1', {}, titulo), h('div', { style: 'height:12px' }), form, rodape || null);
}
function telaEntrar() {
  app.replaceChildren(formulario('Entrar', [campo('E-mail', 'email', 'email', { autocomplete: 'username' }), campo('Senha', 'password', 'password', { autocomplete: 'current-password' })], 'Entrar',
    async (d) => {
      const r = await api('POST', '/api/login', d);
      if (!r.ok) return r.error;
      await carregarSessao();
      if (!sessao.tenant && sessao.tenants.length > 1) return telaEscolherEscritorio();
      location.hash = '#/'; rota();
    },
    h('p', { class: 'suave' }, h('a', { href: '#/esqueci' }, 'Esqueci a senha'), ' · ', h('a', { href: '#/cadastro' }, 'Criar conta do escritório'))));
}
function telaEscolherEscritorio() {
  app.replaceChildren(h('div', { class: 'cartao estreito' }, h('h1', {}, 'Escolha o escritório'), h('div', { style: 'height:12px' }),
    ...sessao.tenants.map((t) => h('p', {}, h('button', { class: 'principal', onclick: async () => { await api('POST', '/api/tenant/select', { tenant_id: t.tenant_id }); await carregarSessao(); location.hash = '#/'; rota(); } }, t.name + ' (' + t.role + ')')))));
}
function telaCadastro() {
  app.replaceChildren(formulario('Criar conta do escritório', [campo('Nome do escritório', 'office_name'), campo('Seu nome', 'name'), campo('E-mail', 'email', 'email'), campo('Senha (mínimo 10 caracteres)', 'password', 'password', { minlength: 10, autocomplete: 'new-password' })], 'Criar conta',
    async (d) => { const r = await api('POST', '/api/register', d); if (!r.ok) return r.error; await carregarSessao(); location.hash = '#/'; rota(); },
    h('p', { class: 'suave' }, h('a', { href: '#/entrar' }, 'Já tenho conta'))));
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
async function telaPainel() {
  const r = await api('GET', '/api/dashboard');
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'));
  const d = r.data;
  const card = (titulo, valor, cor, href) => h('a', { class: 'cartao', href: href || '#/processos', style: 'text-decoration:none;color:inherit' }, h('h2', {}, titulo), h('div', { class: 'numero', style: cor ? 'color:var(--' + cor + ')' : '' }, valor));
  app.replaceChildren(
    h('div', { class: 'linha entre' }, h('h1', {}, 'Painel'), pode('process.create') ? h('a', { href: '#/processos' }, h('button', { class: 'principal' }, 'Cadastrar processo')) : null),
    h('div', { class: 'grade' },
      card('Processos', d.processes_total), card('Ativos', d.processes_active),
      card('Com falha de consulta', d.with_error, d.with_error ? 'verm' : '', '#/processos?with_error=1'),
      card('Aguardando 1ª consulta', d.never_checked, d.never_checked ? 'amar' : ''),
      card('Sem atualização há 7+ dias', d.stale, d.stale ? 'amar' : '', '#/processos?stale_days=7'),
      card('Notificações não lidas', d.unread_notifications, d.unread_notifications ? 'azul' : '', '#/notificacoes')),
    d.oab_alerts_unseen ? h('a', { href: '#/uf', class: 'msg amarelo', style: 'text-decoration:none;display:block' },
      'Atenção: ' + d.oab_alerts_unseen + ' alerta(s) de possível inscrição suplementar. Clique para ver.') : null,
    h('p', { class: 'suave' }, 'Última consulta bem-sucedida: ' + dataHoraBR(d.last_success_at)),
    h('div', { class: 'cartao' }, h('h2', {}, 'Movimentações dos últimos 7 dias'),
      d.recent_movements.length ? d.recent_movements.map((m) => h('div', { class: 'mov' },
        h('a', { href: '#/processos/' + m.process_id }, m.cnj), ' ', h('span', { class: 'etq' }, m.tribunal), ' ',
        h('span', { class: 'suave' }, 'movimentação de ' + dataBR(m.occurred_at) + ' · capturada em ' + dataHoraBR(m.captured_at)),
        h('pre', {}, (m.description || '').slice(0, 280)))) : h('p', { class: 'vazio' }, 'Nenhuma movimentação nova nos últimos 7 dias.')));
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
  app.replaceChildren(h('h1', {}, 'Processos'),
    novo ? h('div', { class: 'cartao' }, h('h2', {}, 'Cadastrar processo'), novo, msg) : null,
    h('div', { class: 'cartao' }, h('div', { class: 'filtros' }, busca, status, total),
      h('div', { style: 'overflow-x:auto' }, h('table', {}, h('thead', {}, h('tr', {}, ...['Número', 'Cliente', 'Tribunal', 'Responsável', 'Última consulta com sucesso', 'Situação'].map((t) => h('th', {}, t)))), corpoTabela))));
  carregar();
}

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
      pode('process.update') ? h('div', { class: 'linha' },
        p.status === 'active' ? acao('Consultar agora', '/check') : null,
        p.status === 'active' ? acao('Pausar', '/pause') : null,
        p.status === 'paused' ? acao('Retomar', '/resume') : null,
        pode('process.archive') && p.status !== 'archived' ? acao('Arquivar', '/archive', { perigo: true, confirmar: 'Arquivar para de monitorar e libera uma vaga do plano. O histórico continua guardado. Continuar?' }) : null) : null,
      msg),
    h('div', { class: 'cartao' }, h('h2', {}, 'Histórico de movimentações'),
      movements.length ? movements.map((m) => h('div', { class: 'mov' },
        h('div', { class: 'linha' }, h('strong', {}, 'Movimentação de ' + dataBR(m.occurred_at)), h('span', { class: 'suave' }, 'capturada em ' + dataHoraBR(m.captured_at)),
          m.kind ? h('span', { class: 'etq' }, m.kind) : null, m.is_baseline ? h('span', { class: 'etq' }, 'histórico inicial') : null,
          link(m.source_url) ? h('a', { href: link(m.source_url), target: '_blank', rel: 'noopener noreferrer' }, 'Fonte oficial') : null),
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
  const r = await api('GET', '/api/plan');
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'));
  const p = r.data;
  const uso = (rotulo, usado, max) => h('div', { class: 'cartao' }, h('h2', {}, rotulo), h('div', { class: 'numero' }, usado + ' / ' + max), h('div', { class: 'barra' }, h('i', { style: 'width:' + Math.min(100, Math.round(usado / max * 100)) + '%' })));
  app.replaceChildren(h('h1', {}, 'Plano e assinatura'),
    h('div', { class: 'cartao' }, h('h2', {}, 'Plano contratado'), h('div', { class: 'numero' }, p.plan_name),
      h('p', { class: 'suave' }, 'Situação: ' + { trialing: 'período de teste', active: 'ativa', past_due: 'pagamento em atraso', suspended: 'suspensa', cancelled: 'cancelada' }[p.status] +
        ' · cada processo é consultado no mínimo a cada ' + (p.min_interval_minutes / 60) + ' h')),
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
      selecao('new_only', [['', 'Todas'], ['1', 'Só as novas']]), total), msg, lista));
  carregar();
}

async function telaUf(ano) {
  const r = await api('GET', '/api/oab-stats', null, ano ? { year: ano } : null);
  if (!r.ok) return app.replaceChildren(aviso(r.error, 'erro'));
  const d = r.data;
  const nomes = Object.fromEntries(d.oabs.map((o) => [o.id, o.name]));
  const anos = [...new Set([d.year, ...d.years])].sort((a, b) => b - a);
  const maximo = Math.max(1, ...d.rows.map((x) => x.total));
  const alertas = d.alerts.map((a) => h('div', { class: 'msg amarelo' },
    h('strong', {}, a.lawyer_name + ' (OAB ' + a.oab_number + '/' + a.oab_uf + ')'), ' tem ' + a.process_count + ' processos diferentes com publicação em ' + a.alert_year + ' no(a) ',
    h('strong', {}, a.uf), ', onde não consta inscrição suplementar. O Estatuto da Advocacia (art. 10, §2º) considera habitual a atuação em mais de ' + d.threshold + ' causas por ano em uma Seccional. ',
    h('span', { class: 'suave' }, 'Indicativo: confira com a Seccional. '),
    !a.seen_at ? h('button', { class: 'link', onclick: async () => { await api('POST', '/api/oab-alerts/' + a.id + '/seen'); telaUf(d.year); } }, 'marcar como visto') : h('span', { class: 'etq' }, 'visto')));
  app.replaceChildren(
    h('div', { class: 'linha entre' }, h('h1', {}, 'Ações por UF'),
      h('label', { class: 'linha suave' }, 'Ano ', h('select', { onchange: (e) => telaUf(e.target.value) }, ...anos.map((a) => h('option', { value: a, selected: a === d.year }, String(a)))))),
    h('p', { class: 'suave' }, d.notice),
    ...alertas,
    h('div', { class: 'grade' }, h('div', { class: 'cartao' }, h('h2', {}, 'Processos no ano'), h('div', { class: 'numero' }, d.totals.total)),
      h('div', { class: 'cartao' }, h('h2', {}, 'Estados'), h('div', { class: 'numero' }, d.totals.states)),
      ...Object.entries(GRUPOS).map(([k, t]) => h('div', { class: 'cartao' }, h('h2', {}, t), h('div', { class: 'numero' }, d.totals[k])))),
    h('div', { class: 'cartao', style: 'overflow-x:auto' }, h('table', { class: 'larga' },
      h('thead', {}, h('tr', {}, ...['UF', 'Advogado(a)', 'Estadual', 'Federal', 'Trabalho', 'Outras', 'Total', ''].map((t) => h('th', {}, t)))),
      h('tbody', {}, ...(d.rows.length ? d.rows.flatMap((row) => {
        const porOab = Object.entries(row.by_oab);
        return [h('tr', { class: 'uf' }, h('td', {}, h('strong', {}, row.uf)), h('td', {}, porOab.length + ' advogado(s)'),
            ...Object.keys(GRUPOS).map((g) => h('td', {}, String(row.groups[g]))), h('td', {}, h('strong', {}, String(row.total))),
            h('td', { style: 'width:22%' }, h('div', { class: 'barra' }, h('i', { style: 'width:' + Math.round(row.total / maximo * 100) + '%' })))),
          ...porOab.map(([id, v]) => h('tr', {}, h('td', {}), h('td', { class: 'suave' }, nomes[id] || 'OAB ' + id),
            ...Object.keys(GRUPOS).map((g) => h('td', { class: 'suave' }, String(v[g]))), h('td', { class: 'suave' }, String(v.total)), h('td', {})))];
      }) : [h('tr', {}, h('td', { colspan: 8, class: 'vazio' }, 'Sem publicações em ' + d.year + '. Cadastre uma OAB e aguarde a primeira consulta.'))])))),
    d.undetermined ? h('p', { class: 'suave' }, d.undetermined + ' processo(s) estão em tribunais que cobrem mais de um estado (por exemplo TRF1, TRF4, TRT8). Eles não entram na tabela por UF nem no alerta, porque a UF não pode ser afirmada.') : null);
}

// ── roteador ─────────────────────────────────────────────────────────────────
async function rota() {
  const [caminho, qs] = (location.hash || '#/').slice(1).split('?');
  const consulta = Object.fromEntries(new URLSearchParams(qs || ''));
  desenharTopo();
  const publicas = ['/entrar', '/cadastro', '/esqueci', '/redefinir'];
  if (!sessao.authenticated) {
    if (caminho === '/cadastro') return telaCadastro();
    if (caminho === '/esqueci') return telaEsqueci();
    if (caminho === '/redefinir') return telaRedefinir(consulta.token || '');
    return telaEntrar();
  }
  if (publicas.includes(caminho) && caminho !== '/redefinir') { location.hash = '#/'; return; }
  if (!sessao.tenant) return sessao.tenants.length > 1 ? telaEscolherEscritorio() : telaEntrar();
  let m;
  if (caminho === '/' || caminho === '') return telaPainel();
  if (caminho === '/processos') return telaProcessos(consulta);
  if ((m = caminho.match(/^\/processos\/(\d+)$/))) return telaProcesso(Number(m[1]));
  if (caminho === '/oabs' && pode('oab.view')) return telaOabs();
  if (caminho === '/publicacoes' && pode('oab.view')) return telaPublicacoes(consulta);
  if (caminho === '/uf' && pode('oab.view')) return telaUf();
  if (caminho === '/notificacoes') return telaNotificacoes();
  if (caminho === '/equipe') return telaEquipe();
  if (caminho === '/plano') return telaPlano();
  location.hash = '#/';
}
window.addEventListener('hashchange', rota);
carregarSessao().then(rota);
