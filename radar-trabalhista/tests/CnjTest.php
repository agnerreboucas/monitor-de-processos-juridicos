<?php
declare(strict_types=1);

namespace Radar\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Radar\Support\Cnj;

final class CnjTest extends TestCase
{
    /** @return array<string,array{string,string,?string,int}> */
    public static function realNumbers(): array
    {
        return [
            'TJMG' => [DbTestCase::CNJ_TJMG, 'TJMG', 'MG', 8],
            'TJRO' => [DbTestCase::CNJ_TJRO, 'TJRO', 'RO', 8],
            'TRT8' => [DbTestCase::CNJ_TRT8, 'TRT8', null, 5],
            'TJMS' => [DbTestCase::CNJ_TJMS, 'TJMS', 'MS', 8],
            'TJTO' => [DbTestCase::CNJ_TJTO, 'TJTO', 'TO', 8],
            'STM' => [DbTestCase::CNJ_STM, 'STM', null, 7],
            'TRF4' => [DbTestCase::CNJ_TRF4, 'TRF4', null, 4],
        ];
    }

    #[DataProvider('realNumbers')]
    public function testReadsRealNumbers(string $number, string $tribunal, ?string $uf, int $segment): void
    {
        $cnj = Cnj::parse($number);
        $this->assertNotNull($cnj);
        $this->assertSame($tribunal, $cnj->tribunal());
        $this->assertSame($uf, $cnj->uf());
        $this->assertSame($segment, $cnj->segment);
        $this->assertSame($number, $cnj->formatted());
    }

    public function testAcceptsDigitsOnlyAndExtraSpaces(): void
    {
        $this->assertSame(DbTestCase::CNJ_TJMG, Cnj::parse('00229291620168130297')?->formatted());
        $this->assertSame(DbTestCase::CNJ_TJMG, Cnj::parse('  ' . DbTestCase::CNJ_TJMG . ' ')?->formatted());
    }

    /** @return array<string,array{string}> */
    public static function invalid(): array
    {
        return [
            'dígito verificador errado' => ['0022929-17.2016.8.13.0297'],
            'um dígito a menos' => ['0022929-16.2016.8.13.029'],
            'um dígito a mais' => ['0022929-16.2016.8.13.02970'],
            'letras' => ['00229291620168130ABC'],
            'vazio' => [''],
            'só pontuação' => ['..--'],
            'texto com número dentro' => ['processo 0022929-16.2016.8.13.0297 do cliente'],
            'segmento zero' => ['0000000-00.0000.0.00.0000'],
        ];
    }

    #[DataProvider('invalid')]
    public function testRejectsInvalid(string $input): void
    {
        $this->assertNull(Cnj::parse($input));
    }

    public function testLaborJusticeFlag(): void
    {
        $this->assertTrue(Cnj::parse(DbTestCase::CNJ_TRT8)?->isLabor());
        $this->assertFalse(Cnj::parse(DbTestCase::CNJ_TJMG)?->isLabor());
    }
}
