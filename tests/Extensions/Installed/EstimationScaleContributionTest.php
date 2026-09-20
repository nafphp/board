<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Installed;

use Naf\Board\Contracts\AccessInterface;
use Naf\Board\Domain\Estimation;
use Naf\Board\Tests\Support\ExtensionInstalledTestCase;

use function Naf\app;
use function Naf\Board\extensions;

/**
 * A scale an extension brings is a scale like any other: a project can choose
 * it, and everything that reads a scale reads this one the same way. Otherwise
 * "add your own scale" would mean "add one the board cannot sum".
 */
final class EstimationScaleContributionTest extends ExtensionInstalledTestCase
{
    public function testTheContributedScaleIsRegisteredWithItsValuesAndUnit(): void
    {
        $this->assertTrue(extensions()->estimationScales()->has('example.tshirt'));
        $this->assertSame([1, 2, 3, 5, 8, 13], Estimation::values('example.tshirt'));
        $this->assertSame('TS', Estimation::unit('example.tshirt'));
    }

    public function testAProjectCanChooseItAndKeepsIt(): void
    {
        $this->projects->update($this->project, [
            'name'             => 'Extensions',
            'description'      => 'Host for the examples',
            'color'            => '#6366f1',
            'icon'             => 'N',
            'ticket_key'       => 'EXT',
            'estimation_scale' => 'example.tshirt',
        ]);

        $scope = app()->container()->get(AccessInterface::class)->project($this->project);

        $this->assertSame('example.tshirt', Estimation::scale($scope->project['estimation_scale']));
    }
}
