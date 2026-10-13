<?php
declare(strict_types=1);

namespace Radar\Monitoring;

final class CurlHttpClient implements HttpClient
{
    public function __construct(private ?string $proxyUrl = null)
    {
    }

    public function get(string $url, array $query, array $headers, int $timeoutSeconds): HttpResponse
    {
        return $this->send($url . ($query !== [] ? '?' . http_build_query($query) : ''), $headers, $timeoutSeconds, null);
    }

    public function postJson(string $url, array $json, array $headers, int $timeoutSeconds): HttpResponse
    {
        return $this->send($url, $headers + ['Content-Type' => 'application/json'], $timeoutSeconds, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function send(string $fullUrl, array $headers, int $timeoutSeconds, ?string $body): HttpResponse
    {
        $ch = curl_init($fullUrl);
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_ENCODING => '',                       // aceita gzip/deflate
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        if ($body !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
        }
        if ($this->proxyUrl !== null && $this->proxyUrl !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyUrl);
        }
        $result = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $result === false ? curl_error($ch) : null;
        curl_close($ch);
        // a mensagem do curl pode conter o endereço do proxy (com senha): só o tipo de erro sai daqui
        return new HttpResponse($result === false ? 0 : $status, $result === false ? '' : (string) $result,
            $error !== null ? preg_replace('~://[^@\s/]+@~', '://***@', $error) : null);
    }
}
