<?php

declare(strict_types=1);

namespace Naf\Board\Tests\Support;

use Naf\Mail\Core\TransportInterface;
use Naf\Mail\Models\Mail;
use RuntimeException;

/**
 * The mailbox the test installation delivers to: one JSON file per message.
 *
 * A file rather than memory because the sender is often another process -- the
 * web server answering an HTTP test, or a worker draining the queue -- and the
 * test that wants to read the code has to see what that process sent.
 */
final class Outbox implements TransportInterface
{
    public function sendMail(Mail $mail): bool
    {
        $directory = self::directory();
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $file = $directory . '/' . sprintf('%.6f', microtime(true)) . '-' . bin2hex(random_bytes(4)) . '.json';
        file_put_contents($file, json_encode([
            'to'      => $mail->getRecipients(),
            'subject' => $mail->getSubject(),
            'content' => $mail->getContent(),
        ], JSON_THROW_ON_ERROR));

        return true;
    }

    /** @return list<array{to:list<string>,subject:string,content:string}> newest last */
    public static function messagesTo(string $email): array
    {
        $found = [];
        foreach (glob(self::directory() . '/*.json') ?: [] as $file) {
            $message = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            if (in_array($email, $message['to'], true)) {
                $found[] = $message;
            }
        }

        return $found;
    }

    /** The verification code in the newest message to this address. */
    public static function codeFor(string $email): string
    {
        foreach (array_reverse(self::messagesTo($email)) as $message) {
            if (preg_match('/Bestätigungscode: ([A-Z2-9-]+)/u', strip_tags($message['content']), $found)) {
                return $found[1];
            }
        }

        throw new RuntimeException('No verification message reached ' . $email . '.');
    }

    public static function forget(): void
    {
        foreach (glob(self::directory() . '/*.json') ?: [] as $file) {
            unlink($file);
        }
    }

    private static function directory(): string
    {
        return BASE_PATH . '/storage/mail';
    }
}
