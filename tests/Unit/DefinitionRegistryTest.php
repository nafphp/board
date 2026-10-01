<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Naf\Board\Definition\PriorityDefinition;
use Naf\Board\Definition\UiContribution;
use Naf\Board\Registry\PriorityRegistry;
use Naf\Board\Registry\UiRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The rules every registry shares: one order, explicit replacement, and areas
 * of the ticket nobody may take away.
 */
final class DefinitionRegistryTest extends TestCase
{
    public function testDefinitionsAreOrderedByIndexAndThenById(): void
    {
        $registry = new PriorityRegistry();
        $registry->add(new PriorityDefinition('urgent', 'Dringend', index: 40));
        $registry->add(new PriorityDefinition('normal', 'Normal', index: 20));
        $registry->add(new PriorityDefinition('high', 'Hoch', index: 20));

        $this->assertSame(['high', 'normal', 'urgent'], $registry->keys());
    }

    public function testAnIdIsReplacedOnlyWhenAskedTo(): void
    {
        $registry = new PriorityRegistry();
        $registry->add(new PriorityDefinition('normal', 'Normal'));
        $registry->add(new PriorityDefinition('normal', 'Gewöhnlich'), replace: true);

        $this->assertSame('Gewöhnlich', $registry->get('normal')?->label);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('replace: true');

        $registry->add(new PriorityDefinition('normal', 'Anders'));
    }

    public function testRemovingSaysWhetherThereWasAnythingToRemove(): void
    {
        $registry = new PriorityRegistry();
        $registry->add(new PriorityDefinition('low', 'Niedrig'));

        $this->assertTrue($registry->remove('low'));
        $this->assertFalse($registry->remove('low'));
        $this->assertSame(0, $registry->count());
    }

    public function testARegistryTakesOnlyItsOwnKindOfDefinition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new PriorityRegistry())->add(new UiContribution('example.badge', 'board.header', 'example/badge'));
    }

    public function testAFixedTicketAreaCanBeNeitherReplacedNorRemoved(): void
    {
        $registry = new UiRegistry();

        foreach (UiRegistry::RESERVED_IDS as $id) {
            try {
                $registry->remove($id);
                $this->fail($id . ' was removed');
            } catch (LogicException $refused) {
                $this->assertStringContainsString('fixed part of Nafinity', $refused->getMessage());
            }
        }
    }
}
