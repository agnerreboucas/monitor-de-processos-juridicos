<?php
declare(strict_types=1);

namespace Radar\Tests\Support;

use DateTimeImmutable;
use Radar\Monitoring\Movement;
use Radar\Monitoring\SourceAdapter;
use Radar\Support\Cnj;
use Throwable;

/** Fonte falsa: devolve o que o teste mandar (movimentações ou uma exceção) e anota as chamadas. */
final class FakeAdapter implements SourceAdapter
{
    /** @var list<array{cnj:string,since:?string}> */
    public array $calls = [];
    /** @var list<list<Movement>|Throwable> */
    private array $script = [];
    /** @var array<string,list<list<Movement>|Throwable>> respostas por número de processo */
    private array $byCnj = [];

    public function __construct(private string $code = 'djen')
    {
    }

    /** @param list<Movement>|Throwable $next */
    public function then(array|Throwable $next): self
    {
        $this->script[] = $next;
        return $this;
    }

    /** @param list<Movement>|Throwable $next resposta só para este número de processo (a ordem entre processos não importa) */
    public function thenFor(string $cnj, array|Throwable $next): self
    {
        $this->byCnj[$cnj][] = $next;
        return $this;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function fetch(Cnj $cnj, ?DateTimeImmutable $since): array
    {
        $this->calls[] = ['cnj' => $cnj->formatted(), 'since' => $since?->format('Y-m-d H:i:s')];
        $next = isset($this->byCnj[$cnj->formatted()]) ? array_shift($this->byCnj[$cnj->formatted()]) : array_shift($this->script);
        $next ??= [];
        if ($next instanceof Throwable) {
            throw $next;
        }
        return $next;
    }

    public static function movement(string $id, string $text = 'Despacho de teste', ?string $date = '2026-10-09', string $prefix = 'djen'): Movement
    {
        return new Movement($prefix . '-' . $id, $date, 'Intimação', $text, '<p>' . $text . '</p>', 'https://tribunal.exemplo/' . $id, ['orgao' => 'Vara Teste']);
    }
}
