<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\Forbidden;
use Radar\Exception\NotFound;
use Radar\Monitoring\Worker;
use Radar\Repository\MovementRepository;
use Radar\Repository\NotificationRepository;
use Radar\Repository\ProcessRepository;
use Radar\Service\DashboardService;
use Radar\Service\MemberService;
use Radar\Service\ProcessService;
use Radar\Tenancy\TenantContext;
use Radar\Tests\Support\FakeAdapter;

/**
 * Critério obrigatório do PRD (RNF-002): nenhum acesso cruzado entre escritórios.
 * Cenário: dois escritórios com processos, movimentações, alertas e equipes próprios.
 */
final class TenantIsolationTest extends DbTestCase
{
    private array $a;
    private array $b;
    private TenantContext $ctxA;
    private TenantContext $ctxB;
    private int $processA;
    private int $processB;
    private ProcessService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ProcessService($this->pdo, $this->clock);
        $this->a = $this->office('Escritório A', 'a@a.com');
        $this->b = $this->office('Escritório B', 'b@b.com');
        $this->ctxA = $this->ctx($this->a['user_id'], $this->a['tenant_id']);
        $this->ctxB = $this->ctx($this->b['user_id'], $this->b['tenant_id']);
        $this->processA = (int) $this->svc->create($this->ctxA, self::CNJ_TJMG, 'Cliente do A', $this->a['user_id'])['id'];
        $this->processB = (int) $this->svc->create($this->ctxB, self::CNJ_TJRO, 'Cliente do B', $this->b['user_id'])['id'];
        // cada escritório já tem histórico e alerta (consulta base + uma movimentação nova)
        $adapter = (new FakeAdapter())
            ->thenFor(self::CNJ_TJMG, [FakeAdapter::movement('a1', 'Movimento secreto do A')])
            ->thenFor(self::CNJ_TJRO, [FakeAdapter::movement('b1', 'Movimento secreto do B')])
            ->thenFor(self::CNJ_TJMG, [FakeAdapter::movement('a1', 'Movimento secreto do A'), FakeAdapter::movement('a2', 'Novo do A')])
            ->thenFor(self::CNJ_TJRO, [FakeAdapter::movement('b1', 'Movimento secreto do B'), FakeAdapter::movement('b2', 'Novo do B')]);
        $worker = new Worker($this->pdo, [$adapter], $this->clock);
        $worker->runBatch(10);
        $this->clock->advance('PT25H');
        $worker->enqueueDue();
        $worker->runBatch(10);
    }

    public function testFixtureHasDataInBothOffices(): void
    {
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM process_movements WHERE tenant_id = ?', [$this->a['tenant_id']]));
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM process_movements WHERE tenant_id = ?', [$this->b['tenant_id']]));
        $this->assertGreaterThan(0, (int) $this->scalar('SELECT COUNT(*) FROM notifications WHERE tenant_id = ?', [$this->b['tenant_id']]));
    }

    public function testUserCannotBuildAContextForAnotherOffice(): void
    {
        $this->expectException(Forbidden::class);
        TenantContext::resolve($this->pdo, $this->a['user_id'], $this->b['tenant_id']);
    }

    public function testSuspendedOfficeOrUserLosesAccess(): void
    {
        $this->pdo->exec("UPDATE tenants SET status = 'suspended' WHERE id = " . $this->a['tenant_id']);
        $this->expectException(Forbidden::class);
        $this->ctx($this->a['user_id'], $this->a['tenant_id']);
    }

    public function testReadingAnotherOfficesProcessIsNotFound(): void
    {
        $this->expectException(NotFound::class);
        $this->svc->get($this->ctxA, $this->processB);
    }

    public function testWritingToAnotherOfficesProcessIsNotFoundAndChangesNothing(): void
    {
        $before = $this->pdo->query('SELECT * FROM processes WHERE id = ' . $this->processB)->fetch();
        foreach ([
            fn () => $this->svc->update($this->ctxA, $this->processB, ['client_label' => 'sequestrado']),
            fn () => $this->svc->pause($this->ctxA, $this->processB),
            fn () => $this->svc->resume($this->ctxA, $this->processB),
            fn () => $this->svc->archive($this->ctxA, $this->processB),
            fn () => $this->svc->requestCheck($this->ctxA, $this->processB),
        ] as $i => $attack) {
            try {
                $attack();
                $this->fail("ataque $i não foi barrado");
            } catch (NotFound) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, $this->pdo->query('SELECT * FROM processes WHERE id = ' . $this->processB)->fetch());
    }

    public function testSearchNeverLeaksAnotherOffice(): void
    {
        $repo = new ProcessRepository($this->pdo, $this->ctxA);
        $all = $repo->search();
        $this->assertSame(1, $all['total']);
        $this->assertSame([self::CNJ_TJMG], array_column($all['items'], 'cnj'));
        foreach (['Cliente do B', self::CNJ_TJRO, '70648715420258220001', 'TJRO', '%', '_'] as $term) {
            $this->assertSame(0, $repo->search(['q' => $term])['total'], "busca por '$term'");
        }
        $this->assertSame(0, $repo->search(['tribunal' => 'TJRO'])['total']);
        $this->assertSame(0, $repo->search(['responsible_user_id' => $this->b['user_id']])['total']);
        $this->assertNull($repo->find($this->processB));
    }

    public function testHistoryOfAnotherOfficesProcessIsEmpty(): void
    {
        $this->assertSame([], (new MovementRepository($this->pdo, $this->ctxA))->forProcess($this->processB));
        $text = json_encode((new MovementRepository($this->pdo, $this->ctxA))->recent($this->clock->now()->modify('-30 days'), 100));
        $this->assertStringNotContainsString('do B', $text);
        $this->assertStringContainsString('Novo do A', $text);
    }

    public function testNotificationsAreOnlyTheUsersOwn(): void
    {
        $mineA = (new NotificationRepository($this->pdo, $this->ctxA))->mine();
        $this->assertNotEmpty($mineA);
        $this->assertStringNotContainsString('do B', json_encode($mineA));

        // não consegue marcar como lida a notificação de outro escritório…
        $idOfB = (int) $this->scalar("SELECT id FROM notifications WHERE tenant_id = ? AND channel = 'panel' LIMIT 1", [$this->b['tenant_id']]);
        $this->assertFalse((new NotificationRepository($this->pdo, $this->ctxA))->markRead($idOfB, $this->clock->now()));
        $this->assertSame('pending', $this->scalar('SELECT status FROM notifications WHERE id = ?', [$idOfB]));
        // …nem a de um colega do mesmo escritório
        $colleague = $this->member($this->a['tenant_id'], 'colega@a.com', 'lawyer');
        $idOfAdmin = (int) $this->scalar("SELECT id FROM notifications WHERE user_id = ? AND channel = 'panel' LIMIT 1", [$this->a['user_id']]);
        $this->assertFalse((new NotificationRepository($this->pdo, $this->ctx($colleague, $this->a['tenant_id'])))->markRead($idOfAdmin, $this->clock->now()));
    }

    public function testDashboardCountsOnlyItsOwnOffice(): void
    {
        $dash = (new DashboardService($this->pdo, $this->clock))->summary($this->ctxA);
        $this->assertSame(1, $dash['processes_total']);
        $this->assertStringNotContainsString('do B', json_encode($dash));
    }

    public function testTeamListNeverShowsAnotherOfficesPeople(): void
    {
        $team = (new MemberService($this->pdo, $this->clock))->list($this->ctxA);
        $this->assertSame(['a@a.com'], array_column($team, 'email'));
    }

    public function testTheSameProcessCanBeRegisteredByTwoOffices(): void
    {
        $this->assertSame(self::CNJ_TJRO, $this->svc->create($this->ctxA, self::CNJ_TJRO)['cnj']);      // B já tem; A também pode
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM processes WHERE cnj = ?', [self::CNJ_TJRO]));
    }

    public function testSqlInjectionAttemptsAreStoredAsPlainText(): void
    {
        $evil = "x'); DROP TABLE processes; --";
        $this->svc->update($this->ctxA, $this->processA, ['client_label' => $evil, 'notes' => "1' OR '1'='1"]);
        $this->assertSame($evil, $this->svc->get($this->ctxA, $this->processA)['process']['client_label']);
        $repo = new ProcessRepository($this->pdo, $this->ctxA);
        $this->assertSame(1, $repo->search(['q' => "x'); DROP"])['total']);
        $this->assertSame(0, $repo->search(['q' => "' OR '1'='1"])['total']);        // não vira "tudo"
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM processes'));
    }

    public function testUserOfTwoOfficesSeesEachOneSeparately(): void
    {
        $this->pdo->prepare("INSERT INTO tenant_members (tenant_id, user_id, role, status, created_at) VALUES (?, ?, 'lawyer', 'active', '2026-10-10 12:00:00')")
            ->execute([$this->b['tenant_id'], $this->a['user_id']]);
        $inA = $this->ctx($this->a['user_id'], $this->a['tenant_id']);
        $inB = $this->ctx($this->a['user_id'], $this->b['tenant_id']);
        $this->assertSame([self::CNJ_TJMG], array_column((new ProcessRepository($this->pdo, $inA))->search()['items'], 'cnj'));
        $this->assertSame([self::CNJ_TJRO], array_column((new ProcessRepository($this->pdo, $inB))->search()['items'], 'cnj'));
    }

    public function testRolesLimitWhatEachPersonCanDo(): void
    {
        $assistant = $this->ctx($this->member($this->a['tenant_id'], 'ass@a.com', 'assistant'), $this->a['tenant_id']);
        $lawyer = $this->ctx($this->member($this->a['tenant_id'], 'adv@a.com', 'lawyer'), $this->a['tenant_id']);
        $this->assertTrue($this->ctxA->can('members.manage'));
        $this->assertFalse($lawyer->can('members.manage'));
        $this->assertTrue($lawyer->can('process.archive'));
        $this->assertFalse($assistant->can('process.archive'));
        $this->assertFalse($assistant->can('members.manage'));
        $this->assertFalse($assistant->can('audit.view'));
        $this->assertFalse($assistant->can('permissao.inexistente'));
    }

    /**
     * Rede de proteção contra um erro humano futuro: toda consulta escrita num repositório
     * precisa mencionar o escritório. Se alguém esquecer o filtro, este teste quebra.
     */
    public function testEveryRepositoryQueryMentionsTheTenant(): void
    {
        $checked = 0;
        foreach (glob(__DIR__ . '/../src/Repository/*.php') ?: [] as $file) {
            $parts = explode('->prepare(', (string) file_get_contents($file));
            array_shift($parts);
            foreach ($parts as $chunk) {
                $statement = explode(');', $chunk, 2)[0];
                // a busca monta o WHERE em partes; o filtro do escritório é a primeira delas
                if (str_contains($statement, '$sqlWhere')) {
                    $this->assertStringContainsString("\$where = ['p.tenant_id = :tenant']", (string) file_get_contents($file));
                } else {
                    $this->assertStringContainsString('tenant', $statement, basename($file) . ': consulta sem tenant_id');
                }
                $checked++;
            }
        }
        $this->assertGreaterThanOrEqual(8, $checked);
    }
}
