<?php

declare(strict_types=1);

namespace App\Support\Fields;

use function Naf\I18n\t;

/** Several lines of plain text. */
final class TextareaType extends AbstractFieldType
{
    public function id(): string
    {
        return 'textarea';
    }

    public function normalize(mixed $value, array $options): mixed
    {
        return $value === null ? null : rtrim((string) $value);
    }

    protected function check(mixed $value, array $options): array
    {
        if (!is_string($value)) {
            return [t('Bitte gib einen Text ein.')];
        }

        $limit = (int) ($options['max'] ?? 4000);

        if (mb_strlen($value) > $limit) {
            return [t('Höchstens :count Zeichen.', ['count' => $limit])];
        }

        return [];
    }
}
