<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\Forbidden;
use Radar\Exception\LimitExceeded;
use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Service\MemberService;

final class MemberServiceTest extends DbTestCase
{
    private MemberService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new MemberService($this->pdo, $this->clock);
    }

    public function testInvitingANewPersonGivesALinkToSetThePassword(): void
    {
        $o = $this->office('A', 'a@a.com');
        $r = $this->svc->invite($this->ctx($o['user_id'], $o['tenant_id']), 'Nova Pessoa', 'Nova@A.com', 'lawyer');
        $this->assertNotNull($r['invite_token']);
        $this->assertFalse($r['pending_acceptance']);
        // o convidado define a senha pelo link e entra
        $this->auth()->resetPassword($r['invite_token'], 'senha-da-nova-pessoa');
        $this->assertSame('lawyer', $this->auth()->login('nova@a.com', 'senha-da-nova-pessoa')['tenants'][0]['role']);
    }

    public function testInviteTokenIsNotStoredInPlainText(): void
    {
        $o = $this->office('A', 'a@a.com');
        $r = $this->svc->invite($this->ctx($o['user_id'], $o['tenant_id']), 'X', 'x@a.com', 'assistant');
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [$r['invite_token']]));
    }

    public function testExistingAccountOfAnotherOfficeMustAccept(): void
    {
        $a = $this->office('A', 'a@a.com');
        $b = $this->office('B', 'b@b.com');
        $r = $this->svc->invite($this->ctx($a['user_id'], $a['tenant_id']), 'Admin B', 'b@b.com', 'lawyer');
        $this->assertTrue($r['pending_acceptance']);
        $this->assertNull($r['invite_token']);
        // enquanto não aceita, não tem acesso ao escritório A
        $this->expectException(Forbidden::class);
        try {
            $this->ctx($b['user_id'], $a['tenant_id']);
        } finally {
            $this->svc->acceptInvitation($b['user_id'], $a['tenant_id']);
            $this->assertSame('lawyer', $this->ctx($b['user_id'], $a['tenant_id'])->role->value);
        }
    }

    public function testCannotAcceptAnInviteThatDoesNotExist(): void
    {
        $a = $this->office('A', 'a@a.com');
        $b = $this->office('B', 'b@b.com');
        $this->expectException(NotFound::class);
        $this->svc->acceptInvitation($b['user_id'], $a['tenant_id']);
    }

    public function testUserLimitOfThePlan(): void
    {
        $this->pdo->exec("INSERT INTO plans (code, name, price_cents, max_processes, max_users, min_interval_minutes) VALUES ('duo', 'Duo', 0, 10, 2, 60)");
        $o = $this->office('A', 'a@a.com', 'duo');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $this->svc->invite($ctx, 'Um', 'um@a.com', 'lawyer');
        $this->expectException(LimitExceeded::class);
        $this->svc->invite($ctx, 'Dois', 'dois@a.com', 'lawyer');
    }

    public function testCannotInviteSomeoneTwiceOrWithABadRole(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $this->svc->invite($ctx, 'Um', 'um@a.com', 'lawyer');
        foreach ([['um@a.com', 'lawyer', 'já faz parte'], ['x@a.com', 'superuser', 'Papel inválido'], ['ruim', 'lawyer', 'E-mail inválido']] as [$email, $role, $msg]) {
            try {
                $this->svc->invite($ctx, 'X', $email, $role);
                $this->fail($msg);
            } catch (ValidationError $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
    }

    public function testOnlyOfficeAdminManagesTheTeam(): void
    {
        $o = $this->office('A', 'a@a.com');
        $lawyer = $this->member($o['tenant_id'], 'adv@a.com', 'lawyer');
        $assistant = $this->member($o['tenant_id'], 'ass@a.com', 'assistant');
        foreach ([$lawyer, $assistant] as $user) {
            try {
                $this->svc->invite($this->ctx($user, $o['tenant_id']), 'X', 'x@a.com', 'assistant');
                $this->fail('só o administrador convida');
            } catch (Forbidden) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOfficeNeverLosesItsLastAdmin(): void
    {
        $o = $this->office('A', 'a@a.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        foreach ([fn () => $this->svc->disable($ctx, $o['user_id']), fn () => $this->svc->changeRole($ctx, $o['user_id'], 'lawyer')] as $act) {
            try {
                $act();
                $this->fail();
            } catch (ValidationError $e) {
                $this->assertStringContainsString('ao menos um administrador', $e->getMessage());
            }
        }
    }

    public function testDisabledMemberLosesAccessImmediately(): void
    {
        $o = $this->office('A', 'a@a.com');
        $lawyer = $this->member($o['tenant_id'], 'adv@a.com', 'lawyer');
        $this->ctx($lawyer, $o['tenant_id']);                                   // tem acesso
        $this->svc->disable($this->ctx($o['user_id'], $o['tenant_id']), $lawyer);
        $this->expectException(Forbidden::class);
        $this->ctx($lawyer, $o['tenant_id']);
    }

    public function testCannotDisableSomeoneFromAnotherOffice(): void
    {
        $a = $this->office('A', 'a@a.com');
        $b = $this->office('B', 'b@b.com');
        $bLawyer = $this->member($b['tenant_id'], 'adv@b.com', 'lawyer');
        try {
            $this->svc->disable($this->ctx($a['user_id'], $a['tenant_id']), $bLawyer);
            $this->fail();
        } catch (NotFound) {
        }
        $this->assertSame('active', $this->scalar('SELECT status FROM tenant_members WHERE user_id = ?', [$bLawyer]));
    }
}
