<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Envia os e-mails do Radar pelo wp_mail() do WordPress (o que já estiver configurado no site: SMTP, WP Mail SMTP etc.). */
final class Radar_WP_Mailer implements \Radar\Mail\Mailer
{
    public function send(string $toEmail, string $toName, string $subject, string $textBody): void
    {
        $to = $toName !== '' ? sprintf('%s <%s>', str_replace(['"', "\r", "\n", '<', '>'], '', $toName), $toEmail) : $toEmail;
        $sent = wp_mail($to, $subject, $textBody, ['Content-Type: text/plain; charset=UTF-8']);
        if (!$sent) {
            throw new \Radar\Mail\MailerException('Falha ao enviar o e-mail (wp_mail). Confira o envio de e-mail do WordPress.');
        }
    }
}
