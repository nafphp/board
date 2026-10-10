<?php

declare(strict_types=1);

namespace Naf\Board\Export;

use Naf\Board\Domain\Failure;
use Naf\Board\Support\Input;

use function Naf\I18n\t;

/** Explicit EUR net pricing; no remembered or guessed tax treatment. */
final readonly class InvoiceExportOptions
{
    private function __construct(
        public int $project,
        public string $format,
        public TimeExportOptions $dates,
        public int $rateCents,
        public int $taxBasisPoints,
        public ?int $sevdeskUnity,
    ) {
    }

    public static function fromInput(array $input): self
    {
        $format = Input::validate($input, ['format' => 'required|string|max:80'])['format'];
        $rate   = self::decimal($input['rate'] ?? null, 'rate', 9999999);
        $tax    = self::decimal($input['tax'] ?? null, 'tax', 10000);
        $unity  = $input['sevdesk_unity'] ?? '';
        $unity  = $unity === '' ? null : Input::id($unity, 'sevdesk_unity');
        if ($format === 'invoice.sevdesk' && $unity === null) {
            throw new Failure(t('Bitte gib die ID der Stunden-Einheit aus deinem sevdesk-Konto an.'), 422);
        }
        if ($format === 'invoice.lexware' && !in_array($tax, [0, 500, 700, 1600, 1900], true)) {
            throw new Failure(t('Dieser Steuersatz wird von Lexware Office nicht unterstützt.'), 422);
        }

        return new self(Input::id($input['project'] ?? null, 'project'), $format, TimeExportOptions::fromInput($input), $rate, $tax, $unity);
    }

    private static function decimal(mixed $value, string $field, int $maximum): int
    {
        if (!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,4})(?:[.,]([0-9]{1,2}))?$/D', $value, $parts)) {
            throw new Failure(t('Bitte gib einen nicht negativen Dezimalwert mit höchstens zwei Nachkommastellen ein.'), 422, [$field => [t('Ungültiger Dezimalwert.')]]);
        }
        $number = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');
        if ($number > $maximum) {
            throw new Failure(t('Dieser Dezimalwert ist zu groß.'), 422, [$field => [t('Wert zu groß.')]]);
        }

        return $number;
    }
}
