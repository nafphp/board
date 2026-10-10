<?php

declare(strict_types=1);

namespace Naf\Board\Support\Fields;

use function Naf\I18n\t;

/** Secrets are literal: leading/trailing whitespace must not be trimmed. */
final class SecretType extends AbstractFieldType
{
    public function id(): string
    {
        return 'secret';
    }

    public function view(): string
    {
        return 'fields/text';
    }

    public function normalize(mixed $value, array $options): mixed
    {
        return $value;
    }

    protected function check(mixed $value, array $options): array
    {
        return is_string($value) && strlen($value) <= 16384
            ? [] : [t('Bitte gib einen Text mit höchstens 16384 Bytes ein.')];
    }
}
