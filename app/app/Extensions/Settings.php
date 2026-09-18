<?php

declare(strict_types=1);

namespace Nafinity;

use Naf\Support\Collection;
use Nafinity\Contracts\AccessInterface;
use Nafinity\Contracts\SettingsServiceInterface;
use Nafinity\Support\SettingsContext;

use function Naf\app;

/**
 * The PHP way to read and write declared settings.
 *
 * The facade holds nothing but which values it is about. A context method
 * returns a new instance, so `settings()->forProject($id)` never changes what
 * the next `settings()->get()` means. There is no shortcut to somebody else's
 * personal values: user context is always the person who is signed in.
 */
final readonly class Settings
{
    /**
     * @param SettingsContext|null $context The chosen context, or the signed-in user
     */
    public function __construct(private ?SettingsContext $context = null)
    {
    }

    /**
     * One value; an unknown key falls back to the caller's default
     *
     * @param string $key     Literal key, dots included
     * @param mixed  $default Returned only when the key is not registered here
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->service()->get($this->context(), $key, $default);
    }

    /**
     * Every readable, non-sensitive value of this context
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->service()->all($this->context());
    }

    /**
     * Whether the key is registered and readable here, even when its value is null
     *
     * @param string $key Literal key
     */
    public function has(string $key): bool
    {
        return $this->service()->has($this->context(), $key);
    }

    /**
     * The same values as a NAF collection.
     *
     * This is a snapshot. The collection reads `null` as absent and `add()` on it
     * stores nothing; use this facade when either of those matters.
     */
    public function collection(): Collection
    {
        return new Collection($this->all());
    }

    /**
     * Validate and store a subset of this context's values
     *
     * @param array<string, mixed> $values    Values to store
     * @param list<string>         $resetKeys Keys reset to their definition default
     */
    public function save(array $values, array $resetKeys = []): void
    {
        $this->service()->save($this->context(), $values, $resetKeys);
    }

    /**
     * The project's own settings; membership is checked here
     *
     * @param int $projectId The project to read
     */
    public function forProject(int $projectId): self
    {
        $this->access()->project($projectId);

        return new self(SettingsContext::project($projectId));
    }

    /**
     * This person's settings inside one project
     *
     * @param int $projectId The project to read
     */
    public function forProjectUser(int $projectId): self
    {
        $this->access()->project($projectId);

        return new self(SettingsContext::projectUser($projectId, $this->access()->actor()));
    }

    /** The declared, read-only configuration values of the installation. */
    public function forApplication(): self
    {
        return new self(SettingsContext::application());
    }

    /** Which values this instance is about. */
    public function context(): SettingsContext
    {
        return $this->context ?? SettingsContext::user($this->access()->actor());
    }

    private function service(): SettingsServiceInterface
    {
        return app()->container()->get(SettingsServiceInterface::class);
    }

    private function access(): AccessInterface
    {
        return app()->container()->get(AccessInterface::class);
    }
}
