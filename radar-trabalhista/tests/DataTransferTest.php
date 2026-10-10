<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Exception\Forbidden;
use Radar\Exception\ValidationError;
use Radar\Monitoring\OabMonitor;
use Radar\Service\DataTransferService;
use Radar\Service\OabService;
use Radar\Tests\Support\FakeOabSource as F;

final class DataTransferTest extends DbTestCase
{
    private DataTransferService $svc;
    private array $office;

    protected function setUp(): void
    {
        parent::setUp();
        $this->office = $this->office('A', 'a@a.com', 'essencial');          // 50 processos
        $this->svc = new DataTransferService($this->pdo, $this->clock);
    }

    private function ctx1(): \Radar\Tenancy\TenantContext
    {
        return $this->ctx($this->office['user_id'], $this->office['tenant_id']);
    }

    public function testImportWithHeaderSemicolonAndReportOfBadLines(): void
    {
        $csv = "Processo;Cliente\n" . self::CNJ_TRT8 . ";Maria da Silva\n0001457-85.2026.5.08.0107;Digito errado\n" . self::CNJ_TJMG . ";\n"
            . self::CNJ_TRT8 . ";Repetido\n;\nlixo\n";
        $r = $this->svc->importProcesses($this->ctx1(), $csv);
        $this->assertSame(2, $r['created']);
        $this->assertCount(3, $r['skipped']);
        $this->assertSame(3, $r['skipped'][0]['line']);                       // linha da planilha (cabeçalho = 1)
        $this->assertStringContainsString('inválido', $r['skipped'][0]['reason']);
        $this->assertStringContainsString('Repetido', $r['skipped'][1]['reason']);
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM processes'));
        $this->assertSame('Maria da Silva', $this->scalar('SELECT client_label FROM processes WHERE cnj = ?', [self::CNJ_TRT8]));
        $this->assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM monitoring_jobs WHERE status = 'queued'"));   // já entram na fila
    }

    public function testImportAcceptsCommaAndNoHeaderAndExistingProcessIsSkipped(): void
    {
        $this->svc->importProcesses($this->ctx1(), self::CNJ_TJMG . ",Cliente\n");
        $r = $this->svc->importProcesses($this->ctx1(), self::CNJ_TJMG . ",Cliente\n" . self::CNJ_TJRO . "\n");
        $this->assertSame(1, $r['created']);
        $this->assertStringContainsString('já está cadastrado', $r['skipped'][0]['reason']);
    }

    public function testImportStopsAtThePlanLimitAndReportsTheRest(): void
    {
        $this->pdo->exec("UPDATE plans SET max_processes = 2 WHERE code = 'essencial'");
        $csv = implode("\n", [self::CNJ_TRT8, self::CNJ_TJMG, self::CNJ_TJRO, self::CNJ_TJMS]);
        $r = $this->svc->importProcesses($this->ctx1(), $csv);
        $this->assertSame(2, $r['created']);
        $this->assertTrue($r['stopped_by_limit']);
        $this->assertCount(2, $r['skipped']);
        $this->assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM processes'));
    }

    public function testImportRefusesEmptyHugeAndForbidden(): void
    {
        foreach (['', "\n\n"] as $empty) {
            try {
                $this->svc->importProcesses($this->ctx1(), $empty);
                $this->fail();
            } catch (ValidationError) {
                $this->addToAssertionCount(1);
            }
        }
        try {
            $this->svc->importProcesses($this->ctx1(), str_repeat(self::CNJ_TRT8 . "\n", DataTransferService::MAX_ROWS + 1));
            $this->fail();
        } catch (ValidationError) {
            $this->addToAssertionCount(1);
        }
        $viewerLess = $this->ctx($this->member($this->office['tenant_id'], 'x@a.com', 'assistant'), $this->office['tenant_id']);
        $this->assertSame(1, $this->svc->importProcesses($viewerLess, self::CNJ_TJMG)['created']);   // assistente pode cadastrar
        $this->expectException(Forbidden::class);
        $this->svc->exportPublications($viewerLess);                          // mas não vê OAB
    }

    public function testExportNeutralizesSpreadsheetFormulasAndKeepsAccents(): void
    {
        $this->svc->importProcesses($this->ctx1(), self::CNJ_TRT8 . ';=HYPERLINK("http://x","Clique")');
        $csv = $this->svc->exportProcesses($this->ctx1());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(';=HYPERLINK', $csv);
        $this->assertStringContainsString('Situação', $csv);
        $this->assertCount(2, DataTransferService::parse($csv));
        $this->assertSame('Processo', DataTransferService::parse($csv)[0][0]);
    }

    public function testExportsOnlyOwnOfficeData(): void
    {
        $this->svc->importProcesses($this->ctx1(), self::CNJ_TRT8 . ';Cliente da A');
        $b = $this->office('B', 'b@b.com');
        $ctxB = $this->ctx($b['user_id'], $b['tenant_id']);
        $this->assertStringNotContainsString('Cliente da A', $this->svc->exportProcesses($ctxB));
        // publicações
        (new OabService($this->pdo, $this->clock))->add($this->ctx1(), '1', 'MG', 'Dra. A');
        $src = (new F())->then([F::pub('1', F::cnj(1, 2026, 8, '13'), '2026-02-01', 'Texto da publicação da A')]);
        (new OabMonitor($this->pdo, $src, $this->clock))->runDue();
        $this->assertStringContainsString('Texto da publicação da A', $this->svc->exportPublications($this->ctx1()));
        $this->assertStringContainsString('Estadual', $this->svc->exportPublications($this->ctx1(), 2026));
        $this->assertStringNotContainsString('Texto da publicação da A', $this->svc->exportPublications($this->ctx1(), 2025));
        $this->assertStringNotContainsString('Texto da publicação da A', $this->svc->exportPublications($ctxB));
    }
}
