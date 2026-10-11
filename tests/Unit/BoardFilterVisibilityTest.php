<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use Naf\Board\Definition\BoardFilterDefinition;
use Naf\Board\Support\Fields\BoardFilterVisibilityType;
use Naf\Board\Support\SqlCondition;
use PHPUnit\Framework\TestCase;

use function Naf\Board\extensions;
use function Naf\Board\partial;

final class BoardFilterVisibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        extensions()->boardFilters()->remove('test.visible');
        extensions()->boardFilters()->remove('test.query-only');
        parent::tearDown();
    }

    public function testLateRegisteredFiltersAppearInTheSwitchListAndValidateWithoutChangingCoreSettings(): void
    {
        $type = new BoardFilterVisibilityType();
        $this->assertNotSame([], $type->validate(['test.visible' => false], []));
        extensions()->boardFilters()->add($this->filter('test.visible', 'Test <filter>', 'board/filter'));

        $this->assertSame([], $type->validate(['test.visible' => false], []));
        $this->assertSame(['test.visible' => false], $type->normalize(['test.visible' => '0'], []));
        $markup = partial('fields/board_filters', ['name' => 'values[board_filters]', 'value' => [], 'disabled' => false]);
        $this->assertStringContainsString('Test &lt;filter&gt;', $markup);
        $this->assertMatchesRegularExpression('/role="switch"\s+name="values\[board_filters\]\[test.visible\]"\s+value="1"\s+checked/', $markup);
    }

    public function testQueryOnlyFiltersAndSearchAreNotOfferedAsVisibilitySwitches(): void
    {
        extensions()->boardFilters()->add($this->filter('test.query-only', 'Query only', null));
        $controls = extensions()->boardFilters()->controls();
        $this->assertArrayNotHasKey('q', $controls);
        $this->assertArrayNotHasKey('test.query-only', $controls);
        $this->assertNotSame([], (new BoardFilterVisibilityType())->validate(['test.query-only' => true], []));
    }

    public function testRemovedFiltersLeaveStoredDataHarmlessAndCanBeAddedAgain(): void
    {
        $type   = new BoardFilterVisibilityType();
        $stored = ['test.visible' => false];
        $this->assertSame([], $type->normalize($stored, []));
        extensions()->boardFilters()->add($this->filter('test.visible', 'Test filter', 'board/filter'));
        $this->assertSame($stored, $type->normalize($stored, []));
    }

    public function testQueryOnlyValuesKeepNestedFieldsAndExplicitFalse(): void
    {
        $markup = partial('board/filter-value', ['name' => 'filters[test.query-only]', 'value' => ['checked' => false, 'ids' => [2, 3]]]);
        $this->assertStringContainsString('name="filters[test.query-only][checked]" value="0"', $markup);
        $this->assertStringContainsString('name="filters[test.query-only][ids][0]" value="2"', $markup);
        $this->assertStringContainsString('name="filters[test.query-only][ids][1]" value="3"', $markup);
    }

    private function filter(string $id, string $label, ?string $view): BoardFilterDefinition
    {
        return new BoardFilterDefinition(
            $id,
            $label,
            static fn(mixed $value) => $value,
            static fn(mixed $value) => new SqlCondition('1=?', [$value]),
            150,
            $view,
        );
    }
}
