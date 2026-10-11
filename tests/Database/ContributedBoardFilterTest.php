<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use DOMDocument;
use DOMXPath;
use Naf\Board\Contracts\PageRendererInterface;
use Naf\Board\Definition\BoardFilterDefinition;
use Naf\Board\Support\BoardFilterContext;
use Naf\Board\Support\SqlCondition;
use Naf\Board\Tests\Support\BoardTestCase;

use function Naf\app;
use function Naf\Board\extensions;
use function Naf\Board\settings;

/** A host-added filter participates in controls, settings, cards and counts together. */
final class ContributedBoardFilterTest extends BoardTestCase
{
    protected function tearDown(): void
    {
        extensions()->boardFilters()->remove('test.high');
        parent::tearDown();
    }

    public function testALateContributionRendersFiltersAndRemainsClearableWhenHidden(): void
    {
        extensions()->boardFilters()->add(new BoardFilterDefinition(
            'test.high',
            'Test priority',
            static fn(mixed $value) => $value === '1',
            static fn(mixed $value, BoardFilterContext $context) => new SqlCondition(
                $context->alias . '.priority ' . ($value ? '=' : '<>') . ' ?',
                ['high'],
            ),
            150,
            'test/filter',
        ));
        $this->tickets->create($this->projectA, $this->ticketData(['priority' => 'high']));
        $this->tickets->create($this->projectA, $this->ticketData(['priority' => 'low']));

        $this->assertSame(1, $this->query->board($this->projectA, ['filters' => ['test.high' => '1']])['total']);
        $this->assertSame(1, $this->query->board($this->projectA, ['filters' => ['test.high' => '0']])['total']);
        $this->assertContains('filters[test.high]', $this->controls([]));

        settings()->save(['board_filters' => ['test.high' => false]]);
        $this->assertNotContains('filters[test.high]', $this->controls([]));
        $this->assertContains('filters[test.high]', $this->controls(['filters' => ['test.high' => '0']]));
        $this->assertSame(['test.high' => false], settings()->get('board_filters'));
    }

    private function controls(array $filters): array
    {
        $response = app()->container()->get(PageRendererInterface::class)->render('board', [
            'title' => 'Board',
            ...$this->query->board($this->projectA, $filters),
        ]);
        $this->assertSame(200, $response->getStatusCode());
        $dom = new DOMDocument();
        @$dom->loadHTML((string) $response->getBody());
        $nodes = (new DOMXPath($dom))->query('//form[@class="filterbar"]//select');

        return array_map(static fn($node) => $node->getAttribute('name'), iterator_to_array($nodes));
    }
}
