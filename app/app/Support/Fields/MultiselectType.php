<?php

declare(strict_types=1);

namespace App\Support\Fields;

use function Naf\I18n\t;

/** Any number of the declared choices, without duplicates. */
final class MultiselectType extends AbstractFieldType
{
    public function id(): string
    {
        return 'multiselect';
    }

    public function normalize(mixed $value, array $options): mixed
    {
        if ($value === null) {
            return null;
        }

        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_map('strval', $values)));
    }

    protected function check(mixed $value, array $options): array
    {
        if (!is_array($value)) {
            return [t('Bitte triff eine Auswahl.')];
        }

        $choices = array_map('strval', array_keys($options['choices'] ?? []));

        foreach ($value as $entry) {
            if (!is_string($entry) && !is_int($entry)) {
                return [t('Diese Auswahl gibt es nicht.')];
            }

            if (!in_array((string) $entry, $choices, true)) {
                return [t('Diese Auswahl gibt es nicht.')];
            }
        }

        return [];
    }
}
