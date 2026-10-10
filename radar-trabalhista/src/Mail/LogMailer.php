<?php
declare(strict_types=1);

namespace Radar\Mail;

/** Para desenvolvimento: em vez de enviar, grava a mensagem em um arquivo. Nunca use em produção. */
final class LogMailer implements Mailer
{
    public function __construct(private string $file)
    {
    }

    public function send(string $toEmail, string $toName, string $subject, string $textBody): void
    {
        $entry = sprintf("=== %s ===\nPara: %s <%s>\nAssunto: %s\n\n%s\n\n", date('c'), $toName, $toEmail, $subject, $textBody);
        if (file_put_contents($this->file, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new MailerException('Não foi possível gravar o e-mail de desenvolvimento.');
        }
    }
}
