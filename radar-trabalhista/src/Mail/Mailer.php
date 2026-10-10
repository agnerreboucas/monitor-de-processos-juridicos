<?php
declare(strict_types=1);

namespace Radar\Mail;

interface Mailer
{
    /** @throws MailerException quando o envio falhar (a mensagem pode ir para a tela; nunca leva senha) */
    public function send(string $toEmail, string $toName, string $subject, string $textBody): void;
}
