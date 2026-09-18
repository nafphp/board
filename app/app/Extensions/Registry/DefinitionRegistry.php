<?php

declare(strict_types=1);

namespace Nafinity\Registry;

use InvalidArgumentException;
use LogicException;

/**
 * Shared behaviour of every Nafinity contribution registry.
 *
 * Identity is the definition id. Duplicates need an explicit replacement, an
 * unknown removal is simply false, and every listing is ordered by `index`
 * ascending with the id as the tie-breaker, so two plugins that pick the same
 * index still produce one stable order.
 */
abstract class DefinitionRegistry
{
    /** @var array<string, object> */
    private array $definitions = [];

    /**
     * @param class-string $definitionClass Accepted definition type
     * @param string       $label           Human readable name used in diagnostics
     */
    public function __construct(private string $definitionClass, private string $label)
    {
    }

    /**
     * Add a definition, optionally replacing the one registered under its id
     *
     * @param object $definition The definition to register
     * @param bool   $replace    Whether an existing definition may be replaced
     */
    public function add(object $definition, bool $replace = false): void
    {
        if (!$definition instanceof $this->definitionClass) {
            throw new InvalidArgumentException(sprintf(
                '%s expects %s, got %s.',
                $this->label,
                $this->definitionClass,
                $definition::class,
            ));
        }

        $id = $this->identify($definition);

        if ($id === '') {
            throw new InvalidArgumentException($this->label . ' needs a non-empty id.');
        }

        $this->guardReserved($id, $definition);

        if (array_key_exists($id, $this->definitions) && !$replace) {
            throw new LogicException(sprintf(
                '%s "%s" is already registered. Pass replace: true to replace it.',
                $this->label,
                $id,
            ));
        }

        $this->definitions[$id] = $definition;
    }

    /**
     * @param string $id Definition id
     *
     * @return object|null The definition, or null when it is not registered
     */
    public function get(string $id): ?object
    {
        return $this->definitions[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->definitions);
    }

    /**
     * All definitions, ordered by index and id, keyed by id
     *
     * @return array<string, object>
     */
    public function all(): array
    {
        $ordered = $this->definitions;

        uasort($ordered, fn($left, $right) => $this->compare($left, $right));

        return $ordered;
    }

    /**
     * @param string $id Definition id
     *
     * @return bool True when a definition was removed
     */
    public function remove(string $id): bool
    {
        $this->guardReserved($id, $this->definitions[$id] ?? null);

        if (!array_key_exists($id, $this->definitions)) {
            return false;
        }

        unset($this->definitions[$id]);

        return true;
    }

    public function count(): int
    {
        return count($this->definitions);
    }

    /**
     * The registry key of a definition
     *
     * @param object $definition The definition to identify
     */
    protected function identify(object $definition): string
    {
        return (string) $definition->id;
    }

    /**
     * The sort value of a definition
     *
     * @param object $definition The definition to order
     */
    protected function indexOf(object $definition): int
    {
        return (int) $definition->index;
    }

    /**
     * Reject ids this registry protects; the default registry protects none
     *
     * @param string      $id         The id about to be added or removed
     * @param object|null $definition The incoming definition, or null on removal
     */
    protected function guardReserved(string $id, ?object $definition): void
    {
    }

    private function compare(object $left, object $right): int
    {
        return $this->indexOf($left) <=> $this->indexOf($right)
            ?: strcmp($this->identify($left), $this->identify($right));
    }
}
