<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\AuthError;
use Radar\Exception\ValidationError;

final class AuthTest extends DbTestCase
{
    public function testRegistersOfficeAdminAndTrial(): void
    {
        $r = $this->office('Silva & Souza Advocacia', 'dra@silva.com');
        $this->assertSame('office_admin', $this->scalar('SELECT role FROM tenant_members WHERE user_id = ?', [$r['user_id']]));
        $this->assertSame('trialing', $this->scalar('SELECT status FROM subscriptions WHERE tenant_id = ?', [$r['tenant_id']]));
        $this->assertSame('silva-souza-advocacia', $this->scalar('SELECT slug FROM tenants WHERE id = ?', [$r['tenant_id']]));
    }

    public function testPasswordIsNeverStoredInPlainText(): void
    {
        $r = $this->office('A', 'a@a.com');
        $hash = (string) $this->scalar('SELECT password_hash FROM users WHERE id = ?', [$r['user_id']]);
        $this->assertStringNotContainsString('senha-segura-123', $hash);
        $this->assertTrue(password_verify('senha-segura-123', $hash));
    }

    public function testSlugsStayUnique(): void
    {
        $this->office('Mesmo Nome', 'a@a.com');
        $b = $this->office('Mesmo Nome', 'b@b.com');
        $this->assertSame('mesmo-nome-2', $this->scalar('SELECT slug FROM tenants WHERE id = ?', [$b['tenant_id']]));
    }

    public function testRegistrationValidations(): void
    {
        $this->office('A', 'a@a.com');
        foreach ([
            ['', 'Fulano', 'x@x.com', 'senha-segura-123', 'nome do escritório'],
            ['X', '', 'x@x.com', 'senha-segura-123', 'nome do administrador'],
            ['X', 'Fulano', 'nao-e-email', 'senha-segura-123', 'E-mail inválido'],
            ['X', 'Fulano', 'x@x.com', 'curta', 'ao menos'],
            ['X', 'Fulano', 'A@A.com', 'senha-segura-123', 'já está cadastrado'],     // maiúscula não escapa da checagem
        ] as [$office, $name, $email, $pass, $msg]) {
            try {
                $this->auth()->registerTenant($office, $name, $email, $pass);
                $this->fail("deveria recusar: $msg");
            } catch (ValidationError $e) {
                $this->assertStringContainsString($msg, $e->getMessage());
            }
        }
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM tenants'));      // nada ficou pela metade
    }

    public function testUnknownPlanLeavesNothingBehind(): void
    {
        try {
            $this->auth()->registerTenant('X', 'Y', 'x@x.com', 'senha-segura-123', 'inexistente');
            $this->fail();
        } catch (ValidationError) {
        }
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM tenants'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM users'));
    }

    public function testLoginReturnsTheOfficesOfTheUser(): void
    {
        $r = $this->office('Escritório A', 'a@a.com');
        $login = $this->auth()->login('  A@A.com ', 'senha-segura-123', '10.0.0.1');
        $this->assertSame($r['user_id'], $login['user_id']);
        $this->assertSame([['tenant_id' => $r['tenant_id'], 'name' => 'Escritório A', 'role' => 'office_admin']], $login['tenants']);
        $this->assertFalse($login['is_platform_admin']);
    }

    public function testWrongPasswordAndUnknownEmailLookTheSame(): void
    {
        $this->office('A', 'a@a.com');
        $msgs = [];
        foreach ([['a@a.com', 'errada-errada-1'], ['ninguem@a.com', 'senha-segura-123']] as [$email, $pass]) {
            try {
                $this->auth()->login($email, $pass);
                $this->fail();
            } catch (AuthError $e) {
                $msgs[] = $e->getMessage();
            }
        }
        $this->assertSame($msgs[0], $msgs[1]);        // não revela quais e-mails existem
    }

    public function testLocksAfterTooManyFailuresAndUnlocksLater(): void
    {
        $this->office('A', 'a@a.com');
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->auth()->login('a@a.com', 'errada-errada-1');
            } catch (AuthError) {
            }
        }
        // mesmo com a senha certa, está bloqueado
        try {
            $this->auth()->login('a@a.com', 'senha-segura-123');
            $this->fail('deveria estar bloqueado');
        } catch (AuthError $e) {
            $this->assertStringContainsString('Muitas tentativas', $e->getMessage());
        }
        $this->clock->advance('PT16M');
        $this->assertSame(1, count($this->auth()->login('a@a.com', 'senha-segura-123')['tenants']));
    }

    public function testSuccessfulLoginResetsTheFailureCounter(): void
    {
        $r = $this->office('A', 'a@a.com');
        for ($i = 0; $i < 3; $i++) {
            try {
                $this->auth()->login('a@a.com', 'errada-errada-1');
            } catch (AuthError) {
            }
        }
        $this->auth()->login('a@a.com', 'senha-segura-123');
        $this->assertSame(0, (int) $this->scalar('SELECT failed_logins FROM users WHERE id = ?', [$r['user_id']]));
    }

    public function testDisabledUserCannotLogIn(): void
    {
        $r = $this->office('A', 'a@a.com');
        $this->pdo->exec("UPDATE users SET status = 'disabled' WHERE id = " . $r['user_id']);
        $this->expectException(AuthError::class);
        $this->auth()->login('a@a.com', 'senha-segura-123');
    }

    public function testAuditNeverStoresPasswordsOrTokens(): void
    {
        $this->office('A', 'a@a.com');
        try {
            $this->auth()->login('a@a.com', 'errada-errada-1');
        } catch (AuthError) {
        }
        $token = $this->auth()->requestPasswordReset('a@a.com');
        $all = json_encode($this->pdo->query('SELECT * FROM audit_logs')->fetchAll());
        $this->assertStringNotContainsString('errada-errada-1', $all);
        $this->assertStringNotContainsString('senha-segura-123', $all);
        $this->assertStringNotContainsString((string) $token, $all);
        $this->assertStringContainsString('auth.login_failed', $all);
    }

    public function testPasswordResetFlow(): void
    {
        $this->office('A', 'a@a.com');
        $token = $this->auth()->requestPasswordReset('a@a.com');
        $this->assertNotNull($token);
        $this->assertSame(64, strlen($token));
        // só o hash do token fica no banco
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [$token]));
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM password_resets WHERE token_hash = ?', [hash('sha256', $token)]));

        $this->auth()->resetPassword($token, 'nova-senha-forte-9');
        $this->assertCount(1, $this->auth()->login('a@a.com', 'nova-senha-forte-9')['tenants']);
        try {
            $this->auth()->login('a@a.com', 'senha-segura-123');
            $this->fail('a senha antiga não pode mais valer');
        } catch (AuthError) {
        }
        // uso único
        $this->expectException(ValidationError::class);
        $this->auth()->resetPassword($token, 'outra-senha-forte-9');
    }

    public function testResetTokenExpires(): void
    {
        $this->office('A', 'a@a.com');
        $token = (string) $this->auth()->requestPasswordReset('a@a.com');
        $this->clock->advance('PT61M');
        $this->expectException(ValidationError::class);
        $this->auth()->resetPassword($token, 'nova-senha-forte-9');
    }

    public function testNewResetInvalidatesOpenLinksAfterUse(): void
    {
        $this->office('A', 'a@a.com');
        $first = (string) $this->auth()->requestPasswordReset('a@a.com');
        $second = (string) $this->auth()->requestPasswordReset('a@a.com');
        $this->auth()->resetPassword($second, 'nova-senha-forte-9');
        $this->expectException(ValidationError::class);
        $this->auth()->resetPassword($first, 'outra-senha-forte-9');      // o outro link aberto morreu junto
    }

    public function testResetForUnknownEmailRevealsNothing(): void
    {
        $this->assertNull($this->auth()->requestPasswordReset('ninguem@a.com'));
        $this->assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM password_resets'));
    }

    public function testResetWithWeakPasswordKeepsTheToken(): void
    {
        $this->office('A', 'a@a.com');
        $token = (string) $this->auth()->requestPasswordReset('a@a.com');
        try {
            $this->auth()->resetPassword($token, 'curta');
            $this->fail();
        } catch (ValidationError) {
        }
        $this->auth()->resetPassword($token, 'nova-senha-forte-9');        // ainda vale: a senha fraca não gastou o link
        $this->addToAssertionCount(1);
    }
}
