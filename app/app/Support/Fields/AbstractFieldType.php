<?php

declare(strict_types=1);

namespace App\Support\Fields;

use Nafinity\Contracts\FieldTypeInterface;

use function Naf\I18n\t;

/**
 * Shared rules of every built-in value type.
 *
 * Validation always runs on the raw value first: a type says which raw shapes
 * it accepts, and only then is the value normalized. Nothing invalid is ever
 * quietly turned into a default.
 */
abstract class AbstractFieldType implements FieldTypeInterface
{
    public function view(): string
    {
        return 'fields/' . $this->id();
    }

    public function module(): ?string
    {
        return null;
    }

    public function validate(mixed $value, array $options): array
    {
        if ($value === null) {
            return ($options['nullable'] ?? false) === true
                ? []
                : [t('Dieses Feld darf nicht leer sein.')];
        }

        $messages = $this->check($value, $options);

        if ($messages !== []) {
            return $messages;
        }

        if (($options['required'] ?? false) === true && !$this->hasValue($this->normalize($value, $options))) {
            return [t('Dieses Feld wird benötigt.')];
        }

        return [];
    }

    /**
     * Whether a normalized value counts as present for a required field
     *
     * Zero and false are values; only nothing is nothing.
     *
     * @param mixed $value The normalized value
     */
    protected function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * Check the raw value of this type
     *
     * @param mixed $value   Raw value, never null here
     * @param array $options Definition options
     *
     * @return list<string> Messages; empty when the value is acceptable
     */
    abstract protected function check(mixed $value, array $options): array;
}
