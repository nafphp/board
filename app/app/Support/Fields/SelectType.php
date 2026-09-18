<?php

declare(strict_types=1);

namespace App\Support\Fields;

use function Naf\I18n\t;

/** Exactly one of the declared choices. */
final class SelectType extends AbstractFieldType
{
    public function id(): string
    {
        return 'select';
    }

    public function normalize(mixed $value, array $options): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    protected function check(mixed $value, array $options): array
    {
        $choices = array_map('strval', array_keys($options['choices'] ?? []));

        if ($value === '') {
            return ($options['nullable'] ?? false) === true
                ? []
                : [t('Bitte triff eine Auswahl.')];
        }

        if (!is_string($value) && !is_int($value)) {
            return [t('Bitte triff eine Auswahl.')];
        }

        if (!in_array((string) $value, $choices, true)) {
            return [t('Diese Auswahl gibt es nicht.')];
        }

        return [];
    }
}
