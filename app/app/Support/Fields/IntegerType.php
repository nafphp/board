<?php

declare(strict_types=1);

namespace App\Support\Fields;

use function Naf\I18n\t;

/** A whole number within the declared range. */
final class IntegerType extends AbstractFieldType
{
    public function id(): string
    {
        return 'integer';
    }

    public function normalize(mixed $value, array $options): mixed
    {
        return $value === null ? null : (int) $value;
    }

    protected function check(mixed $value, array $options): array
    {
        $isCanonical = is_int($value)
            || (is_string($value) && preg_match('/^-?(0|[1-9][0-9]*)$/D', $value) === 1);

        if (!$isCanonical) {
            return [t('Bitte gib eine ganze Zahl ein.')];
        }

        $number = (int) $value;

        if (isset($options['min']) && $number < (int) $options['min']) {
            return [t('Mindestens :count.', ['count' => (int) $options['min']])];
        }

        if (isset($options['max']) && $number > (int) $options['max']) {
            return [t('Höchstens :count.', ['count' => (int) $options['max']])];
        }

        return [];
    }

    protected function hasValue(mixed $value): bool
    {
        return $value !== null;
    }
}
