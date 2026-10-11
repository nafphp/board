<?php

declare(strict_types=1);

namespace Naf\Board\Export\Invoice;

final class FastbillExporter extends JsonItemsExporter
{
    protected function property(): string
    {
        return 'ITEMS';
    }

    protected function item(array $data): array
    {
        return [
            'ARTICLE_NUMBER' => $data['ticket'], 'DESCRIPTION' => $data['name'] . "\n" . $data['description'],
            'QUANTITY'       => $data['quantity'], 'UNIT_PRICE' => $data['net_rate'],
            'VAT_PERCENT'    => $data['tax_rate'],
        ];
    }
}
