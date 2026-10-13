<?php
declare(strict_types=1);

namespace Radar\Api;

/**
 * Token da API: "rdr_live_" + 40 caracteres aleatórios. Só o SHA-256 vai para o banco; o token inteiro aparece
 * UMA vez, na tela, no momento em que é criado. Como o token já é aleatório e longo, o hash simples basta.
 */
final class ApiKeys
{
    public const PREFIX = 'rdr_live_';
    private const ALPHABET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** @return array{token:string,prefix:string,hash:string} */
    public static function generate(): array
    {
        $body = '';
        for ($i = 0; $i < 40; $i++) {
            $body .= self::ALPHABET[random_int(0, 61)];
        }
        $token = self::PREFIX . $body;
        return ['token' => $token, 'prefix' => substr($token, 0, 13), 'hash' => self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function looksValid(string $token): bool
    {
        return preg_match('/^rdr_live_[0-9A-Za-z]{40}$/', $token) === 1;
    }
}
