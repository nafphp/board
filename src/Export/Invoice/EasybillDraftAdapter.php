<?php

declare(strict_types=1);

namespace Naf\Board\Export\Invoice;

use Naf\Board\Contracts\InvoiceDraftAdapterInterface;
use Naf\Board\Domain\Failure;
use SensitiveParameter;

use function Naf\I18n\t;

final class EasybillDraftAdapter implements InvoiceDraftAdapterInterface
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
        $data = $this->api->request('https://api.easybill.de/rest/v1/customers/' . Api::customerId($id), 'GET', 'Bearer ' . $credentials['api_key']);

        return Api::customerName($data['display_name'] ?? $data['company_name'] ?? trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')));
    }

    public function payload(array $positions, array $input, string $reference): array
    {
        return ['type'     => 'INVOICE', 'customer_id' => (int) Api::customerId($input['customer_id']),
            'currency'     => 'EUR', 'document_date' => $input['invoice_date'], 'title' => $input['title'],
            'vat_option'   => $input['tax_case'] === 'standard' ? null : 'smallBusiness',
            'service_date' => ['type' => 'SERVICE', 'date_from' => $input['service_from'], 'date_to' => $input['service_until']],
            'external_id'  => $reference, ...$positions];
    }

    public function create(array $payload, #[SensitiveParameter] array $credentials): string
    {
        // POST always creates a draft; is_draft is read-only. Never call /done.
        $data = $this->api->request('https://api.easybill.de/rest/v1/documents', 'POST', 'Bearer ' . $credentials['api_key'], $payload);
        if (($data['is_draft'] ?? null) !== true) {
            throw new Failure(t('Das Rechnungstool hat keine gültige Bestätigung geliefert.'), 502);
        }

        return Api::identifier($data['id'] ?? null);
    }
}
