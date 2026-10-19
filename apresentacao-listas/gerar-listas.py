#!/usr/bin/env python3
"""Gera 3 planilhas (30 processos cada) a partir das publicações REAIS do DJEN (Diário de Justiça Eletrônico Nacional).
O DJEN só responde a IPs do Brasil: rode no seu computador (com internet do Brasil) ou no servidor do Radar.

  python3 gerar-listas.py                      # publicações de ontem
  python3 gerar-listas.py 2026-10-12           # um dia específico
  python3 gerar-listas.py --arquivo resposta.json   # usa uma resposta já salva (teste, sem internet)

Fica de fora: interdição, família, infância, criminal, sigilo e afins. Saída: lista-1-trabalhista.csv,
lista-2-civel-estadual.csv, lista-3-federal-superiores.csv (colunas: numero,cliente), prontas para Processos > Importar CSV."""
import csv, json, re, sys, urllib.parse, urllib.request
from datetime import date, timedelta

SENSIVEL = re.compile(r'interdi|curatela|fam[ií]lia|inf[aâ]ncia|juventude|alimento|guarda|crimin|penal|sigilo|ado[cç][aã]o|viol[eê]ncia|medida protetiva|menor|tutela|div[oó]rcio|uni[aã]o est[aá]vel|sucess|invent[aá]rio|ato infracional|j[uú]ri|tr[aá]fico|furto|roubo|homic', re.I)
LISTAS = [('lista-1-trabalhista.csv', 'Lista 1 - Trabalhista', lambda s: s.startswith('TRT') or s == 'TST'),
          ('lista-2-civel-estadual.csv', 'Lista 2 - Civel estadual', lambda s: s.startswith('TJ')),
          ('lista-3-federal-superiores.csv', 'Lista 3 - Federal e superiores', lambda s: s.startswith('TRF') or s in ('STJ', 'STF', 'CJF'))]
POR_LISTA = 30
API = 'https://comunicaapi.pje.jus.br/api/v1/comunicacao'


def cnj_valido(n):
    d = re.sub(r'\D', '', n)
    if len(d) != 20:
        return False
    dv = int(d[7:9]); resto = int(d[:7] + d[9:] + '00') % 97
    return 98 - resto == dv


def buscar(dia, paginas=15):
    for p in range(1, paginas + 1):
        q = urllib.parse.urlencode({'dataDisponibilizacaoInicio': dia, 'dataDisponibilizacaoFim': dia, 'itensPorPagina': 100, 'pagina': p})
        req = urllib.request.Request(API + '?' + q, headers={'Accept': 'application/json', 'User-Agent': 'RadarProcessual/1.0'})
        with urllib.request.urlopen(req, timeout=40) as r:
            itens = json.load(r).get('items') or []
        if not itens:
            return
        yield from itens


def main():
    args = sys.argv[1:]
    if args[:1] == ['--arquivo']:
        itens = json.load(open(args[1], encoding='utf-8'))['items']
    else:
        dia = args[0] if args else (date.today() - timedelta(days=1)).isoformat()
        itens = list(buscar(dia))
    escolhidos = [[] for _ in LISTAS]; vistos = set()
    for it in itens:
        n = it.get('numeroprocessocommascara') or ''
        texto = ' '.join(str(it.get(k) or '') for k in ('texto', 'nomeOrgao', 'nomeClasse'))
        if n in vistos or not cnj_valido(n) or SENSIVEL.search(texto):
            continue
        vistos.add(n)
        for i, (_, _, regra) in enumerate(LISTAS):
            if regra(str(it.get('siglaTribunal') or '')) and len(escolhidos[i]) < POR_LISTA:
                escolhidos[i].append(n)
    for (arq, rotulo, _), nums in zip(LISTAS, escolhidos):
        with open(arq, 'w', newline='', encoding='utf-8') as f:
            w = csv.writer(f); w.writerow(['numero', 'cliente']); w.writerows([n, rotulo] for n in nums)
        print(f'{arq}: {len(nums)} processos' + ('' if len(nums) >= POR_LISTA else f' (faltam {POR_LISTA - len(nums)}: tente outro dia ou mais páginas)'))


if __name__ == '__main__':
    main()
