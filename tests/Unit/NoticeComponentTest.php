<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Unit;

use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

use function Naf\View\view;

final class NoticeComponentTest extends TestCase
{
    public function testSeverityHasAnIconAndAccessibleMeaningWithoutDependingOnColour(): void
    {
        foreach (['notice' => 'notifications', 'info' => 'info', 'warning' => 'warning', 'error' => 'error'] as $severity => $icon) {
            $xpath  = $this->xpath(view('components/notice', ['message' => 'Example', 'severity' => $severity]));
            $notice = $xpath->query('//div[contains(@class,"ui-notice--")]')->item(0);
            $this->assertSame($severity === 'error' ? 'alert' : 'note', $notice->getAttribute('role'));
            $this->assertStringContainsString('ui-notice--' . $severity, $notice->getAttribute('class'));
            $this->assertSame($icon, $xpath->query('//span[@aria-hidden="true"]')->item(0)->textContent);
            $this->assertNotEmpty(trim($xpath->query('//span[@class="sr-only"]')->item(0)->textContent));
        }
    }

    public function testMessageTitleAndDynamicAttributeValuesRemainPlainText(): void
    {
        $message = '<img src=x onerror="alert(1)"> & "message"';
        $title   = '<script>alert(1)</script>';
        $xpath   = $this->xpath(view('components/notice', [
            'message'           => $message,
            'title'             => $title,
            'messageAttributes' => ['id' => 'text" onclick="alert(1)', 'data-account-status' => true, 'hidden' => false],
        ]));
        $this->assertSame($message, $xpath->query('//p[@class="ui-notice__message"]')->item(0)->textContent);
        $this->assertSame($title, $xpath->query('//p[@class="ui-notice__title"]')->item(0)->textContent);
        $this->assertSame('text" onclick="alert(1)', $xpath->query('//p[@data-account-status]')->item(0)->getAttribute('id'));
        $this->assertCount(0, $xpath->query('//img | //script | //*[@onclick] | //*[@hidden]'));
    }

    public function testUnknownSeverityFallsBackToInformationWithoutInjectingClasses(): void
    {
        $xpath = $this->xpath(view('components/notice', ['message' => 'Example', 'severity' => 'error" onclick="alert(1)']));
        $this->assertCount(1, $xpath->query('//div[@class="ui-notice ui-notice--info" and @role="note"]'));
        $this->assertCount(0, $xpath->query('//*[@onclick] | //*[@role="alert"]'));
    }

    public function testDynamicMessageHookPreservesItsStatusRoleAndDoesNotContainTheIcon(): void
    {
        $xpath = $this->xpath(view('components/notice', [
            'severity'          => 'notice',
            'messageAttributes' => ['data-account-status' => true, 'role' => 'status'],
        ]));
        $this->assertCount(1, $xpath->query('//p[@data-account-status and @role="status"]'));
        $this->assertSame('', $xpath->query('//p[@data-account-status]')->item(0)->textContent);
        $this->assertCount(0, $xpath->query('//p[@data-account-status]/*'));
        $this->assertCount(1, $xpath->query('//span[@aria-hidden="true"]'));
    }

    private function xpath(string $markup): DOMXPath
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $markup);

        return new DOMXPath($document);
    }
}
