<?php
declare(strict_types=1);

namespace Radar\Api;

/** Descrição da API em OpenAPI 3 (serve a /v1/openapi.json; importável no Postman, Insomnia e geradores de SDK). */
final class OpenApi
{
    /** @return array<string,mixed> */
    public static function spec(string $baseUrl = ''): array
    {
        $erro = ['description' => 'Erro', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Erro']]]];
        return [
            'openapi' => '3.0.3',
            'info' => ['title' => 'API de Processos e Publicações (Radar Processual)', 'version' => '1.0.0',
                'description' => 'Consulta de andamento processual (Datajud/CNJ) e publicações do Diário (DJEN/Comunica). Dados públicos de fontes oficiais; confira sempre no tribunal antes de agir.'],
            'servers' => [['url' => $baseUrl !== '' ? rtrim($baseUrl, '/') . '/v1' : '/v1']],      // com APP_URL a URL é completa (GPT Actions e outros sistemas exigem)
            'security' => [['bearer' => []]],
            'paths' => [
                '/processos/{numero}' => ['get' => ['summary' => 'Capa e andamento completo de um processo', 'description' => 'Junta o Datajud (andamento interno) e o Diário (publicações, partes e advogados). Cada fonte vem com o seu status: se uma falhar, o quadro está incompleto.',
                    'parameters' => [['name' => 'numero', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'example' => '0001457-85.2026.5.08.0106', 'description' => 'Número CNJ, com ou sem pontuação.']],
                    'responses' => ['200' => ['description' => 'Processo consolidado'], '401' => $erro, '422' => $erro, '429' => $erro, '502' => $erro]]],
                '/oab/{uf}/{numero}/publicacoes' => ['get' => ['summary' => 'Publicações do Diário em nome de um advogado',
                    'parameters' => [['name' => 'uf', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'example' => 'SP'], ['name' => 'numero', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string'], 'example' => '128733'],
                        ['name' => 'inicio', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Padrão: 6 dias antes do fim.'], ['name' => 'fim', 'in' => 'query', 'schema' => ['type' => 'string', 'format' => 'date'], 'description' => 'Padrão: hoje. Período máximo de 31 dias.']],
                    'responses' => ['200' => ['description' => 'Lista de publicações'], '401' => $erro, '422' => $erro, '429' => $erro, '502' => $erro]]],
                '/uso' => ['get' => ['summary' => 'Cota e consumo do mês da sua conta (não gasta cota)', 'responses' => ['200' => ['description' => 'Uso'], '401' => $erro]]],
                '/saude' => ['get' => ['summary' => 'Disponibilidade (sem chave)', 'security' => [], 'responses' => ['200' => ['description' => 'ok']]]],
            ],
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Authorization: Bearer rdr_live_… (ou o cabeçalho X-API-Key).']],
                'schemas' => ['Erro' => ['type' => 'object', 'properties' => ['erro' => ['type' => 'object', 'properties' => ['codigo' => ['type' => 'string'], 'mensagem' => ['type' => 'string']]]]]],
            ],
        ];
    }
}
