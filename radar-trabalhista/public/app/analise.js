// Leitura automática do texto de uma publicação: que ato é, o que fazer, quem, prazo estimado, impacto, riscos e atores.
// Regras simples e transparentes (palavras e frases do próprio texto). NÃO é parecer jurídico: serve para triar e nunca
// substitui a conferência do advogado. O prazo é uma ESTIMATIVA em dias úteis (feriados nacionais e recesso 20/12–20/01).
(function (raiz) {
  'use strict';

  const norm = (t) => String(t || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/\s+/g, ' ');
  const limpar = (t) => String(t || '').replace(/\s+/g, ' ').trim();

  // ── calendário: dias úteis ───────────────────────────────────────────────
  const iso = (d) => d.toISOString().slice(0, 10);
  const utc = (s) => new Date(s + 'T12:00:00Z');
  const soma = (s, n) => { const d = utc(s); d.setUTCDate(d.getUTCDate() + n); return iso(d); };
  function pascoa(ano) {                       // algoritmo de Meeus/Jones/Butcher
    const a = ano % 19, b = Math.floor(ano / 100), c = ano % 100, d = Math.floor(b / 4), e = b % 4, f = Math.floor((b + 8) / 25), g = Math.floor((b - f + 1) / 3);
    const h = (19 * a + b - d - g + 15) % 30, i = Math.floor(c / 4), k = c % 4, l = (32 + 2 * e + 2 * i - h - k) % 7, m = Math.floor((a + 11 * h + 22 * l) / 451);
    const mes = Math.floor((h + l - 7 * m + 114) / 31), dia = ((h + l - 7 * m + 114) % 31) + 1;
    return `${ano}-${String(mes).padStart(2, '0')}-${String(dia).padStart(2, '0')}`;
  }
  const feriadosCache = {};
  function feriados(ano) {
    if (feriadosCache[ano]) return feriadosCache[ano];
    const p = pascoa(ano), f = new Set(['01-01', '04-21', '05-01', '09-07', '10-12', '11-02', '11-15', '11-20', '12-25'].map((x) => ano + '-' + x));
    f.add(soma(p, -2)); f.add(soma(p, 60));     // sexta-feira santa e Corpus Christi (feriados forenses)
    return (feriadosCache[ano] = f);
  }
  function diaUtil(s) {
    const d = utc(s), w = d.getUTCDay(), md = s.slice(5);
    if (w === 0 || w === 6) return false;
    if (md >= '12-20' || md <= '01-20') return false;          // recesso forense
    return !feriados(Number(s.slice(0, 4))).has(s);
  }
  const proximoUtil = (s) => { let d = soma(s, 1); while (!diaUtil(d)) d = soma(d, 1); return d; };
  function somaUteis(s, n) { let d = s; for (let i = 0; i < n; i++) d = proximoUtil(d); return d; }
  function diasEntre(a, b) { return Math.round((utc(b) - utc(a)) / 86400000); }

  // ── extração ─────────────────────────────────────────────────────────────
  const NUM = '(\\d{1,3})\\s*(?:\\([^)]{1,30}\\)\\s*)?';
  const RE_PRAZO = [
    new RegExp('prazo\\s+(?:comum\\s+|sucessivo\\s+|legal\\s+|improrrogavel\\s+|final\\s+)?(?:de|para|:)?\\s*' + NUM + '(dias uteis|dias corridos|dias|horas|hora)', 'g'),
    new RegExp('(?:no|em)\\s+(?:o\\s+)?prazo\\s+(?:de\\s+)?' + NUM + '(dias uteis|dias corridos|dias|horas|hora)', 'g'),
    new RegExp('\\b(?:em|no prazo de)\\s+' + NUM + '(dias uteis|dias corridos|dias|horas)\\b', 'g'),
    new RegExp('\\b' + NUM + '(dias uteis|dias|horas)\\s+(?:para|a contar|contados)', 'g'),
  ];
  function prazos(n) {
    const achados = [], vistos = new Set();
    for (const re of RE_PRAZO) { re.lastIndex = 0; let m;
      while ((m = re.exec(n))) { const dias = Number(m[1]), un = m[2].startsWith('hora') ? 'horas' : (m[2].includes('corrid') ? 'corridos' : 'uteis');
        const chave = m.index + ':' + dias; if (vistos.has(chave) || dias === 0 || dias > 365) continue; vistos.add(chave);
        achados.push({ n: dias, unidade: un, pos: m.index, contexto: n.slice(Math.max(0, m.index - 140), m.index + 90) }); } }
    return achados.sort((a, b) => a.pos - b.pos);
  }

  function dataBR(txt) { const m = txt.match(/(\d{2})\/(\d{2})\/(\d{4})/); return m ? `${m[3]}-${m[2]}-${m[1]}` : null; }
  function audiencias(n) {
    const out = [], re = /audiencia(?:s)?\s*(una|inicial|de instrucao|de conciliacao|de julgamento|de encerramento|telepresencial|virtual|presencial|trabalhista)?[^.]{0,160}?(?:dia|data|em)\s*(\d{2}\/\d{2}\/\d{4})(?:[^0-9]{1,25}?(\d{1,2})\s*(?:h|:)\s*(\d{2})?)?/g;
    let m;
    while ((m = re.exec(n))) { const iso_ = dataBR(m[2]); if (!iso_) continue;
      const contexto = n.slice(m.index, m.index + 700);
      out.push({ tipo: (m[1] && !/telepresencial|virtual|presencial|trabalhista/.test(m[1]) ? m[1] : 'audiencia'), data: iso_, hora: m[3] ? String(m[3]).padStart(2, '0') + ':' + (m[4] || '00') : null,
        modo: /telepresencial|zoom|videoconferencia|meet|virtual|100% digital/.test(contexto) ? 'telepresencial' : (/presencial|sala de audiencias/.test(contexto) ? 'presencial' : null) }); }
    const unicas = []; for (const a of out) if (!unicas.some((u) => u.data === a.data && u.hora === a.hora)) unicas.push(a);
    return unicas;
  }

  const LADO_A = /reclamante|autor(?:a|es|as)?\b|exequente|requerente|embargante|impetrante/, LADO_P = /reclamad[oa]s?|\breu(?:s)?\b|executad[oa]s?|requerid[oa]s?|embargad[oa]s?/;
  // quem é o destinatário direto da frase ("concede-se ao reclamado…", "devem as reclamadas…", "notifique-se a reclamada")
  const DESTINO = /(?:concede-se|conceda-se|deve\(m\)|devem|deve|ficam?|intime-se|intimem-se|notifique-se|intimad[oa]s?|cabe|cabera|incumbe)\s+(?:ao\(a\)|ao\(à\)|a\(s\)|o\(a\)|ao|aos|as|os|a|o)?\s*\(?(reclamad|reclamante|autor|reu|executad|exequente|perit|partes)/;
  function quem(trecho) {
    const t = norm(trecho), q = [];
    const d = t.match(DESTINO);
    if (d && d[1] !== 'partes') return [/^(?:reclamante|autor|exequente)/.test(d[1]) ? 'parte autora (reclamante/autor)' : /^perit/.test(d[1]) ? 'perito' : 'parte ré (reclamada/réu)'];
    if (/ambas as partes|as partes|partes\b/.test(t) && !/partes? (?:reclamad|autor)/.test(t)) q.push('ambas as partes');
    if (LADO_A.test(t) && !/ambas as partes/.test(t)) q.push('parte autora (reclamante/autor)');
    if (LADO_P.test(t) && !/ambas as partes/.test(t)) q.push('parte ré (reclamada/réu)');
    if (/perit[oa]|expert/.test(t)) q.push('perito');
    if (/ministerio publico|\bmpt?\b|procurador/.test(t)) q.push('Ministério Público');
    if (/apelante|recorrente|agravante|embargante/.test(t)) q.push('quem recorreu');
    if (/apelad[oa]|recorrid[oa]|agravad[oa]/.test(t)) q.push('parte contrária ao recurso');
    if (/secretaria|cartorio|diretor de secretaria/.test(t) && !q.length) q.push('secretaria/cartório');
    return [...new Set(q)];
  }
  const ladoDe = (qs) => { const a = qs.some((x) => x.startsWith('parte autora')), p = qs.some((x) => x.startsWith('parte ré')), amb = qs.includes('ambas as partes'); return amb || (a && p) ? 'AP' : a ? 'A' : p ? 'P' : null; };

  // ── tipos de ato ─────────────────────────────────────────────────────────
  const ATOS = [
    ['sigilo', 'Processo sigiloso (conteúdo fora do Diário)', /processo sigiloso|tramita sob sigilo|segredo de justica|para visualizacao do documento consulte os autos/],
    ['sentenca', 'Sentença', /julgo (?:totalmente |parcialmente )?(?:procedente|improcedente|extint)|julgo procedentes|resolvo o merito|dispositivo[^.]{0,60}julgo|(?:intimacao|intimad[oa]s?|ciencia)[^.]{0,60}\bsentenca\b(?! de (?:interdicao|curatela))|\bsentenca\b[^.]{0,30}(?:publicada|proferida|prolatada|id [0-9a-f]{5,})/],
    ['acordao', 'Acórdão / decisão colegiada', /\bacordao\b|\bacordam\b|negar provimento|dar provimento|nao conhecer d[oe]/],
    ['audiencia', 'Audiência', /audiencia/],
    ['citacao', 'Citação / notificação inicial', /\bcitacao\b|\bcite-se\b|\bcitem-se\b|\bnotificacao inicial\b|mandado de citacao|edital de citacao|ficam? (?:o\(a\) |a\(s\) |as |os |o |a )?(?:reu|reclamad|executad|requerid)\w* (?:citad|notificad)|notifique-se (?:a|o) reclamad/],
    ['penhora', 'Penhora / bloqueio de bens', /penhora|bloqueio|sisbajud|bacenjud|renajud|infojud|constricao|alienacao judicial|leilao/],
    ['pericia', 'Perícia', /\bpericia\b|\bperit[oa]\b|\blaudo\b/],
    ['calculos', 'Cálculos / liquidação', /\bcalculos?\b|liquidacao|atualizacao do debito|memoria de calculo/],
    ['custas', 'Custas / depósito / preparo', /custas|deposito recursal|preparo|honorarios periciais|guia gru|alvara|deposito judicial/],
    ['recurso', 'Recurso / embargos', /embargos de declaracao|recurso ordinario|recurso de revista|agravo (?:de peticao|de instrumento|interno|regimental)|\bapelacao|apelante|contrarrazoes|contraminuta|recurso especial|recurso extraordinario/],
    ['decisao', 'Decisão', /\bdecisao\b|\bdecido\b|\bdefiro\b|\bindefiro\b|\bhomologo\b/],
    ['despacho', 'Despacho', /\bdespacho\b|\bintime-se\b|\bcumpra-se\b|\bvista a(?:s)? parte/],
    ['edital', 'Edital', /\bedital\b/],
    ['certidao', 'Certidão', /\bcertidao\b|\bcertifico\b/],
    ['arquivamento', 'Arquivamento / baixa / trânsito', /arquivamento|arquivem-se|baixa definitiva|transito em julgado|transitou em julgado|extinc[ao]o|extingo/],
    ['pauta', 'Pauta de julgamento', /pauta de julgamento|sessao de julgamento|inclu[ií]do em pauta/],
    ['distribuicao', 'Distribuição', /lista de distribuicao|distribuido|distribuicao/],
    ['ciencia', 'Ciência / intimação', /intimad[oa]s?|ciencia|intimacao/],
  ];
  function atos(n, tipoComunicacao) {
    const out = [];
    for (const [id, rotulo, re] of ATOS) if (re.test(n)) out.push({ id, rotulo });
    if (/distribuicao/i.test(norm(tipoComunicacao)) && !out.some((a) => a.id === 'distribuicao')) out.push({ id: 'distribuicao', rotulo: 'Distribuição' });
    return out;
  }

  // ── o que fazer ──────────────────────────────────────────────────────────
  const ACOES = [
    { id: 'defesa', rotulo: 'Apresentar defesa / contestação', re: /contestac|defesa escrita|apresentar(?:em)? defesa|contestar|resposta do reu/ , como: 'Peticionar nos autos (PJe) dentro do prazo ou até o horário da audiência, se assim determinado.' },
    { id: 'manifestar', rotulo: 'Manifestar-se nos autos', re: /manifest(?:e|em|ar|acao)(?:-se|-se\b)?|vista a(?:s)? parte|dizer sobre|digam as partes|requeira(?:m)? o que (?:entender|de direito)|impugn/, como: 'Peticionar nos autos (PJe) sobre o que foi juntado/determinado.' },
    { id: 'audiencia', rotulo: 'Comparecer à audiência', re: /audiencia/, como: 'Comparecer (partes, advogado e testemunhas), pelo meio indicado.' },
    { id: 'recorrer', rotulo: 'Avaliar recurso', re: /\bsentenca\b|\bacordao\b|julgo (?:parcialmente )?(?:procedente|improcedente)|negar provimento|dar provimento|decisao (?:interlocutoria )?|indefiro|nao conheco/, como: 'Decidir com o cliente: recurso (ordinário/apelação/agravo) ou embargos de declaração, dentro do prazo recursal.' },
    { id: 'pagar', rotulo: 'Pagar / depositar / recolher', re: /custas|deposito recursal|preparo|honorarios periciais|guia gru|pagamento|pagar|depositar|recolher|quitar/, como: 'Gerar a guia, pagar e juntar o comprovante nos autos.' },
    { id: 'testemunhas', rotulo: 'Apresentar rol de testemunhas / provas', re: /rol de testemunhas|indicar testemunhas|apresentar testemunhas|especificar provas|especifiquem as provas|produzir provas/, como: 'Peticionar com nomes e qualificação, ou levar as testemunhas à audiência.' },
    { id: 'cumprir', rotulo: 'Cumprir determinação do juízo', re: /cumpra-se|cumpra |cumprir|no prazo de|sob pena de|apresentar|juntar|regularizar|emendar|informar/, como: 'Atender o que o juízo determinou, com petição e documentos.' },
    { id: 'calculos', rotulo: 'Conferir / impugnar cálculos', re: /calculos?|liquidacao|memoria de calculo/, como: 'Conferir os valores e impugnar com planilha própria, se for o caso.' },
    { id: 'pericia', rotulo: 'Acompanhar a perícia (quesitos / assistente)', re: /pericia|perito|laudo|quesitos/, como: 'Apresentar quesitos / indicar assistente técnico ou se manifestar sobre o laudo.' },
    { id: 'ciencia', rotulo: 'Tomar ciência', re: /tomar ciencia|ciencia|intimad/, como: 'Ler o ato e registrar. Sem prazo identificado no texto: confira o ato original.' },
  ];

  // ── impacto ──────────────────────────────────────────────────────────────
  // lado: A = favorece quem pede (reclamante/autor/exequente), P = favorece quem se defende (reclamada/réu/executado)
  const IMPACTOS = [
    [/julgo (?:totalmente )?procedente|julgo procedentes|procedencia do(?:s)? pedido|condeno (?:a|o|as|os)? ?(?:reclamad|re[ou]|executad)/, 'A', 'Sentença/decisão de procedência: favorece quem pediu.'],
    [/julgo parcialmente procedente|procedencia parcial|procedente em parte|julgo procedentes em parte/, 'A', 'Procedência parcial: favorece em parte quem pediu.'],
    [/julgo improcedente|improcedencia|rejeito os pedidos/, 'P', 'Improcedência: favorece quem se defende.'],
    [/decreto a revelia|revelia (?:decretada|configurada)|aplico a pena de confissao|confissao ficta/, 'A', 'Revelia/confissão: favorece quem pediu.'],
    [/(?:defiro|determino|efetuad[oa]|realizad[oa]|efetivad[oa]|proceda-se a?)\s+(?:a |o |de )?(?:penhora|bloqueio|constricao)|bloqueio efetivado|valores bloqueados|penhora (?:realizada|efetivada)|auto de penhora/, 'A', 'Medida de constrição de bens: favorece o credor, pressiona o devedor.'],
    [/deserto|deserc[ao]o|recurso (?:nao )?conhecid[oa]? por intempestiv|intempestiv/, 'neutro-atencao', 'Recurso deserto/intempestivo: desfavorece quem recorreu.'],
    [/homologo o acordo|acordo homologado|homologacao do acordo/, 'N', 'Acordo homologado: encerra a discussão do que foi acordado.'],
    [/julgo extint[oa]|extingo o processo|extinto sem resolucao|determino o arquivamento|arquivem-se os autos|arquivado[s]? (?:o )?processo/, 'atencao', 'Extinção/arquivamento do processo: reveja se cabe nova ação ou recurso.'],
    [/litigancia de ma-fe|multa por|multa de \d|multa diaria/, 'atencao', 'Há multa/penalidade citada no ato.'],
    [/negar provimento|nego provimento|mantida a sentenca/, 'atencao', 'Recurso desprovido: favorece quem NÃO recorreu.'],
    [/dar provimento|dou provimento|provido o recurso|reformada a sentenca/, 'atencao', 'Recurso provido: favorece quem recorreu.'],
  ];
  function impacto(n) {
    const ach = [];
    for (const [re, lado, motivo] of IMPACTOS) if (re.test(n)) ach.push({ lado, motivo });
    return ach;
  }

  // lado = quem sofre a consequência: A (reclamante/autor), P (reclamada/réu), null (qualquer um)
  const RISCOS = [
    [/ausente o\(?a\)? reclamante|reclamante (?:ausente|nao comparecer)|arquivamento da reclamacao/, 'Arquivamento da reclamação (e custas) se o reclamante faltar à audiência.', 'A'],
    [/revel(?:ia)?[^.]{0,80}(?:ausente o\(?a\)? reclamad|reclamad[oa]s? ausente)|(?:ausente o\(?a\)? reclamad)[^.]{0,120}revel|sob pena de revelia/, 'Revelia e confissão quanto à matéria de fato para a reclamada que faltar ou não apresentar defesa.', 'P'],
    [/sob pena de confissao|confissao ficta/, 'Confissão quanto à matéria de fato para quem faltar ao depoimento.', null],
    [/preclusao|precluso/, 'Preclusão: perda do direito de praticar o ato se o prazo passar.', null],
    [/deserc[ao]o|deserto/, 'Deserção: recurso não conhecido por falta de preparo/depósito.', null],
    [/multa diaria|astreintes|multa de \d|multa (?:de )?\d+ ?%/, 'Multa por descumprimento.', null],
    [/litigancia de ma-fe/, 'Litigância de má-fé.', null],
    [/(?:defiro|determino|efetuad[oa]|realizad[oa])\s+(?:a |o )?(?:penhora|bloqueio)|sisbajud|bacenjud/, 'Constrição de bens/valores (penhora, bloqueio).', 'P'],
    [/honorarios (?:advocaticios|sucumbenciais|periciais)/, 'Honorários (advocatícios/periciais) em discussão.', null],
    [/prisao civil|condu[cz]ao coercitiva/, 'Risco de medida coercitiva pessoal.', null],
    [/intimad[oa]s? automaticamente|nao havera nova (?:intimacao|publicacao)|sem nova intimacao/, 'Pode não haver nova intimação sobre este ato: acompanhe o prazo por conta própria.', null],
  ];
  function riscos(n, original) {
    const out = [];
    for (const [re, txt, lado] of RISCOS) if (re.test(n) && !out.some((o) => o.texto === txt)) out.push({ texto: txt, lado });
    const low = limpar(original).toLowerCase(), re = /sob pena de ([^.;,]{3,110})/g; let m;
    while ((m = re.exec(low))) { const t = 'Sob pena de ' + (m[1].length > 100 ? m[1].slice(0, m[1].lastIndexOf(' ', 100)) + '…' : m[1].trim()) + '.'; if (!/revelia|confissao|confissão|preclus|deserc|multa/.test(norm(m[1])) && !out.some((o) => o.texto === t)) out.push({ texto: t, lado: null }); }
    return out.slice(0, 6);
  }

  // ── atores ───────────────────────────────────────────────────────────────
  function atores(textoOriginal, meta) {
    const t = limpar(textoOriginal), a = { juiz: null, relator: null, perito: null, orgao: (meta && meta.orgao) || null, partes: (meta && meta.partes) || [], advogados: (meta && meta.advogados) || [] };
    let m = t.match(/([A-ZÁÂÃÀÉÊÍÓÔÕÚÇ][A-ZÁÂÃÀÉÊÍÓÔÕÚÇ.' ]{4,70}?)\s+(Ju[ií]z(?:a)?(?: do Trabalho| de Direito| Federal)?(?: Titular| Substitut[oa]| Auxiliar)?)\b/);
    if (m) a.juiz = limpar(m[1]) + ' — ' + m[2];
    m = t.match(/Relat(?:or|ora)\s*[:\-]?\s*(?:Des(?:\(a\))?\.?\s*|Desembargador(?:a)?\s*|Ministr[oa]\s*|Juiz(?:a)?\s*)?([A-ZÁÂÃÀÉÊÍÓÔÕÚÇ][A-Za-zÀ-ú'.]*(?:\s+(?:d[aeo]s?\s+)?[A-ZÁÂÃÀÉÊÍÓÔÕÚÇ][A-Za-zÀ-ú'.]*){1,5})/);
    if (m) a.relator = limpar(m[1]);
    m = t.match(/perit[oa](?:\(a\))?\s*[:\-]?\s*(?:Dr\.?|Dra\.?|Sr\.?|Sra\.?)?\s*([A-ZÁÂÃÀÉÊÍÓÔÕÚÇ][A-Za-zÀ-ú'.]*(?:\s+(?:d[aeo]s?\s+)?[A-ZÁÂÃÀÉÊÍÓÔÕÚÇ][A-Za-zÀ-ú'.]*){1,4})/);
    if (m && !/^(?:Judicial|Contador|Do|Da)/.test(m[1])) a.perito = limpar(m[1]);
    return a;
  }

  // ── análise de uma publicação ────────────────────────────────────────────
  // pub: { texto, data (aaaa-mm-dd da disponibilização), tipo, metadata:{orgao,partes,advogados} }
  // opcoes: { hoje: 'aaaa-mm-dd', ladoCliente: 'A'|'P'|null }
  function analisar(pub, opcoes) {
    const o = opcoes || {}, hoje = o.hoje || iso(new Date());
    const bruto = limpar(pub.texto), corte = bruto.search(/intimado\(s\)\s*\/\s*citado\(s\)/i);
    const original = corte > 0 ? bruto.slice(0, corte).trim() : bruto, n = norm(original);
    const intimados = corte > 0 ? bruto.slice(corte).split(/\s-\s/).slice(1).map((x) => limpar(x)).filter((x) => x && x.length < 140) : [];
    const lista = atos(n, pub.tipo), ehEdital = /^edital/.test(norm(pub.tipo));
    const principal = lista.find((a) => a.id === 'sigilo') || (ehEdital && !lista.some((a) => ['penhora', 'citacao', 'audiencia'].includes(a.id)) ? { id: 'edital', rotulo: 'Edital (aviso a terceiros / partes)' } : (lista.find((a) => !['ciencia', 'despacho', 'certidao'].includes(a.id)) || lista[0] || { id: 'outro', rotulo: 'Publicação' }));
    const publicacao = pub.data ? proximoUtil(pub.data) : null;       // a publicação vale no 1º dia útil após a disponibilização
    const acoes = [], vistoAcao = new Set();
    const put = (a) => { if (vistoAcao.has(a.id + (a.vence || a.data || ''))) return; vistoAcao.add(a.id + (a.vence || a.data || '')); acoes.push(a); };

    // audiências com data marcada
    const auds = audiencias(n).filter((a) => !pub.data || a.data >= pub.data);   // data anterior à publicação = menção ao histórico
    for (const a of auds) {
      const dias = diasEntre(hoje, a.data);
      put({ id: 'audiencia', rotulo: 'Comparecer à audiência' + (a.hora ? ' às ' + a.hora : ''), como: (a.modo === 'telepresencial' ? 'Audiência telepresencial: confirmar o link e testar o acesso; levar testemunhas.' : a.modo === 'presencial' ? 'Audiência presencial: comparecer à sala indicada com testemunhas e documentos.' : 'Comparecer com o cliente e as testemunhas, pelo meio indicado no ato.'),
        quem: ['ambas as partes', 'advogados'], lado: 'AP', data: a.data, hora: a.hora, vence: a.data, prazoTexto: 'dia ' + a.data.split('-').reverse().join('/') + (a.hora ? ' às ' + a.hora : ''), estimado: false, dias, tipoPrazo: 'data' });
    }

    // cada frase do texto que traz prazo ou "até <momento>" vira uma ação, com quem e como
    const sentencas = n.split(/(?<=[.;])\s+/), sentOrig = original.split(/(?<=[.;])\s+/), temOrig = sentOrig.length === sentencas.length;
    const acharAcao = (t) => {
      if (/apresentar manifestacao|manifest/.test(t) && !/defesa escrita|apresentar(?:em)? defesa/.test(t)) return ACOES.find((x) => x.id === 'manifestar');
      if (/recolh|complementa\w* o (?:deposito|preparo|valor)|custas|preparo|garantir o valor|pagar ou garantir|pagar o debito/.test(t)) return ACOES.find((x) => x.id === 'pagar');
      if (/recurso|apelacao|agravo|embargos de declaracao/.test(t) && /prazo/.test(t)) return ACOES.find((x) => x.id === 'recorrer');
      return ACOES.find((x) => !['audiencia', 'ciencia', 'cumprir', 'recorrer'].includes(x.id) && x.re.test(t)) || ACOES.find((x) => x.id === 'cumprir');
    };
    const audData = auds.length ? auds[0] : null;
    for (const [si, sent] of sentencas.entries()) {
      const qs = quem(sent), lado = ladoDe(qs);
      // "até o dia e horário da audiência" / "até a audiência": vence na audiência
      if (audData && /ate (?:o dia e horario d[ae]|a data d[ae]|o inicio d[ae]|a) audiencia|ate a realizacao d[ae] audiencia|antes d[ae] audiencia/.test(sent)) {
        const acao = acharAcao(sent);
        put({ id: acao.id, rotulo: acao.rotulo + ' até a audiência', como: acao.id === 'defesa' ? 'Juntar a defesa escrita (com os documentos) no PJe até o dia e horário da audiência.' : acao.como, quem: qs.length ? qs : ['conforme o ato'], lado,
          prazoTexto: 'até o dia e horário da audiência', vence: audData.data, hora: audData.hora, estimado: false, dias: diasEntre(hoje, audData.data), tipoPrazo: 'data' });
        continue;
      }
      const ate = sent.match(/prazo ate (?:a |o )?(juntada d[ao] [a-z ]{3,30}|[a-z ]{3,40}?)(?: para|,|$)/);
      if (ate && /juntada|audiencia/.test(ate[1]) && !/audiencia/.test(ate[1])) {
        const acao = acharAcao(sent);
        put({ id: acao.id, rotulo: acao.rotulo, como: acao.como, quem: qs.length ? qs : ['conforme o ato'], lado, prazoTexto: 'até ' + ((temOrig && (sentOrig[si].match(/prazo at[ée] (?:a |o )?(juntada d[ao] [^,.;]{3,40}?)(?= para|,|\.|;|$)/i) || [])[1]) || ate[1].trim()) + ' (sem data certa)', vence: null, estimado: false, dias: null, tipoPrazo: 'evento' });
        continue;
      }
      for (const p of prazos(sent)) {
        const acao = acharAcao(sent);
        let vence = null;
        if (publicacao) vence = p.unidade === 'corridos' ? soma(publicacao, p.n) : p.unidade === 'horas' ? somaUteis(publicacao, Math.max(1, Math.ceil(p.n / 24))) : somaUteis(publicacao, p.n);
        if (p.unidade === 'corridos' && vence && !diaUtil(vence)) vence = proximoUtil(vence);       // prazo que termina em dia sem expediente vai ao próximo útil
        put({ id: acao.id, rotulo: acao.rotulo, como: acao.como, quem: qs.length ? qs : ['conforme o ato'], lado, prazoTexto: p.n + (p.unidade === 'horas' ? ' hora(s)' : p.unidade === 'corridos' ? ' dia(s) corridos' : ' dia(s) úteis'), vence, estimado: true, dias: vence ? diasEntre(hoje, vence) : null, tipoPrazo: 'dias' });
      }
    }
    // intimação/notificação de uma parte, sem prazo: só ciência
    for (const sent of sentencas) {
      const m = sent.match(/notifique-se (?:a |o )?(reclamad[oa]s?|reclamante|autor[a]?|re[ou]|executad[oa]s?)/);
      if (m && !acoes.some((a) => a.id === 'ciencia')) put({ id: 'ciencia', rotulo: 'Notificar / dar ciência à ' + m[1], como: 'A secretaria notifica a parte; confira se a notificação foi cumprida.', quem: quem(m[1]), lado: ladoDe(quem(m[1])), prazoTexto: null, vence: null, estimado: false, dias: null, tipoPrazo: 'nenhum' });
    }

    // ato que exige decisão do advogado mesmo sem prazo escrito
    const sem = (id) => !acoes.some((a) => a.id === id);
    if (principal.id === 'sigilo') {
      put({ id: 'sigilo', rotulo: 'Consultar os autos (processo sigiloso)', como: 'O Diário não traz o conteúdo: abra o processo no sistema do tribunal com seu certificado/login e veja o ato.', quem: ['advogados'], lado: 'AP', prazoTexto: null, vence: null, estimado: false, dias: null, tipoPrazo: 'nenhum' });
    }
    if (['sentenca', 'acordao'].includes(principal.id) && !ehEdital && sem('recorrer')) {
      const vence = publicacao ? somaUteis(publicacao, principal.id === 'sentenca' ? 8 : 8) : null;      // recurso ordinário/apelação: 8 dias úteis na CLT; 15 no CPC (confira)
      put({ id: 'recorrer', rotulo: 'Avaliar recurso / embargos', como: 'Decidir com o cliente. Prazo-padrão estimado em 8 dias úteis (CLT) — no CPC costuma ser 15; confira o ato e a legislação.', quem: ['ambas as partes'], lado: 'AP', prazoTexto: '8 dias úteis (padrão CLT, estimado)', vence, estimado: true, dias: vence ? diasEntre(hoje, vence) : null, tipoPrazo: 'padrao' });
    }
    if (!acoes.length) {
      const base = ACOES.find((x) => x.id === (principal.id === 'pericia' ? 'pericia' : principal.id === 'calculos' ? 'calculos' : principal.id === 'custas' ? 'pagar' : 'ciencia'));
      put({ id: base.id, rotulo: base.rotulo, como: base.como, quem: quem(original.slice(0, 600)).length ? quem(original.slice(0, 600)) : ['conforme o ato'], lado: ladoDe(quem(original.slice(0, 600))), prazoTexto: null, vence: null, estimado: false, dias: null, tipoPrazo: 'nenhum' });
    }

    // impacto
    const imp = impacto(n), ladoCli = o.ladoCliente || null;
    let efeito = { grau: 'neutro', rotulo: 'Neutro / informativo', motivo: 'Nenhuma palavra de resultado identificada no texto.', favorece: null };
    if (imp.length) {
      const primeiro = imp[0], lado = primeiro.lado;
      if (lado === 'A' || lado === 'P') {
        const rel = ladoCli ? (ladoCli === lado ? 'favoravel' : 'desfavoravel') : 'depende';
        efeito = { grau: rel, favorece: lado, motivo: primeiro.motivo + (ladoCli ? '' : ' Informe quem você representa para ver se é favorável ou não.'),
          rotulo: rel === 'favoravel' ? 'Favorável ao seu cliente' : rel === 'desfavoravel' ? 'Desfavorável ao seu cliente' : 'Favorece ' + (lado === 'A' ? 'o autor/reclamante' : 'o réu/reclamado') };
      } else efeito = { grau: lado === 'N' ? 'neutro' : 'atencao', favorece: null, rotulo: lado === 'N' ? 'Encerra a discussão' : 'Atenção', motivo: primeiro.motivo };
      efeito.outros = imp.slice(1).map((x) => x.motivo);
    }
    const r = riscos(n, original);
    if (!ladoCli && r.length && efeito.grau === 'neutro') efeito = { ...efeito, grau: 'atencao', rotulo: 'Atenção: há risco no ato', motivo: 'O ato cita consequências para quem não cumprir; veja os riscos.' };

    // urgência = a ação com data mais próxima
    const datadas = acoes.filter((a) => a.vence).sort((a, b) => a.vence.localeCompare(b.vence));
    const prox = datadas.find((a) => a.dias >= 0) || datadas[datadas.length - 1] || null;
    let urgencia = 'sem_prazo';
    if (prox) urgencia = prox.dias < 0 ? 'vencido' : prox.dias === 0 ? 'hoje' : prox.dias <= 3 ? 'ate3' : prox.dias <= 7 ? 'ate7' : 'depois';
    // frase-chave: a primeira que traz prazo, audiência ou resultado
    const frases = original.split(/(?<=[.;!?])\s+/), chave = frases.find((f) => /prazo|designad|audiencia|sob pena|julgo|condeno|defiro|indefiro|intimad|manifest/i.test(norm(f))) || frases[0] || '';

    const temPrazoFirme = acoes.some((a) => a.tipoPrazo === 'data' || a.tipoPrazo === 'dias'), padrao = acoes.some((a) => a.tipoPrazo === 'padrao');
    const confianca = principal.id === 'sigilo' || (temPrazoFirme && !padrao) ? 'alta' : (padrao || ['ciencia', 'edital', 'outro'].includes(principal.id) ? 'baixa' : 'media');
    return { confianca, intimados, ato: principal, atos: lista, publicacao, acoes, quemAtua: [...new Set(acoes.flatMap((a) => a.quem))], impacto: efeito, riscos: r, atores: atores(original, pub.metadata), urgencia, proximo: prox, trecho: limpar(chave).slice(0, 320), audiencias: auds };
  }

  const API = { analisar, norm, diaUtil, proximoUtil, somaUteis, diasEntre, prazos, audiencias, quem, ladoDe, feriados, pascoa,
    ROTULO_URGENCIA: { vencido: 'Vencido', hoje: 'Vence hoje', ate3: 'Até 3 dias', ate7: 'Até 7 dias', depois: 'Mais de 7 dias', sem_prazo: 'Sem prazo identificado' } };
  if (typeof module !== 'undefined' && module.exports) module.exports = API; else raiz.Analise = API;
})(typeof window !== 'undefined' ? window : globalThis);
