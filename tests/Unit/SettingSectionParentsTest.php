<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use LogicException;
use Naf\Board\Definition\SettingSection;
use Naf\Board\Registry\SettingSectionRegistry;
use PHPUnit\Framework\TestCase;

final class SettingSectionParentsTest extends TestCase
{
    public function testParentsCanBeRegisteredLaterAndRemovedWithoutLosingTheirChildren(): void
    {
        $registry = new SettingSectionRegistry();
        $registry->add(new SettingSection('leaf', 'application', 'Leaf', parent: 'group'));
        $registry->add(new SettingSection('group', 'application', 'Group'));
        self::assertSame('group', $registry->forScope('application')['leaf']->parent);
        $registry->remove('group');
        self::assertArrayHasKey('leaf', $registry->forScope('application'));
    }

    public function testCyclesFailExplicitlyRatherThanHidingCardsOrOpeningForever(): void
    {
        $registry = new SettingSectionRegistry();
        $registry->add(new SettingSection('first', 'application', 'First', parent: 'second'));
        $registry->add(new SettingSection('second', 'application', 'Second', parent: 'first'));
        $this->expectException(LogicException::class);
        $registry->forScope('application');
    }
}
