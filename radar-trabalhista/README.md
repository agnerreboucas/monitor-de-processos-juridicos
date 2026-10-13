# Radar Trabalhista

SaaS de monitoramento processual para escritórios de advocacia trabalhista. PHP 8.2+, MySQL 8 / MariaDB 10.6+, HTML/CSS/JS puro. Especificação: o PRD "Radar Trabalhista v1.0".

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

228 testes cobrem CNJ, OAB (limite 5 × 6, UF indeterminada, isolamento, concorrência), migrações, login, processos, equipe, **isolamento entre escritórios**, worker (deduplicação, falha ≠ sem novidade, concorrência), e-mail a API HTTP (CSRF, sessão, rotas protegidas) e o **formato real do DJEN** (amostra de retorno verdadeiro em `tests/fixtures/djen-real-sample.json`; só prova o formato, não a consulta ao vivo).

## O que NÃO está garantido

- **Termos e Política de Privacidade são rascunhos** (`public/app/termos.html`, `privacidade.html`) com campos `[PREENCHER]`. Precisam de revisão de advogado antes de vender.
- **Sem cobrança automática, sem confirmação de e-mail, sem verificação em duas etapas.** E-mail real depende de configurar SMTP e SPF/DKIM do domínio.
- O CI (`.github/workflows/radar-trabalhista.yml`) foi escrito mas **ainda não rodou no GitHub**.

- **Alerta de inscrição suplementar é só indicativo.** A UF vem do tribunal e só é conhecida quando ele cobre um estado (TRF1/2/3/4/5, TRT8/10/11/14 ficam em "UF indeterminada" e fora do alerta). O advogado confere a habitualidade com a Seccional. Rodar `bin/worker.php` consulta até 3 OABs por rodada.

- **Nunca rodou contra o DJEN ao vivo.** Os testes usam respostas gravadas no formato real. O primeiro teste de verdade deve ser feito num servidor no Brasil, com um processo conhecido, comparando com o tribunal.
- **Termos de uso comercial** do DJEN e do Datajud ainda precisam ser lidos e confirmados (PRD §7.3 e §12).
- O adaptador do Datajud (movimentos internos que não saem no diário) ainda não foi portado: hoje a fonte é só o diário.
- Não calcula prazos jurídicos (PRD §10, item 10). Não é intimação nem garantia de captura de tudo.
- Sem backup automático, sem política de retenção implementada, sem termos de uso e política de privacidade (RNF-007).
