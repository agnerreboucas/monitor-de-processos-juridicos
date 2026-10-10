# Colocar o Radar Trabalhista no ar (passo a passo)

Tempo: 1 a 2 horas na primeira vez. Você vai copiar e colar comandos num servidor. Se travar em qualquer passo, copie a mensagem de erro e me mande.

**Importante antes de começar**
- O DJEN (a fonte dos dados) recusa acesso de fora do Brasil. Por isso o servidor precisa estar **no Brasil**.
- Este kit **não foi executado por mim** (não tenho Docker funcionando no meu ambiente). A parte de código foi testada (214 testes), mas esta instalação é o primeiro uso real. Siga os passos e me conte o que aparecer.
- Antes de vender para clientes, leia a parte 8.

## 1. O que você precisa
1. **Um servidor (VPS) no Brasil** com Ubuntu 22.04 ou 24.04, pelo menos 2 GB de memória. Contrate um que informe "São Paulo" ou "Brasil" como localização.
2. **Um domínio** (ex.: `radar.seuescritorio.com.br`). Registro.br vende domínios .com.br.
3. No painel do domínio, crie um registro **A** apontando `radar` para o **IP do servidor**.

## 2. Preparar o servidor
Conecte por SSH (o provedor mostra o comando, algo como `ssh root@IP_DO_SERVIDOR`) e rode:

```bash
curl -fsSL https://get.docker.com | sh
apt-get install -y git ufw
ufw allow 22 && ufw allow 80 && ufw allow 443 && ufw --force enable
```

## 3. Baixar o sistema
```bash
git clone https://github.com/agnerreboucas/monitor-de-processos-juridicos.git
cd monitor-de-processos-juridicos/deploy
```
(Se o repositório for privado, o GitHub vai pedir login. Use um token de acesso pessoal como senha.)

## 4. Configurar e subir
```bash
cp .env.producao.exemplo .env.producao
nano .env.producao
```
Preencha: `DOMINIO`, `APP_URL` e as duas senhas do banco (use `openssl rand -hex 24` para gerar). Salve com Ctrl+O, Enter, Ctrl+X. Depois:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.producao up -d --build
docker compose -f docker-compose.prod.yml ps
```
Na primeira vez demora uns minutos (baixa e monta tudo). Quando todos os serviços aparecerem como "running", abra `https://SEU-DOMINIO/` no navegador. O certificado de HTTPS sai sozinho em até 1 minuto.

## 5. Testar a fonte de dados (o teste que decide tudo)
Rode com um número de processo **seu**, de verdade:

```bash
docker compose -f docker-compose.prod.yml exec app php bin/testar-fonte.php processo 0000000-00.0000.0.00.0000
docker compose -f docker-compose.prod.yml exec app php bin/testar-fonte.php oab 12345 MG 30
```
- **Apareceram movimentações/publicações?** Ótimo. A fonte funciona deste servidor. Compare com o que o tribunal mostra e pule para a parte 6.
- **Apareceu "FALHOU ... 403"?** O DJEN está recusando o IP deste servidor. Veja a parte 5-B.
- **Apareceu "0 movimentações"?** Pode ser normal (processo sem publicações no diário). Teste com um processo que você sabe que teve publicação.

### 5-B. Se o DJEN recusar o servidor (403)
Em ordem, do mais simples ao mais trabalhoso:

1. **Troque por um servidor realmente no Brasil.** Alguns IPs de provedores são tratados como "de fora". Um provedor brasileiro costuma resolver.
2. **Use um proxy brasileiro de um provedor.** O proxy é um intermediário: o servidor pede ao proxy, e o proxy (que tem IP brasileiro) pede ao DJEN. Contrate num provedor um proxy com **IPs do Brasil** e peça o endereço no formato `http://usuario:senha@endereco:porta`. Cole em `HTTP_PROXY_URL` no `.env.producao` e rode:
   ```bash
   docker compose -f docker-compose.prod.yml --env-file .env.producao up -d
   ```
   Teste de novo (parte 5). Sobre custo: o Radar consulta pouco (cada consulta é um texto pequeno, e há pausas entre as páginas). Uma estimativa grosseira é de dezenas de MB por mês para um escritório pequeno, mais uma carga inicial maior ao cadastrar uma OAB. Pacotes cobrados por GB costumam bastar, mas **confira o consumo no primeiro mês**.
3. **Rode o robô num computador seu, no Brasil** (veja `radar-trabalhista/local/LEIA-ME.md`). Só serve para uso interno.

**O que eu não vou montar:** proxy que troca de IP o tempo todo (por exemplo, celular alternando modo avião) nem recriar servidores quando o IP é bloqueado. Isso é contornar um bloqueio do CNJ, pode violar os termos da fonte e derruba o serviço para todo mundo quando o bloqueio vier. O Radar já consulta devagar (poucas OABs por rodada, pausa entre páginas) justamente para ser bom vizinho.

## 6. Criar sua conta e virar administrador
1. Abra `https://SEU-DOMINIO/entrar#/cadastro` e crie a conta do seu escritório.
2. No servidor:
   ```bash
   docker compose -f docker-compose.prod.yml exec app php bin/criar-admin.php seu@email.com
   ```
   Recarregue a página: aparece o menu **Administração**.
3. Esqueceu a senha e o e-mail ainda não está configurado? Rode:
   ```bash
   docker compose -f docker-compose.prod.yml exec app php bin/redefinir-senha.php seu@email.com
   ```

## 7. Conferir que está funcionando
- `https://SEU-DOMINIO/api/health` deve mostrar `"status":"ok"` alguns minutos depois de subir (o robô precisa ter rodado uma vez).
- Cadastre um processo seu. Em até 5 minutos ele deve aparecer como "Monitorado".
- No menu **Administração**, o aviso vermelho do robô some quando ele está rodando.
- Ver o que o robô está fazendo: `docker compose -f docker-compose.prod.yml logs -f worker`
- **Monitoramento externo:** cadastre `https://SEU-DOMINIO/api/health` num serviço grátis de monitoramento (por exemplo UptimeRobot) para receber aviso se cair.
- **Backup:** é feito todo dia em `deploy/backups/`. **Copie esses arquivos para fora do servidor** (outro provedor, Google Drive etc.) e teste uma restauração (instruções em `radar-trabalhista/bin/backup.php`).

## 8. Antes de cobrar de clientes (não pule)
- [ ] **Termos de Uso e Política de Privacidade** (`radar-trabalhista/public/app/termos.html` e `privacidade.html`) são rascunhos. Preencha os `[PREENCHER]` e peça revisão de um advogado especialista em LGPD.
- [ ] **E-mail**: configure o SMTP no `.env.producao` (convites e avisos por e-mail só funcionam com isso).
- [ ] **Termos de uso comercial do DJEN/Datajud**: leia e confirme que o uso em produto pago é permitido.
- [ ] **Cobrança**: ainda é manual. Você renova a assinatura de cada escritório na tela Administração.
- [ ] **Backup fora do servidor** funcionando e restauração testada.
- [ ] Atualizações do servidor: `apt-get update && apt-get upgrade` de vez em quando, e `git pull` + o comando `up -d --build` da parte 4 para atualizar o sistema.
