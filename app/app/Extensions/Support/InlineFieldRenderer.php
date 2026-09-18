<?php

declare(strict_types=1);

namespace Nafinity\Support;

use Closure;

/**
 * Renders one editable field the way the ticket already renders its own.
 *
 * A contributed panel that wants an editable value should not build a form: it
 * asks for one here and gets the existing inline editor, with its auto-save,
 * its draft handling, its keyboard behaviour and its version check.
 */
final readonly class InlineFieldRenderer
{
    public function __construct(private Closure $renderer)
    {
    }

    /**
     * @param string $name    Form field name
     * @param string $label   Translated label
     * @param string $type    One of the inline editor's input types
     * @param mixed  $value   Current value
     * @param string $display Already escaped read-only representation
     * @param array  $options Choices, for select and multiple
     * @param array  $extra   Overrides such as action, hidden, readonly
     */
    public function render(
        string $name,
        string $label,
        string $type,
        mixed $value,
        string $display,
        array $options = [],
        array $extra = [],
    ): string {
        return ($this->renderer)($name, $label, $type, $value, $display, $options, $extra);
    }
}
