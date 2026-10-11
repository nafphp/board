<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use DOMDocument;
use DOMXPath;
use Naf\Board\Tests\Support\AcceptanceTestCase;

final class ProjectDeletionOverHttpTest extends AcceptanceTestCase
{
    public function testGeneralSettingsGroupSubtleArchiveAndCollapsedDeletionForOwners(): void
    {
        $dom = new DOMDocument();
        @$dom->loadHTML($this->page($this->alice, '/projects/' . self::PROJECT . '/settings'));
        $xpath = new DOMXPath($dom);
        $this->assertCount(1, $xpath->query('//section[contains(@class,"project-management")]'));
        $this->assertCount(0, $xpath->query('//section[contains(@class,"project-management")]//p[@class="settings-hint"]'));
        $this->assertCount(1, $xpath->query('//details[@class="project-delete" and not(@open)]//input[@name="confirmation" and @required]'));
        $this->assertCount(1, $xpath->query('//form[@action="/projects/' . self::PROJECT . '/delete"]//button[@data-confirm]'));
        $viewer = $this->page($this->viewer, '/projects/' . self::PROJECT . '/settings');
        $this->assertStringNotContainsString('class="settings-section project-management"', $viewer);
        $this->assertStringNotContainsString('class="project-delete"', $viewer);
    }

    public function testDeletionRequiresCsrfOwnershipAndTheExactNameThenRedirectsToProjects(): void
    {
        $name    = 'Disposable HTTP project';
        $created = $this->post($this->alice, '/projects', ['name' => $name, 'description' => 'Delete test']);
        $this->assertSame(200, $created['status'], $created['body']);
        $url      = json_decode($created['body'], true)['url'];
        $settings = $this->page($this->alice, $url . '/settings');
        $token    = $this->alice->token($settings);

        $noCsrf = $this->alice->request($url . '/delete', ['confirmation' => $name], ['Content-Type: application/json', 'Accept: application/json']);
        $this->assertSame(400, $noCsrf['status']);
        $wrong = $this->post($this->alice, $url . '/delete', ['confirmation' => 'Wrong'], $token);
        $this->assertSame(422, $wrong['status']);
        $foreign = $this->post($this->bob, $url . '/delete', ['confirmation' => $name], $this->bob->token($this->page($this->bob, '/projects/' . self::OTHER_PROJECT)));
        $this->assertSame(404, $foreign['status']);
        $added = $this->post($this->alice, $url . '/members', ['email' => 'viewer@example.test', 'role' => 'manager'], $token);
        $this->assertSame(200, $added['status'], $added['body']);
        $manager = $this->post($this->viewer, $url . '/delete', ['confirmation' => $name]);
        $this->assertSame(403, $manager['status']);
        $this->assertStringNotContainsString('class="project-delete"', $this->page($this->viewer, $url . '/settings'));

        $saved = $this->post($this->alice, $url . '/delete', ['confirmation' => $name], $token);
        $this->assertSame(200, $saved['status'], $saved['body']);
        $this->assertSame('/projects', json_decode($saved['body'], true)['url']);
        $this->assertSame(404, $this->alice->request($url)['status']);
        $this->assertSame(404, $this->post($this->alice, $url . '/delete', ['confirmation' => $name], $token)['status']);
    }

    public function testArchivedProjectCanBeDeletedWithANativeForm(): void
    {
        $name    = 'Disposable archived HTTP project';
        $created = $this->post($this->alice, '/projects', ['name' => $name, 'description' => 'Delete test']);
        $this->assertSame(200, $created['status'], $created['body']);
        $url      = json_decode($created['body'], true)['url'];
        $token    = $this->alice->token($this->page($this->alice, $url));
        $archived = $this->post($this->alice, $url . '/archive', ['action' => 'archive'], $token);
        $this->assertSame(200, $archived['status'], $archived['body']);
        $this->assertStringContainsString('class="project-delete"', $this->page($this->alice, $url . '/settings'));
        $deleted = $this->alice->request($url . '/delete', http_build_query(['_csrf' => $token, 'confirmation' => $name]), ['Content-Type: application/x-www-form-urlencoded']);
        $this->assertSame(303, $deleted['status'], $deleted['body']);
        $this->assertStringContainsString('Location: /projects', $deleted['headers']);
        $this->assertSame(404, $this->alice->request($url)['status']);
    }
}
