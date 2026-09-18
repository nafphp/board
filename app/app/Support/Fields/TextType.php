<?php

declare(strict_types=1);

namespace App\Support\Fields;

use function Naf\I18n\t;

/** A single line of text. */
final class TextType extends AbstractFieldType
{
    public function id(): string
    {
        return 'text';
    }

    public function normalize(mixed $value, array $options): mixed
    {
        return $value === null ? null : trim((string) $value);
    }

    protected function check(mixed $value, array $options): array
    {
        if (!is_string($value)) {
            return [t('Bitte gib einen Text ein.')];
        }

        $limit = (int) ($options['max'] ?? 190);

        if (mb_strlen(trim($value)) > $limit) {
            return [t('Höchstens :count Zeichen.', ['count' => $limit])];
        }

        return [];
    }
}
