<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\Forbidden;
use Radar\Exception\LimitExceeded;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Service\ProcessService;

final class ProcessServiceTest extends DbTestCase
{
    private ProcessService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ProcessService($this->pdo, $this->clock);
    }

    public function testCreatesAProcessAndQueuesTheFirstCheck(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $p = $this->svc->create($ctx, '00014578520265080106', 'Maria da Silva', null, 'Reclamante');

        $this->assertSame(self::CNJ_TRT8, $p['cnj']);
        $this->assertSame('TRT8', $p['tribunal']);
        $this->assertSame('active', $p['status']);
        $this->assertNull($p['last_success_at']);                 // cadastrado NÃO é "monitorado com sucesso"
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM monitoring_jobs WHERE process_id = ? AND status = 'queued'", [$p['id']]));
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM audit_logs WHERE action = 'process.created'"));
    }

    public function testRejectsInvalidNumber(): void
    {
        $o = $this->office('A', 'a@a.com');
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('inválido');
        $this->svc->create($this->ctx($o['user_id'], $o['tenant_id']), '0022929-17.2016.8.13.0297');
    }

    public function testRejectsDuplicateInTheSameOfficeEvenWithDifferentFormatting(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $this->svc->create($ctx, self::CNJ_TJMG);
        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('já está cadastrado');
        $this->svc->create($ctx, '00229291620168130297');
    }

    public function testPlanLimitBlocksNewProcessesAndArchivingFreesASlot(): void
    {
        $this->pdo->exec("INSERT INTO plans (code, name, price_cents, max_processes, max_users, min_interval_minutes) VALUES ('mini', 'Mini', 0, 2, 2, 60)");
        $o = $this->office('A', 'a@a.com', 'mini');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $first = $this->svc->create($ctx, self::CNJ_TJMG);
        $this->svc->create($ctx, self::CNJ_TJRO);
        try {
            $this->svc->create($ctx, self::CNJ_TRT8);
            $this->fail('deveria bloquear no limite');
        } catch (LimitExceeded $e) {
            $this->assertStringContainsString('Limite de 2 processos', $e->getMessage());
        }
        $this->svc->archive($ctx, (int) $first['id']);
        $this->assertSame(self::CNJ_TRT8, $this->svc->create($ctx, self::CNJ_TRT8)['cnj']);
    }

    public function testInactiveSubscriptionBlocksNewProcesses(): void
    {
        foreach (['suspended', 'cancelled'] as $status) {
            $this->setUp();
            $o = $this->office('A', 'a@a.com');
            $this->pdo->exec("UPDATE subscriptions SET status = '$status'");
            try {
                $this->svc->create($this->ctx($o['user_id'], $o['tenant_id']), self::CNJ_TJMG);
                $this->fail($status);
            } catch (LimitExceeded $e) {
                $this->assertStringContainsString('assinatura', $e->getMessage());
            }
        }
    }

    public function testPauseCancelsQueuedWorkAndResumeChecksAgain(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $p = $this->svc->create($ctx, self::CNJ_TJMG);
        $this->svc->pause($ctx, (int) $p['id']);
        $this->assertSame('paused', $this->scalar('SELECT status FROM processes WHERE id = ?', [$p['id']]));
        $this->assertSame('cancelled', $this->scalar('SELECT status FROM monitoring_jobs WHERE process_id = ?', [$p['id']]));
        $this->svc->resume($ctx, (int) $p['id']);
        $this->assertSame('active', $this->scalar('SELECT status FROM processes WHERE id = ?', [$p['id']]));
        $this->assertNotNull($this->scalar('SELECT next_check_at FROM processes WHERE id = ?', [$p['id']]));
    }

    public function testRequestCheckDoesNotPileUpJobs(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $p = $this->svc->create($ctx, self::CNJ_TJMG);                 // já há um trabalho na fila
        $this->assertFalse($this->svc->requestCheck($ctx, (int) $p['id']));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM monitoring_jobs'));
    }

    public function testPausedProcessCannotBeChecked(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $p = $this->svc->create($ctx, self::CNJ_TJMG);
        $this->svc->pause($ctx, (int) $p['id']);
        $this->expectException(ValidationError::class);
        $this->svc->requestCheck($ctx, (int) $p['id']);
    }

    public function testResponsibleMustBeAnActiveMemberOfTheSameOffice(): void
    {
        $a = $this->office('A', 'a@a.com');
        $b = $this->office('B', 'b@b.com');
        $ctx = $this->ctx($a['user_id'], $a['tenant_id']);
        $this->expectException(ValidationError::class);
        $this->svc->create($ctx, self::CNJ_TJMG, null, $b['user_id']);          // usuário de outro escritório
    }

    public function testUpdateChangesOnlyWhatWasSent(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $p = $this->svc->create($ctx, self::CNJ_TJMG, 'Cliente', null, 'nota');
        $this->svc->update($ctx, (int) $p['id'], ['client_label' => 'Novo nome', 'responsible_user_id' => $o['user_id']]);
        $row = $this->svc->get($ctx, (int) $p['id'])['process'];
        $this->assertSame('Novo nome', $row['client_label']);
        $this->assertSame('nota', $row['notes']);
        $this->assertSame($o['user_id'], (int) $row['responsible_user_id']);
    }

    public function testTextLimitsAndBlankBecomeNull(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $this->assertNull($this->svc->create($ctx, self::CNJ_TJMG, '   ')['client_label']);
        $this->expectException(ValidationError::class);
        $this->svc->create($ctx, self::CNJ_TJRO, str_repeat('x', 161));
    }

    public function testAssistantCanRegisterButNotArchive(): void
    {
        $o = $this->office('A', 'a@a.com');
        $assistant = $this->member($o['tenant_id'], 'assist@a.com', 'assistant');
        $ctx = $this->ctx($assistant, $o['tenant_id']);
        $p = $this->svc->create($ctx, self::CNJ_TJMG);
        $this->expectException(Forbidden::class);
        $this->svc->archive($ctx, (int) $p['id']);
    }

    public function testGetUnknownProcessIsNotFound(): void
    {
        $o = $this->office('A', 'a@a.com');
        $this->expectException(NotFound::class);
        $this->svc->get($this->ctx($o['user_id'], $o['tenant_id']), 999999);
    }
}
