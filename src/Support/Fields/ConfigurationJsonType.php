<?php

declare(strict_types=1);

namespace Naf\Board\Support\Fields;

use JsonException;

use function Naf\I18n\t;

/** Explicit JSON for lists, structured values and non-integer numbers. */
final class ConfigurationJsonType extends AbstractFieldType
{
    public function id(): string
    {
        return 'configuration_json';
    }

    public function normalize(mixed $value, array $options): mixed
    {
        return is_string($value) ? json_decode($value, true, 64, JSON_THROW_ON_ERROR) : $value;
    }

    protected function check(mixed $value, array $options): array
    {
        try {
            $normalized = $this->normalize($value, $options);
            if (strlen(json_encode($normalized, JSON_THROW_ON_ERROR)) > 16384) {
                return [t('Wert zu groß.')];
            }
            if (isset($options['shape']) && get_debug_type($normalized) !== $options['shape']) {
                return [t('Der JSON-Wert hat einen anderen Datentyp als die Konfiguration.')];
            }

            return [];
        } catch (JsonException) {
            return [t('Bitte gib gültiges JSON ein.')];
        }
    }
}
