# Rodar o Radar Processual com processos reais (no seu computador)

Precisa: computador **no Brasil** (o DJEN recusa IP de fora) e o **Docker Desktop** instalado (https://www.docker.com/products/docker-desktop/).

## 1. Teste rápido da fonte (2 minutos, sem instalar o sistema)
Com PHP 8.2+ e `composer install` feitos, rode com um processo seu de verdade:

    php bin/testar-fonte.php processo 0000000-00.0000.0.00.0000
    php bin/testar-fonte.php oab 12345 MG 30

Se aparecerem as publicações, a fonte funciona. Se aparecer "FALHOU: ... 403", o seu IP não é brasileiro ou o DJEN bloqueou: me mande a mensagem exata.
Compare o resultado com o que o tribunal mostra. Esse é o teste que ainda não tinha sido feito.

## 2. Sistema completo
Na pasta `radar-trabalhista`:

    docker compose up --build

Abra http://localhost:8080, crie a conta do escritório, cadastre seus processos e suas OABs.
O robô roda sozinho a cada 5 minutos; "Consultar agora" na tela adianta a consulta.

- A primeira consulta de cada processo traz o histórico em silêncio. Só movimentação **nova depois disso** gera alerta.
- E-mails não saem (modo local): ficam em `storage/mail.log` dentro do contêiner.
- Para parar: Ctrl+C. Os dados ficam guardados. Para apagar tudo: `docker compose down -v`.

## Cuidados
- Use só processos e OABs que você pode acompanhar. Publicações de diário contêm dados pessoais de terceiros.
- Isto é um ambiente de teste na sua máquina, sem HTTPS nem backup. Não é a versão para clientes.
- Este kit **não foi executado por mim** (o ambiente onde trabalho não tem Docker em funcionamento). Se algo falhar ao subir, copie a mensagem de erro e me envie.
