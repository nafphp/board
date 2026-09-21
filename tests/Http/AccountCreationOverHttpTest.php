<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Http;

use Naf\Board\Tests\Support\AcceptanceTestCase;

/**
 * Opening an account for somebody else.
 *
 * It used to need a shell on the server, which meant that adding a colleague
 * was an operations task. It is a right now, and a right is something an
 * installation can hand out without handing out everything else.
 */
final class AccountCreationOverHttpTest extends AcceptanceTestCase
{
    public function testSomebodyWithTheRightOpensOne(): void
    {
        $address  = 'neu-' . bin2hex(random_bytes(4)) . '@example.test';
        $response = $this->create($this->alice, $address);

        $this->assertContains($response['status'], [200, 303], $response['body']);
        $this->assertStringContainsString(
            $address,
            $this->alice->request('/settings')['body'],
            'the account was not listed afterwards',
        );
    }

    public function testSomebodyWithoutItIsRefused(): void
    {
        $response = $this->create($this->viewer, 'verboten-' . bin2hex(random_bytes(4)) . '@example.test');

        $this->assertSame(403, $response['status']);
    }

    /** The same rules as anywhere else: a weak password is refused, not shortened. */
    public function testAPasswordThatIsTooWeakIsRefused(): void
    {
        $response = $this->create(
            $this->alice,
            'schwach-' . bin2hex(random_bytes(4)) . '@example.test',
            'kurz',
        );

        $this->assertSame(422, $response['status']);
    }

    /** @return array{status:int, body:string} */
    private function create($client, string $email, string $password = 'Ein-langes-Passwort-2026!'): array
    {
        // From a page this person can actually open: somebody without the right
        // never sees the settings, and a test that asks them to would be testing
        // the wrong refusal.
        $page = $client->request('/preferences')['body'];

        return $client->request('/settings/users', [
            '_csrf'    => $client->token($page),
            'name'     => 'Neu Angelegt',
            'email'    => $email,
            'password' => $password,
        ]);
    }
}
