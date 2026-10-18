# Usar a API do Radar em outro sistema (inclusive GPT)

A API é a mesma para qualquer sistema: cada sistema recebe **uma chave própria**, com cota e extrato separados.

## 1. Pré-requisito
O Radar precisa estar no ar com endereço público (ex.: `https://radar.seudominio.com.br`) e `APP_URL` preenchido no `.env`. Sem isso, outro sistema não alcança a API.

## 2. Criar uma chave para cada sistema
1. Entre como dono do sistema e abra **API e clientes**.
2. **Novo cliente**: dê um nome (ex.: "Meu GPT") e escolha o plano (cota e limite por minuto).
3. Dentro do cliente, **Criar chave**. Ela aparece **uma única vez** (`rdr_live_...`). Copie e guarde; o banco só guarda o resumo (SHA-256).
4. Se vazar, **revogue** e crie outra.

## 3. Chamar a API
Cabeçalho: `Authorization: Bearer rdr_live_...` (ou `X-API-Key`).

| Chamada | Para quê |
|---|---|
| `GET /v1/processos/{numero}` | capa e andamento completo (Datajud + Diário) |
| `GET /v1/oab/{uf}/{numero}/publicacoes?inicio=AAAA-MM-DD&fim=AAAA-MM-DD` | publicações do Diário de um advogado (até 31 dias) |
| `GET /v1/uso` | cota e consumo do mês (não gasta cota) |
| `GET /v1/saude` | teste de disponibilidade (sem chave) |

Teste rápido:
```
curl -H "Authorization: Bearer rdr_live_SUACHAVE" https://radar.seudominio.com.br/v1/processos/0001457-85.2026.5.08.0106
```

## 4. Em um GPT personalizado (Actions)
1. No GPT, abra **Configurar > Ações > Criar nova ação**.
2. **Importar de URL**: `https://radar.seudominio.com.br/v1/openapi.json` (a URL completa do servidor já vem no arquivo quando `APP_URL` está preenchido).
3. **Autenticação**: tipo **Chave de API**, esquema **Bearer**, cole a chave `rdr_live_...`.
4. Nas instruções do GPT, diga que as respostas têm `fontes[].status` e que, se uma fonte falhou, o quadro está incompleto; e que o resultado não é intimação nem prazo oficial.
5. Cada consulta do GPT conta na cota da chave. Se o plano for "por busca", custa R$ 0,30 por consulta bem-sucedida.

## 5. Dois sistemas, uma API
Crie **um cliente e uma chave para cada sistema**. Assim cada um tem o seu consumo, pode ser pausado ou revogado sozinho, e a cobrança sai separada. Não compartilhe a mesma chave.

## 6. Cuidados
- A chave dá acesso pago: não coloque em código público nem em páginas do navegador; use no servidor do outro sistema.
- Chamadas feitas pelo navegador de outro site não são liberadas (a API não usa CORS, de propósito). Chame de servidor a servidor.
- Erros 429 (limite) e 502 (fonte fora do ar) não consomem cota.
