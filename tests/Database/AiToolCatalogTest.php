<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Database;

use Naf\Board\Tests\Support\AiProjectTestCase;

/**
 * What the assistant is offered is what the person asking may do.
 *
 * The catalog is filtered by the caller's rights rather than by the model's
 * judgement, so a tool it never sees is a tool it cannot be talked into using.
 */
final class AiToolCatalogTest extends AiProjectTestCase
{
    public function testTheCatalogOffersOnlyToolsTheCallerMayUse(): void
    {
        $this->actAs($this->member);

        $names = array_column($this->ai->definitions($this->project), 'name');

        $this->assertContains('nafinity_comment', $names, 'a tool the role allows is missing');
        $this->assertNotContains('nafinity_ticket_create', $names, 'a write tool leaked into the catalog');
    }

    /**
     * A tool that needs another one is worse than useless without it: the
     * assistant would plan a step it cannot finish.
     */
    public function testNoOfferedToolDependsOnOneThatWasWithheld(): void
    {
        $this->actAs($this->member);

        $definitions = $this->ai->definitions($this->project);
        $names       = array_column($definitions, 'name');

        foreach ($definitions as $definition) {
            $this->assertSame(
                [],
                array_diff($definition['meta']['requires'], $names),
                $definition['name'] . ' needs a tool that is not authorized',
            );
        }
    }

    public function testThereIsNoCatalogForAProjectTheCallerIsNotIn(): void
    {
        $this->actAs($this->member);

        $this->assertDenied(404, fn() => $this->ai->definitions($this->elsewhere));
    }
}
