<?php

declare(strict_types=1);

namespace Nafinity\Contracts;

/**
 * One value type shared by settings and ticket metadata.
 *
 * Validation checks the accepted raw shape first and the normalized domain
 * afterwards, so invalid input is reported instead of being swallowed by a
 * normalization that quietly produces a default.
 */
interface FieldTypeInterface
{
    /** The registry id of this type, for example `boolean`. */
    public function id(): string;

    /**
     * Convert accepted raw input into the stored representation
     *
     * @param mixed $value   Raw value from a form, JSON body or definition default
     * @param array $options Definition options
     */
    public function normalize(mixed $value, array $options): mixed;

    /**
     * @param mixed $value   Raw value, before normalization
     * @param array $options Definition options
     *
     * @return list<string> Messages; an empty list means the value is valid
     */
    public function validate(mixed $value, array $options): array;

    /** Logical view name rendering an input for this type. */
    public function view(): string;

    /** Public URL of a browser module mounted for this type, when it needs one. */
    public function module(): ?string;
}
