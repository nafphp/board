<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Services\SavedBoardViews;
use PHPUnit\Framework\TestCase;

final class SavedViewLinksTest extends TestCase
{
    public function testSharedQueryRetainsArchiveSemanticsAndNestedExtensionFilters(): void
    {
        $query = SavedBoardViews::parameters([
            'status'        => 'archived', 'q' => 'A & B',
            'example.score' => ['min' => 0, 'max' => 5],
        ]);
        parse_str(http_build_query($query), $received);
        self::assertSame('archived', $received['status']);
        self::assertSame('A & B', $received['q']);
        self::assertSame(['min' => '0', 'max' => '5'], $received['filters']['example.score']);
        self::assertArrayNotHasKey('example_score', $received);
    }
}
