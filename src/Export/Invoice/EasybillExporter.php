<?php

declare(strict_types=1);

namespace Naf\Board\Export\Invoice;

final class EasybillExporter extends JsonItemsExporter
{
    protected function property(): string
    {
        return 'items';
    }

    protected function item(array $data): array
    {
        return [
            'type'             => 'POSITION', 'itemType' => 'SERVICE',
            'number'           => $data['ticket'], 'description' => $data['name'] . "\n" . $data['description'],
            'quantity'         => $data['quantity'], 'unit' => 'Stunde',
            'single_price_net' => $data['rate_cents'], 'vat_percent' => $data['tax_rate'],
        ];
    }
}
