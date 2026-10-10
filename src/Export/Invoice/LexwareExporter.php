<?php

declare(strict_types=1);

namespace Naf\Board\Export\Invoice;

final class LexwareExporter extends JsonItemsExporter
{
    protected function property(): string
    {
        return 'lineItems';
    }

    protected function item(array $data): array
    {
        return [
            'type'      => 'custom', 'name' => $data['name'], 'description' => $data['description'],
            'quantity'  => round($data['quantity'], 4), 'unitName' => 'Stunde',
            'unitPrice' => ['currency' => 'EUR', 'netAmount' => $data['net_rate'], 'taxRatePercentage' => $data['tax_rate']],
        ];
    }
}
