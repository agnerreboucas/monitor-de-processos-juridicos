<?php
declare(strict_types=1);

namespace Radar\Mail;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/** Envio de verdade por SMTP (DSN do Symfony Mailer, ex.: smtp://usuario:senha@host:587). */
final class SmtpMailer implements Mailer
{
    private SymfonyMailer $mailer;

    public function __construct(string $dsn, private string $from, private string $fromName = 'Radar Trabalhista')
    {
        $this->mailer = new SymfonyMailer(Transport::fromDsn($dsn));
    }

    public function send(string $toEmail, string $toName, string $subject, string $textBody): void
    {
        $message = (new Email())
            ->from(new Address($this->from, $this->fromName))
            ->to(new Address($toEmail, $toName))
            ->subject($subject)
            ->text($textBody);
        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            // a mensagem original pode citar o servidor e o usuário; só o tipo do problema sai daqui
            throw new MailerException('Falha ao enviar o e-mail (' . (new \ReflectionClass($e))->getShortName() . ').', 0, $e);
        }
    }
}
