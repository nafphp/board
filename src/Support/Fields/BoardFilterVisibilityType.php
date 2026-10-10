<?php

declare(strict_types=1);

namespace Naf\Board\Support\Fields;

use function Naf\Board\extensions;
use function Naf\I18n\t;

/**
 * Personal visibility by filter id. An omitted id is visible, including newly
 * installed filters. Resolve controls at request time, after every provider and
 * the host's extensions.php have had their turn.
 *
 * @internal
 */
final class BoardFilterVisibilityType extends AbstractFieldType
{
    public function id(): string
    {
        return 'board_filters';
    }

    public function normalize(mixed $value, array $options): array
    {
        $boolean = new BooleanType();
        $visible = [];

        foreach (array_intersect_key((array) $value, extensions()->boardFilters()->controls()) as $id => $show) {
            $visible[$id] = $boolean->normalize($show, []);
        }

        return $visible;
    }

    protected function check(mixed $value, array $options): array
    {
        if (!is_array($value)) {
            return [t('Bitte wähle die sichtbaren Boardfilter.')];
        }

        $controls = extensions()->boardFilters()->controls();
        $boolean  = new BooleanType();

        foreach ($value as $id => $show) {
            if (!is_string($id) || !isset($controls[$id])) {
                return [t('Diesen Boardfilter gibt es nicht.')];
            }

            if ($boolean->validate($show, []) !== []) {
                return [t('Bitte wähle Ja oder Nein.')];
            }
        }

        return [];
    }
}
