<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use RuntimeException;

/**
 * The local mailbox the test installation sends to.
 *
 * Mail is the one step of a verification that leaves the application, so the
 * only honest way to check that a code arrives is to go and read it where it
 * arrived. Mailpit is a real SMTP server with an API, which makes "the message
 * reached a mailbox" a thing a test can assert rather than assume.
 */
final class Mailpit
{
    public function __construct(private string $base = 'http://mailpit:8025')
    {
    }

    /** @return list<array<string,mixed>> the messages addressed to someone */
    public function messagesTo(string $email): array
    {
        $query = http_build_query(['query' => 'to:' . $email]);
        $body  = $this->get('/api/v1/search?' . $query);

        return json_decode($body, true)['messages'] ?? [];
    }

    /**
     * The verification code in the newest message to this address.
     *
     * Waited for, because sending is not instant and a test that read once
     * would be a race rather than a check.
     */
    public function codeFor(string $email, int $attempts = 30): string
    {
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            foreach ($this->messagesTo($email) as $message) {
                $text = json_decode($this->get('/api/v1/message/' . $message['ID']), true)['Text'] ?? '';
                if (preg_match('/Bestätigungscode: ([A-Z2-9-]+)/u', $text, $found)) {
                    return $found[1];
                }
            }
            usleep(200000);
        }

        throw new RuntimeException('No verification message reached ' . $email . '.');
    }

    public function forget(): void
    {
        $handle = curl_init($this->base . '/api/v1/messages');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
        ]);
        curl_exec($handle);
    }

    private function get(string $path): string
    {
        $handle = curl_init($this->base . $path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5]);
        $body = curl_exec($handle);

        if ($body === false) {
            throw new RuntimeException('Mailpit did not answer: ' . curl_error($handle));
        }

        return (string) $body;
    }
}
