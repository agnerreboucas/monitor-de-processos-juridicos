<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\NotFound;
use Radar\Exception\ValidationError;
use Radar\Monitoring\Movement;
use Radar\Monitoring\Worker;
use Radar\Repository\MovementRepository;
use Radar\Service\ProcessService;
use Radar\Tests\Support\FakeAdapter;

final class ActionsTest extends DbTestCase
{
    /** @return array{0:array,1:\Radar\Tenancy\TenantContext,2:int} */
    private function withPublication(string $email = 'a@a.com'): array
    {
        $o = $this->office('A', $email);
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $pid = (int) (new ProcessService($this->pdo, $this->clock))->create($ctx, self::CNJ_TRT8, 'Cliente', $o['user_id'])['id'];
        $djen = (new FakeAdapter('djen'))->then([new Movement('djen-1', '2026-10-09', 'Intimação', 'Designada AUDIÊNCIA para o dia 11/03/2027 10:35 horas.', null, 'https://x.exemplo/1',
            ['orgao' => 'Vara', 'partes' => [['nome' => 'ANA', 'polo' => 'A']], 'advogados' => [['nome' => 'DRA', 'oab' => '1', 'uf' => 'PA']]])]);
        $datajud = (new FakeAdapter('datajud'))->then([FakeAdapter::movement('x', 'Conclusão', '2026-10-05', 'datajud')]);
        (new Worker($this->pdo, [$djen, $datajud], $this->clock, 'w1'))->runBatch(10);
        return [$o, $ctx, $pid];
    }

    public function testFeedHasOnlyDiarioPublicationsWithMetadataAndStatus(): void
    {
        [, $ctx, $pid] = $this->withPublication();
        $rows = (new MovementRepository($this->pdo, $ctx))->feed(new \DateTimeImmutable('2026-01-01'));
        $this->assertCount(1, $rows);                                  // o andamento do Datajud não entra: só publicação
        $this->assertSame($pid, (int) $rows[0]['process_id']);
        $this->assertSame('open', $rows[0]['action_status']);
        $this->assertSame('ANA', $rows[0]['metadata']['partes'][0]['nome']);
        $this->assertNull($rows[0]['client_side']);
    }

    public function testMarkingAnActionPersistsAndCanBeReopened(): void
    {
        [, $ctx] = $this->withPublication();
        $repo = new MovementRepository($this->pdo, $ctx);
        $id = (int) $repo->feed(new \DateTimeImmutable('2026-01-01'))[0]['id'];
        $repo->setAction($id, 'done', 'Petição protocolada', $this->clock->now());
        $row = $repo->feed(new \DateTimeImmutable('2026-01-01'))[0];
        $this->assertSame('done', $row['action_status']);
        $this->assertSame('Petição protocolada', $row['action_note']);
        $repo->setAction($id, 'open', null, $this->clock->now());
        $this->assertSame('open', $repo->feed(new \DateTimeImmutable('2026-01-01'))[0]['action_status']);
        $this->assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM movement_actions'));   // regrava a mesma linha, não duplica
    }

    public function testAnotherOfficeCannotSeeOrMarkIt(): void
    {
        $this->withPublication();
        $id = (int) $this->scalar('SELECT id FROM process_movements LIMIT 1');
        $other = $this->office('B', 'b@b.com');
        $ctxB = $this->ctx($other['user_id'], $other['tenant_id']);
        $this->assertSame([], (new MovementRepository($this->pdo, $ctxB))->feed(new \DateTimeImmutable('2026-01-01')));
        $this->expectException(NotFound::class);
        (new MovementRepository($this->pdo, $ctxB))->setAction($id, 'done', null, $this->clock->now());
    }

    public function testClientSideIsValidatedAndStored(): void
    {
        [, $ctx, $pid] = $this->withPublication();
        $service = new ProcessService($this->pdo, $this->clock);
        $service->update($ctx, $pid, ['client_side' => 'P']);
        $this->assertSame('P', $service->get($ctx, $pid)['process']['client_side']);
        $service->update($ctx, $pid, ['client_side' => '']);
        $this->assertNull($service->get($ctx, $pid)['process']['client_side']);
        $this->expectException(ValidationError::class);
        $service->update($ctx, $pid, ['client_side' => 'X']);
    }
}
