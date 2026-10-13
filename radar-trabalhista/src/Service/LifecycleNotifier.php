<?php
declare(strict_types=1);

namespace Radar\Service;

use DateInterval;
use DateTimeZone;
use PDO;
use Radar\Mail\Mailer;
use Radar\Mail\MailerException;
use Radar\Support\Clock;
use Radar\Support\Schedule;

/**
 * Avisos automáticos aos responsáveis pelo escritório: boas-vindas, marcos do teste grátis e o resumo do dia
 * (depois da busca no horário escolhido). Cada aviso sai uma vez só (tabela tenant_notices); se o envio falha,
 * a marca é desfeita e a próxima rodada tenta de novo. Respeita a preferência "receber e-mails" de cada pessoa.
 */
final class LifecycleNotifier
{
    public function __construct(private PDO $pdo, private Mailer $mailer, private string $appUrl = '', private Clock $clock = new Clock())
    {
    }

    /** @return array{sent:int,failed:int} */
    public function run(): array
    {
        $result = ['sent' => 0, 'failed' => 0];
        $tenants = $this->pdo->query("SELECT t.id, t.name, t.search_time, s.status FROM tenants t JOIN subscriptions s ON s.tenant_id = t.id
                                       WHERE t.status = 'active' AND s.status IN ('trialing','active','past_due')")->fetchAll();
        $summary = new DailySummary($this->pdo, $this->clock);
        $plans = new PlanService($this->pdo, $this->clock);
        foreach ($tenants as $t) {
            $tid = (int) $t['id'];
            $plan = $plans->forTenant($tid);
            $data = $summary->forTenant($tid);
            foreach ($this->due($t, $plan, $data) as $kind) {
                $this->send($tid, $kind, $t, $plan, $data, $result);
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $t @param array<string,mixed> $plan @param array<string,mixed> $data @return list<string> */
    private function due(array $t, array $plan, array $data): array
    {
        $kinds = ['welcome'];
        if ($plan['status'] === 'trialing') {
            $day = (int) $plan['trial_day'];
            $left = (int) $plan['days_left'];
            if ($day >= 7) {
                $kinds[] = 'trial_d7';
            }
            if ($left <= 3) {
                $kinds[] = 'trial_d12';
            }
            if ($left <= 1) {
                $kinds[] = 'trial_last';
            }
        }
        if ($data['monitored'] > 0 && ($data['updated_today'] > 0 || $data['failed_total'] > 0)) {
            $local = $this->clock->now()->setTimezone(new DateTimeZone('America/Sao_Paulo'));
            $at = $t['search_time'] !== null ? substr((string) $t['search_time'], 0, 5) : '06:30';
            [$h, $m] = array_map('intval', explode(':', $at));
            $ready = $local->setTime($h, $m)->add(new DateInterval('PT30M'));
            if ($local >= $ready) {
                $kinds[] = 'daily_' . Schedule::localDay($this->clock->now());
            }
        }
        return $kinds;
    }

    /** @param array<string,mixed> $t @param array<string,mixed> $plan @param array<string,mixed> $data @param array{sent:int,failed:int} $result */
    private function send(int $tid, string $kind, array $t, array $plan, array $data, array &$result): void
    {
        $mark = $this->pdo->prepare('INSERT IGNORE INTO tenant_notices (tenant_id, kind, sent_at) VALUES (?, ?, ?)');
        $mark->execute([$tid, $kind, Clock::sql($this->clock->now())]);
        if ($mark->rowCount() === 0) {
            return;                                                       // já enviado
        }
        $people = $this->pdo->prepare("SELECT u.email, u.name FROM tenant_members m JOIN users u ON u.id = m.user_id
                                        WHERE m.tenant_id = ? AND m.role = 'office_admin' AND m.status = 'active' AND u.status = 'active' AND u.email_notifications = 1");
        $people->execute([$tid]);
        $rows = $people->fetchAll();
        $subject = $this->subject($kind, $t, $plan, $data);
        $ok = true;
        foreach ($rows as $p) {
            try {
                $this->mailer->send((string) $p['email'], (string) $p['name'], $subject, $this->body($kind, $this->first((string) $p['name']), $t, $plan, $data));
                $result['sent']++;
            } catch (MailerException) {
                $ok = false;
                $result['failed']++;
            }
        }
        if (!$ok) {
            $this->pdo->prepare('DELETE FROM tenant_notices WHERE tenant_id = ? AND kind = ?')->execute([$tid, $kind]);      // tenta de novo na próxima rodada
        }
    }

    private function first(string $name): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: [], static fn ($x) => preg_match('/^(dr|dra|sr|sra|prof)\.?$/i', $x) !== 1));
        return $parts[0] ?? '';
    }

    private static function money(int $cents): string
    {
        return 'R$ ' . number_format($cents / 100, 2, ',', '.');
    }

    /** @param array<string,mixed> $t @param array<string,mixed> $plan @param array<string,mixed> $data */
    private function subject(string $kind, array $t, array $plan, array $data): string
    {
        return match (true) {
            $kind === 'welcome' => 'Bem-vindo ao Radar Processual',
            $kind === 'trial_d7' => 'Seu teste grátis: veja o que o Radar já fez por você',
            $kind === 'trial_d12' => 'Seu teste grátis termina em ' . $plan['days_left'] . ' dia(s)',
            $kind === 'trial_last' => 'Último dia do teste grátis',
            default => 'Resumo de hoje: ' . $data['updated_today'] . ' processo(s) atualizado(s)' . ($data['failed_total'] > 0 ? ', ' . $data['failed_total'] . ' sem acesso' : ''),
        };
    }

    /** @param array<string,mixed> $t @param array<string,mixed> $plan @param array<string,mixed> $data */
    private function pricing(array $plan): string
    {
        if ($plan['billing_model'] === 'per_search') {
            return 'No plano "' . $plan['plan_name'] . '" você paga ' . self::money((int) $plan['price_per_search_cents']) . ' por busca realizada; busca que falha não é cobrada.';
        }
        $line = 'Plano ' . $plan['plan_name'] . ': ' . self::money((int) $plan['price_cents']) . ' por mês, até ' . $plan['max_processes'] . ' processos.';
        if ($plan['first_cycle']) {
            $line .= "\nNo primeiro mês você paga só os " . $plan['first_charge_days'] . ' dias de uso depois do teste: ' . self::money((int) $plan['first_charge_cents']) . '.';
        }
        return $line;
    }

    /** @param array<string,mixed> $t @param array<string,mixed> $plan @param array<string,mixed> $data */
    private function body(string $kind, string $name, array $t, array $plan, array $data): string
    {
        $hi = $name !== '' ? "Olá, $name!" : 'Olá!';
        $link = $this->appUrl !== '' ? "\n\nAbrir o painel: " . rtrim($this->appUrl, '/') . '/entrar' : '';
        $summary = DailySummary::text($data);
        $time = $data['search_time'] !== null ? "Todos os seus processos são atualizados todos os dias às {$data['search_time']}." : 'Você pode escolher o horário da atualização diária (por exemplo, 5h30) na tela Plano.';
        $foot = "\n\nEste aviso é um apoio operacional: não é intimação nem cálculo oficial de prazo. Confira sempre nas fontes oficiais.";
        return match (true) {
            $kind === 'welcome' => "Bem-vindo, $name! Sua conta do escritório " . $t['name'] . " está criada.\n\n"
                . "Você tem {$plan['trial_days']} dias grátis. A cobrança começa só no 15º dia.\n$time\n\n"
                . "Para começar, cadastre seus processos (um a um ou por planilha). Na primeira consulta o histórico entra em silêncio; depois você é avisado de cada novidade.\n\n"
                . $this->pricing($plan) . $link . $foot,
            $kind === 'trial_d7' => "$hi Metade do teste já passou.\n\nAté agora o Radar acompanhou {$data['monitored']} processo(s) para você e registrou {$data['new_movements_24h']} movimentação(ões) nas últimas 24 horas.\n\n$summary\n\nFaltam {$plan['days_left']} dia(s) de teste.\n" . $this->pricing($plan) . $link . $foot,
            $kind === 'trial_d12' => "$hi Seu teste grátis termina em {$plan['days_left']} dia(s).\n\n$summary\n\n" . $this->pricing($plan) . "\nPara continuar sem interrupção, abra a tela Plano e peça a contratação." . $link . $foot,
            $kind === 'trial_last' => "$hi Hoje é o último dia do teste grátis.\n\n" . $this->pricing($plan) . "\nPara continuar, abra a tela Plano e clique em \"Quero contratar\". Seus dados continuam guardados." . $link . $foot,
            default => "$hi Aqui está o resumo da atualização de hoje.\n\n$summary" . $link . $foot,
        };
    }
}
