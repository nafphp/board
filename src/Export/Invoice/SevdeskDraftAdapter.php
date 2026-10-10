<?php

declare(strict_types=1);

namespace Naf\Board\Export\Invoice;

use Naf\Board\Contracts\InvoiceDraftAdapterInterface;
use Naf\Board\Domain\Failure;
use Naf\Board\Support\Input;
use SensitiveParameter;

use function Naf\I18n\t;

/** sevdesk system 2.0 tax rules; the account supplies its own unit, user and country ids. */
final class SevdeskDraftAdapter implements InvoiceDraftAdapterInterface
{
    public function __construct(private Api $api)
    {
    }

    public function fields(): array
    {
        return ['sevdesk_unity' => 'sevdesk: ID der Stunden-Einheit', 'sevdesk_user' => 'sevdesk: Ansprechpartner-ID', 'sevdesk_country' => 'sevdesk: Länder-ID der Rechnungsadresse'];
    }

    public function customer(string $id, #[SensitiveParameter] array $credentials): string
    {
        $data    = $this->api->request('https://my.sevdesk.de/api/v1/Contact/' . Api::customerId($id), 'GET', $credentials['api_key']);
        $contact = $data['objects'][0] ?? [];

        return Api::customerName($contact['name'] ?? trim(($contact['surename'] ?? '') . ' ' . ($contact['familyname'] ?? '')));
    }

    public function payload(array $positions, array $input, string $reference): array
    {
        if ($input['service_until'] > $input['invoice_date']) {
            throw new Failure(t('Bei sevdesk darf der Leistungszeitraum nicht nach dem Rechnungsdatum enden.'), 422);
        }
        $date = static fn(string $value): string => substr($value, 8, 2) . '.' . substr($value, 5, 2) . '.' . substr($value, 0, 4);

        return ['invoice' => [
            'objectName'        => 'Invoice', 'mapAll' => true, 'status' => '100', 'invoiceType' => 'RE',
            'contact'           => ['id' => (int) Api::customerId($input['customer_id']), 'objectName' => 'Contact'],
            'contactPerson'     => ['id' => Input::id($input['sevdesk_user'] ?? null, 'sevdesk_user'), 'objectName' => 'SevUser'],
            'addressCountry'    => ['id' => Input::id($input['sevdesk_country'] ?? null, 'sevdesk_country'), 'objectName' => 'StaticCountry'],
            'invoiceDate'       => $date($input['invoice_date']), 'deliveryDate' => $date($input['service_from']),
            'deliveryDateUntil' => strtotime($input['service_until'] . 'T00:00:00Z'),
            'currency'          => 'EUR', 'showNet' => true, 'discount' => 0, 'taxRate' => 0,
            'taxRule'           => ['id' => $input['tax_case'] === 'standard' ? '1' : '11', 'objectName' => 'TaxRule'],
            'taxText'           => $input['tax_case'] === 'standard' ? 'Umsatzsteuer ' . $input['tax'] . '%' : 'Keine Umsatzsteuer nach §19 UStG',
            'header'            => $input['title'], 'headText' => $reference,
        ], ...$positions, 'invoicePosDelete' => null, 'discountSave' => [], 'discountDelete' => null, 'takeDefaultAddress' => true];
    }

    public function create(array $payload, #[SensitiveParameter] array $credentials): string
    {
        $data    = $this->api->request('https://my.sevdesk.de/api/v1/Invoice/Factory/saveInvoice', 'POST', $credentials['api_key'], $payload);
        $invoice = $data['objects']['invoice'] ?? $data['invoice'] ?? null;
        if (!is_array($invoice) || (string) ($invoice['status'] ?? '') !== '100') {
            throw new Failure(t('Das Rechnungstool hat keine gültige Bestätigung geliefert.'), 502);
        }

        return Api::identifier($invoice['id'] ?? null);
    }
}
