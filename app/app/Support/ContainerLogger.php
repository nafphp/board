<?php

declare(strict_types=1);

namespace App\Support;

use Naf\Core\Log;
use Stringable;

/** Send NAF's PSR-3 messages through PHP/FPM to the bounded Compose log transport. */
final class ContainerLogger extends Log
{
    public function __construct()
    {
        parent::__construct(BASE_PATH.'/storage/logs/app.log');
    }

    public function log($level, mixed $message, array $context = []): void
    {
        $text = (string)$message;
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null || $value instanceof Stringable) {
                $text = str_replace('{'.$key.'}', (string)$value, $text);
            }
        }
        error_log(json_encode(['level' => $level, 'message' => $text], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
