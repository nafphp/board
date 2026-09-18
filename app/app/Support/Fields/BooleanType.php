<?php

declare(strict_types=1);

namespace App\Support\Fields;

use function Naf\I18n\t;

/**
 * Yes or no.
 *
 * Forms send the strings `0` and `1`; a missing checkbox is not an absent value
 * but an explicit false, which the form has to send.
 */
final class BooleanType extends AbstractFieldType
{
    public function id(): string
    {
        return 'boolean';
    }

    public function normalize(mixed $value, array $options): mixed
    {
        if ($value === null) {
            return null;
        }

        return $value === true || $value === '1' || $value === 1;
    }

    protected function check(mixed $value, array $options): array
    {
        if (is_bool($value) || $value === '0' || $value === '1' || $value === 0 || $value === 1) {
            return [];
        }

        return [t('Bitte wähle Ja oder Nein.')];
    }

    protected function hasValue(mixed $value): bool
    {
        return $value !== null;
    }
}
