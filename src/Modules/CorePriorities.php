<?php

declare(strict_types=1);

namespace Naf\Board\Modules;

use Naf\Board\Contracts\ExtensionProviderInterface;
use Naf\Board\Definition\PriorityDefinition;
use Naf\Board\ExtensionContext;

/**
 * The four priorities the board brings.
 *
 * They sit in the registry as ordinary entries, declared exactly the way a
 * package declares a fifth. Writing them as an enum instead would have made the
 * board's own four a second, closed truth beside the open one -- and then
 * "add a priority" would have meant two different things depending on who asks.
 *
 * A new priority is a row below: id, label, symbol and where it sorts. The
 * indices leave room, so one can be slotted between two without renumbering.
 *
 * @internal
 */
final class CorePriorities implements ExtensionProviderInterface
{
    /** @var list<array{string, string, string, int}> id, label, Material symbol, sort key */
    private const array PRIORITIES = [
        ['low', 'Niedrig', 'arrow_downward', 100],
        ['normal', 'Normal', 'remove', 200],
        ['high', 'Hoch', 'arrow_upward', 300],
        ['urgent', 'Dringend', 'keyboard_double_arrow_up', 400],
    ];

    public function register(ExtensionContext $context): void
    {
        foreach (self::PRIORITIES as [$id, $label, $icon, $index]) {
            $context->priorities()->add(new PriorityDefinition($id, $label, $icon, $index));
        }
    }
}
