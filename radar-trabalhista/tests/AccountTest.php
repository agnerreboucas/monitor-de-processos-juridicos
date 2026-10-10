<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\LimitExceeded;
use Radar\Exception\ValidationError;
use Radar\Monitoring\Worker;
use Radar\Service\AccountService;
use Radar\Service\ProcessService;
use Radar\Tests\Support\FakeAdapter;

final class AccountTest extends DbTestCase
{
    private AccountService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AccountService($this->pdo, $this->clock);
    }

    public function testChangePasswordNeedsTheCurrentOneAndTheNewOneWorks(): void
    {
        $o = $this->office('A', 'a@a.com');
        try {
            $this->svc->changePassword($o['user_id'], 'errada-errada', 'nova-senha-123');
            $this->fail('trocou sem a senha atual');
        } catch (ValidationError) {
            $this->addToAssertionCount(1);
        }
        $this->svc->changePassword($o['user_id'], 'senha-segura-123', 'nova-senha-456');
        $this->assertSame($o['user_id'], $this->auth()->login('a@a.com', 'nova-senha-456')['user_id']);
        $this->expectException(\Radar\Exception\AuthError::class);
        $this->auth()->login('a@a.com', 'senha-segura-123');
    }

    public function testNewPasswordRulesAndOpenResetLinksAreInvalidated(): void
    {
        $o = $this->office('A', 'a@a.com');
        $token = $this->auth()->requestPasswordReset('a@a.com');
        foreach (['curta', 'senha-segura-123'] as $bad) {
            try {
                $this->svc->changePassword($o['user_id'], 'senha-segura-123', $bad);
                $this->fail("aceitou $bad");
            } catch (ValidationError) {
                $this->addToAssertionCount(1);
            }
        }
        $this->svc->changePassword($o['user_id'], 'senha-segura-123', 'nova-senha-456');
        $this->expectException(ValidationError::class);                      // o link antigo de recuperação não vale mais
        $this->auth()->resetPassword((string) $token, 'outra-senha-789');
    }

    public function testGuessingTheCurrentPasswordIsRateLimited(): void
    {
        $o = $this->office('A', 'a@a.com');
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->svc->changePassword($o['user_id'], "chute-$i-xxxxxxx", 'nova-senha-456');
            } catch (ValidationError) {
            }
        }
        $this->expectException(LimitExceeded::class);
        $this->svc->changePassword($o['user_id'], 'senha-segura-123', 'nova-senha-456');   // nem a certa passa durante o bloqueio
    }

    public function testPasswordNeverReachesTheAuditLog(): void
    {
        $o = $this->office('A', 'a@a.com');
        $this->svc->changePassword($o['user_id'], 'senha-segura-123', 'nova-senha-456');
        $all = json_encode($this->pdo->query('SELECT * FROM audit_logs')->fetchAll());
        $this->assertStringNotContainsString('nova-senha-456', $all);
        $this->assertStringNotContainsString('senha-segura-123', $all);
    }

    public function testProfileUpdateIsOnlyForTheOwnAccount(): void
    {
        $a = $this->office('A', 'a@a.com');
        $b = $this->office('B', 'b@b.com');
        $this->svc->update($a['user_id'], '  Dra. Nova  ', false);
        $this->assertSame('Dra. Nova', $this->svc->get($a['user_id'])['name']);
        $this->assertFalse($this->svc->get($a['user_id'])['email_notifications']);
        $this->assertSame('Admin B', $this->svc->get($b['user_id'])['name']);
        $this->assertTrue($this->svc->get($b['user_id'])['email_notifications']);
        $this->expectException(ValidationError::class);
        $this->svc->update($a['user_id'], '   ', null);
    }

    public function testOptingOutOfEmailKeepsThePanelAlert(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        (new ProcessService($this->pdo, $this->clock))->create($ctx, self::CNJ_TRT8, 'X', $o['user_id']);
        $adapter = new FakeAdapter();
        $worker = new Worker($this->pdo, [$adapter], $this->clock, 'w');
        $adapter->then([FakeAdapter::movement('1')]);
        $worker->runBatch(10);
        $this->svc->update($o['user_id'], null, false);
        $this->clock->advance('PT7H');
        $worker->enqueueDue();
        $adapter->then([FakeAdapter::movement('1'), FakeAdapter::movement('2', 'Sentença')]);
        $worker->runBatch(10);
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM notifications WHERE channel = 'panel'"));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM notifications WHERE channel = 'email'"));
    }
}
