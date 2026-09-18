<?php

declare(strict_types=1);

namespace App\Ai;

use App\Domain\Failure;
use Closure;
use Nafinity\Contracts\ProjectToolInterface;

/**
 * One definition supplies the MCP schema, chat label and confirmation policy.
 *
 * The public properties stay exactly where they were so existing constructor
 * calls keep working; the contract's getters answer from the same values, which
 * is what lets a contributed tool be treated like any other.
 */
final readonly class ProjectTool implements ProjectToolInterface
{
    public function __construct(
        private string $identifier,
        private string $summary,
        private array $schema,
        private Closure $handler,
        public string $title,
        public string $permission = 'read',
        public array $requires = [],
        public array $keywords = [],
    ) {
    }

    public function name(): string
    {
        return $this->identifier;
    }

    public function description(): string
    {
        return $this->summary;
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, ...$this->schema];
    }

    public function title(): string
    {
        return $this->title;
    }

    public function permission(): string
    {
        return $this->permission;
    }

    /** @return list<string> */
    public function requires(): array
    {
        return $this->requires;
    }

    /** @return list<string> */
    public function keywords(): array
    {
        return $this->keywords;
    }

    public function handle(array $args): mixed
    {
        $properties = $this->schema['properties'] ?? [];
        if (array_diff(array_keys($args), array_keys($properties)) || array_diff($this->schema['required'] ?? [], array_keys($args))) {
            throw new Failure('Die Werkzeugargumente sind unvollständig oder ungültig.');
        }

        return ($this->handler)($args);
    }
}
