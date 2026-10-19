<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Mail\LogMailer;
use Radar\Monitoring\Worker;
use Radar\Service\NotificationDispatcher;
use Radar\Service\ProcessService;
use Radar\Tests\Support\FakeAdapter;
use Radar\Tests\Support\FakeMailer;

final class NotificationDispatcherTest extends DbTestCase
{
    private array $office;
    private FakeMailer $mailer;
    private NotificationDispatcher $dispatcher;
    private Worker $worker;
    private FakeAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->office = $this->office('A', 'a@a.com');
        $this->mailer = new FakeMailer();
        $this->dispatcher = new NotificationDispatcher($this->pdo, $this->mailer, 'https://radar.exemplo.com.br/', $this->clock);
        $this->adapter = new FakeAdapter();
        $this->worker = new Worker($this->pdo, [$this->adapter], $this->clock);
    }

    /** Cria o processo, importa o histórico e gera movimentações novas na rodada seguinte. */
    private function withNewMovements(string $cnj, array $new): int
    {
        $svc = new ProcessService($this->pdo, $this->clock);
        $id = (int) $svc->create($this->ctx($this->office['user_id'], $this->office['tenant_id']), $cnj, null, $this->office['user_id'])['id'];
        $this->adapter->thenFor($cnj, [FakeAdapter::movement('base')]);
        $this->worker->runBatch(10);
        $this->clock->advance('PT25H');
        $this->worker->enqueueDue();
        $this->adapter->thenFor($cnj, [FakeAdapter::movement('base'), ...$new]);
        $this->worker->runBatch(10);
        return $id;
    }

    public function testSendsOneDigestPerPersonAndMarksAsSent(): void
    {
        $this->withNewMovements(self::CNJ_TRT8, [FakeAdapter::movement('n1', 'Audiência designada'), FakeAdapter::movement('n2', 'Juntada de petição')]);
        $r = $this->dispatcher->sendPending();

        $this->assertSame(['emails' => 1, 'notifications' => 2, 'failed' => 0], $r);
        $this->assertCount(1, $this->mailer->sent);
        $mail = $this->mailer->sent[0];
        $this->assertSame('a@a.com', $mail['to']);
        $this->assertStringContainsString('2 novas movimentações', $mail['subject']);
        $this->assertStringContainsString(self::CNJ_TRT8, $mail['body']);
        $this->assertStringContainsString('Audiência designada', $mail['body']);
        $this->assertStringContainsString('Data da movimentação: 09/10/2026', $mail['body']);
        $this->assertStringContainsString('Data da captura pelo sistema: 11/10/2026', $mail['body']);
        $this->assertStringContainsString('https://radar.exemplo.com.br/entrar#/processos/', $mail['body']);
        $this->assertStringContainsString('não significa que o escritório foi intimado', $mail['body']);
        $this->assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM notifications WHERE channel = 'email' AND status = 'sent' AND sent_at IS NOT NULL"));
    }

    public function testNeverSendsTheSameNotificationTwice(): void
    {
        $this->withNewMovements(self::CNJ_TRT8, [FakeAdapter::movement('n1')]);
        $this->dispatcher->sendPending();
        $this->assertSame(['emails' => 0, 'notifications' => 0, 'failed' => 0], $this->dispatcher->sendPending());
        $this->assertCount(1, $this->mailer->sent);
    }

    public function testPanelNotificationsAreNotEmailed(): void
    {
        $this->withNewMovements(self::CNJ_TRT8, [FakeAdapter::movement('n1')]);
        $this->dispatcher->sendPending();
        $this->assertSame('pending', $this->scalar("SELECT status FROM notifications WHERE channel = 'panel'"));
    }

    public function testNoAlertForBaselineHistory(): void
    {
        $svc = new ProcessService($this->pdo, $this->clock);
        $svc->create($this->ctx($this->office['user_id'], $this->office['tenant_id']), self::CNJ_TRT8);
        $this->adapter->thenFor(self::CNJ_TRT8, [FakeAdapter::movement('1'), FakeAdapter::movement('2')]);
        $this->worker->runBatch(10);
        $this->assertSame([], $this->mailer->sent);
        $this->assertSame(['emails' => 0, 'notifications' => 0, 'failed' => 0], $this->dispatcher->sendPending());
    }

    public function testFailureIsRecordedAndRetriedWithGrowingWait(): void
    {
        $this->withNewMovements(self::CNJ_TRT8, [FakeAdapter::movement('n1')]);
        $this->mailer->failNext = 99;
        $waits = [];
        for ($i = 1; $i <= 4; $i++) {
            $r = $this->dispatcher->sendPending();
            $this->assertSame(1, $r['failed']);
            $row = $this->pdo->query("SELECT status, attempts, last_error, next_attempt_at FROM notifications WHERE channel = 'email'")->fetch();
            $this->assertSame('failed', $row['status']);
            $this->assertSame($i, (int) $row['attempts']);
            $this->assertStringContainsString('TransportException', (string) $row['last_error']);
            $wait = (strtotime($row['next_attempt_at'] . ' UTC') - $this->clock->now()->getTimestamp()) / 60;
            $waits[] = (int) $wait;
            $this->assertSame(0, $this->dispatcher->sendPending()['failed']);        // ainda não é a hora
            $this->clock->advance('PT' . (int) $wait . 'M');
        }
        $this->assertSame([5, 10, 20, 40], $waits);
    }

    public function testGivesUpAfterTheMaximumAttempts(): void
    {
        $this->withNewMovements(self::CNJ_TRT8, [FakeAdapter::movement('n1')]);
        $this->mailer->failNext = 99;
        for ($i = 0; $i < NotificationDispatcher::MAX_ATTEMPTS; $i++) {
            $this->dispatcher->sendPending();
            $this->clock->advance('PT2H');
        }
        $row = $this->pdo->query("SELECT status, attempts, next_attempt_at FROM notifications WHERE channel = 'email'")->fetch();
        $this->assertSame('failed', $row['status']);
        $this->assertSame(NotificationDispatcher::MAX_ATTEMPTS, (int) $row['attempts']);
        $this->assertNull($row['next_attempt_at']);
        $this->assertSame(0, $this->dispatcher->sendPending()['failed']);               // não insiste mais
    }

    public function testRecoversWhenTheMailServerComesBack(): void
    {
        $this->withNewMovements(self::CNJ_TRT8, [FakeAdapter::movement('n1')]);
        $this->mailer->failNext = 1;
        $this->dispatcher->sendPending();
        $this->clock->advance('PT6M');
        $this->assertSame(1, $this->dispatcher->sendPending()['emails']);
        $this->assertSame('sent', $this->scalar("SELECT status FROM notifications WHERE channel = 'email'"));
        $this->assertSame(2, (int) $this->scalar("SELECT attempts FROM notifications WHERE channel = 'email'"));
    }

    public function testDoesNotEmailSomeoneWhoLeftTheOffice(): void
    {
        $this->withNewMovements(self::CNJ_TRT8, [FakeAdapter::movement('n1')]);
        $this->pdo->exec("UPDATE tenant_members SET status = 'disabled'");
        $this->assertSame(0, $this->dispatcher->sendPending()['emails']);
        $this->assertSame([], $this->mailer->sent);
    }

    public function testLongDescriptionIsTrimmedAndMessagesAreChunked(): void
    {
        $many = [];
        for ($i = 1; $i <= 25; $i++) {
            $many[] = FakeAdapter::movement("n$i", $i === 1 ? str_repeat('palavra ', 100) : "Movimento $i");
        }
        $this->withNewMovements(self::CNJ_TRT8, $many);
        $r = $this->dispatcher->sendPending();
        $this->assertSame(['emails' => 2, 'notifications' => 25, 'failed' => 0], $r);     // 20 + 5
        $this->assertStringContainsString('…', $this->mailer->sent[0]['body']);
        $this->assertLessThan(6500, strlen($this->mailer->sent[0]['body']));
    }

    public function testLogMailerWritesToAFile(): void
    {
        $file = sys_get_temp_dir() . '/radar-mail-' . bin2hex(random_bytes(4)) . '.log';
        (new LogMailer($file))->send('x@y.com', 'X', 'Assunto', 'Corpo');
        $this->assertStringContainsString('Para: X <x@y.com>', (string) file_get_contents($file));
        unlink($file);
    }
}
