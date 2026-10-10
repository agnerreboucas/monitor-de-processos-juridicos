<?php
declare(strict_types=1);

namespace Radar\Tests\Support;

use Radar\Monitoring\HttpClient;
use Radar\Monitoring\HttpResponse;

final class FakeHttp implements HttpClient
{
    /** @var list<array{url:string,query:array<string,scalar>,headers:array<string,string>}> */
    public array $requests = [];
    /** @var list<HttpResponse> */
    private array $responses = [];

    public function push(HttpResponse $response): self
    {
        $this->responses[] = $response;
        return $this;
    }

    public function get(string $url, array $query, array $headers, int $timeoutSeconds): HttpResponse
    {
        $this->requests[] = ['url' => $url, 'query' => $query, 'headers' => $headers];
        return array_shift($this->responses) ?? new HttpResponse(200, '{"items":[],"count":0}');
    }
}
