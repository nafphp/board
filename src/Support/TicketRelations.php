<?php

declare(strict_types=1);

namespace Naf\Board\Support;

use Naf\Board\Domain\Failure;

use function Naf\I18n\t;

/** One vocabulary for stored relationships and their two reading directions. @internal */
final class TicketRelations
{
    private const array TYPES = [
        'relates'    => ['steht in Beziehung zu', 'steht in Beziehung zu'],
        'blocks'     => ['blockiert', 'wird blockiert von'],
        'duplicates' => ['dupliziert', 'wird dupliziert von'],
        'clones'     => ['klont', 'wird geklont von'],
        'resolves'   => ['löst', 'wird gelöst von'],
    ];

    public static function options(): array
    {
        $options = [];
        foreach (self::TYPES as $type => $labels) {
            $options[$type] = t($labels[0]);
            if ($labels[0] !== $labels[1]) {
                $options[$type . '_by'] = t($labels[1]);
            }
        }

        return $options;
    }

    /** @return array{string, bool} */
    public static function parse(mixed $value): array
    {
        if (!is_string($value) || !array_key_exists($value, self::options())) {
            throw new Failure(t('Bitte wähle eine gültige Beziehung.'), 422);
        }
        $inverse = str_ends_with($value, '_by');

        return [$inverse ? substr($value, 0, -3) : $value, $inverse];
    }

    public static function label(string $type, bool $inverse): string
    {
        return t((self::TYPES[$type] ?? self::TYPES['relates'])[(int) $inverse]);
    }
}
