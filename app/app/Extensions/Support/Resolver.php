<?php

declare(strict_types=1);

namespace Nafinity\Support;

use Naf\Decorators\AutoResolvingContainer;
use Naf\Exceptions\ServiceNotFoundException;
use Psr\Container\ContainerInterface;

/**
 * How Nafinity resolves a class an extension named.
 *
 * A binding wins, so a replacement is honoured; anything unbound is built by
 * the container the same way a controller is. Construction errors stay visible.
 */
final class Resolver
{
    /**
     * @param ContainerInterface $container The application container
     * @param class-string       $class     The class to resolve
     */
    public static function service(ContainerInterface $container, string $class): object
    {
        if ($container->has($class)) {
            return $container->get($class);
        }

        return self::build($container, $class);
    }

    /**
     * Build a fresh instance with constructor injection, ignoring any binding
     *
     * @param ContainerInterface $container The application container
     * @param class-string       $class     The class to build
     */
    public static function build(ContainerInterface $container, string $class): object
    {
        if ($container instanceof AutoResolvingContainer) {
            return $container->make($class);
        }

        throw new ServiceNotFoundException(
            'Service "' . $class . '" cannot be built by this container.',
        );
    }
}
