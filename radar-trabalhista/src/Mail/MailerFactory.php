<?php
declare(strict_types=1);

namespace Radar\Mail;

use Radar\Config;

final class MailerFactory
{
    public static function fromConfig(Config $config, string $storageDir): Mailer
    {
        $host = $config->get('SMTP_HOST');
        if ($host !== '') {
            $scheme = $config->get('SMTP_SECURE', 'tls') === 'ssl' ? 'smtps' : 'smtp';
            $dsn = sprintf('%s://%s:%s@%s:%d', $scheme, rawurlencode($config->get('SMTP_USER')), rawurlencode($config->get('SMTP_PASS')),
                $host, $config->int('SMTP_PORT', $scheme === 'smtps' ? 465 : 587));
            return new SmtpMailer($dsn, $config->get('SMTP_FROM', $config->get('SMTP_USER')));
        }
        return $config->isProduction() ? new DisabledMailer() : new LogMailer(rtrim($storageDir, '/') . '/mail.log');
    }
}
