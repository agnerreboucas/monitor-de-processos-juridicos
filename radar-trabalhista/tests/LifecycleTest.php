<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Monitoring\SourceUnavailable;
use Radar\Monitoring\Worker;
use Radar\Service\DailySummary;
use Radar\Service\LifecycleNotifier;
use Radar\Service\PlanService;
use Radar\Service\ProcessService;
use Radar\Service\SubscriptionService;
use Radar\Tests\Support\FakeAdapter;
use Radar\Tests\Support\FakeMailer;

final class LifecycleTest extends DbTestCase
{
    public function testMonthlyPlansHaveTheNewPricesAndFirstChargeIsFifteenDays(): void
    {
        $o = $this->office('Silva', 'a@silva.com', 'profissional');
        $p = (new PlanService($this->pdo, $this->clock))->forTenant($o['tenant_id']);
        $this->assertSame([59900, 100, 1440], [$p['price_cents'], $p['max_processes'], $p['min_interval_minutes']]);
        $this->assertTrue($p['first_cycle']);
        $this->assertSame([29950, 15], [$p['first_charge_cents'], $p['first_charge_days']]);
        $this->assertSame([1, 14], [$p['trial_day'], $p['days_left']]);
        $this->assertSame([29900, 149900], [(int) $this->scalar("SELECT price_cents FROM plans WHERE code='essencial'"), (int) $this->scalar("SELECT price_cents FROM plans WHERE code='equipe'")]);

        $this->clock->advance('P6D');
        $p = (new PlanService($this->pdo, $this->clock))->forTenant($o['tenant_id']);
        $this->assertSame([7, 8], [$p['trial_day'], $p['days_left']]);

        (new SubscriptionService($this->pdo, $this->clock))->renew($o['tenant_id'], 15);
        $p = (new PlanService($this->pdo, $this->clock))->forTenant($o['tenant_id']);
        $this->assertFalse($p['first_cycle']);                          // depois do primeiro pagamento, é a mensalidade cheia
    }

    public function testFailureReasonsAreExplainedInPlainLanguage(): void
    {
        $this->assertStringContainsString('fora do ar', DailySummary::reason('DJEN indisponível (HTTP 503).'));
        $this->assertStringContainsString('limitou', DailySummary::reason('DJEN limitou as consultas (HTTP 429).'));
        $this->assertStringContainsString('recusou', DailySummary::reason('Acesso recusado pelo DJEN (HTTP 403).'));
        $this->assertStringContainsString('sigilo', DailySummary::reason('Processo em sigilo'));
    }

    public function testSummaryCountsUpdatedAndGroupsFailuresByReason(): void
    {
        $o = $this->office('Silva', 'a@silva.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $svc = new ProcessService($this->pdo, $this->clock);
        $svc->create($ctx, self::CNJ_TRT8, 'A', $o['user_id']);
        $worker = new Worker($this->pdo, [(new FakeAdapter('djen'))->then([FakeAdapter::movement('1')])], $this->clock, 'w1');
        $worker->runBatch(10);
        $s = (new DailySummary($this->pdo, $this->clock))->forContext($ctx);
        $this->assertSame([1, 1, 0], [$s['monitored'], $s['updated_today'], $s['failed_total']]);

        $this->pdo->prepare("UPDATE processes SET last_error = 'DJEN indisponível (HTTP 503).', last_success_at = NULL WHERE tenant_id = ?")->execute([$o['tenant_id']]);
        $s = (new DailySummary($this->pdo, $this->clock))->forContext($ctx);
        $this->assertSame([0, 1], [$s['updated_today'], $s['failed_total']]);
        $this->assertStringContainsString('fora do ar', $s['failed'][0]['reason']);
        $this->assertStringContainsString('não quer dizer "sem novidade"', DailySummary::text($s));
    }

    public function testWelcomeAndTrialMilestonesAreSentOnceAndRespectThePreference(): void
    {
        $o = $this->office('Silva', 'carlos@silva.com');
        $this->pdo->prepare('UPDATE users SET name = ? WHERE id = ?')->execute(['Carlos Silva', $o['user_id']]);
        $mail = new FakeMailer();
        $n = new LifecycleNotifier($this->pdo, $mail, 'https://radar.exemplo.com.br', $this->clock);
        $this->assertSame(1, $n->run()['sent']);
        $this->assertStringContainsString('Bem-vindo, Carlos', $mail->sent[0]['body']);
        $this->assertStringContainsString('15º dia', $mail->sent[0]['body']);
        $this->assertSame(0, $n->run()['sent']);                       // não repete

        $this->clock->advance('P7D');                                  // dia 8 do teste
        $this->assertSame(1, $n->run()['sent']);
        $this->assertStringContainsString('Metade do teste', $mail->sent[1]['body']);

        $this->clock->advance('P5D');                                  // faltam 2 dias
        $n->run();
        $this->assertStringContainsString('termina em 2 dia', end($mail->sent)['subject']);

        $this->pdo->prepare('UPDATE users SET email_notifications = 0 WHERE id = ?')->execute([$o['user_id']]);
        $before = count($mail->sent);
        $this->clock->advance('P1D');
        $n->run();                                                     // quem desligou os e-mails não recebe
        $this->assertSame($before, count($mail->sent));
    }

    public function testDailySummaryGoesOutAfterTheChosenTimeAndFailedSendRetries(): void
    {
        $o = $this->office('Silva', 'a@silva.com');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        (new ProcessService($this->pdo, $this->clock))->create($ctx, self::CNJ_TRT8, 'A', $o['user_id']);
        $this->pdo->prepare("UPDATE tenants SET search_time = '05:30:00' WHERE id = ?")->execute([$o['tenant_id']]);
        (new Worker($this->pdo, [(new FakeAdapter('djen'))->then([FakeAdapter::movement('1')])], $this->clock, 'w1'))->runBatch(10);    // 09:00 em Brasília, já passou das 06:00
        $mail = new FakeMailer();
        $n = new LifecycleNotifier($this->pdo, $mail, '', $this->clock);
        $mail->failNext = 2;                                           // boas-vindas e resumo falham
        $r = $n->run();
        $this->assertSame(0, $r['sent']);
        $r = $n->run();                                                // tenta de novo
        $this->assertSame(2, $r['sent']);
        $subjects = array_column($mail->sent, 'subject');
        $this->assertNotEmpty(array_filter($subjects, static fn ($s) => str_starts_with($s, 'Resumo de hoje: 1 processo(s) atualizado(s)')));
        $this->assertSame(0, $n->run()['sent']);
    }

    public function testRegistrationAcceptsOnlyExistingPlansFromTheSalesPage(): void
    {
        // o cadastro por HTTP é testado em HttpApiTest; aqui, a regra de planos válidos na base
        $this->assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM plans WHERE code = 'profissional' AND active = 1"));
        $this->assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM plans WHERE code = 'inexistente'"));
    }
}
