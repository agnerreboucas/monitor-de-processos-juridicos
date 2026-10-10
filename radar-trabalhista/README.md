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
| RF-012 Administração da plataforma | **Falta** (tela do dono do SaaS) |
| Cobrança automática | **Falta** (PRD adia até escolher o provedor) |

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
- **Cron** (a cada 5 minutos): `*/5 * * * * php /caminho/radar-trabalhista/bin/worker.php >> /var/log/radar-worker.log 2>&1`. Cada rodada recupera trabalho preso, agenda o que venceu, consulta em lotes pequenos e envia os e-mails pendentes.
- **Apache/Hostinger:** aponte o domínio para `public/` (o `.htaccess` já encaminha `/api/*` ao PHP). Sirva **só por HTTPS**.
- **Teste local:** `php -S 127.0.0.1:8080 -t public public/index.php`. Sem `SMTP_HOST` e com `APP_ENV=local`, os e-mails vão para `storage/mail.log`. Em produção sem SMTP o envio é recusado, de propósito.

## Testes

```bash
composer install
# precisa de um MariaDB/MySQL de teste (o banco é APAGADO e recriado):
#   RADAR_TEST_DSN, RADAR_TEST_USER, RADAR_TEST_PASS  (padrão: localhost, radar_teste, radar / radar_teste_local)
./vendor/bin/phpunit
```

141 testes cobrem CNJ, migrações, login, processos, equipe, **isolamento entre escritórios**, worker (deduplicação, falha ≠ sem novidade, concorrência), e-mail e a API HTTP (CSRF, sessão, rotas protegidas).

## O que NÃO está garantido

- **Nunca rodou contra o DJEN ao vivo.** Os testes usam respostas gravadas no formato real. O primeiro teste de verdade deve ser feito num servidor no Brasil, com um processo conhecido, comparando com o tribunal.
- **Termos de uso comercial** do DJEN e do Datajud ainda precisam ser lidos e confirmados (PRD §7.3 e §12).
- O adaptador do Datajud (movimentos internos que não saem no diário) ainda não foi portado: hoje a fonte é só o diário.
- Não calcula prazos jurídicos (PRD §10, item 10). Não é intimação nem garantia de captura de tudo.
- Sem backup automático, sem política de retenção implementada, sem termos de uso e política de privacidade (RNF-007).
