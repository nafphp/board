<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Extensions\Order;

use PHPUnit\Framework\TestCase;

use function Naf\app;
use function Naf\Board\extensions;

/**
 * The same two extensions, booted in the opposite order.
 *
 * NAF boots Composer plugins in the order an application lists them, and this
 * host lists extension B first. What must not change is the order the providers
 * run in: that is decided by their index and their id, so B still replaces what
 * A registered rather than racing it. Otherwise which extension wins would
 * depend on what Composer happened to write down, which is alphabetical.
 */
final class PluginOrderTest extends TestCase
{
    public function testTheHostReallyDidBootThemTheOtherWayRound(): void
    {
        $booted = array_keys(app()->getPlugins());

        $a = array_search('example/nafinity-extension-a', $booted, true);
        $b = array_search('example/nafinity-extension-b', $booted, true);

        $this->assertNotFalse($a, 'extension A was not booted at all');
        $this->assertNotFalse($b, 'extension B was not booted at all');
        $this->assertLessThan($a, $b, 'the listing was not swapped: ' . implode(', ', $booted));
    }

    public function testTheProvidersStillRunInIndexAndIdOrder(): void
    {
        $this->assertSame(['example.reports', 'example.review'], extensions()->executed());
    }

    public function testWhatBReplacedIsStillReplaced(): void
    {
        $widget = extensions()->ui()->get('example.reports.widget');

        $this->assertNotNull($widget, 'the widget is gone');
        $this->assertSame('example-b/ticket-widget', $widget->template);
        $this->assertSame(
            'example.review',
            extensions()->settings()->find('project', 'example.reports.limit')?->section,
            "the settings field went back to A's card",
        );
        $this->assertSame(
            'example-b/reports',
            extensions()->views()->resolve('example-a/reports'),
            'the view override is gone',
        );
    }

    public function testWhatOnlyARegistersIsStillThere(): void
    {
        $this->assertTrue(extensions()->permissions()->has('example.reports.view'));
        $this->assertNotNull(extensions()->ticketFields()->get('example.external_id'));
        $this->assertNotNull(extensions()->boardFilters()->get('example.reviewed'));
        $this->assertNotNull(extensions()->navigation()->get('example.reports.link'));
        $this->assertTrue(extensions()->estimationScales()->has('example.tshirt'));
    }
}
