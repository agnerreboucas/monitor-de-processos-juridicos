# Radar Processual na hospedagem compartilhada da Hostinger

Para quem **não usa terminal**. Tudo é feito pelo painel (hPanel) e por uma página de instalação. Os nomes dos menus do hPanel mudam de vez em quando: se algum estiver diferente, procure o parecido ou me mande um print.

**O que foi e não foi testado:** o pacote e a página de instalação foram testados num servidor Apache comum (rotas, proteção das pastas internas, página de instalação, robô). **Não foram testados na Hostinger**, e o teste com o DJEN real só você consegue fazer (o meu ambiente não alcança o DJEN).

## Estrutura recomendada
- **Site (WordPress):** `seudominio.com.br`, como já é.
- **Radar:** `app.seudominio.com.br`, num subdomínio, com o banco de dados próprio. Um não atrapalha o outro.

## 0. Antes de começar, confira 3 coisas no hPanel
1. **Localização do servidor da sua hospedagem.** O DJEN só atende o Brasil. Em *Hospedagem > Gerenciar*, veja a localização do servidor. Se não for Brasil, o teste do passo 6 provavelmente dará "403". Mesmo assim vale testar, e há saídas (passo 11).
2. **Versão do PHP 8.2 ou mais.** Em *Avançado > Configuração do PHP*, escolha 8.2 ou superior.
3. **Cron Jobs disponível** em *Avançado > Cron Jobs* (o robô precisa disso).

## 1. Criar o subdomínio
*Domínios > Subdomínios*: crie `app`. Anote a **pasta** que a Hostinger criou para ele (algo como `public_html/app`).

## 2. Criar o banco de dados
*Bancos de dados > Gerenciamento > MySQL*: crie um banco novo. Anote **nome do banco, usuário e senha** (a Hostinger coloca um prefixo como `u123456789_`).

## 3. Enviar os arquivos
1. Baixe o arquivo **`radar-hostinger.zip`** (eu envio para você junto com este guia).
2. *Arquivos > Gerenciador de arquivos*: entre na pasta do subdomínio, clique em **Upload** e envie o zip.
3. Clique com o botão direito no zip > **Extrair**. Vai aparecer uma pasta `radar`.
4. Ative "mostrar arquivos ocultos" (engrenagem do gerenciador). Entre na pasta `radar`, **selecione tudo (inclusive o `.htaccess`) e mova para a pasta do subdomínio**. Depois apague a pasta `radar` vazia e o zip.
5. Ao final, dentro da pasta do subdomínio devem estar: `.htaccess`, `bin`, `migrations`, `public`, `src`, `storage`, `vendor`, `composer.json` e `env-exemplo.txt`.

## 4. Criar o arquivo de configuração
1. Renomeie `env-exemplo.txt` para **`.env`** (com o ponto na frente).
2. Edite e preencha: `APP_URL` (o endereço do subdomínio com https), `INSTALL_TOKEN` (invente uma sequência longa), os dados do banco (`DB_NAME`, `DB_USER`, `DB_PASS`) e, se já tiver, o e-mail (passo 9).
3. Nunca compartilhe esse arquivo.

## 5. Ativar o HTTPS
*Segurança > SSL*: ative o certificado gratuito para o subdomínio e ligue "forçar HTTPS".

## 6. Rodar a página de instalação
Abra `https://app.seudominio.com.br/instalar.php`, digite o código (`INSTALL_TOKEN`) e:
1. Veja a lista de verificações. O que estiver em vermelho diz o que corrigir.
2. Clique em **Criar/atualizar tabelas**.
3. Clique em **Testar a fonte de dados (DJEN)**. **Esse é o teste que decide tudo:**
   - **"O DJEN respondeu normalmente":** a sua hospedagem serve. Siga em frente.
   - **"O DJEN recusou este servidor (403)":** veja o passo 11.

## 7. Criar sua conta e virar administrador
1. Abra `https://app.seudominio.com.br/entrar#/cadastro` e crie a conta do escritório.
2. Volte em `/instalar.php`, use "Tornar administradora" com o seu e-mail. Recarregue o sistema: aparece o menu **Administração**.

## 8. Ligar o robô (cron)
Em *Avançado > Cron Jobs*, crie uma tarefa **a cada 5 minutos** com o comando que a página `/instalar.php` mostra (algo como `/usr/bin/php /home/.../worker.php`). Se o comando não funcionar, a Hostinger mostra o erro no próprio painel de cron; confira a versão do PHP da linha de comando (precisa ser 8.2+). Depois de 5 a 10 minutos, a página de instalação deve mostrar o robô como **OK**.

## 9. E-mail
1. *E-mails*: crie uma caixa (por exemplo `contato@seudominio.com.br`).
2. No `.env`, preencha `SMTP_USER`, `SMTP_PASS` e `SMTP_FROM` (o `env-exemplo.txt` já traz o servidor e a porta da Hostinger).
3. Em `/instalar.php`, use **Enviar e-mail de teste**.

## 10. Terminar a instalação
**Apague a linha `INSTALL_TOKEN` do `.env`.** A página `/instalar.php` passa a responder "não encontrado". Se precisar dela de novo (por exemplo para atualizar o sistema), coloque a linha de volta.

## 11. Se o DJEN recusar sua hospedagem (403)
1. **Pergunte ao suporte da Hostinger** se o servidor da sua hospedagem é no Brasil e se há bloqueio de saída para `comunicaapi.pje.jus.br`.
2. **Proxy brasileiro de um provedor** (um intermediário com IP do Brasil). Cole o endereço `http://usuario:senha@endereco:porta` em `HTTP_PROXY_URL` no `.env` e refaça o teste. O Radar consulta pouco, então um pacote pequeno costuma bastar, mas confira o consumo no primeiro mês.
3. **Um servidor (VPS) no Brasil** e o kit da pasta `deploy/` (veja `deploy/LEIA-ME-NO-AR.md`). Pode continuar com o WordPress na Hostinger.

Não monte proxy que troca de IP o tempo todo: é contornar bloqueio do CNJ.

## 12. Cuidados da hospedagem compartilhada
- **Backup:** ligue os backups automáticos da Hostinger (*Arquivos > Backups*) e, uma vez por mês, exporte o banco pelo phpMyAdmin e guarde fora da Hostinger. O script `bin/backup.php` precisa de recursos que a hospedagem compartilhada pode não liberar.
- **Limite de recursos:** o plano compartilhado limita CPU e processos. Para poucos escritórios e algumas centenas de processos deve servir; se começar a travar, mude para um VPS.
- **Atualizar o sistema:** suba o novo zip por cima, **sem apagar o `.env` e a pasta `storage`**, ponha `INSTALL_TOKEN` de volta e clique em "Criar/atualizar tabelas".
- **Monitorar:** cadastre `https://app.seudominio.com.br/api/health` num serviço de monitoramento (por exemplo UptimeRobot). Responde `ok` quando banco e robô estão bem.
- **Antes de cobrar clientes:** Termos e Privacidade são rascunhos; veja a parte 8 de `deploy/LEIA-ME-NO-AR.md`.

## 13. Ligar o WordPress ao Radar
No site WordPress, os botões **Entrar** e **Testar grátis** devem apontar para `https://app.seudominio.com.br/entrar` e `https://app.seudominio.com.br/entrar#/cadastro`.

## Andamento completo (Datajud) e consulta por número
O Diário (DJEN) só traz o que foi **publicado**. Para ver **todo o andamento** (distribuição, conclusão, juntadas, audiências, remessa…) o sistema usa também o **Datajud**, a API pública do CNJ:

1. Copie a chave pública em https://datajud-wiki.cnj.jus.br/api-publica/acesso
2. Cole no `.env`, na linha `DATAJUD_API_KEY=`.
3. No sistema, menu **Consultar processo**: digite o número e veja a capa e o andamento completo. O botão **Acompanhar este processo** cadastra para monitorar.

Sem a chave, tudo continua funcionando só com o Diário, e a tela diz claramente que o quadro está incompleto. O Datajud demora alguns dias para refletir o que acabou de acontecer; o Diário (Comunica) é a fonte em tempo real.
