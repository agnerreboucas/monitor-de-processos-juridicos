<?php
declare(strict_types=1);

namespace Radar\Tests;

use Radar\Service\DataTransferService;

/** As 3 listas de processos reais para apresentações (apresentacao-listas/) têm de importar sem recusas. */
final class PresentationListsTest extends DbTestCase
{
    public function testThePresentationListsImportCleanly(): void
    {
        $o = $this->office('Demo', 'demo@demo.com', 'profissional');
        $ctx = $this->ctx($o['user_id'], $o['tenant_id']);
        $svc = new DataTransferService($this->pdo, $this->clock);
        $files = glob(__DIR__ . '/../../apresentacao-listas/*.csv') ?: [];
        $this->assertCount(3, $files);
        $total = 0;
        foreach ($files as $file) {
            $r = $svc->importProcesses($ctx, (string) file_get_contents($file));
            $this->assertSame([], $r['skipped'], basename($file));
            $total += $r['created'];
        }
        $this->assertSame(35, $total);
    }
}
