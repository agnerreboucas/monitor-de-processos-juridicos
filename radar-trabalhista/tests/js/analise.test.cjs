// Testes da leitura automática das publicações (public/app/analise.js). Rodar:  node --test tests/js
const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), path = require('node:path');
const A = require('../../public/app/analise.js');

const fixture = JSON.parse(fs.readFileSync(path.join(__dirname, '../fixtures/djen-real-sample.json'), 'utf8')).items;
const ENT = { '&ccedil;': 'ç', '&Ccedil;': 'Ç', '&atilde;': 'ã', '&Atilde;': 'Ã', '&otilde;': 'õ', '&aacute;': 'á', '&eacute;': 'é', '&iacute;': 'í', '&oacute;': 'ó', '&uacute;': 'ú', '&Aacute;': 'Á', '&Eacute;': 'É', '&Iacute;': 'Í', '&Oacute;': 'Ó', '&Uacute;': 'Ú', '&acirc;': 'â', '&ecirc;': 'ê', '&ocirc;': 'ô', '&Acirc;': 'Â', '&Ecirc;': 'Ê', '&Ocirc;': 'Ô', '&nbsp;': ' ', '&ordm;': 'º', '&amp;': '&', '&quot;': '"' };
const plano = (h) => h.replace(/<(style|script)[\s\S]*?<\/\1>/gi, ' ').replace(/<br\s*\/?>|<\/(p|div|tr|h\d)>/gi, '\n').replace(/<[^>]+>/g, ' ').replace(/&[a-zA-Z]+;/g, (e) => ENT[e] || ' ');
const item = (id) => fixture.find((x) => x.id === id);
const pub = (x) => ({ texto: plano(x.texto), data: x.data_disponibilizacao, tipo: x.tipoComunicacao, metadata: { orgao: x.nomeOrgao } });
const HOJE = '2026-10-13';

test('calendário: dia útil, recesso, feriados e Sexta-feira Santa', () => {
  assert.equal(A.diaUtil('2026-10-12'), false);          // Nossa Senhora Aparecida
  assert.equal(A.diaUtil('2026-10-10'), false);          // sábado
  assert.equal(A.diaUtil('2026-12-21'), false);          // recesso forense
  assert.equal(A.diaUtil('2027-01-20'), false);
  assert.equal(A.diaUtil('2027-01-21'), true);
  assert.equal(A.pascoa(2027), '2027-03-28');
  assert.equal(A.diaUtil('2027-03-26'), false);          // sexta-feira santa
  assert.equal(A.proximoUtil('2026-10-09'), '2026-10-13'); // sexta → pula sáb, dom e o feriado de 12/10
  assert.equal(A.somaUteis('2026-10-13', 5), '2026-10-20');
});

test('despacho real do TRT8: audiência, defesa até a audiência, manifestação, riscos e atores', () => {
  const r = A.analisar(pub(item(751951120)), { hoje: HOJE });
  assert.equal(r.ato.id, 'audiencia');
  const aud = r.acoes.find((a) => a.id === 'audiencia');
  assert.equal(aud.vence, '2027-03-11'); assert.equal(aud.hora, '10:35'); assert.equal(aud.dias, 149);
  assert.match(aud.como, /telepresencial/);
  const defesa = r.acoes.find((a) => a.id === 'defesa');
  assert.equal(defesa.vence, '2027-03-11'); assert.equal(defesa.lado, 'P'); assert.match(defesa.quem[0], /parte ré/);
  const manif = r.acoes.find((a) => a.id === 'manifestar');
  assert.equal(manif.lado, 'P'); assert.match(manif.prazoTexto, /juntada da contesta/); assert.equal(manif.vence, null);
  assert.ok(r.riscos.some((x) => /Arquivamento/.test(x.texto) && x.lado === 'A'));
  assert.ok(r.riscos.some((x) => /Revelia/.test(x.texto) && x.lado === 'P'));
  assert.match(r.atores.juiz, /ANDRE MAROJA DE SOUZA/);
  assert.deepEqual(r.intimados, ['ARLEY DOS SANTOS FERREIRA']);
  assert.equal(r.confianca, 'alta');
  assert.equal(r.urgencia, 'depois');
  assert.notEqual(r.impacto.grau, 'favoravel');           // ato de pauta, sem resultado: não inventa vitória nem derrota
});

test('prazo em dias úteis conta da publicação (1º dia útil após a disponibilização)', () => {
  const r = A.analisar({ texto: 'Fica a parte intimada para manifestar-se sobre o laudo no prazo de 8 (oito) dias.', data: '2026-10-09', tipo: 'Intimação' }, { hoje: HOJE });
  const a = r.acoes.find((x) => x.id === 'manifestar' || x.id === 'pericia');
  assert.ok(a, JSON.stringify(r.acoes));
  assert.equal(r.publicacao, '2026-10-13');
  assert.equal(a.vence, A.somaUteis('2026-10-13', 8));
  assert.equal(A.diaUtil(a.vence), true);
  assert.equal(a.estimado, true);
  assert.equal(r.urgencia, 'depois');                     // vence em 23/10, 10 dias à frente
  const curto = A.analisar({ texto: 'Intime-se a reclamada para apresentar documentos no prazo de 3 (três) dias.', data: '2026-10-09', tipo: 'Intimação' }, { hoje: HOJE });
  assert.equal(curto.acoes[0].vence, '2026-10-16'); assert.equal(curto.urgencia, 'ate3');
  assert.equal(A.analisar({ texto: 'Prazo de 2 dias.', data: '2026-09-01', tipo: 'Intimação' }, { hoje: HOJE }).urgencia, 'vencido');
});

test('impacto é relativo ao lado que o escritório representa', () => {
  const t = { texto: 'SENTENÇA. Ante o exposto, JULGO PROCEDENTE o pedido para condenar a reclamada ao pagamento de horas extras.', data: '2026-10-09', tipo: 'Intimação' };
  assert.equal(A.analisar(t, { hoje: HOJE }).impacto.grau, 'depende');
  assert.equal(A.analisar(t, { hoje: HOJE, ladoCliente: 'A' }).impacto.grau, 'favoravel');
  assert.equal(A.analisar(t, { hoje: HOJE, ladoCliente: 'P' }).impacto.grau, 'desfavoravel');
  const improc = { texto: 'Ante o exposto, JULGO IMPROCEDENTE o pedido.', data: '2026-10-09', tipo: 'Intimação' };
  assert.equal(A.analisar(improc, { hoje: HOJE, ladoCliente: 'P' }).impacto.grau, 'favoravel');
});

test('sentença sugere avaliar recurso com prazo-padrão marcado como estimativa e confiança baixa/média', () => {
  const r = A.analisar({ texto: 'SENTENÇA. JULGO PARCIALMENTE PROCEDENTE o pedido. Intimem-se.', data: '2026-10-09', tipo: 'Intimação' }, { hoje: HOJE });
  const a = r.acoes.find((x) => x.id === 'recorrer');
  assert.ok(a && a.estimado && a.tipoPrazo === 'padrao');
  assert.notEqual(r.confianca, 'alta');
});

test('edital de aviso a terceiros não vira "avaliar recurso"; processo sigiloso manda consultar os autos', () => {
  const ed = A.analisar({ texto: 'EDITAL DE PUBLICAÇÃO DE SENTENÇA DE INTERDIÇÃO. O Doutor Fulano, Juiz de Direito, faz saber que foi decretada a interdição.', data: '2026-10-09', tipo: 'Edital' }, { hoje: HOJE });
  assert.ok(!ed.acoes.some((a) => a.id === 'recorrer'));
  const sig = A.analisar({ texto: 'Processo sigiloso. Para visualização do documento consulte os autos digitais.', data: '2026-10-09', tipo: 'Intimação' }, { hoje: HOJE });
  assert.equal(sig.ato.id, 'sigilo'); assert.equal(sig.acoes[0].id, 'sigilo');
});

test('audiência citada como passado não gera ação', () => {
  const r = A.analisar({ texto: 'Conforme ata da audiência realizada no dia 29/10/2025, as partes foram intimadas.', data: '2026-10-09', tipo: 'Intimação' }, { hoje: HOJE });
  assert.ok(!r.acoes.some((a) => a.id === 'audiencia'));
});

test('varredura nos itens reais: nunca quebra, sempre dá ao menos uma ação e prazo em dia útil', () => {
  assert.ok(fixture.length >= 10);
  for (const x of fixture) {
    const r = A.analisar(pub(x), { hoje: HOJE });
    assert.ok(r.ato && r.acoes.length >= 1, 'sem ação: ' + x.id);
    for (const a of r.acoes) if (a.tipoPrazo === 'dias' && a.vence) assert.equal(A.diaUtil(a.vence), true, `prazo em dia não útil: ${x.id} ${a.vence}`);
    assert.ok(['alta', 'media', 'baixa'].includes(r.confianca));
  }
});

test('prazo-padrão de recurso depende do ramo (CLT 8 dias, CPC 15)', () => {
  const base = { texto: 'Vistos. Julgo procedente o pedido. Sentença proferida.', data: '2026-10-13', tipo: 'Intimação' };
  const dias = (cnj) => A.analisar({ ...base, cnj }, { hoje: HOJE }).acoes.find((a) => a.id === 'recorrer');
  const trab = dias('0003320-79.2025.5.02.0041'), civ = dias('1000123-45.2025.8.26.0100');
  assert.match(trab.prazoTexto, /^8 dias úteis/);
  assert.match(civ.prazoTexto, /^15 dias úteis/);
});
