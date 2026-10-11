<?php

declare(strict_types=1);

namespace Naf\Board\Export\Invoice;

use Naf\Board\Contracts\InvoiceDraftAdapterInterface;
use SensitiveParameter;

final class FastbillDraftAdapter implements InvoiceDraftAdapterInterface
{
    public function __construct(private Api $api)
    {
    }

    public function fields(): array
    {
        return [];
    }

    private function request(array $payload, #[SensitiveParameter] array $credentials): array
    {
        return $this->api->request('https://my.fastbill.com/api/1.0/api.php', 'POST', 'Basic ' . base64_encode($credentials['email'] . ':' . $credentials['api_key']), $payload);
    }

    public function customer(string $id, #[SensitiveParameter] array $credentials): string
    {
        $data     = $this->request(['SERVICE' => 'customer.get', 'FILTER' => ['CUSTOMER_ID' => Api::customerId($id)], 'LIMIT' => 1], $credentials);
        $customer = $data['RESPONSE']['CUSTOMERS'][0] ?? [];

        return Api::customerName($customer['ORGANIZATION'] ?? trim(($customer['FIRST_NAME'] ?? '') . ' ' . ($customer['LAST_NAME'] ?? '')));
    }

    public function payload(array $positions, array $input, string $reference): array
    {
        return ['SERVICE' => 'invoice.create', 'DATA' => [
            'CUSTOMER_ID'   => Api::customerId($input['customer_id']), 'CURRENCY_CODE' => 'EUR', 'IS_GROSS' => 0,
            'INVOICE_DATE'  => $input['invoice_date'], 'SERVICE_PERIOD_START' => $input['service_from'], 'SERVICE_PERIOD_END' => $input['service_until'],
            'VAT_CASE'      => $input['tax_case'] === 'standard' ? 'standard' : 'small_business_regulation',
            'INVOICE_TITLE' => $input['title'], 'INTROTEXT' => $reference, ...$positions,
        ]];
    }

    public function create(array $payload, #[SensitiveParameter] array $credentials): string
    {
        $data = $this->request($payload, $credentials);

        return Api::identifier($data['RESPONSE']['INVOICE_ID'] ?? null);
    }
}
