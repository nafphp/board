<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Domain\Failure;
use Naf\Board\Domain\ProjectScope;
use Naf\Board\Services\BoardDql;
use Naf\Board\Support\BoardFilterContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DqlParserTest extends TestCase
{
    public function testPrecedenceQuotesAndCanonicalRoundTrips(): void
    {
        $node = BoardDql::parse('status = open OR priority IN (high, urgent) AND NOT assignee = me');
        self::assertSame('OR', $node['kind']);
        self::assertSame('AND', $node['right']['kind']);
        self::assertSame('NOT', $node['right']['right']['kind']);
        $query = 'title ~ ' . BoardDql::quote('A "quoted" \\ path AND (literal)');
        self::assertSame('A "quoted" \\ path AND (literal)', BoardDql::parse($query)['values'][0]);
        self::assertSame(BoardDql::format($node), BoardDql::format(BoardDql::parse(BoardDql::format($node))));
        self::assertSame('Alice Winter', BoardDql::parse("assignee = 'Alice Winter'")['values'][0]);
        self::assertNull(BoardDql::parse('  '));
    }

    #[DataProvider('invalidQueries')]
    public function testMalformedAndUnboundedQueriesAreRefused(mixed $query): void
    {
        try {
            BoardDql::parse($query);
            self::fail('Invalid query accepted');
        } catch (Failure $failure) {
            self::assertSame(422, $failure->status);
            self::assertArrayHasKey('dql', $failure->errors);
        }
    }

    public static function invalidQueries(): iterable
    {
        yield ["title = \"a\nb\""];
        yield ["status = open\r\nAND priority = high"];
        yield ["title = \"nul\0value\""];
        foreach (['status =', 'status = open AND', 'status = open closed', 'status == open', 'priority IN ()', 'priority IN (low,)', 'status NOT = open', '(status = open', 'status = "open', 'status = open; DROP TABLE tickets', 'status = open --', str_repeat('NOT ', 14) . 'status = open', str_repeat('(', 14) . 'status = open' . str_repeat(')', 14), str_repeat('a', 2001), 'priority IN (' . implode(',', range(1, 21)) . ')', 'status = "' . "\xff" . '"', [], 1] as $query) {
            yield [$query];
        }
    }

    public function testSqlValuesAreBoundAndTheProjectIsNeverPartOfTheExpression(): void
    {
        $value     = '%_!\' OR 1=1 --';
        $context   = new BoardFilterContext(42, new ProjectScope(['archived_at' => null], '7', 'viewer'));
        $condition = BoardDql::condition(BoardDql::parse('title ~ ' . BoardDql::quote($value) . ' OR assignee = me'), $context);
        self::assertStringNotContainsString($value, $condition->sql);
        self::assertSame(['%!%!_!!\' OR 1=1 --%', 7], $condition->parameters);
        self::assertStringContainsString('f.project_id=t.project_id', $condition->sql);
    }

    #[DataProvider('invalidProperties')]
    public function testUnknownPropertiesAndInvalidValuesHaveFieldErrors(string $query): void
    {
        $context = new BoardFilterContext(42, new ProjectScope(['archived_at' => null], '7', 'viewer'));

        try {
            BoardDql::condition(BoardDql::parse($query), $context);
            self::fail('Invalid property accepted');
        } catch (Failure $failure) {
            self::assertArrayHasKey('dql', $failure->errors);
            self::assertMatchesRegularExpression('/position 1:/i', $failure->getMessage());
        }
    }

    public static function invalidProperties(): iterable
    {
        foreach (['missing.field = value', 'status = done', 'priority = invented', 'assignee > 1', 'due = 2026-02-30', 'number = -1', 'dql = "status = open"', 'title > abc'] as $query) {
            yield [$query];
        }
    }
}
