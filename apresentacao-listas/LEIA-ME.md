# Listas de processos reais para apresentações

Três planilhas (CSV) prontas para importar no Radar: **Processos > Importar CSV**.

| Arquivo | Processos | O que tem |
|---|---|---|
| `lista-1-trabalhista.csv` | 25 | Varas do Trabalho e TRTs de vários estados |
| `lista-2-civel-estadual.csv` | 23 | Justiça estadual (TJs): cível, execução, juizado e outros |
| `lista-3-federal-superiores.csv` | 8 | Justiça Federal (TRF), STJ e CJF |

Foram 56 processos, **todos os que a amostra de publicações do DJEN de 12/10/2026 trouxe sem tema sensível**. Para ter **30 em cada lista**, rode `gerar-listas.py` (dentro do Brasil, o DJEN só responde a IPs do Brasil): ele busca as publicações de um dia, tira interdição, família, infância, criminal e sigilo, confere o dígito verificador e grava as 3 planilhas com até 30 cada. Dica: a lista federal enche mais se você rodar em um dia útil e aumentar as páginas.

**De onde vêm:** os números foram tirados de publicações reais do Diário (DJEN) que você me enviou em 12/10/2026. Cada número é válido (dígito verificador conferido) e a importação foi testada. Eu **não** confirmei, nesta data, que cada processo ainda tem publicação nova.

**Antes de apresentar**
1. O sistema precisa estar no ar, com servidor no Brasil, para consultar o Diário de verdade. Sem isso, os processos entram, mas aparecem como "sem acesso".
2. Importe com antecedência e deixe rodar a primeira consulta (ou clique em "Consultar tudo agora"): a primeira busca traz o histórico, e só depois aparecem as ações e prazos.
3. **Cuidado com dados pessoais:** são processos de pessoas e empresas reais. Mesmo sendo publicações públicas, as telas mostram partes e advogados. Em apresentação aberta, prefira a **Lista 1 (trabalhista)** ou cubra os nomes, e não mostre processos de família, interdição ou infância (por isso nenhum deles está nas listas).
4. Os nomes dos clientes ficaram genéricos ("Lista 1 - Trabalhista" etc.), só para você filtrar por lista na tela Processos.

Para apagar depois da apresentação: tela Processos, arquivar cada um (ou excluir o escritório de teste em Minha conta).
