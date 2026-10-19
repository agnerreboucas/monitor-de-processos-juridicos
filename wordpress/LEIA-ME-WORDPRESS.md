# Radar Processual no WordPress (plugin + tema)

O sistema inteiro roda **dentro do seu WordPress**, no mesmo site e no mesmo banco, sem subdomínio:

| Endereço | O que é |
|---|---|
| `seusite.com.br/` | página de venda (o **tema**) |
| `seusite.com.br/demonstracao/` | **demonstração** com escritório modelo e dados fictícios (3 tabelas), para apresentar o sistema |
| `seusite.com.br/radar/entrar` | painel do sistema: cadastro, login, processos, plano |
| `seusite.com.br/radar/v1/...` | **API por chave** (para o seu GPT e outros sistemas) |
| `seusite.com.br/radar/app/api-docs.html` | documentação da API para os clientes |
| WordPress > **Radar** | configurações e saúde do sistema (só administrador do site) |

São dois pacotes: `radar-processual-plugin.zip` (o sistema) e `radar-processual-tema.zip` (o site de venda).

## O que foi testado
Um WordPress 6.9 real, instalado aqui, com os dois pacotes: cadastro com plano escolhido, painel, resumo do dia, horário e modo de busca, "Consultar tudo agora", bloqueio de pedido de outro site, painel da API, chave, chamadas `/radar/v1` com e sem chave, robô pelo WP-Cron, e-mails pelo `wp_mail`, tela de configurações no wp-admin. Também a suíte automática do Radar (mais de 300 testes) rodando com o prefixo de tabelas do plugin.
**Não testado:** contra o Diário (DJEN) e o Datajud de verdade (este ambiente não os alcança), em hospedagem real, nem com plugins de cache ou de segurança de terceiros.

## Instalação (sem terminal)
1. **Requisitos:** WordPress 6.0+ (testado no 6.9), PHP 8.2+ (com `pdo_mysql`, `curl`, `mbstring`), HTTPS ativo e **links permanentes "bonitos"** (Configurações > Links permanentes > "Nome do post" ou outro que não seja "Simples").
2. **Aparência > Temas > Adicionar novo > Enviar tema:** envie `radar-processual-tema.zip` e **ative**.
3. **Plugins > Adicionar novo > Enviar plugin:** envie `radar-processual-plugin.zip` e **ative**. Ao ativar, o plugin cria as tabelas do Radar no banco do WordPress, com o prefixo `<prefixo-do-wp>radar_` (ex.: `wp_radar_tenants`), e agenda o robô.
4. Abra **Radar** no menu do WordPress e confira a "Saúde do sistema".
5. **Crie sua conta** em `seusite.com.br/radar/entrar#/cadastro` e, na tela Radar do WordPress, informe o mesmo e-mail em **Dono do sistema > Tornar administrador**. No painel do Radar aparecem "Administração", "Usuários e uso" e "API e clientes".
6. Em **Radar > Configurações**, preencha: chave do Datajud, "Como o cliente paga" (seu Pix) e contato de suporte. Clique em **Testar o Diário (DJEN)**.

## O robô (muito importante)
As buscas automáticas rodam pelo **WP-Cron** a cada 5 minutos, mas o WP-Cron só dispara quando alguém visita o site. Para as buscas saírem no horário (ex.: 5h30), faça assim:
1. No `wp-config.php`, acrescente: `define('DISABLE_WP_CRON', true);`
2. No painel da hospedagem (Cron Jobs), crie uma tarefa **a cada minuto** (ou a cada 5) com: `wget -q -O - "https://seusite.com.br/wp-cron.php?doing_wp_cron" >/dev/null 2>&1`
A tela Radar mostra a última rodada e a próxima.

## E-mails
Os avisos (boas-vindas, resumo do dia, convites, redefinição de senha) saem pelo `wp_mail()` do WordPress. Se o seu site já envia e-mail (por exemplo com o plugin WP Mail SMTP), o Radar usa o mesmo. Sem isso, os e-mails podem cair no spam ou não sair.

## API para outro sistema (GPT)
Siga o `API-INTEGRACAO.md`, trocando o endereço-base por `https://seusite.com.br/radar`. O arquivo de especificação fica em `https://seusite.com.br/radar/v1/openapi.json` (já com o endereço completo).

## Plugins de cache e segurança
Exclua `/radar/` do cache de página (o plugin avisa os principais por `DONOTCACHEPAGE`, mas confira) e, se usar firewall, libere `/radar/api/` e `/radar/v1/`.

## Como o Radar convive com o WordPress
- **Contas separadas:** os clientes do Radar **não** são usuários do WordPress. Têm login, senha e permissões próprios (isolados por escritório). Assim ninguém ganha acesso ao seu wp-admin.
- **Banco:** as tabelas ficam no banco do WordPress, com prefixo. O backup do WordPress já as inclui.
- **Chave do Datajud:** fica guardada nas opções do WordPress (no banco). Quem tem acesso ao banco ou ao wp-admin pode vê-la; proteja esses acessos.
- **Não funciona em Multisite** (rede de sites) nesta versão.

## Atualizar
Envie o plugin novo em Plugins > Adicionar novo > Enviar plugin ("substituir"). As migrações do banco rodam sozinhas no primeiro acesso depois da atualização.

## Desinstalar
Desativar o plugin só para o robô. **Excluir** o plugin mantém os dados dos clientes, a menos que você marque, em Radar > Configurações, "apagar também todas as tabelas e dados do Radar".

## Para quem desenvolve
- Montar os pacotes: `sh wordpress/montar-wordpress.sh` (gera os dois zips na raiz do repositório).
- Teste de integração com WordPress de verdade: `sh wordpress/teste/rodar.sh` (precisa de php, composer, zip e MariaDB local).
- O plugin é só um invólucro: o núcleo (`src/`, `migrations/`, telas) é o mesmo do sistema independente, com `DB_PREFIX` ligado.

## Páginas editáveis no Elementor (grátis)
O tema usa o **Elementor** (versão gratuita) para as páginas **Início** e **Demonstração**. Instale e ative o plugin **Elementor** (Plugins > Adicionar novo) e depois o tema: as duas páginas são criadas sozinhas, já com todo o conteúdo, e ficam em **Páginas > Editar com o Elementor**. Sem o Elementor elas aparecem do mesmo jeito (texto simples), mas só dá para editar com ele.

- Cada bloco é seção, coluna, título, texto, botão, imagem ou perguntas (acordeão): tudo do Elementor grátis. Textos, preços, links e imagens se trocam clicando.
- O tema **nunca sobrescreve** uma página que já tem conteúdo do Elementor. Para voltar uma página ao original: **Aparência > Páginas do Radar** (apaga as suas edições nela).
- Novas páginas (por exemplo "Sobre"): crie em **Páginas > Adicionar nova** e use "Editar com o Elementor"; elas ocupam a largura toda, no mesmo visual.
- **Cabeçalho e rodapé não são editáveis no Elementor grátis** (ele só faz isso na versão paga). Ficam no WordPress: o menu em **Aparência > Menus** (locais "Menu do topo" e "Menu do rodapé"; sem menu, aparece o padrão) e a faixa do topo, o texto do rodapé e o WhatsApp do botão flutuante em **Aparência > Personalizar > Radar**.
- Os botões de WhatsApp **dentro das páginas** têm o link no próprio botão (edite no Elementor).
- As tabelas da demonstração são widgets "Shortcode": `[radar_demo_kpis]` e `[radar_demo_tabela tipo="carteira|acoes|publicacoes" limite="4"]`. Os dados (fictícios) estão em `inc/demo-data.php`, com datas sempre relativas a hoje.
- O Elementor grátis também pede a fonte Roboto ao Google por padrão; o tema desliga isso.

## Página de demonstração
É a página **Demonstração** (`/demonstracao/`): escritório modelo fictício (Prado & Associados), com 3 tabelas: carteira de processos, ações e prazos e publicações do dia. As datas são sempre relativas a hoje. Para trocar nomes, números ou linhas das tabelas, edite `inc/demo-data.php` no tema; os textos ao redor se editam no Elementor. Os números de processo são inventados, mas com dígito verificador válido.
