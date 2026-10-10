/* Painel da API — Robô Jurídico.
 * Sem framework e sem nada de fora. Todo texto vindo do servidor entra com
 * textContent (nunca innerHTML), então nome de cliente não vira código na tela.
 */
"use strict";

const PLANOS = {
  Start: { limite_mensal: 2000, limite_por_minuto: 30, valor: 197 },
  Pro: { limite_mensal: 10000, limite_por_minuto: 60, valor: 497 },
  Revenda: { limite_mensal: 30000, limite_por_minuto: 120, valor: 997 },
};

const estado = { csrf: "", email: "", clientes: [] };
const $ = (sel) => document.querySelector(sel);

/* ── utilidades ─────────────────────────────────────────────────────────── */

function el(tag, attrs, ...filhos) {
  const n = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === "class") n.className = v;
    else if (k === "text") n.textContent = v;
    else if (k.startsWith("on")) n.addEventListener(k.slice(2), v);
    else n.setAttribute(k, v === true ? "" : String(v));
  }
  for (const f of filhos.flat()) {
    if (f === null || f === undefined || f === false) continue;
    n.append(f instanceof Node ? f : document.createTextNode(String(f)));
  }
  return n;
}

const fmtInt = new Intl.NumberFormat("pt-BR");
const fmtMoeda = new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" });
const inteiro = (n) => fmtInt.format(n || 0);
const reais = (centavos) => fmtMoeda.format((centavos || 0) / 100);

function dataHora(iso) {
  if (!iso) return "—";
  const d = new Date(iso);
  return d.toLocaleDateString("pt-BR") + " " + d.toLocaleTimeString("pt-BR", { hour: "2-digit", minute: "2-digit" });
}

function relativo(iso) {
  if (!iso) return "nunca";
  const s = (Date.now() - new Date(iso).getTime()) / 1000;
  if (s < 60) return "agora";
  if (s < 3600) return `há ${Math.floor(s / 60)} min`;
  if (s < 86400) return `há ${Math.floor(s / 3600)} h`;
  const dias = Math.floor(s / 86400);
  return dias === 1 ? "ontem" : `há ${dias} dias`;
}

function avisar(texto, falha = false) {
  const item = el("div", { class: "aviso-item" + (falha ? " falha" : ""), role: "status", text: texto });
  $("#avisos").append(item);
  setTimeout(() => item.remove(), 4500);
}

class ErroPainel extends Error {}

async function api(metodo, caminho, corpo) {
  const opcoes = { method: metodo, credentials: "same-origin", headers: {} };
  if (metodo !== "GET") opcoes.headers["X-CSRF-Token"] = estado.csrf;
  if (corpo !== undefined) {
    opcoes.headers["Content-Type"] = "application/json";
    opcoes.body = JSON.stringify(corpo);
  }
  let resposta;
  try {
    resposta = await fetch("/admin/api" + caminho, opcoes);
  } catch {
    throw new ErroPainel("Sem conexão com o servidor.");
  }
  let dados = {};
  try { dados = await resposta.json(); } catch { /* corpo vazio */ }
  if (resposta.status === 401 && caminho !== "/login") {
    mostrarLogin();
    throw new ErroPainel("Sessão expirada. Entre de novo.");
  }
  if (!resposta.ok || dados.ok === false) {
    const erro = dados.erro || {};
    const detalhe = (erro.detalhes || []).map((d) => `${d.campo}: ${d.mensagem}`).join("; ");
    throw new ErroPainel(erro.mensagem ? erro.mensagem + (detalhe ? ` (${detalhe})` : "") : "Algo deu errado.");
  }
  return dados;
}

function consumo(usado, limite, grande = false) {
  const pct = limite > 0 ? Math.min(100, (usado / limite) * 100) : (usado > 0 ? 100 : 0);
  const classe = pct >= 100 ? "estouro" : pct >= 80 ? "alerta" : "";
  const barra = el("div", { class: "enchido " + classe });
  barra.style.width = pct.toFixed(1) + "%";
  return el("div", { class: "consumo" + (grande ? " grande" : "") },
    el("div", { class: "trilho", role: "progressbar", "aria-valuemin": 0, "aria-valuemax": limite,
      "aria-valuenow": usado, "aria-label": "Consumo do mês" }, barra),
    el("span", { class: "txt", text: `${inteiro(usado)} / ${inteiro(limite)}  ·  ${pct.toFixed(0)}%` }));
}

function pilulaStatus(c) {
  if (!c.ativo) return el("span", { class: "pilula ruim", text: "Suspenso" });
  if (c.limite_mensal > 0 && c.usado_mes >= c.limite_mensal) return el("span", { class: "pilula aviso", text: "Cota esgotada" });
  return el("span", { class: "pilula ok", text: "Ativo" });
}

function pilulaHttp(status) {
  if (status === null || status === undefined) return el("span", { class: "pilula neutra", text: "…" });
  const classe = status < 400 ? "ok" : status === 402 || status === 429 || status === 404 ? "aviso" : "ruim";
  return el("span", { class: "pilula " + classe, text: String(status) });
}

function grafico(serie) {
  const maior = Math.max(1, ...serie.map((d) => d.chamadas));
  const cols = serie.map((d) => {
    const livres = d.chamadas - d.cobradas;
    const bc = el("div", { class: "b cobrada" });
    const bl = el("div", { class: "b livre" });
    bc.style.height = (d.cobradas / maior) * 100 + "%";
    bl.style.height = (livres / maior) * 100 + "%";
    const [a, m, dia] = d.dia.split("-");
    return el("div", { class: "col", title: `${dia}/${m}/${a}: ${inteiro(d.cobradas)} cobradas, ${inteiro(livres)} sem custo ou com erro` }, bl, bc);
  });
  const fmt = (s) => s.split("-").reverse().slice(0, 2).join("/");
  return el("div", {},
    el("div", { class: "barras", role: "img", "aria-label": "Chamadas por dia nos últimos dias" }, cols),
    el("div", { class: "eixo" }, el("span", { text: fmt(serie[0].dia) }), el("span", { text: fmt(serie[serie.length - 1].dia) })));
}

function tabela(cabecalhos, linhas, vazio) {
  if (!linhas.length) return el("p", { class: "vazio", text: vazio });
  return el("div", { class: "rolagem" }, el("table", {},
    el("thead", {}, el("tr", {}, cabecalhos.map(([t, c]) => el("th", { class: c || null, text: t })))),
    el("tbody", {}, linhas)));
}

function cabeca(sobre, titulo, subtitulo, ...acoes) {
  return el("header", { class: "cabeca" },
    el("div", {}, sobre ? el("p", { class: "sobre", text: sobre }) : null, el("h1", { text: titulo }),
      subtitulo ? el("p", { class: "subtitulo", text: subtitulo }) : null),
    acoes.length ? el("div", { class: "acoes" }, acoes) : null);
}

/* ── diálogos ───────────────────────────────────────────────────────────── */

function abrirDialogo(conteudo) {
  const d = $("#dialogo");
  d.replaceChildren(conteudo);
  if (!d.open) d.showModal();
  const foco = d.querySelector("input, select, textarea, button.primario, button.perigo");
  if (foco) foco.focus();
  return d;
}

function fecharDialogo() { const d = $("#dialogo"); if (d.open) d.close(); }

function confirmar(titulo, texto, rotulo, perigo = false) {
  return new Promise((resolve) => {
    const d = $("#dialogo");
    const fim = (v) => { d.removeEventListener("close", aoFechar); fecharDialogo(); resolve(v); };
    const aoFechar = () => fim(false);
    d.addEventListener("close", aoFechar);
    abrirDialogo(el("div", { class: "corpo" },
      el("h2", { text: titulo }), el("p", { class: "texto", text: texto }),
      el("div", { class: "rodape" },
        el("button", { type: "button", class: "botao", text: "Cancelar", onclick: () => fim(false) }),
        el("button", { type: "button", class: "botao " + (perigo ? "perigo" : "primario"), text: rotulo, onclick: () => fim(true) }))));
  });
}

/* ── login ──────────────────────────────────────────────────────────────── */

function mostrarLogin() {
  estado.csrf = "";
  fecharDialogo();
  $("#tela-app").hidden = true;
  $("#tela-login").hidden = false;
  $("#form-login").email.focus();
}

function mostrarApp() {
  $("#tela-login").hidden = true;
  $("#tela-app").hidden = false;
  $("#quem").textContent = estado.email;
  rotear();
}

$("#form-login").addEventListener("submit", async (ev) => {
  ev.preventDefault();
  const f = ev.target;
  const erro = $("#login-erro");
  erro.hidden = true;
  const botao = f.querySelector("button");
  botao.disabled = true;
  try {
    const r = await api("POST", "/login", { email: f.email.value, senha: f.senha.value });
    estado.csrf = r.csrf;
    estado.email = r.email;
    f.senha.value = "";
    if (!location.hash) location.hash = "#/visao";
    mostrarApp();
  } catch (e) {
    erro.textContent = e.message;
    erro.hidden = false;
  } finally {
    botao.disabled = false;
  }
});

$("#sair").addEventListener("click", async () => {
  try { await api("POST", "/sair"); } catch { /* já saiu */ }
  mostrarLogin();
});

/* ── rotas ──────────────────────────────────────────────────────────────── */

async function rotear() {
  if ($("#tela-app").hidden) return;
  const partes = (location.hash || "#/visao").slice(2).split("/");
  const secao = partes[0] || "visao";
  document.querySelectorAll(".menu a").forEach((a) => a.classList.toggle("ativo", a.dataset.rota === secao));
  const alvo = $("#conteudo");
  alvo.replaceChildren(el("p", { class: "carregando", text: "Carregando…" }));
  try {
    let tela;
    if (secao === "clientes" && partes[1]) tela = await telaCliente(Number(partes[1]));
    else if (secao === "clientes") tela = await telaClientes();
    else if (secao === "uso") tela = await telaUso();
    else if (secao === "auditoria") tela = await telaAuditoria();
    else tela = await telaVisao();
    alvo.replaceChildren(tela);
    alvo.focus();
  } catch (e) {
    if (e instanceof ErroPainel && !$("#tela-login").hidden) return;
    alvo.replaceChildren(el("p", { class: "erro", text: e.message }));
  }
}
window.addEventListener("hashchange", rotear);

/* ── visão geral ────────────────────────────────────────────────────────── */

async function telaVisao() {
  const r = await api("GET", "/resumo");
  const mes = new Date(r.mes_inicio).toLocaleDateString("pt-BR", { month: "long", year: "numeric", timeZone: "America/Sao_Paulo" });
  const taxaErro = r.mes.chamadas ? ((r.mes.erros / r.mes.chamadas) * 100).toFixed(1) + "%" : "—";
  const numero = (rot, val, obs) => el("div", { class: "numero" },
    el("p", { class: "rot", text: rot }), el("p", { class: "val", text: val }), el("p", { class: "obs", text: obs }));

  const top = r.top_clientes.length
    ? r.top_clientes.map((c) => el("tr", { class: "clicavel", onclick: () => { location.hash = `#/clientes/${c.id}`; } },
        el("td", {}, el("span", { class: "principal", text: c.nome }), el("span", { class: "secundario", text: c.empresa || "" })),
        el("td", {}, consumo(c.usado, c.limite_mensal))))
    : [];

  return el("div", {},
    cabeca(mes, "Visão geral", null,
      el("button", { class: "botao primario", text: "Novo cliente", onclick: () => formCliente() })),
    el("div", { class: "numeros" },
      numero("Receita mensal", reais(r.mrr_centavos), "soma dos planos ativos"),
      numero("Clientes ativos", inteiro(r.clientes_ativos), `${inteiro(r.clientes_total)} cadastrados`),
      numero("Consultas cobradas", inteiro(r.mes.cobradas), `${inteiro(r.mes.chamadas)} chamadas no mês`),
      numero("Tempo médio", r.mes.ms_medio ? (r.mes.ms_medio / 1000).toFixed(1) + " s" : "—", `erros de servidor: ${taxaErro}`)),
    el("div", { class: "duas" },
      el("section", { class: "cartao" },
        el("div", { class: "cartao-cabeca" }, el("h2", { text: "Chamadas por dia" }),
          el("div", { class: "legenda" }, el("span", { text: "cobradas" }), el("span", { class: "l-livre", text: "sem custo ou erro" }))),
        grafico(r.serie)),
      el("section", { class: "cartao" },
        el("div", { class: "cartao-cabeca" }, el("h2", { text: "Quem mais consome" }), el("p", { text: "no mês" })),
        tabela([["Cliente"], ["Consumo"]], top, "Nenhuma consulta cobrada neste mês ainda."))));
}

/* ── clientes ───────────────────────────────────────────────────────────── */

async function telaClientes() {
  const r = await api("GET", "/clientes");
  estado.clientes = r.clientes;
  const corpo = el("tbody");
  const desenhar = (filtro) => {
    const f = filtro.trim().toLowerCase();
    const lista = r.clientes.filter((c) => !f || [c.nome, c.empresa, c.email, c.plano].join(" ").toLowerCase().includes(f));
    corpo.replaceChildren(...lista.map((c) => el("tr", { class: "clicavel", onclick: () => { location.hash = `#/clientes/${c.id}`; } },
      el("td", {}, el("span", { class: "principal", text: c.nome }), el("span", { class: "secundario", text: c.empresa ? `${c.empresa} · ${c.email}` : c.email })),
      el("td", { text: c.plano || "—" }),
      el("td", {}, consumo(c.usado_mes, c.limite_mensal)),
      el("td", { class: "num", text: reais(c.valor_mensal_centavos) }),
      el("td", {}, pilulaStatus(c)),
      el("td", { class: "secundario", text: relativo(c.ultimo_uso) }))));
  };
  desenhar("");

  const conteudo = r.clientes.length
    ? el("div", { class: "rolagem" }, el("table", {},
        el("thead", {}, el("tr", {}, ["Cliente", "Plano", "Consumo do mês", "Mensalidade", "Situação", "Último uso"]
          .map((t, i) => el("th", { class: i === 3 ? "num" : null, text: t })))), corpo))
    : el("div", { class: "vazio" }, el("p", { text: "Nenhum cliente ainda. Cadastre o primeiro e gere a chave dele." }),
        el("button", { class: "botao primario", text: "Novo cliente", onclick: () => formCliente() }));

  return el("div", {},
    cabeca(null, "Clientes", `${inteiro(r.clientes.length)} cadastrados`,
      r.clientes.length ? el("input", { type: "search", class: "busca", placeholder: "Buscar por nome, e-mail ou plano",
        "aria-label": "Buscar clientes", oninput: (e) => desenhar(e.target.value) }) : null,
      el("button", { class: "botao primario", text: "Novo cliente", onclick: () => formCliente() })),
    el("section", { class: "cartao" }, conteudo));
}

function formCliente(atual) {
  const c = atual || { plano: "Start", ...PLANOS.Start, valor_mensal_centavos: PLANOS.Start.valor * 100, ativo: 1 };
  const campo = (rotulo, nome, attrs, ajuda) => el("label", { class: attrs.inteira ? "inteira" : null }, rotulo,
    el(attrs.tag || "input", { name: nome, ...attrs, tag: null, inteira: null }), ajuda ? el("span", { class: "ajuda", text: ajuda }) : null);

  const plano = el("select", { name: "plano" },
    [...Object.keys(PLANOS), "Personalizado"].map((p) => el("option", { value: p, text: p, selected: (c.plano || "Personalizado") === p })));
  if (c.plano && !PLANOS[c.plano] && c.plano !== "Personalizado") plano.append(el("option", { value: c.plano, text: c.plano, selected: true }));

  const erro = el("p", { class: "erro", role: "alert", hidden: true });
  const form = el("form", { novalidate: true },
    el("h2", { text: atual ? "Editar cliente" : "Novo cliente" }),
    el("div", { class: "grade-form" },
      campo("Nome do responsável", "nome", { required: true, maxlength: 120, value: c.nome || "", autocomplete: "off" }),
      campo("E-mail", "email", { type: "email", required: true, maxlength: 254, value: c.email || "", autocomplete: "off" }),
      campo("Empresa", "empresa", { maxlength: 120, value: c.empresa || "", inteira: true }),
      el("label", {}, "Plano", plano),
      campo("Mensalidade (R$)", "valor", { type: "number", min: 0, step: "0.01", value: ((c.valor_mensal_centavos || 0) / 100).toFixed(2) }),
      campo("Consultas por mês", "limite_mensal", { type: "number", min: 0, step: 1, value: c.limite_mensal }, "Cota que zera no dia 1º"),
      campo("Requisições por minuto", "limite_por_minuto", { type: "number", min: 1, step: 1, value: c.limite_por_minuto }),
      campo("Observações", "observacoes", { tag: "textarea", maxlength: 2000, inteira: true })),
    erro,
    el("div", { class: "rodape" },
      el("button", { type: "button", class: "botao", text: "Cancelar", onclick: fecharDialogo }),
      el("button", { type: "submit", class: "botao primario", text: atual ? "Salvar" : "Cadastrar" })));
  form.observacoes.value = c.observacoes || "";

  plano.addEventListener("change", () => {
    const p = PLANOS[plano.value];
    if (!p) return;
    form.limite_mensal.value = p.limite_mensal;
    form.limite_por_minuto.value = p.limite_por_minuto;
    form.valor.value = p.valor.toFixed(2);
  });

  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    erro.hidden = true;
    const dados = {
      nome: form.nome.value.trim(), email: form.email.value.trim(), empresa: form.empresa.value.trim(),
      plano: plano.value, valor_mensal_centavos: Math.round(Number(form.valor.value || 0) * 100),
      limite_mensal: Number(form.limite_mensal.value || 0), limite_por_minuto: Number(form.limite_por_minuto.value || 1),
      observacoes: form.observacoes.value,
    };
    if (!dados.nome || !dados.email) { erro.textContent = "Preencha nome e e-mail."; erro.hidden = false; return; }
    const botao = form.querySelector("button[type=submit]");
    botao.disabled = true;
    try {
      const r = atual ? await api("PATCH", `/clientes/${atual.id}`, dados) : await api("POST", "/clientes", dados);
      fecharDialogo();
      avisar(atual ? "Cliente atualizado." : "Cliente cadastrado. Agora gere a chave dele.");
      if (atual) rotear(); else location.hash = `#/clientes/${r.cliente.id}`;
    } catch (e) {
      erro.textContent = e.message;
      erro.hidden = false;
    } finally {
      botao.disabled = false;
    }
  });
  abrirDialogo(form);
}

async function telaCliente(id) {
  const r = await api("GET", `/clientes/${id}`);
  const c = r.cliente;
  const origem = location.origin;
  const ativas = r.chaves.filter((k) => !k.revogada_em);

  const suspender = async () => {
    const vai = c.ativo
      ? await confirmar("Suspender cliente?", `${c.nome} deixa de conseguir usar a API na hora, com todas as chaves. Dá para reativar depois.`, "Suspender", true)
      : await confirmar("Reativar cliente?", `As chaves ativas de ${c.nome} voltam a funcionar.`, "Reativar");
    if (!vai) return;
    try { await api("PATCH", `/clientes/${id}`, { ativo: !c.ativo }); avisar(c.ativo ? "Cliente suspenso." : "Cliente reativado."); rotear(); }
    catch (e) { avisar(e.message, true); }
  };

  const chaveItem = (k) => el("div", { class: "chave-item" + (k.revogada_em ? " revogada" : "") },
    el("span", { class: "mono", text: k.prefixo + "…" }),
    el("span", { class: "meta", text: [k.nome || "sem nome", `criada ${dataHora(k.criada_em)}`,
      k.revogada_em ? `revogada ${dataHora(k.revogada_em)}` : `último uso ${relativo(k.ultimo_uso)}`].join("  ·  ") }),
    k.revogada_em ? el("span", { class: "pilula neutra", text: "Revogada" })
      : el("button", { class: "botao pequeno", text: "Revogar", onclick: async () => {
          if (!(await confirmar("Revogar chave?", `A chave ${k.prefixo}… para de funcionar imediatamente. Quem estiver usando vai receber erro 401.`, "Revogar", true))) return;
          try { await api("DELETE", `/clientes/${id}/chaves/${k.id}`); avisar("Chave revogada."); rotear(); }
          catch (e) { avisar(e.message, true); }
        } }));

  const historico = r.uso.map((u) => el("tr", {},
    el("td", { class: "mono", text: dataHora(u.momento) }),
    el("td", { class: "mono", text: u.caminho }),
    el("td", {}, pilulaHttp(u.status)),
    el("td", { class: "num", text: u.ms !== null ? inteiro(u.ms) + " ms" : "—" }),
    el("td", { text: u.cobrado ? "Cobrada" : "—" }),
    el("td", { class: "mono", text: u.chave ? u.chave + "…" : "—" })));

  const exemplo = el("pre", { class: "codigo" },
    el("span", { class: "c", text: "# processo completo\n" }),
    `curl -H "X-API-Key: SUA_CHAVE" \\\n  ${origem}/v1/processos/5053283-30.2024.8.13.0079\n\n`,
    el("span", { class: "c", text: "# consumo do mês\n" }),
    `curl -H "X-API-Key: SUA_CHAVE" ${origem}/v1/conta`);

  const reinicio = new Date(); reinicio.setMonth(reinicio.getMonth() + 1, 1);

  return el("div", {},
    el("p", { class: "migalha" }, el("a", { href: "#/clientes", text: "Clientes" }), " / ", c.nome),
    cabeca(null, c.nome, [c.empresa, c.email].filter(Boolean).join(" · "),
      el("button", { class: "botao", text: "Editar", onclick: () => formCliente(c) }),
      el("button", { class: "botao" + (c.ativo ? "" : " primario"), text: c.ativo ? "Suspender" : "Reativar", onclick: suspender })),
    el("section", { class: "cartao" },
      el("div", { class: "cartao-cabeca" },
        el("h2", { text: "Consumo do mês" }), pilulaStatus(c)),
      consumo(c.usado_mes, c.limite_mensal, true),
      el("dl", { class: "ficha" },
        el("div", {}, el("dt", { text: "Plano" }), el("dd", { text: c.plano || "—" })),
        el("div", {}, el("dt", { text: "Mensalidade" }), el("dd", { text: reais(c.valor_mensal_centavos) })),
        el("div", {}, el("dt", { text: "Limite por minuto" }), el("dd", { text: inteiro(c.limite_por_minuto) + " requisições" })),
        el("div", {}, el("dt", { text: "Cota renova em" }), el("dd", { text: "01/" + String(reinicio.getMonth() + 1).padStart(2, "0") + "/" + reinicio.getFullYear() }))),
      c.observacoes ? el("p", { class: "subtitulo", text: c.observacoes }) : null),
    el("section", { class: "cartao" },
      el("div", { class: "cartao-cabeca" },
        el("div", {}, el("h2", { text: "Chaves de acesso" }), el("p", { text: `${ativas.length} ativa(s)` })),
        el("button", { class: "botao primario", text: "Gerar chave", disabled: !c.ativo, onclick: () => gerarChave(c) })),
      r.chaves.length ? el("div", { class: "chave-lista" }, r.chaves.map(chaveItem))
        : el("p", { class: "vazio", text: "Nenhuma chave ainda. Gere uma e entregue ao cliente por canal seguro." })),
    el("section", { class: "cartao" },
      el("div", { class: "cartao-cabeca" }, el("h2", { text: "Chamadas por dia" }),
        el("div", { class: "legenda" }, el("span", { text: "cobradas" }), el("span", { class: "l-livre", text: "sem custo ou erro" }))),
      grafico(r.serie)),
    el("section", { class: "cartao" },
      el("div", { class: "cartao-cabeca" }, el("h2", { text: "Como o cliente usa" }), el("p", { text: "envie junto com a chave" })),
      exemplo),
    el("section", { class: "cartao" },
      el("div", { class: "cartao-cabeca" }, el("h2", { text: "Histórico" }), el("p", { text: "últimas 100 chamadas" })),
      tabela([["Quando"], ["Chamada"], ["Status"], ["Tempo", "num"], ["Cobrança"], ["Chave"]], historico,
        "Este cliente ainda não fez nenhuma chamada.")));
}

function gerarChave(c) {
  const erro = el("p", { class: "erro", role: "alert", hidden: true });
  const form = el("form", { novalidate: true },
    el("h2", { text: "Gerar chave" }),
    el("p", { class: "texto", text: `Nova chave de acesso para ${c.nome}. As chaves que já existem continuam valendo.` }),
    el("label", {}, "Identificação (opcional)",
      el("input", { name: "nome", maxlength: 60, placeholder: "ex.: produção, homologação", autocomplete: "off" })),
    erro,
    el("div", { class: "rodape" },
      el("button", { type: "button", class: "botao", text: "Cancelar", onclick: fecharDialogo }),
      el("button", { type: "submit", class: "botao primario", text: "Gerar" })));
  form.addEventListener("submit", async (ev) => {
    ev.preventDefault();
    try {
      const r = await api("POST", `/clientes/${c.id}/chaves`, { nome: form.nome.value.trim() });
      mostrarChave(r.chave);
    } catch (e) { erro.textContent = e.message; erro.hidden = false; }
  });
  abrirDialogo(form);
}

function mostrarChave(texto) {
  const campo = el("input", { readonly: true, value: texto, "aria-label": "Chave gerada", onfocus: (e) => e.target.select() });
  const copiar = el("button", { type: "button", class: "botao primario", text: "Copiar", onclick: async () => {
    try { await navigator.clipboard.writeText(texto); copiar.textContent = "Copiada"; }
    catch { campo.select(); avisar("Selecione e copie com Ctrl+C.", true); }
  } });
  const d = abrirDialogo(el("div", { class: "corpo" },
    el("h2", { text: "Chave gerada" }),
    el("div", { class: "chave-nova" },
      el("p", { text: "Copie agora. Por segurança, ela não aparece de novo: o painel guarda só uma impressão digital dela. Se perder, revogue e gere outra." }),
      el("div", { class: "chave-copia" }, campo, copiar)),
    el("div", { class: "rodape" }, el("button", { type: "button", class: "botao", text: "Já copiei", onclick: fecharDialogo }))));
  d.addEventListener("close", () => { campo.value = ""; rotear(); }, { once: true });
}

/* ── uso e auditoria ────────────────────────────────────────────────────── */

async function telaUso() {
  const r = await api("GET", "/uso?limite=300");
  const linhas = r.uso.map((u) => el("tr", {},
    el("td", { class: "mono", text: dataHora(u.momento) }),
    el("td", {}, u.cliente_id ? el("a", { href: `#/clientes/${u.cliente_id}`, text: u.cliente || "—" }) : "—"),
    el("td", { class: "mono", text: u.caminho }),
    el("td", {}, pilulaHttp(u.status)),
    el("td", { class: "num", text: u.ms !== null ? inteiro(u.ms) + " ms" : "—" }),
    el("td", { text: u.cobrado ? "Cobrada" : "—" }),
    el("td", { class: "mono", text: u.ip })));
  return el("div", {},
    cabeca(null, "Uso da API", "As últimas 300 chamadas de todos os clientes"),
    el("section", { class: "cartao" },
      tabela([["Quando"], ["Cliente"], ["Chamada"], ["Status"], ["Tempo", "num"], ["Cobrança"], ["IP"]], linhas,
        "Nenhuma chamada registrada ainda.")));
}

const ACOES = {
  login: "Entrou no painel", logout: "Saiu do painel", login_falhou: "Login recusado",
  login_bloqueado: "Login bloqueado por excesso de tentativas", cliente_criado: "Cadastrou cliente",
  cliente_alterado: "Alterou cliente", cliente_suspenso: "Suspendeu cliente", cliente_reativado: "Reativou cliente",
  chave_gerada: "Gerou chave", chave_revogada: "Revogou chave",
};

async function telaAuditoria() {
  const r = await api("GET", "/eventos?limite=300");
  const linhas = r.eventos.map((e) => {
    const alvo = /^cliente:(\d+)$/.exec(e.alvo || "");
    return el("tr", {},
      el("td", { class: "mono", text: dataHora(e.momento) }),
      el("td", { text: e.admin_email || "—" }),
      el("td", {}, el("span", { class: "principal", text: ACOES[e.acao] || e.acao }),
        e.detalhe ? el("span", { class: "secundario", text: e.detalhe }) : null),
      el("td", {}, alvo ? el("a", { href: `#/clientes/${alvo[1]}`, text: `cliente #${alvo[1]}` }) : (e.alvo || "—")),
      el("td", { class: "mono", text: e.ip }));
  });
  return el("div", {},
    cabeca(null, "Auditoria", "Quem fez o quê no painel, e quando"),
    el("section", { class: "cartao" },
      tabela([["Quando"], ["Quem"], ["Ação"], ["Alvo"], ["IP"]], linhas, "Nenhum evento ainda.")));
}

/* ── início ─────────────────────────────────────────────────────────────── */

(async function iniciar() {
  try {
    const r = await api("GET", "/sessao");
    estado.csrf = r.csrf;
    estado.email = r.email;
    mostrarApp();
  } catch {
    mostrarLogin();
  }
})();
