<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Contracts\AiServiceInterface;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

use function Naf\app;

/**
 * A tool an extension contributes is offered on the same terms as the
 * application's own: only to someone who holds the right it was declared with.
 * The assistant is never shown a tool the person asking may not use.
 */
final class AiToolContributionTest extends ExtensionInstalledTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->reviewerRole();
    }

    public function testTheContributedToolIsWithheldWithoutTheGrant(): void
    {
        $names = array_column($this->ai()->definitions($this->project), 'name');

        $this->assertContains('nafinity_board', $names, 'a core tool is missing');
        $this->assertNotContains('example_reports', $names, 'the tool appeared without the grant');
    }

    public function testTheContributedToolAppearsWithTheGrant(): void
    {
        $this->actAs('reviewer');

        $names = array_column($this->ai()->definitions($this->project), 'name');

        $this->assertContains('example_reports', $names);
    }

    private function ai(): AiServiceInterface
    {
        return app()->container()->get(AiServiceInterface::class);
    }
}
