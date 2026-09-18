<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

use Naf\MCP\Tools\ToolInterface;

/**
 * A NAF tool plus what Nafinity's chat needs to route and authorize it.
 *
 * The extra metadata decides where a tool appears and what it needs; it never
 * grants anything. Permissions are checked before the catalogue is handed out
 * and again before the tool runs.
 */
interface ProjectToolInterface extends ToolInterface
{
    /** Short label shown in the chat. */
    public function title(): string;

    /** Project action required to see and to run this tool. */
    public function permission(): string;

    /** @return list<string> Other tool names that must be available first */
    public function requires(): array;

    /** @return list<string> Words the browser router matches against */
    public function keywords(): array;
}
