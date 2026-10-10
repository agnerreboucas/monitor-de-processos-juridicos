<?php
declare(strict_types=1);

namespace Radar\Service;

use DateInterval;
use PDO;
use Radar\Mail\Mailer;
use Radar\Mail\MailerException;
use Radar\Support\Clock;

/**
 * Envia por e-mail as notificações pendentes (canal "email").
 *
 * - Um e-mail-resumo por pessoa por rodada, não um por movimentação.
 * - Cada notificação guarda o status (pending → sent | failed), o número de tentativas e o erro.
 * - Falha é tentada de novo com espera progressiva (5, 10, 20, 40 min), até MAX_ATTEMPTS.
 * - Enviar um alerta NÃO significa intimação nem prazo calculado: o texto diz isso.
 */
final class NotificationDispatcher
{
    public const MAX_ATTEMPTS = 5;
    private const MAX_PER_EMAIL = 20;
    private const EXCERPT = 240;

    public function __construct(
        private PDO $pdo,
        private Mailer $mailer,
        private string $appUrl = '',
        private Clock $clock = new Clock(),
    ) {
    }

    /** @return array{emails:int,notifications:int,failed:int} */
    public function sendPending(int $limit = 200): array
    {
        $now = $this->clock->now();
        $stmt = $this->pdo->prepare(
            "SELECT n.id, n.user_id, n.attempts, n.tenant_id, u.email, u.name AS user_name, p.id AS process_id, p.cnj, p.tribunal,
                    m.occurred_at, m.captured_at, m.kind, m.description, m.source_url
               FROM notifications n
               JOIN users u ON u.id = n.user_id AND u.status = 'active'
               JOIN tenant_members tm ON tm.tenant_id = n.tenant_id AND tm.user_id = n.user_id AND tm.status = 'active'
               JOIN processes p ON p.id = n.process_id AND p.tenant_id = n.tenant_id
               JOIN process_movements m ON m.id = n.movement_id AND m.tenant_id = n.tenant_id
              WHERE n.channel = 'email' AND n.status IN ('pending','failed') AND n.attempts < :max
                AND (n.next_attempt_at IS NULL OR n.next_attempt_at <= :now)
              ORDER BY n.user_id, n.tenant_id, n.id LIMIT :limit"
        );
        $stmt->bindValue(':max', self::MAX_ATTEMPTS, PDO::PARAM_INT);
        $stmt->bindValue(':now', Clock::sql($now));
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $groups = [];
        foreach ($stmt->fetchAll() as $row) {
            $groups[$row['user_id'] . ':' . $row['tenant_id']][] = $row;      // uma mensagem por pessoa e escritório
        }
        $result = ['emails' => 0, 'notifications' => 0, 'failed' => 0];
        foreach ($groups as $rows) {
            foreach (array_chunk($rows, self::MAX_PER_EMAIL) as $chunk) {
                $ids = array_map(static fn (array $r) => (int) $r['id'], $chunk);
                try {
                    $this->mailer->send((string) $chunk[0]['email'], (string) $chunk[0]['user_name'],
                        count($chunk) === 1 ? 'Radar Trabalhista: nova movimentação em processo monitorado'
                            : 'Radar Trabalhista: ' . count($chunk) . ' novas movimentações em processos monitorados',
                        $this->body($chunk));
                    $this->mark($ids, 'sent', null, null, $now);
                    $result['emails']++;
                    $result['notifications'] += count($ids);
                } catch (MailerException $e) {
                    $this->markFailed($chunk, $e->getMessage(), $now);
                    $result['failed'] += count($ids);
                }
            }
        }
        return $result;
    }

    /** @param list<array<string,mixed>> $rows */
    private function body(array $rows): string
    {
        $lines = ['Foram identificadas novas movimentações nos processos que você acompanha:', ''];
        foreach ($rows as $r) {
            $lines[] = '• Processo ' . $r['cnj'] . ' (' . $r['tribunal'] . ')';
            $lines[] = '  Tipo: ' . ($r['kind'] !== '' ? $r['kind'] : 'movimentação');
            $lines[] = '  Data da movimentação: ' . ($r['occurred_at'] !== null ? $this->br((string) $r['occurred_at']) : 'não informada pela fonte');
            $lines[] = '  Data da captura pelo sistema: ' . $this->br(substr((string) $r['captured_at'], 0, 10));
            $excerpt = trim((string) preg_replace('/\s+/', ' ', (string) $r['description']));
            if ($excerpt !== '') {
                $lines[] = '  Resumo: ' . (mb_strlen($excerpt) > self::EXCERPT ? mb_substr($excerpt, 0, self::EXCERPT - 1) . '…' : $excerpt);
            }
            if ($this->appUrl !== '') {
                $lines[] = '  Detalhes: ' . rtrim($this->appUrl, '/') . '/#/processos/' . $r['process_id'];
            }
            if (is_string($r['source_url']) && str_starts_with($r['source_url'], 'https://')) {
                $lines[] = '  Fonte oficial: ' . $r['source_url'];
            }
            $lines[] = '';
        }
        $lines[] = '—';
        $lines[] = 'Este aviso é um apoio operacional. Ele não significa que o escritório foi intimado nem que um prazo foi calculado.';
        $lines[] = 'Confira sempre a movimentação na fonte oficial.';
        return implode("\n", $lines) . "\n";
    }

    private function br(string $date): string
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $date, $m) === 1 ? "$m[3]/$m[2]/$m[1]" : $date;
    }

    /** @param list<int> $ids */
    private function mark(array $ids, string $status, ?string $error, ?string $nextAttempt, \DateTimeImmutable $now): void
    {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare(
            "UPDATE notifications SET status = ?, attempts = attempts + 1, last_error = ?, next_attempt_at = ?,
                    sent_at = IF(? = 'sent', ?, sent_at) WHERE id IN ($in)"
        )->execute([$status, $error, $nextAttempt, $status, Clock::sql($now), ...$ids]);
    }

    /** @param list<array<string,mixed>> $rows */
    private function markFailed(array $rows, string $error, \DateTimeImmutable $now): void
    {
        foreach ($rows as $r) {
            $attempt = (int) $r['attempts'] + 1;
            $next = $attempt >= self::MAX_ATTEMPTS ? null
                : Clock::sql($now->add(new DateInterval('PT' . (5 * (2 ** ($attempt - 1))) . 'M')));
            $this->mark([(int) $r['id']], 'failed', mb_substr($error, 0, 500), $next, $now);
        }
    }
}
