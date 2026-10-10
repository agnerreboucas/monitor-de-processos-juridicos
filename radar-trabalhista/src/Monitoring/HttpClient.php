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
}
