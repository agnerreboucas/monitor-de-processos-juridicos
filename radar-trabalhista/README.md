# Radar Processual

SaaS de monitoramento processual para escritórios de advocacia. PHP 8.2+, MySQL 8 / MariaDB 10.6+, HTML/CSS/JS puro. Especificação: o PRD "Radar Processual v1.0".

O robô em Python da pasta raiz deste repositório é a **referência**: dali foram portados a validação do número CNJ e o adaptador do DJEN, e os testes dele serviram de modelo.

## O que já funciona

| PRD | Situação |
|---|---|
| RF-001/002 Escritórios, login, papéis | Pronto: cadastro, login com bloqueio (5 erros = 15 min, e por IP), recuperação de senha (token de uso único, só o hash fica no banco), papéis administrador / advogado / assistente |
| RF-003 Cadastro de processos | Pronto: valida **formato e dígito verificador** do número CNJ, limite do plano, sem duplicar no escritório |
| RF-004/006/007 Consulta, fila, detecção | Pronto: fila no MySQL com `SKIP LOCKED`, espera progressiva (2, 4, 8, 16 min…, teto 6 h), deduplicação feita pelo próprio banco, primeira consulta importa o histórico **sem alertar** |
| RF-005 Histórico | Pronto: separa data da movimentação e data da captura; guarda o texto original sem alteração |
| RF-008 Notificações | Painel + e-mail (resumo por pessoa, tentativas controladas, status por notificação). Falta só configurar o SMTP real |
| RF-009/010 Painel e filtros | Pronto: indicadores, filtros e busca |
| RF-011 Equipe | Pronto: convite, papéis, desativar (perde o acesso na hora), nunca fica sem administrador |
| RF-013 Planos e limites | Pronto: Essencial / Profissional / Equipe (valores e limites **experimentais**), assinatura controlada à mão |
| RF-014 Auditoria | Pronto, sem senha nem token nos registros |
| Monitoramento por OAB (extra, inspirado no painel de concorrentes) | Pronto: cadastro de OAB por advogado (limite no plano: 2 / 5 / 15), consulta do diário por OAB desde 1º de janeiro (primeira vez em silêncio), lista de publicações com filtros e **"Monitorar este processo"**, painel **Ações por UF** (Estadual / Federal / Trabalho / Outras, por advogado e ano) e **alerta de possível inscrição suplementar** (mais de 5 processos diferentes no ano em uma UF onde o advogado não tem inscrição — Estatuto da Advocacia, art. 10, §2º) |
| RF-012 Administração da plataforma | Pronto: menu **Administração** só para o dono (`php bin/criar-admin.php email`): escritórios, uso, receita mensal das assinaturas ativas, saúde do robô, renovar, trocar plano, suspender, bloquear acesso, anotação. Não mostra conteúdo de processos. Tudo auditado |
| Ciclo da assinatura | Pronto, **sem provedor de pagamento**: teste de 14 dias → vencida (3 dias de carência, consultas continuam) → suspensa (consultas pausam, dados ficam). O dono renova à mão; o provedor, quando existir, só chama `renew()`. Faixa de aviso na tela |
| Cobrança automática | **Falta** (escolher Asaas/Stripe/Pagar.me; PRD adia) |
| Conta da pessoa | Pronto: nome, trocar senha (com limite de tentativas), aviso por e-mail ligado/desligado |
| Planilhas | Pronto: importar processos por CSV (relatório linha a linha, respeita o limite do plano) e exportar processos e publicações (CSV seguro contra fórmulas) |
| LGPD / termos | Aceite dos termos registrado; páginas de Termos e Privacidade **em rascunho, sem revisão jurídica**; cópia completa dos dados (JSON) e exclusão definitiva do escritório; limpeza por prazo (auditoria 730 dias, histórico de consultas 180) |
| Operação | `/api/health` (503 se o robô parou há 20 min), `bin/backup.php` (backup compactado; **restauração testada**), CI no GitHub Actions |

**Regra de ouro (PRD §10):** falha de consulta nunca vira "sem novidade" e nunca marca o processo como atualizado. A tela mostra a data da última consulta que **deu certo** e, em separado, o erro da última tentativa.

## Segurança do isolamento entre escritórios

- Toda tabela do cliente tem `tenant_id` e as chaves estrangeiras são **compostas** (`id, tenant_id`): o banco recusa uma movimentação do escritório B ligada a um processo do A, mesmo que o código erre.
- `TenantContext` é o único caminho para os dados e só nasce depois de conferir no banco usuário, escritório e vínculo ativos. Os repositórios sempre filtram por ele.
- O escritório vem **da sessão**, nunca do pedido. Um teste varre os repositórios e falha se uma consulta esquecer o `tenant`.

## Instalação

```bash
cd radar-trabalhista
composer install --no-dev
cp .env.example .env        # preencha DB_*, APP_URL, APP_KEY e SMTP_*
php bin/migrate.php
```

- **Servidor no Brasil.** O DJEN (gov.br) responde 403 a IP de fora do país. Fora do Brasil, use `HTTP_PROXY_URL`.
- **Backup diário:** `0 3 * * * php /caminho/radar-trabalhista/bin/backup.php` e copie o arquivo para FORA do servidor. **Monitoramento:** aponte um serviço (UptimeRobot etc.) para `/api/health`.
- **Hospedagem compartilhada (Hostinger), sem terminal:** veja `hostinger/LEIA-ME-HOSTINGER.md` e monte o pacote com `sh hostinger/montar-pacote.sh`. Traz o assistente `/instalar.php`. Testado em Apache comum, **não na Hostinger**.
- **Colocar no ar (VPS no Brasil, HTTPS, robô, backup):** veja `deploy/LEIA-ME-NO-AR.md` (kit Docker, **ainda não executado**). Atrás de proxy reverso, defina `TRUSTED_PROXIES` para o limite de tentativas enxergar o IP real. Senha esquecida sem e-mail: `php bin/redefinir-senha.php email`.
- **Dono do SaaS:** crie a conta pela tela e rode `php bin/criar-admin.php seu@email.com`.
- **Cron** (a cada 5 minutos): `*/5 * * * * php /caminho/radar-trabalhista/bin/worker.php >> /var/log/radar-worker.log 2>&1`. Cada rodada recupera trabalho preso, agenda o que venceu, consulta em lotes pequenos e envia os e-mails pendentes.
- **Apache/Hostinger:** aponte o domínio para `public/` (o `.htaccess` já encaminha `/api/*` ao PHP). Sirva **só por HTTPS**.
- **Com processos reais no seu computador:** veja `local/LEIA-ME.md` (Docker) e `php bin/testar-fonte.php`.
- **Páginas:** `/` é a apresentação (`public/app/inicio.html`), `/entrar` é o login e o sistema, `/app/...` são arquivos estáticos. A apresentação ainda tem textos de preço e prazos a confirmar (valores iniciais) e não traz depoimentos nem números de clientes de propósito.
- **Teste local:** `php -S 127.0.0.1:8080 -t public public/index.php`. Sem `SMTP_HOST` e com `APP_ENV=local`, os e-mails vão para `storage/mail.log`. Em produção sem SMTP o envio é recusado, de propósito.

## Testes

```bash
composer install
# precisa de um MariaDB/MySQL de teste (o banco é APAGADO e recriado):
#   RADAR_TEST_DSN, RADAR_TEST_USER, RADAR_TEST_PASS  (padrão: localhost, radar_teste, radar / radar_teste_local)
./vendor/bin/phpunit
```

314 testes (PHP) + 9 (JavaScript) cobrem CNJ, OAB (limite 5 × 6, UF indeterminada, isolamento, concorrência), migrações, login, processos, equipe, **isolamento entre escritórios**, worker (deduplicação, falha ≠ sem novidade, concorrência), e-mail a API HTTP (CSRF, sessão, rotas protegidas) e o **formato real do DJEN** (amostra de retorno verdadeiro em `tests/fixtures/djen-real-sample.json`; só prova o formato, não a consulta ao vivo).

## O que NÃO está garantido

- **Termos e Política de Privacidade são rascunhos** (`public/app/termos.html`, `privacidade.html`) com campos `[PREENCHER]`. Precisam de revisão de advogado antes de vender.
- **Sem cobrança automática, sem confirmação de e-mail, sem verificação em duas etapas.** E-mail real depende de configurar SMTP e SPF/DKIM do domínio.
- O CI (`.github/workflows/radar-trabalhista.yml`) foi escrito mas **ainda não rodou no GitHub**.

- **Alerta de inscrição suplementar é só indicativo.** A UF vem do tribunal e só é conhecida quando ele cobre um estado (TRF1/2/3/4/5, TRT8/10/11/14 ficam em "UF indeterminada" e fora do alerta). O advogado confere a habitualidade com a Seccional. Rodar `bin/worker.php` consulta até 3 OABs por rodada.

- **Nunca rodou contra o DJEN ao vivo.** Os testes usam respostas gravadas no formato real. O primeiro teste de verdade deve ser feito num servidor no Brasil, com um processo conhecido, comparando com o tribunal.
- **Termos de uso comercial** do DJEN e do Datajud ainda precisam ser lidos e confirmados (PRD §7.3 e §12).
- Datajud (andamento completo, inclusive o que não sai no diário): adaptador pronto e testado só com respostas simuladas; precisa da chave pública do CNJ em `DATAJUD_API_KEY` e nunca foi chamado ao vivo (o ambiente de desenvolvimento não alcança o CNJ).
- Não calcula prazos jurídicos (PRD §10, item 10). Não é intimação nem garantia de captura de tudo.
- Sem backup automático, sem política de retenção implementada, sem termos de uso e política de privacidade (RNF-007).

## Andamento completo (Datajud) e consulta por número
O Diário (DJEN) só traz o que foi **publicado**. Para ver **todo o andamento** (distribuição, conclusão, juntadas, audiências, remessa…) o sistema usa também o **Datajud**, a API pública do CNJ:

1. Copie a chave pública em https://datajud-wiki.cnj.jus.br/api-publica/acesso
2. Cole no `.env`, na linha `DATAJUD_API_KEY=`.
3. No sistema, menu **Consultar processo**: digite o número e veja a capa e o andamento completo. O botão **Acompanhar este processo** cadastra para monitorar.

Sem a chave, tudo continua funcionando só com o Diário, e a tela diz claramente que o quadro está incompleto. O Datajud demora alguns dias para refletir o que acabou de acontecer; o Diário (Comunica) é a fonte em tempo real.

## Ações e prazos (triagem das publicações)
O menu **Ações e prazos** lê o texto de cada publicação do Diário e mostra, com filtros: **que ato é**, **o que fazer**, **quem** tem de agir, **como**, **prazo** (urgência), **impacto** (favorável, desfavorável, atenção ou neutro), **riscos** e **atores** (juiz/relator, perito, intimados, partes e advogados).
- Informe em cada processo **de que lado o escritório atua** (autor/reclamante ou réu/reclamado): só assim o impacto aparece como favorável ou desfavorável.
- Cada publicação pode ser marcada como **tratada** ou **não requer ação** (vale para todo o escritório).
- A leitura é feita por **regras simples** (frases do próprio texto) no navegador (`public/app/analise.js`). É triagem: **pode errar ou deixar passar algo e não substitui a conferência do advogado nem o controle de prazos**. Os prazos são **estimados em dias úteis** (feriados nacionais, Sexta-feira Santa, Corpus Christi e recesso de 20/12 a 20/01); feriados locais e suspensões do tribunal não entram.
- Testes: `node --test tests/js/analise.test.cjs` (inclui o despacho real do TRT8 e uma varredura nos itens reais).

## API comercial (vender o acesso por chave)
No menu **API e clientes** (só o administrador da plataforma) você cadastra os **clientes da API**, escolhe o **plano** (cota por mês e limite por minuto), gera a **chave (token)** e acompanha o **consumo** de cada um. A cobrança é sua: no fechamento do mês, baixe o **CSV de uso** (consultas, cota e quanto passou dela).

- **Chave**: `rdr_live_` + 40 caracteres. Aparece **uma única vez**, na hora de gerar; o sistema guarda só o SHA-256. Cada cliente pode ter até 10 chaves, com validade opcional, e você revoga na hora.
- **Endpoints** (`/v1`, só GET): `/processos/{numero}` (capa + andamento completo do Datajud + publicações do Diário, partes e advogados), `/oab/{uf}/{numero}/publicacoes` (até 31 dias por consulta), `/uso` (cota do mês, não gasta cota), `/saude` e `/openapi.json` (sem chave).
- **Autenticação**: `Authorization: Bearer <chave>` ou `X-API-Key`. Sem sessão, sem cookie e sem CORS (a chave é para servidor).
- **Cota e limite**: só consulta bem-sucedida conta na cota; erro, fonte fora do ar (502) e limite estourado (429) não contam. Cabeçalhos `X-RateLimit-*` e `X-Quota-*` em toda resposta. Cache de 10 minutos protege as fontes públicas (consulta repetida do cache conta na cota).
- **Fonte que falha nunca vira "sem andamento"**: cada resposta traz `fontes[].status`.
- **Documentação para o cliente**: `/app/api-docs.html` (com exemplos em curl, JavaScript, Python e PHP) e `/v1/openapi.json`.
- Testes: `tests/ApiTest.php` (chaves, limites, cota, cache, cobrança e isolamento) e a rota completa em `tests/HttpApiTest.php`.

**Antes de vender (pendências suas):** confirmar os termos de uso do DJEN e do Datajud para uso comercial; ter contrato/termos da API e política de privacidade próprios (os textos de publicações trazem dados pessoais: LGPD); definir preços (os três planos iniciais são exemplos); cobrança automática (hoje é manual). A análise de ações e prazos (menu Ações e prazos) ainda roda só no navegador e **não** está na API.

## Porta de entrada
O endereço principal (`/`) abre a **página de apresentação**; o sistema fica em `/entrar`. Ao **sair** do sistema, a pessoa volta para a página de apresentação. No menu do administrador (grupo Plataforma) há os links **Página de apresentação** e **Página de login**; este abre em nova aba uma prévia do login (`/entrar?previa=login`) mesmo com você logado, com o botão para fechar a prévia.


## Para qualquer área do Direito
O produto não é só trabalhista. A leitura automática das publicações usa prazo-padrão de recurso por ramo (CLT 8 dias úteis na Justiça do Trabalho, CPC 15 nos demais, pelo número CNJ) e sempre avisa que é estimativa. Marca: **Radar Processual** (troca de nome é só substituir o texto).

## Cobrança por busca
- **R$ 0,30 por busca realizada com sucesso** (processo, OAB, consulta avulsa ou API). Busca que falhou fica registrada, **mas não é cobrada**.
- Teste de 14 dias não cobra. Cada escritório pode definir um **teto de gasto mensal**; ao atingir, as buscas pausam.
- Extrato por escritório e por cliente da API; o dono baixa o CSV de fechamento. Pagamento manual (Pix/instruções em `PAYMENT_INFO`); não há gateway.

## Buscas automáticas em horário fixo
O escritório escolhe um horário (ex.: 05:30, fuso de São Paulo) e todas as buscas rodam nele todo dia. Sem horário escolhido, vale o intervalo padrão. Precisa do cron do worker a cada 5 minutos.

## Painel do dono: usuários e uso
Telas de administração: prontidão para venda, pedidos de contratação, **usuários** (ver, bloquear/liberar — vale na hora) e **estatísticas de uso** do todo e por escritório (buscas, falhas, gasto, API). O painel mostra só cadastro e números, nunca o conteúdo dos processos.

## Cadastro de clientes
Qualquer escritório se cadastra sozinho, tem 14 dias grátis, cadastra seus processos reais e pode pedir a contratação dentro do sistema (o dono é avisado por e-mail).


## Assinatura mensal, teste de 14 dias e avisos
- Planos mensais por escritório: **Essencial R$ 299 (50 processos), Profissional R$ 599 (100), Escritório R$ 1.499 (250)**; "Pague por busca" (R$ 0,30) continua como opção. Escolha feita na página de venda (`/entrar#/cadastro?plano=...`).
- **14 dias grátis; a cobrança começa no 15º dia e o primeiro mês é de só 15 dias (meio mês).** A tela Plano mostra o valor; ao registrar o 1º pagamento (Administração > Renovar, sugere 15 dias), o escritório passa à mensalidade cheia.
- Contagem regressiva do teste no painel e na faixa do topo; e-mails automáticos (`LifecycleNotifier`, no `bin/worker.php`): boas-vindas, metade do teste, 3 dias, último dia e **resumo do dia** depois da atualização (atualizados, sem acesso e o motivo, novidades). Cada aviso sai uma vez; quem desligou e-mails não recebe.
- "Sigilo" no resumo só aparece quando a fonte informar o motivo; hoje a detecção automática de sigilo nos processos monitorados não existe.

## Como o escritório é atualizado
Na tela Plano: **uma vez por dia** (horário à escolha, ex.: 05:30) **ou de 6 em 6 horas**, mais o botão **"Consultar tudo agora"** (a cada 15 min por escritório) e o "Consultar agora" de cada processo (a cada 5 min por processo). O pedido entra na fila e sai na próxima rodada do robô; para sair em ~1 minuto, rode o `bin/worker.php` a cada minuto (servidor próprio).

## Painel do processo
Botão **"Ver o que fazer com este processo"**: resumo com ações, quem, prazo e impacto; depois o inteiro teor.
