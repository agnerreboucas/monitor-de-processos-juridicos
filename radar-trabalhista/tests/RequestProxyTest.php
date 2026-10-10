<?php
declare(strict_types=1);

namespace Radar\Tests;

use PHPUnit\Framework\TestCase;
use Radar\Http\Request;

final class RequestProxyTest extends TestCase
{
    private array $backup;

    protected function setUp(): void
    {
        $this->backup = $_SERVER;
        $_SERVER = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/api/session'];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->backup;
    }

    private function request(string $remote, array $headers, array $trusted): Request
    {
        $_SERVER['REMOTE_ADDR'] = $remote;
        foreach ($headers as $k => $v) {
            $_SERVER[$k] = $v;
        }
        return Request::fromGlobals($trusted);
    }

    public function testForwardedHeadersAreIgnoredWhenTheCallerIsNotATrustedProxy(): void
    {
        $r = $this->request('203.0.113.9', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_X_FORWARDED_PROTO' => 'https'], ['172.16.0.0/12']);
        $this->assertSame('203.0.113.9', $r->ip);            // quem manda o cabeçalho não escolhe o próprio IP
        $this->assertFalse($r->secure);
    }

    public function testTrustedProxyPassesTheRealVisitorAndProtocol(): void
    {
        $r = $this->request('172.18.0.3', ['HTTP_X_FORWARDED_FOR' => '200.150.10.20', 'HTTP_X_FORWARDED_PROTO' => 'https'], ['172.16.0.0/12']);
        $this->assertSame('200.150.10.20', $r->ip);
        $this->assertTrue($r->secure);
    }

    public function testAForgedFirstHopCannotChangeTheVisitorSeenByTheProxy(): void
    {
        // o visitante mandou "X-Forwarded-For: 9.9.9.9"; o proxy acrescentou o IP real dele no fim
        $r = $this->request('172.18.0.3', ['HTTP_X_FORWARDED_FOR' => '9.9.9.9, 200.150.10.20'], ['172.16.0.0/12']);
        $this->assertSame('200.150.10.20', $r->ip);
    }

    public function testGarbageInTheChainFallsBackToTheProxyAddress(): void
    {
        $r = $this->request('172.18.0.3', ['HTTP_X_FORWARDED_FOR' => 'lixo'], ['172.16.0.0/12']);
        $this->assertSame('172.18.0.3', $r->ip);
    }

    public function testNoTrustedProxiesConfiguredMeansDirectConnection(): void
    {
        $r = $this->request('203.0.113.9', ['HTTP_X_FORWARDED_FOR' => '1.2.3.4'], []);
        $this->assertSame('203.0.113.9', $r->ip);
    }

    public function testIpInListUnderstandsSingleIpsCidrAndIpv6(): void
    {
        $this->assertTrue(Request::ipInList('127.0.0.1', ['127.0.0.1']));
        $this->assertTrue(Request::ipInList('172.31.255.255', ['172.16.0.0/12']));
        $this->assertFalse(Request::ipInList('172.32.0.1', ['172.16.0.0/12']));
        $this->assertTrue(Request::ipInList('10.1.2.3', ['10.0.0.0/8', '192.168.0.0/16']));
        $this->assertTrue(Request::ipInList('::1', ['::1']));
        $this->assertTrue(Request::ipInList('fd00::5', ['fd00::/8']));
        $this->assertFalse(Request::ipInList('8.8.8.8', ['fd00::/8']));
        $this->assertFalse(Request::ipInList('nao-e-ip', ['0.0.0.0/0']));
        $this->assertFalse(Request::ipInList('1.2.3.4', ['']));
    }
}
