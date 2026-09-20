<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use LogicException;
use Naf\Board\Definition\ViewOverride;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

use function Naf\Board\extensions;

/**
 * Replacing a template by name.
 *
 * An extension may point a logical view somewhere else, and the installation may
 * point it back -- whoever registers last wins, and the host registers after
 * every package. That order is the whole mechanism: it is how an installation
 * keeps the final say over what its users see.
 */
final class ViewOverrideTest extends ExtensionInstalledTestCase
{
    public function testAPackageOverridesAnotherPackagesView(): void
    {
        $this->assertSame('example-b/reports', extensions()->views()->resolve('example-a/reports'));
    }

    public function testTheHostWinsLast(): void
    {
        try {
            extensions()->views()->add(new ViewOverride('example-a/reports', 'example-a/reports'), true);

            $this->assertSame('example-a/reports', extensions()->views()->resolve('example-a/reports'));
        } finally {
            extensions()->views()->add(new ViewOverride('example-a/reports', 'example-b/reports'), true);
        }
    }

    /**
     * Two overrides pointing at each other would resolve forever. It is reported
     * as a cycle rather than left to exhaust the stack, because the message is
     * the only thing that tells an operator which two views to look at.
     */
    public function testAMappingCycleIsReportedRatherThanFollowed(): void
    {
        extensions()->views()->add(new ViewOverride('example/cycle-one', 'example/cycle-two'));
        extensions()->views()->add(new ViewOverride('example/cycle-two', 'example/cycle-one'));

        try {
            extensions()->views()->resolve('example/cycle-one');
            $this->fail('a cycle resolved');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('cycle', $exception->getMessage());
        } finally {
            extensions()->views()->remove('example/cycle-one');
            extensions()->views()->remove('example/cycle-two');
        }
    }
}
