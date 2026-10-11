<?php

declare(strict_types=1);

namespace Naf\Board\Export\Invoice;

use Naf\Board\Contracts\InvoiceDraftAdapterInterface;
use SensitiveParameter;

final class LexwareDraftAdapter implements InvoiceDraftAdapterInterface
{
    public function __construct(private Api $api)
    {
    }

    public function fields(): array
    {
        return [];
    }

    public function customer(string $id, #[SensitiveParameter] array $credentials): string
    {
        $data = $this->api->request('https://api.lexware.io/v1/contacts/' . Api::customerId($id, true), 'GET', 'Bearer ' . $credentials['api_key']);

        return Api::customerName($data['company']['name'] ?? trim(($data['person']['firstName'] ?? '') . ' ' . ($data['person']['lastName'] ?? '')));
    }

    public function payload(array $positions, array $input, string $reference): array
    {
        return [
            'voucherDate' => $input['invoice_date'] . 'T00:00:00.000Z',
            'address'     => ['contactId' => Api::customerId($input['customer_id'], true)],
            ...$positions,
            'totalPrice'         => ['currency' => 'EUR'],
            'taxConditions'      => ['taxType' => $input['tax_case'] === 'standard' ? 'net' : 'vatfree'],
            'shippingConditions' => ['shippingType' => 'serviceperiod', 'shippingDate' => $input['service_from'] . 'T00:00:00.000Z', 'shippingEndDate' => $input['service_until'] . 'T00:00:00.000Z'],
            'title'              => $input['title'], 'remark' => $reference,
        ];
    }

    public function create(array $payload, #[SensitiveParameter] array $credentials): string
    {
        $data = $this->api->request('https://api.lexware.io/v1/invoices?finalize=false', 'POST', 'Bearer ' . $credentials['api_key'], $payload);

        return Api::identifier($data['id'] ?? null, true);
    }
}
