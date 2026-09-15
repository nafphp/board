<?php

declare(strict_types=1);

namespace App\Ai;

use App\Domain\Failure;
use Closure;
use Naf\MCP\Tools\ToolInterface;

/** One definition supplies the MCP schema, chat label and confirmation policy. */
final readonly class ProjectTool implements ToolInterface
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

    public function handle(array $args): mixed
    {
        $properties = $this->schema['properties'] ?? [];
        if (array_diff(array_keys($args), array_keys($properties)) || array_diff($this->schema['required'] ?? [], array_keys($args))) {
            throw new Failure('Die Werkzeugargumente sind unvollständig oder ungültig.');
        }

        return ($this->handler)($args);
    }
}
