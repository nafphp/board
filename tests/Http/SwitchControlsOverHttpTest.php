<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use DOMDocument;
use DOMXPath;
use Naf\Board\Tests\Support\AcceptanceTestCase;

final class SwitchControlsOverHttpTest extends AcceptanceTestCase
{
    public function testAccountAndRoleControlsRenderAsLabelledNativeSwitches(): void
    {
        foreach (['/preferences', '/projects/' . self::PROJECT . '/settings'] as $path) {
            $xpath = $this->xpath($this->page($this->alice, $path));
            $this->assertGreaterThan(0, $xpath->query('//input[@role="switch"]')->length);
            $this->assertCount(0, $xpath->query('//input[@type="checkbox" and not(@role="switch")]'), $path);
            foreach ($xpath->query('//input[@role="switch"]') as $input) {
                $this->assertSame('checkbox', $input->getAttribute('type'));
                if (!$input->hasAttribute('data-ai-stream')) {
                    $this->assertNotSame('', $input->getAttribute('name'));
                }
                $this->assertCount(1, $xpath->query('ancestor::label', $input));
            }
        }
    }

    public function testRememberMeStartsOffAsANativeSwitch(): void
    {
        $xpath = $this->xpath($this->page($this->guest('switch-login'), '/login'));
        $this->assertCount(1, $xpath->query('//input[@name="remember" and @type="checkbox" and @role="switch" and not(@checked)]'));
    }

    private function xpath(string $markup): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $markup);

        return new DOMXPath($document);
    }
}
