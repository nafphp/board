<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use Naf\Board\Tests\Support\HttpClient;

final class ProjectNotificationPreferencesOverHttpTest extends AcceptanceTestCase
{
    protected function tearDown(): void
    {
        foreach ([$this->alice, $this->viewer] as $client) {
            $this->post($client, '/api/projects/' . self::PROJECT . '/settings/user', [
                'values' => ['muted' => false],
            ]);
        }
        parent::tearDown();
    }

    public function testTheProfileOffersMuteControlsForMembershipsOnAnyPage(): void
    {
        $form = $this->muteForm($this->alice, '/projects');
        $this->assertSame('/api/projects/' . self::PROJECT . '/settings/user', $form->getAttribute('action'));
        $this->assertSame('post', $form->getAttribute('method'));

        $xpath = new DOMXPath($form->ownerDocument);
        $this->assertCount(1, $xpath->query('.//input[@type="hidden" and @name="values[muted]" and @value="0"]', $form));
        $this->assertCount(1, $xpath->query('.//input[@role="switch" and @name="values[muted]"]', $form));
        $this->assertCount(1, $xpath->query('.//button[@type="submit" and not(@hidden)]', $form));
        $this->assertCount(0, $xpath->query('//dialog[@id="profile-dialog"]//form[@data-project-notification="' . self::OTHER_PROJECT . '"]'));
    }

    public function testMutingAndEnablingAProjectPersistsForOnlyTheCurrentAccount(): void
    {
        $form  = $this->muteForm($this->alice);
        $token = (new DOMXPath($form->ownerDocument))->query('.//input[@name="_csrf"]', $form)->item(0)->getAttribute('value');

        foreach ([true, false] as $muted) {
            $saved = $this->post($this->alice, $form->getAttribute('action'), ['values' => ['muted' => $muted]], $token);
            $this->assertSame(200, $saved['status'], $saved['body']);
            $this->assertSame($muted, json_decode($saved['body'], true)['values']['muted']);
            $this->assertSame($muted, $this->isMuted($this->alice));
            $this->assertFalse($this->isMuted($this->viewer));
        }
    }

    public function testTheNativeFormCanMuteAndEnableWithoutJavaScript(): void
    {
        $form  = $this->muteForm($this->alice);
        $token = (new DOMXPath($form->ownerDocument))->query('.//input[@name="_csrf"]', $form)->item(0)->getAttribute('value');

        foreach (['1', '0'] as $value) {
            $body  = http_build_query(['_csrf' => $token, 'values' => ['muted' => $value]]);
            $saved = $this->alice->request($form->getAttribute('action'), $body, ['Content-Type: application/x-www-form-urlencoded']);
            $this->assertSame(303, $saved['status']);
            $this->assertSame($value === '1', $this->isMuted($this->alice));
        }
    }

    public function testMutingRequiresCsrfAndDoesNotAllowForeignProjects(): void
    {
        $form  = $this->muteForm($this->viewer);
        $saved = $this->viewer->request($form->getAttribute('action'), ['values' => ['muted' => true]], [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        $this->assertSame(400, $saved['status']);
        $this->assertFalse($this->isMuted($this->viewer));

        $foreign = $this->post($this->viewer, '/api/projects/' . self::OTHER_PROJECT . '/settings/user', ['values' => ['muted' => true]]);
        $this->assertSame(404, $foreign['status']);
    }

    private function muteForm(HttpClient $client, string $path = '/projects/' . self::PROJECT): DOMElement
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($this->page($client, $path));
        $forms = (new DOMXPath($dom))->query('//dialog[@id="profile-dialog"]//form[@data-project-notification="' . self::PROJECT . '"]');
        $this->assertCount(1, $forms);

        return $forms->item(0);
    }

    private function isMuted(HttpClient $client): bool
    {
        $form  = $this->muteForm($client);
        $input = (new DOMXPath($form->ownerDocument))->query('.//input[@role="switch"]', $form)->item(0);

        return $input->hasAttribute('checked');
    }
}
