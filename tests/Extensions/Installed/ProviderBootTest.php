<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Example\ExtensionA\ExtensionAProvider;
use LogicException;
use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\ExtensionContext;
use Naf\Board\ExtensionRegistry;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;
use RuntimeException;

use function Naf\app;
use function Naf\Board\extensions;

/** A provider that fails on purpose, to show what a failure looks like. */
final class BrokenProvider implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        throw new RuntimeException('deliberately broken');
    }
}

/**
 * Booting the extensions.
 *
 * They run after the application's own defaults and in the order their index and
 * id give them, never in whatever order Composer happened to list the packages.
 * Registration is a pass that closes: afterwards the set is fixed, so nothing can
 * quietly appear halfway through a request.
 */
final class ProviderBootTest extends ExtensionInstalledTestCase
{
    public function testBothExtensionsBootInIndexOrder(): void
    {
        $this->assertTrue(app()->hasPlugin('naf/board'), 'The host must install the board, not copy its bootstrap.');
        $this->assertTrue(app()->getPlugins()['naf/board']->isBooted());
        $this->assertSame(['example.reports', 'example.review'], extensions()->executed());
    }

    public function testTheApplicationsOwnDefinitionsAreStillThere(): void
    {
        $this->assertTrue(extensions()->permissions()->has('write'), 'a core permission was overwritten');
        $this->assertTrue(extensions()->permissions()->has(ExtensionAProvider::PERMISSION));
    }

    public function testInitialisingASecondTimeDoesNotRunTheProvidersAgain(): void
    {
        $before = extensions()->executed();

        extensions()->initialize(app()->container());

        $this->assertSame($before, extensions()->executed(), 'the providers ran twice');
    }

    public function testRegisteringAfterThePassSaysWhichExtensionWasLate(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/example\.too\.late/');

        extensions()->register('example.too.late', ExtensionAProvider::class);
    }

    /**
     * A broken extension stops the boot rather than leaving a half-configured
     * application running, and the message names both the extension and what
     * went wrong -- otherwise the operator is left with a stack trace pointing
     * into the framework.
     */
    public function testAFailingProviderNamesItselfAndItsCause(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('example.broken', BrokenProvider::class);

        try {
            $registry->initialize(app()->container());
            $this->fail('a broken provider did not stop the boot');
        } catch (LogicException $exception) {
            $this->assertStringContainsString('example.broken', $exception->getMessage());
            $this->assertStringContainsString('deliberately broken', $exception->getMessage());
        }
    }
}
