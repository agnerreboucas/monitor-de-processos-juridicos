# consulta-processos

Busca e consulta de processos judiciais brasileiros nas fontes públicas, em Python.

Duas fontes, ambas oficiais e públicas do CNJ:

| Fonte | O que traz | Chave |
|---|---|---|
| **Datajud** | Metadados do processo (classe, assunto, órgão julgador, grau, datas) e todo o histórico de movimentos, inclusive os internos, que não saem no diário. **Não traz partes nem advogados** — a API pública não publica esses campos | Chave pública, já embutida |
| **Comunica / DJEN** | As publicações do Diário de Justiça Eletrônico Nacional, com o teor completo do ato, as partes e os advogados. É a única fonte pública que permite procurar por OAB | Não precisa |

As duas se completam: o Datajud tem o histórico mais completo, mas demora alguns dias para indexar; o DJEN publica no dia seguinte ao ato, e é por ele que se acompanha prazo.

## Instalação

```bash
pip install consulta-processos

# recomendado em produção (evita bloqueios em volume):
pip install "consulta-processos[navegador]"
```

Python 3.10 ou mais novo.

## Primeiro uso

Não precisa configurar nada para começar: a chave do Datajud é pública, o CNJ a divulga na [documentação da API](https://datajud-wiki.cnj.jus.br/api-publica/acesso) e ela já vem embutida. Se um dia você tiver uma chave própria, ou precisar de proxy, copie o modelo e preencha — o arquivo `.env` é lido sozinho, da pasta onde você estiver:

```bash
cp .env.exemplo .env
```

### Pela tela, no navegador

Quem não trabalha com terminal pode usar a tela:

```bash
consulta-processos web
```

O navegador abre sozinho em `http://localhost:8765`, com três abas: consultar um processo pelo número, listar os processos de um advogado pela OAB e ver as publicações do diário no período. O resultado aparece formatado, com partes, advogados, movimentações e o teor de cada publicação.

O servidor roda **só na sua máquina** — ninguém de fora acessa, e as consultas continuam indo direto do seu computador às fontes do CNJ. Não instala nada além da própria biblioteca: a página é um arquivo único, sem buscar nada na internet. Para fechar, volte ao terminal e pressione Ctrl+C.

```bash
consulta-processos web --porta 9000    # se a 8765 estiver ocupada
consulta-processos web --sem-abrir     # não abre o navegador sozinho
```

### Pelo código

```python
from consulta_processos import consultar_processo

processo = consultar_processo("1000254-20.2025.8.13.0079")

print(processo.titulo)                    # Fulano de Tal × Empresa X
print(processo.tribunal, processo.classe) # TJMG Procedimento Comum Cível
print(len(processo.movimentacoes), "movimentações")

for mov in processo.movimentacoes[:5]:
    print(f"{mov.data:%d/%m/%Y}  {mov.tipo.value}  {mov.descricao}")

for pub in processo.publicacoes[:3]:
    print(pub.data_disponibilizacao, pub.tipo_comunicacao)
    print(pub.texto[:300])                # teor do ato, já sem HTML
```

Tudo é dataclass: `processo.to_dict()` devolve um dicionário pronto para virar JSON ou registro no seu banco.

### Na linha de comando

```bash
consulta-processos web                 # a tela no navegador
consulta-processos processo 1000254-20.2025.8.13.0079
consulta-processos processo 1000254-20.2025.8.13.0079 --json > processo.json
consulta-processos oab 123456 MG --dias 60 --tribunal TJMG
consulta-processos publicacoes 123456 MG --dias 7
```

## Procurar pelo advogado: por que vem do diário

A API pública do Datajud **não publica os advogados nem as partes** do processo. Os documentos dela têm apenas número, classe, assunto, órgão julgador, grau, datas e a lista de movimentos. Procurar advogado ali devolve zero, sempre — não é falta de chave nem erro de consulta.

Quem indexa OAB é o diário (DJEN). Então `buscar_por_oab` reúne as publicações daquela inscrição no período e agrupa por processo. Duas consequências:

- o resultado é **o que saiu publicado no período**, não a carteira inteira do advogado. Um processo parado há meses não aparece: aumente `dias` para alcançar mais;
- as partes e os advogados vêm da publicação, que costuma trazê-los completos.

Sabendo o número do processo, `consultar_processo` junta as duas fontes e aí sim o histórico fica completo.

## O que dá para fazer

```python
from datetime import date, timedelta
from consulta_processos import (CNJ, buscar_por_oab, consultar_processo,
                                existe, publicacoes_por_oab)

# 1. Ler o número sem consultar nada: o próprio número diz o tribunal
cnj = CNJ("10002542020258130079")
cnj.valido, cnj.formatado, cnj.tribunal, cnj.ano
# (True, '1000254-20.2025.8.13.0079', 'TJMG', 2025)

# 2. Processo completo (Datajud + diário)
processo = consultar_processo("1000254-20.2025.8.13.0079")

# 3. Só checar se já existe — útil para processo recém-distribuído
achou, fonte = existe("1000254-20.2025.8.13.0079")

# 4. Processos em que a OAB foi publicada no período (vem do diário, não do Datajud)
processos = buscar_por_oab("123456", "MG", dias=60, tribunal="TJMG")

# 5. Publicações da semana — é assim que se monta o controle de prazos
hoje = date.today()
publicacoes = publicacoes_por_oab("123456", "MG",
                                  inicio=hoje - timedelta(days=7), fim=hoje,
                                  paginas=0)   # 0 = todas as páginas
for pub in publicacoes:
    print(pub.numero_processo, pub.tipo_comunicacao, pub.orgao)
```

### Quando a fonte está fora do ar

```python
from consulta_processos import FonteIndisponivel, consultar_processo

try:
    processo = consultar_processo("1000254-20.2025.8.13.0079")
except FonteIndisponivel as e:
    print("tente de novo mais tarde:", e)
```

O Datajud é público e gratuito, e em horário cheio chega a levar mais de um minuto por consulta (ele informa o tempo gasto no campo `took` da própria resposta). Por isso a espera padrão é de 90 segundos. Em tela, com alguém esperando, use um tempo curto e deixe o diário responder primeiro:

```python
from consulta_processos.fontes import datajud
processo = datajud.consultar_processo(numero, timeout=12)   # desiste rápido
```

`FonteIndisponivel` significa que a API não respondeu — vale repetir. Já `None` como retorno significa que as fontes responderam e o processo não existe nelas: repetir não adianta (a não ser que ele acabe de ser distribuído).

## API REST

Para servir a consulta a outros sistemas (ou alugar o acesso), há uma API em FastAPI:

```bash
pip install "consulta-processos[api]"
consulta-processos admin criar voce@empresa.com.br   # seu acesso ao painel (pede a senha)
consulta-processos api                                # API em :8000 · docs em /docs · painel em /admin
```

### Painel de clientes (/admin)

É onde você vende o acesso: cadastra o cliente com plano, mensalidade, **cota de consultas por mês** e limite por minuto; gera a chave dele (aparece uma única vez; o banco guarda só o hash); acompanha consumo, histórico de chamadas e receita mensal; suspende o cliente ou revoga uma chave na hora. Toda ação fica na aba Auditoria com quem, quando e de qual IP.

- Cobrança: `GET /v1/processos/*` e as rotas de OAB descontam 1 da cota; `/existe`, `/cnj` e `/conta` não. Chamada que termina em erro (4xx/5xx) não é cobrada. A cota zera no dia 1º, horário de Brasília. Consulta repetida que sai do cache é cobrada normalmente.
- O cliente vê o próprio consumo em `GET /v1/conta` e nos cabeçalhos `X-Cota-Limite`, `X-Cota-Usada` e `X-Cota-Restante` de cada resposta. Cota esgotada responde `402 cota_esgotada`; conta suspensa, `403 conta_suspensa`.
- Os dados ficam em SQLite em `dados/api.db` (`API_BANCO`), com permissão 600 — inclua no backup.
- Login com senha forte (12+ caracteres), bloqueio após 5 erros por e-mail em 15 minutos, sessão que expira em 30 minutos parada e 12 horas no total, cookie HttpOnly/Secure/SameSite=Strict e token CSRF. Não existe usuário padrão.
- Antes de publicar o painel na internet, restrinja por IP com `PAINEL_IPS_PERMITIDOS` (ex.: a faixa da VPN). Fora da faixa, `/admin` responde 404.
- Troca de senha: `consulta-processos admin senha voce@empresa.com.br` (derruba as sessões abertas).

```bash
curl -H "X-API-Key: <chave>" http://127.0.0.1:8000/v1/processos/5053283-30.2024.8.13.0079
```

| Rota | O que devolve |
|---|---|
| `GET /v1/processos/{numero}` | Processo completo: capa, polos, advogados, movimentações e publicações, com datas `dd/mm/aaaa` e valor em reais já formatados. `?modo=rapido` usa só o diário (segundos); `?publicacoes=false` pula o diário |
| `GET /v1/processos/{numero}/existe` | Se o processo já aparece em alguma fonte |
| `GET /v1/cnj/{numero}` | Decodifica o número (tribunal, ano, segmento, dígito verificador) sem consultar nada |
| `GET /v1/oab/{uf}/{numero}/processos?dias=30` | Processos em que a OAB saiu no diário no período |
| `GET /v1/oab/{uf}/{numero}/publicacoes?dias=7` | Publicações da OAB no período, para controle de prazo |
| `GET /v1/conta` | Plano do cliente, consumo do mês, quanto resta e quando renova |
| `GET /saude` | Verificação de vida, sem chave (para o healthcheck do proxy) |

Toda resposta tem `ok`; erro vem sempre como `{"ok": false, "erro": {"codigo", "mensagem"}, "id_requisicao"}`, sem detalhe interno. Códigos: `401` chave ausente ou errada, `404` processo não encontrado, `422` entrada inválida (inclusive dígito verificador errado — não gasta consulta), `429` limite atingido (com `Retry-After`), `503` fonte do CNJ fora do ar (tente de novo).

Segurança embutida: chave por cliente (só o hash fica guardado), limite por minuto de cada cliente, bloqueio de IP após chaves erradas seguidas (`API_FALHAS_POR_MINUTO`), cabeçalhos de segurança, log de auditoria com cliente, IP, rota, status e tempo — nunca a chave. O limite e o cache ficam na memória de um processo: rode com um worker só, ou troque por Redis antes de escalar.

A consulta completa espera o Datajud, que pode levar mais de um minuto; o resultado fica em cache por algumas horas, e a repetição sai em milissegundos.

## Monitor: acompanhar OABs e processos, com painel de prazos

O monitor transforma a biblioteca num **controle de publicações**. Você cadastra as OABs dos advogados e os processos que quer acompanhar; uma rotina diária busca no diário (DJEN) o que saiu, guarda só o que é novo e a tela mostra prazos, audiências e alertas.

```bash
consulta-processos monitor adicionar-oab 123456 MG --nome "Dra. Fulana"
consulta-processos monitor adicionar-processo 1000254-20.2025.8.13.0079 --nome "Caso Silva"
consulta-processos monitor listar
consulta-processos monitor executar        # a rotina: rode todo dia (cron / Agendador de Tarefas)
consulta-processos monitor painel          # abre a tela em http://localhost:8766
consulta-processos monitor painel --a-cada 60   # a tela busca sozinha a cada 60 min enquanto aberta
```

**O que a tela mostra:** publicações novas, prazos vencidos e críticos (até 5 dias), prazos a confirmar, audiências e sessões de julgamento futuras, processos acompanhados sem publicação há 60+ dias, e totais por UF, por justiça (Estadual × Federal × Trabalho × outras) e por advogado. Dá para filtrar por advogado, UF, justiça, tipo de ato e palavra (nome de parte, órgão, número, texto), marcar como lido e anotar cada prazo.

**Como a rotina evita perder publicação:** cada busca recua 3 dias a partir da última que deu certo, e a repetição não duplica nada (cada publicação tem identificador único). Se a fonte cair, o erro fica anotado no item, a "última busca" não avança e a próxima rodada cobre o buraco. Uma OAB com erro não derruba as outras.

**O que o robô tira do texto (por regra simples, sem IA):**

- **tipo do ato:** intimação, citação, sentença, acórdão, decisão, despacho, edital, distribuição;
- **audiência ou sessão de julgamento:** a data (e hora) citada perto da palavra "audiência" ou "sessão de julgamento", em `22/10/2026 às 09:00` ou `2 de dezembro de 2026, às 12h30`;
- **prazo sugerido:** "prazo de 15 dias", "prazo de cinco dias úteis", "prazo de 10 (dez) dias corridos". Prazo em horas é ignorado.

> **O prazo é sempre uma estimativa a confirmar ([CONFIRMAR]).** A data final desconta só fins de semana e feriados nacionais fixos. Não sabe de feriado local, ponto facultativo, suspensão de prazo nem recesso forense, e cada rito conta de um jeito. Edital fica sem prazo sugerido de propósito: o prazo do edital corre antes do prazo da parte. Quem confirma é o advogado, e a tela deixa corrigir a data e marcar como confirmado, cumprido ou descartado.

**Onde ficam os dados:** SQLite em `dados/monitor.db` (ou `MONITOR_BANCO`), com permissão 600. O banco guarda o teor das intimações, que pode ter dado pessoal: inclua no backup e proteja o arquivo como protege os autos (LGPD).

**Segurança da tela:** roda só em `127.0.0.1` e **não tem login**. Para usar pela rede, ponha atrás de um proxy com senha. A tela só atende o endereço `localhost`/`127.0.0.1` (contra DNS rebinding), pedidos que alteram dados exigem JSON e um cabeçalho próprio (outro site aberto no navegador não consegue mexer), e todo texto vindo do tribunal entra na página como texto, nunca como HTML.

**Limites (o que ainda não existe):**

- Acompanha pelo **diário (DJEN)**. As movimentações internas do Datajud ainda não entram na rotina (use `consultar_processo` para o histórico completo).
- A "UF" vem do número do processo: Justiça Federal e do Trabalho cobrem várias UFs e ficam sem UF no gráfico.
- Sem alerta por e-mail ou WhatsApp, sem login e sem vários escritórios separados. O monitor é de um escritório só.
- "Processo parado" quer dizer "sem publicação no diário há 60 dias", não "sem andamento".
- **Testado só com respostas gravadas**, no formato real da API do Comunica. Esta versão não foi rodada contra a API ao vivo (ela recusa IP de fora do Brasil, veja "Precisa de proxy?").

## Configuração

Tudo vem de variáveis de ambiente (veja `.env.exemplo`), ou você monta na mão:

```python
from consulta_processos import Config, consultar_processo

config = Config(datajud_api_key="...", comunica_timeout=20)
processo = consultar_processo("1000254-20.2025.8.13.0079", config=config)
```

| Variável | Para que serve |
|---|---|
| `DATAJUD_API_KEY` | Chave pública do Datajud, publicada na [documentação do CNJ](https://datajud-wiki.cnj.jus.br/api-publica/acesso) |
| `CONSULTA_PROXIES` | Lista fixa de proxies, separada por vírgula |
| `CONSULTA_GATEWAY_*` | Gateway residencial que abre um IP por porta |
| `PROXYSELLER_API_KEY` | Exemplo de provedor com API de listagem |
| `CONSULTA_USER_AGENT_EXTRA` | Identificação sua no User-Agent |
| `CONSULTA_PAUSA_PAGINAS` | Pausa entre páginas de uma busca |

### Precisa de proxy?

Quase sempre não. A API do Comunica é do gov.br e recusa requisições vindas de fora do Brasil (HTTP 403); rodando de um IP brasileiro, com volume normal, basta instalar e usar.

Proxy passa a fazer diferença quando você roda de servidor fora do país ou consulta em massa. Se houver pool configurado, a biblioteca dispara algumas tentativas em paralelo e fica com a primeira boa: em pool residencial sempre há IPs lentos, e esperar um por vez deixa a consulta arrastada.

## Como usar com bom senso

São serviços públicos, mantidos com dinheiro público e usados por todo mundo ao mesmo tempo. Vale:

- consultar o que você precisa, não a base inteira;
- guardar em cache o que já consultou, em vez de repetir a mesma consulta;
- usar `CONSULTA_PAUSA_PAGINAS` em varreduras grandes;
- identificar-se em `CONSULTA_USER_AGENT_EXTRA`;
- rodar as varreduras fora do horário de pico.

A biblioteca não contorna captcha nem qualquer proteção de tribunal: só conversa com as duas APIs públicas do CNJ, do jeito documentado. Os dados processuais são públicos, mas processo em segredo de justiça não aparece nessas fontes, e dado pessoal que você guardar continua sujeito à LGPD — trate os dados dos clientes com o mesmo cuidado que você trata os autos.

## Desenvolvimento

```bash
git clone <url-do-repositorio>
cd consulta-processos
python -m venv .venv && . .venv/Scripts/activate   # Linux/macOS: . .venv/bin/activate
pip install -e ".[dev,navegador]"
pytest
```

Os testes não acessam a rede: usam respostas gravadas das APIs.

## Licença

MIT — veja [LICENSE](LICENSE).
