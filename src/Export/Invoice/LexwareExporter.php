<?php

declare(strict_types=1);

namespace Naf\Board\Export\Invoice;

use Naf\Board\Domain\Failure;

use function Naf\I18n\t;

final class LexwareExporter extends JsonItemsExporter
{
    private int $written = 0;

    public function open(array $columns): string
    {
        $this->written = 0;

        return parent::open($columns);
    }

    protected function property(): string
    {
        return 'lineItems';
    }

    protected function item(array $data): array
    {
        if (++$this->written > 300) {
            throw new Failure(t('Lexware Office erlaubt höchstens 300 Rechnungspositionen. Bitte grenze den Buchungszeitraum weiter ein.'), 422);
        }

        return [
            'type'      => 'custom', 'name' => $data['name'], 'description' => $data['description'],
            'quantity'  => round($data['quantity'], 4), 'unitName' => 'Stunde',
            'unitPrice' => ['currency' => 'EUR', 'netAmount' => $data['net_rate'], 'taxRatePercentage' => $data['tax_rate']],
        ];
    }
}
