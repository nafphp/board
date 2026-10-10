<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;
use PDO;

use function Naf\app;

final class PersonalExportOverHttpTest extends AcceptanceTestCase
{
    public function testPersonalExportAppearsOnBoardAndAccountPages(): void
    {
        foreach (['/projects/' . self::PROJECT, '/preferences', '/settings'] as $path) {
            $page = $this->page($this->alice, $path);
            $this->assertStringContainsString('data-profile-section-id="personal_export"', $page);
            $this->assertStringContainsString('action="/profile/export"', $page);
            foreach (['csv', 'json', 'txt', 'pdf'] as $format) {
                $this->assertStringContainsString('value="' . $format . '"', $page);
            }
        }
    }

    public function testEveryOfferedFormatDownloadsWithPrivateHeaders(): void
    {
        foreach (['csv' => 'text/csv', 'json' => 'application/json', 'txt' => 'text/plain', 'pdf' => 'application/pdf'] as $format => $mime) {
            $answer = $this->alice->request('/profile/export?source=time&project=all&format=' . $format);
            $this->assertSame(200, $answer['status'], $answer['body']);
            $this->assertStringContainsString($mime, $answer['headers']);
            $this->assertStringContainsString('attachment; filename="hours-', $answer['headers']);
            $this->assertStringContainsString('no-store', $answer['headers']);
            $this->assertStringContainsString('nosniff', $answer['headers']);
            if ($format === 'pdf') {
                $this->assertStringStartsWith('%PDF-', $answer['body']);
                $this->assertStringContainsString('%%EOF', $answer['body']);
            }
        }
    }

    public function testAStoppedTimerDownloadsOnlyForItsActualAuthor(): void
    {
        $url = $this->createTicket(['title' => 'Personal HTTP hours export']);
        $this->assertSame(200, $this->post($this->alice, $url . '/timer', ['action' => 'start'])['status']);
        $pdo = app()->container()->get(PDO::class);
        $pdo->exec("UPDATE ticket_timers SET started_at=NULL,tracked_seconds=120,state='paused'
            WHERE project_id=1 AND ticket_id=(SELECT id FROM tickets WHERE title='Personal HTTP hours export' AND project_id=1)
              AND user_id=(SELECT id FROM users WHERE email='alice@example.test')");
        $this->assertSame(200, $this->post($this->alice, $url . '/timer', ['action' => 'stop'])['status']);
        $answer = $this->alice->request('/profile/export?format=json&project=1');
        $rows   = json_decode($answer['body'], true, flags: JSON_THROW_ON_ERROR);
        $own    = array_values(array_filter($rows, static fn(array $row): bool => $row['title'] === 'Personal HTTP hours export'));
        $this->assertCount(1, $own);
        $this->assertSame(2, $own[0]['minutes']);
        $this->assertSame(0.033333, $own[0]['hours']);
        $aliceId = (int) $pdo->query("SELECT id FROM users WHERE email='alice@example.test'")->fetchColumn();
        $others  = $this->viewer->request('/profile/export?format=json&project=1&user_id=' . $aliceId);
        $this->assertSame(200, $others['status']);
        $this->assertStringNotContainsString('Personal HTTP hours export', $others['body']);
    }

    public function testScopeCannotBeBroadenedByAUserParameterOrAStrangersProject(): void
    {
        $base  = '/profile/export?format=json&project=' . self::PROJECT;
        $own   = $this->alice->request($base);
        $other = $this->alice->request($base . '&user_id=2&user=2');
        $this->assertSame(200, $other['status']);
        $this->assertSame($own['body'], $other['body']);
        $this->assertSame(404, $this->bob->request($base)['status']);
        $this->assertSame(200, $this->viewer->request($base)['status']);
    }

    public function testMalformedDatesSelectionsAndUnsupportedFormatsDoNotDownload(): void
    {
        foreach (['format[]=pdf', 'format=json&project[]=all', 'format=json&from=2026-02-30',
            'format=json&from[]=2026-09-01', 'format=json&from=2026-10-01&until=2026-09-01'] as $query) {
            $answer = $this->alice->request('/profile/export?' . $query);
            $this->assertSame(422, $answer['status'], $query);
            $this->assertStringNotContainsString('attachment;', $answer['headers']);
        }
        $this->assertSame(404, $this->alice->request('/profile/export?format=missing')['status']);
        $this->assertSame(404, $this->alice->request('/profile/export?format=json&source=tickets')['status']);
        $this->assertSame(404, $this->alice->request('/projects/' . self::PROJECT . '/export/pdf')['status']);
        $guest = $this->guest('personal-export-guest');
        $this->assertSame(303, $guest->request('/profile/export?format=pdf')['status']);
    }
}
