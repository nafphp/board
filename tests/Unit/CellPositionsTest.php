<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Support\Ticket\CellPositions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A card dropped into a cell takes a free number between its neighbours, and
 * says so when there is none, which is when the cell is laid out again.
 */
final class CellPositionsTest extends TestCase
{
    /** @return array<string,array{?int,?int,?int}> */
    public static function neighbours(): array
    {
        $gap = CellPositions::GAP;

        return [
            'an empty cell'                => [null, null, $gap],
            'below the last card'          => [3 * $gap, null, 4 * $gap],
            'above the first card'         => [null, 2 * $gap, $gap],
            'above a card below zero'      => [null, -$gap, -2 * $gap],
            'between two cards'            => [$gap, 2 * $gap, $gap + $gap / 2],
            'between two that almost meet' => [10, 12, 11],
            'between two that touch'       => [10, 11, null],
            'past the largest number'      => [PHP_INT_MAX - 1, null, null],
            'before the smallest number'   => [null, PHP_INT_MIN + 1, null],
        ];
    }

    #[DataProvider('neighbours')]
    public function testAFreePositionLiesBetweenItsNeighbours(?int $above, ?int $below, ?int $expected): void
    {
        $this->assertSame($expected, CellPositions::between($above, $below));
    }
}
