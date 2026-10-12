<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Definition\BoardFilterDefinition;
use Naf\Board\Services\BoardDql;
use Naf\Board\Services\SavedBoardViews;
use Naf\Board\Support\BoardFilterContext;
use Naf\Board\Support\SqlCondition;
use Naf\Board\Tests\Support\BoardTestCase;

use function Naf\Board\extensions;

final class BoardDqlTest extends BoardTestCase
{
    public function testBooleanQueriesCountsArchiveAndAuthorizationStayInAgreement(): void
    {
        $mine     = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Mine', 'priority' => 'high', 'assignee_ids' => [$this->alice->getId()], 'due_date' => '2030-01-01']));
        $other    = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Other', 'priority' => 'low']));
        $archived = $this->tickets->create($this->projectA, $this->ticketData(['title' => 'Archived', 'priority' => 'urgent']));
        $this->pdo->prepare('UPDATE tickets SET archived_at=NOW() WHERE project_id=? AND id=?')->execute([$this->projectA, $archived]);
        $this->actAs($this->bob);
        $this->tickets->create($this->projectB, ['title' => 'Private mine', 'priority' => 'urgent', 'column_id' => $this->boardB['columns'][0]['id'], 'swimlane_id' => $this->boardB['swimlanes'][0]['id'], ...$this->revision($this->projectB)]);
        $this->actAs($this->alice);

        foreach ([
            'status = open AND assignee = me'                                               => [$mine],
            'assignee = "Alice" AND due < 2031-01-01'                                       => [$mine],
            'assignee = none AND NOT priority = urgent'                                     => [$other],
            'priority IN (high, urgent)'                                                    => [$mine],
            'priority NOT IN (high, urgent)'                                                => [$other],
            'status = archived'                                                             => [$archived],
            'status = archived OR priority = high'                                          => [$mine, $archived],
            'NOT status = archived'                                                         => [$mine, $other],
            'board = ' . $this->projectB . ' OR title = "Mine"'                             => [$mine],
            'board.identifier = "' . $this->boardA['project']['ticket_key'] . '"'           => [$mine, $other],
            'due = none'                                                                    => [$other],
            'number >= ' . $this->scalar('SELECT number FROM tickets WHERE id=?', [$other]) => [$other],
        ] as $expression => $expected) {
            $board = $this->query->board($this->projectA, ['dql' => $expression]);
            self::assertEqualsCanonicalizing($expected, array_column($board['cards'], 'id'), $expression);
            self::assertSame(count($expected), $board['total'], $expression);
        }
        $this->assertDenied(404, fn() => $this->query->board($this->projectB, ['dql' => 'status = open OR board = ' . $this->projectA]));
        self::assertSame(1, $this->query->board($this->projectA, ['status' => 'open', 'dql' => 'status = archived OR priority = high'])['total']);
    }

    public function testDropdownConversionPreservesEveryConditionIncludingContradictionsAndOr(): void
    {
        $this->tickets->create($this->projectA, $this->ticketData(['priority' => 'high', 'assignee_ids' => [$this->alice->getId()]]));
        $this->tickets->create($this->projectA, $this->ticketData(['priority' => 'low']));
        $board = $this->query->board($this->projectA);
        foreach (['status = open AND assignee = "Alice"', 'priority = high AND (assignee = me OR assignee = none)', 'priority = high AND priority = low', 'NOT assignee = me', 'column = "Offen" AND swimlane = "Allgemein"', 'status = archived OR status = open', 'status = open AND (status = archived OR priority = high)'] as $query) {
            $simple            = BoardDql::dropdown(BoardDql::parse($query), $board);
            $parameters        = SavedBoardViews::parameters($simple['filters']);
            $parameters['dql'] = $simple['residual'];
            self::assertEqualsCanonicalizing(array_column($this->query->board($this->projectA, ['dql' => $query])['cards'], 'id'), array_column($this->query->board($this->projectA, $parameters)['cards'], 'id'), $query);
        }
        $simple = BoardDql::dropdown(BoardDql::parse('status = open AND assignee = me'), $board);
        self::assertSame(['status' => 'open', 'assignee' => 'me'], $simple['filters']);
        self::assertSame('', $simple['residual']);
        $empty = BoardDql::dropdown(BoardDql::parse('text ~ ""'), $board);
        self::assertSame([], $empty['filters']);
        self::assertSame('text ~ ""', $empty['residual']);
    }

    public function testContributedDefinitionsParticipateWithoutASecondRegistry(): void
    {
        extensions()->boardFilters()->add(new BoardFilterDefinition('test.priority', 'Test', static fn($value) => $value, static fn($value, BoardFilterContext $context) => new SqlCondition($context->alias . '.priority=?', [$value])));

        try {
            $this->tickets->create($this->projectA, $this->ticketData(['priority' => 'high']));
            self::assertSame(1, $this->query->board($this->projectA, ['dql' => 'test.priority IN (high, urgent)'])['total']);
            self::assertSame(0, $this->query->board($this->projectA, ['dql' => 'test.priority != high'])['total']);
        } finally {
            extensions()->boardFilters()->remove('test.priority');
        }
        $this->assertDenied(422, fn() => $this->query->board($this->projectA, ['dql' => 'test.priority = high']));
    }

    public function testMeKeepsItsMeaningForTheRecipientAfterReturningToDropdowns(): void
    {
        $this->tickets->create($this->projectA, $this->ticketData(['assignee_ids' => [$this->alice->getId()]]));
        $memberTicket = $this->tickets->create($this->projectA, $this->ticketData(['assignee_ids' => [$this->member->getId()]]));
        $simple       = BoardDql::dropdown(BoardDql::parse('assignee = me'), $this->query->board($this->projectA));
        $parameters   = SavedBoardViews::parameters($simple['filters']);
        self::assertSame(['assignee' => 'me'], $parameters);
        $this->actAs($this->member);
        self::assertSame([$memberTicket], array_map('intval', array_column($this->query->board($this->projectA, $parameters)['cards'], 'id')));
        self::assertSame(1, $this->query->board($this->projectA, ['dql' => 'assignee = me'])['total']);
    }

    public function testNonPortableNormalizedPluginValuesStayInTheirOriginalEnvelope(): void
    {
        extensions()->boardFilters()->add(new BoardFilterDefinition('test:legacy', 'Legacy', static fn($value) => $value, static fn() => new SqlCondition('1=1')));
        extensions()->boardFilters()->add(new BoardFilterDefinition('test.boolean', 'Boolean', static fn($value) => $value === true, static fn($value) => new SqlCondition('1=?', [(int) $value])));

        try {
            self::assertSame(['expression' => '', 'retained' => ['test.boolean' => true]], BoardDql::advanced(['test.boolean' => true]));
            self::assertSame(['expression' => '', 'retained' => ['test:legacy' => 'value']], BoardDql::advanced(['test:legacy' => 'value']));
            self::assertSame(['expression' => '', 'retained' => ['q' => "two\nlines"]], BoardDql::advanced(['q' => "two\nlines"]));
            $nested = ['checked' => false, 'ids' => [2, 3]];
            self::assertSame($nested, BoardDql::advanced(['test.nested' => $nested])['retained']['test.nested']);
        } finally {
            extensions()->boardFilters()->remove('test.boolean');
            extensions()->boardFilters()->remove('test:legacy');
        }
    }
}
