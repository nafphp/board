<?php

declare(strict_types=1);

namespace Naf\Board\Export\Invoice;

final class SevdeskExporter extends JsonItemsExporter
{
    private int $position = 0;

    public function open(array $columns): string
    {
        $this->position = 0;

        return parent::open($columns);
    }

    protected function property(): string
    {
        return 'invoicePosSave';
    }

    protected function item(array $data): array
    {
        return [
            'objectName'     => 'InvoicePos', 'mapAll' => true,
            'name'           => $data['name'], 'text' => $data['description'],
            'quantity'       => $data['quantity'], 'price' => $data['net_rate'], 'taxRate' => $data['tax_rate'],
            'unity'          => ['id' => $data['sevdesk_unity'], 'objectName' => 'Unity'],
            'positionNumber' => $this->position++,
        ];
    }
}
