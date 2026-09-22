<?php

declare(strict_types=1);

namespace Naf\Board\Export;

use DateTimeImmutable;
use DateTimeZone;
use Naf\Board\Domain\Failure;

use function Naf\I18n\t;

/** Validated filters shared by board and installation downloads. */
final readonly class ExportOptions
{
    private function __construct(
        public string $status,
        public string $archive,
        public ?string $updatedFrom,
        public ?string $updatedBefore,
        public bool $description,
        public bool $metadata,
    ) {
    }

    public static function fromInput(array $input): self
    {
        $status  = self::choice($input, 'status', ['all', 'open', 'closed'], 'all');
        $archive = self::choice($input, 'archive', ['all', 'exclude', 'only'], 'all');
        $from    = self::date($input, 'updated_from');
        $until   = self::date($input, 'updated_until');
        if ($from !== null && $until !== null && $from > $until) {
            throw new Failure(t('Der Beginn des Zeitraums darf nicht nach seinem Ende liegen.'));
        }

        return new self(
            $status,
            $archive,
            $from?->format('Y-m-d 00:00:00'),
            $until?->modify('+1 day')->format('Y-m-d 00:00:00'),
            self::choice($input, 'description', ['0', '1'], '0') === '1',
            self::choice($input, 'metadata', ['0', '1'], '1') === '1',
        );
    }

    private static function choice(array $input, string $key, array $allowed, string $default): string
    {
        $value = $input[$key] ?? $default;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new Failure(t('Ungültige Exportoption: :field', ['field' => $key]));
        }

        return $value;
    }

    private static function date(array $input, string $key): ?DateTimeImmutable
    {
        $value = $input[$key] ?? '';
        if ($value === '') {
            return null;
        }
        if (!is_string($value) || !preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D', $value)) {
            throw new Failure(t('Bitte gib ein gültiges Datum im Format JJJJ-MM-TT an.'));
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d') !== $value || (int) $date->format('Y') > 9998) {
            throw new Failure(t('Bitte gib ein gültiges Datum im Format JJJJ-MM-TT an.'));
        }

        return $date;
    }
}
