<?php
declare(strict_types=1);

namespace Radar\Monitoring;

interface HttpClient
{
    /**
     * @param array<string,scalar> $query
     * @param array<string,string> $headers
     * Status 0 = falha de rede (tempo esgotado, DNS, conexão recusada); o motivo vai em `error`.
     */
    public function get(string $url, array $query, array $headers, int $timeoutSeconds): HttpResponse;

    /**
     * Envia um corpo JSON (o Datajud é consultado por POST).
     * @param array<string,mixed> $json
     * @param array<string,string> $headers
     */
    public function postJson(string $url, array $json, array $headers, int $timeoutSeconds): HttpResponse;
}
