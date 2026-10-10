<?php
declare(strict_types=1);

namespace Radar\Tests\Support;

use Radar\Mail\Mailer;
use Radar\Mail\MailerException;

final class FakeMailer implements Mailer
{
    /** @var list<array{to:string,name:string,subject:string,body:string}> */
    public array $sent = [];
    public int $failNext = 0;

    public function send(string $toEmail, string $toName, string $subject, string $textBody): void
    {
        if ($this->failNext > 0) {
            $this->failNext--;
            throw new MailerException('Falha ao enviar o e-mail (TransportException).');
        }
        $this->sent[] = ['to' => $toEmail, 'name' => $toName, 'subject' => $subject, 'body' => $textBody];
    }
}
