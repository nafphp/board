<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use DOMDocument;
use DOMXPath;
use Naf\Board\Definition\TicketFieldDefinition;
use Naf\Board\Domain\ProjectScope;
use Naf\Board\Support\Fields\TicketFieldVisibilityType;
use Naf\Board\Support\InlineFieldRenderer;
use Naf\Board\Support\TicketSlotContext;
use Naf\Board\Support\UiContext;
use PHPUnit\Framework\TestCase;

use function Naf\Board\extensions;
use function Naf\Board\partial;

final class TicketFieldVisibilityTest extends TestCase
{
    protected function tearDown(): void
    {
        extensions()->ticketFields()->remove('test.visible');
        parent::tearDown();
    }

    public function testContributedFieldsUseTheExistingRegistryAndEscapedSwitchRenderer(): void
    {
        $type = new TicketFieldVisibilityType();
        $this->assertNotSame([], $type->validate(['test.visible' => false], []));
        $definition = new TicketFieldDefinition('test.visible', 'Extra <field>', 'text');
        extensions()->ticketFields()->add($definition);

        $this->assertSame([], $type->validate(['test.visible' => false], []));
        $this->assertSame(['test.visible' => false], $type->normalize(['test.visible' => '0'], []));
        $markup = partial('fields/ticket_fields', [
            'name' => 'values[ticket_fields]', 'fields' => [$definition], 'value' => [], 'disabled' => false,
        ]);
        $this->assertStringContainsString('Extra &lt;field&gt;', $markup);
        $this->assertStringContainsString('name="values[ticket_fields][test.visible]" value="0"', $markup);
        $this->assertMatchesRegularExpression('/role="switch"[^>]+name="values\[ticket_fields\]\[test.visible\]"[^>]+checked/', $markup);
    }

    public function testDistinctFieldKeysKeepDistinctLabelTargets(): void
    {
        $markup = partial('fields/ticket_fields', [
            'name'   => 'values[ticket_fields]',
            'fields' => [
                new TicketFieldDefinition('test.example', 'First field', 'text'),
                new TicketFieldDefinition('test-example', 'Second field', 'text'),
            ],
            'value' => [], 'disabled' => false,
        ]);
        $dom = new DOMDocument();
        @$dom->loadHTML($markup);
        $xpath    = new DOMXPath($dom);
        $switches = $xpath->query('//input[@role="switch"]');
        $ids      = array_map(static fn($node) => $node->getAttribute('id'), iterator_to_array($switches));
        $this->assertCount(2, array_unique($ids));
        foreach ($switches as $switch) {
            $this->assertSame($switch->getAttribute('id'), $switch->parentNode->getAttribute('for'));
        }
    }

    public function testMalformedValuesAndFixedTicketAreasAreRefused(): void
    {
        $type = new TicketFieldVisibilityType();
        foreach ([false, 'priority', ['title' => false], ['description' => true], ['comments' => false], ['priority' => []], ['priority' => 'yes']] as $invalid) {
            $this->assertNotSame([], $type->validate($invalid, []));
        }
        $this->assertSame([], $type->validate(['priority' => false, 'due_date' => '1'], []));
    }

    public function testRemovedFieldsLeaveStoredChoicesHarmlessAndReinstallationRestoresThem(): void
    {
        $type   = new TicketFieldVisibilityType();
        $stored = ['test.visible' => false];
        $this->assertSame([], $type->normalize($stored, []));
        extensions()->ticketFields()->add(new TicketFieldDefinition('test.visible', 'Extra', 'text'));
        $this->assertSame($stored, $type->normalize($stored, []));
    }

    public function testSlotFilteringKeepsPermissionsAndCreationFields(): void
    {
        $definition = new TicketFieldDefinition('test.visible', 'Extra', 'text', readPermission: 'manage');
        extensions()->ticketFields()->add($definition);
        $viewer = $this->context(['priority' => false, 'test.visible' => true], [$definition->key => $definition]);
        $this->assertFalse($viewer->shows('priority'));
        $this->assertFalse($viewer->shows('test.visible'));
        $this->assertSame([], $viewer->fieldsIn('details'));
        $this->assertTrue($viewer->shows('due_date'), 'An omitted field should remain visible.');
        $this->assertTrue($this->context(['priority' => false], [], true)->shows('priority'));
        $this->assertFalse($viewer->showsGroup('not-installed'));
    }

    private function context(array $visibility, array $fields, bool $creating = false): TicketSlotContext
    {
        $project = ['id' => 1, 'archived_at' => null];
        $scope   = new ProjectScope($project, '1', 'viewer');

        return new TicketSlotContext(
            ui: new UiContext(1, $scope),
            ticket: [],
            project: $project,
            scope: $scope,
            board: [],
            params: [],
            token: '',
            editable: false,
            isNew: $creating,
            columns: [],
            swimlanes: [],
            labels: [],
            members: [],
            metadata: [],
            fields: $fields,
            links: [],
            attachments: [],
            activity: [],
            timer: [],
            preferences: [],
            creator: '',
            field: new InlineFieldRenderer(static fn() => ''),
            fieldVisibility: $visibility,
        );
    }
}
