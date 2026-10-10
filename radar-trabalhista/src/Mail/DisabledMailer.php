<?php
declare(strict_types=1);

namespace Radar\Mail;

/** Produção sem SMTP configurado: recusa em vez de gravar em arquivo (links de senha não ficam em log). */
final class DisabledMailer implements Mailer
{
    public function send(string $toEmail, string $toName, string $subject, string $textBody): void
    {
        throw new MailerException('O envio de e-mail não está configurado (SMTP_* no .env).');
    }
}
