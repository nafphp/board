<?php

declare(strict_types=1);

namespace App\Support\Fields;

use DateTimeImmutable;

use function Naf\I18n\t;

/** A calendar day, written as YYYY-MM-DD. */
final class DateType extends AbstractFieldType
{
    public function id(): string
    {
        return 'date';
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
        if ($value === '') {
            return ($options['nullable'] ?? false) === true
                ? []
                : [t('Bitte gib ein Datum an.')];
        }

        if (!is_string($value)) {
            return [t('Bitte gib ein Datum im Format JJJJ-MM-TT an.')];
        }

        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($parsed === false || $parsed->format('Y-m-d') !== $value) {
            return [t('Bitte gib ein Datum im Format JJJJ-MM-TT an.')];
        }

        return [];
    }
}
