<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use InvalidArgumentException;
use LogicException;
use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\ExtensionContext;
use Naf\Board\ExtensionRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;
use stdClass;

/**
 * The provider pass an extension takes part in.
 *
 * Providers are noted while Composer plugins boot and run once, after the
 * board's own defaults, in an order that does not depend on how Composer
 * happened to list the packages: ascending index, ties broken by id.
 */
final class ExtensionRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        RecordingProvider::$ran = [];
    }

    public function testProvidersRunByIndexAndThenById(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('example.late', LateProvider::class, 200);
        $registry->register('example.b', SecondProvider::class);
        $registry->register('example.a', FirstProvider::class);

        $registry->initialize($this->container());

        $this->assertSame([FirstProvider::class, SecondProvider::class, LateProvider::class], RecordingProvider::$ran);
        $this->assertSame(['example.a', 'example.b', 'example.late'], $registry->executed());
    }

    public function testASecondPassRunsNothingAgain(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('example.a', FirstProvider::class);

        $registry->initialize($this->container());
        $registry->initialize($this->container());

        $this->assertSame([FirstProvider::class], RecordingProvider::$ran);
    }

    public function testRegisteringAfterThePassSaysWhereItBelongs(): void
    {
        $registry = new ExtensionRegistry();
        $registry->initialize($this->container());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('before naf/board');

        $registry->register('example.a', FirstProvider::class);
    }

    public function testAnIdIsReplacedOnlyWhenAskedTo(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('example.a', FirstProvider::class);
        $registry->register('example.a', SecondProvider::class, replace: true);
        $registry->initialize($this->container());

        $this->assertSame([SecondProvider::class], RecordingProvider::$ran);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('replace: true');

        $again = new ExtensionRegistry();
        $again->register('example.a', FirstProvider::class);
        $again->register('example.a', SecondProvider::class);
    }

    public function testAProviderHasToBeOne(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ExtensionRegistry())->register('example.a', stdClass::class);
    }

    public function testAFailingProviderNamesItselfAndItsCause(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('example.broken', FailingProvider::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Extension "example.broken" (' . FailingProvider::class . ') failed to register: no table');

        $registry->initialize($this->container());
    }

    /** Hands out every provider class it is asked for, the way the application container does. */
    private function container(): ContainerInterface
    {
        return new class implements ContainerInterface {
            public function get(string $id): object
            {
                return new $id();
            }

            public function has(string $id): bool
            {
                return class_exists($id);
            }
        };
    }
}

abstract class RecordingProvider implements ExtensionProviderInterface
{
    /** @var list<class-string> */
    public static array $ran = [];

    public function register(ExtensionContext $context): void
    {
        self::$ran[] = static::class;
    }
}

final class FirstProvider extends RecordingProvider
{
}

final class SecondProvider extends RecordingProvider
{
}

final class LateProvider extends RecordingProvider
{
}

final class FailingProvider implements ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void
    {
        throw new RuntimeException('no table');
    }
}
