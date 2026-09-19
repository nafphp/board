<?php

declare(strict_types=1);

namespace Naf\Board\Domain;

use RuntimeException;

final class Failure extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly array $errors = [],
    ) {
        parent::__construct($message, $status);
    }
}
