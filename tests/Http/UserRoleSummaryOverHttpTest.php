<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use DOMDocument;
use DOMXPath;
use Naf\Board\Tests\Support\AcceptanceTestCase;
use PDO;

use function Naf\app;

final class UserRoleSummaryOverHttpTest extends AcceptanceTestCase
{
    public function testRoleChipsShowEscapedBoardNamesInsteadOfStorageIdentifiers(): void
    {
        $pdo      = app()->container()->get(PDO::class);
        $original = $pdo->query('SELECT name FROM projects WHERE id=1')->fetchColumn();
        $rename   = $pdo->prepare('UPDATE projects SET name=? WHERE id=1');
        $name     = 'Research <img src=x onerror=alert(1)> & Design';
        $rename->execute([$name]);

        try {
            $page     = $this->page($this->alice, '/settings');
            $document = new DOMDocument();
            @$document->loadHTML('<?xml encoding="UTF-8">' . $page);
            $xpath = new DOMXPath($document);
            $chips = $xpath->query('//span[contains(concat(" ", normalize-space(@class), " "), " settings-user-role ")]');
            $this->assertGreaterThan(0, $chips->length);
            $text = implode(' ', array_map(static fn($chip) => $chip->textContent, iterator_to_array($chips)));

            $this->assertStringContainsString($name, $text);
            $this->assertStringContainsString('Global', $text);
            $this->assertStringContainsString('Administrator', $text);
            $this->assertStringNotContainsString('project:', $text);
            $this->assertSame(0, $xpath->query('//span[contains(@class,"settings-user-role")]//img')->length);
        } finally {
            $rename->execute([$original]);
        }
    }
}
