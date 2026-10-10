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
        $ch = curl_init($url . ($query !== [] ? '?' . http_build_query($query) : ''));
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
        if ($this->proxyUrl !== null && $this->proxyUrl !== '') {
            curl_setopt($ch, CURLOPT_PROXY, $this->proxyUrl);
        }
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $body === false ? curl_error($ch) : null;
        curl_close($ch);
        // a mensagem do curl pode conter o endereço do proxy (com senha): só o tipo de erro sai daqui
        return new HttpResponse($body === false ? 0 : $status, $body === false ? '' : (string) $body,
            $error !== null ? preg_replace('~://[^@\s/]+@~', '://***@', $error) : null);
    }
}
