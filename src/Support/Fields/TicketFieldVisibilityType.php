<?php

declare(strict_types=1);

namespace Naf\Board\Support\Fields;

use function Naf\Board\extensions;
use function Naf\I18n\t;

/** Personal visibility of registered ticket fields; omitted fields stay visible. @internal */
final class TicketFieldVisibilityType extends AbstractFieldType
{
    public function id(): string
    {
        return 'ticket_fields';
    }

    public function normalize(mixed $value, array $options): array
    {
        $boolean = new BooleanType();
        $visible = [];

        foreach (array_intersect_key((array) $value, extensions()->ticketFields()->all()) as $key => $show) {
            $visible[$key] = $boolean->normalize($show, []);
        }

        return $visible;
    }

    protected function check(mixed $value, array $options): array
    {
        if (!is_array($value)) {
            return [t('Bitte wähle die sichtbaren Ticketattribute.')];
        }

        $fields  = extensions()->ticketFields()->all();
        $boolean = new BooleanType();

        foreach ($value as $key => $show) {
            if (!is_string($key) || !isset($fields[$key])) {
                return [t('Dieses Ticketattribut gibt es nicht.')];
            }

            if ($boolean->validate($show, []) !== []) {
                return [t('Bitte wähle Ja oder Nein.')];
            }
        }

        return [];
    }
}
