# Export personal time as invoice items

Invoice-position exports are part of Board core, alongside the personal time report.
They reuse the existing source authorization, exporter registry, renderer and download route
controller. No additional Composer package or host override is needed.

Open the account modal and choose **Export invoice items**. Select one readable board,
an optional inclusive UTC booking-date range, the target format, an explicit EUR net hourly
rate and tax percentage. A rate/tax of `0` is allowed when explicitly entered. Decimal comma
and decimal point are accepted, with at most two decimal places. For sevdesk, additionally
enter the actual hourly `Unity` id from your sevdesk account; ids are account-specific.

Only the current account's settled whole minutes are exported. The same authorization,
historical-data limits and date filtering as Board's personal time report apply. Bookings
are grouped by original ticket id, key and title snapshot, within one board. Renamed titles
remain separate. The original booking ids remain in `ExportLine::record` for listeners.
The booking date range describes when minutes were settled; it is not proof of when the
service was performed and must not automatically become an invoice's service period.

## What the formats contain

| Format | Result | How to use it |
| --- | --- | --- |
| Invoice items CSV | UTF-8 BOM, semicolon, stable machine headers, formula protection | Neutral data for a mapped/custom importer or manual preparation; not a promised provider CSV import |
| Lexware Office JSON | `lineItems` with custom items, hours and EUR net unit prices | Merge into an invoice draft request; add contact/address, invoice date, tax conditions, currency and shipping/service data |
| sevdesk JSON | `invoicePosSave` with net `price`, tax rate and the supplied `Unity` reference | Merge into `Invoice/Factory/saveInvoice`; add invoice/contact and the appropriate invoice-level tax rule, currency and `showNet` |
| easybill JSON | `items` with service positions, hour quantities and `single_price_net` in cents | Merge into a document request; add customer and document-level details and retain net pricing |
| FastBill JSON | `ITEMS` with quantities, descriptions, EUR net unit prices and VAT percentages | Merge into `invoice.create` data; add `CUSTOMER_ID`, EUR currency and the correct net/tax settings |

JSON files are **position fragments for the provider API**, not complete invoice requests
and not files accepted by the providers' web CSV upload screens. Downloading a file stores no credentials
and contacts no provider. The receiving application must supply the remaining invoice
fields, authenticate, create/review the draft and handle duplicate submissions. Downloads
do not mark hours as billed; repeated/overlapping downloads include the same bookings.

CSV headers are `project`, `ticket`, `name`, `description`, `person`, `booked_from`,
`booked_until`, `minutes`, `quantity`, `unit`, `net_rate`, `tax_rate`, `currency`.
`minutes` is exact; `quantity` is hours rounded to six decimals and `unit` is `HUR`.
Prices are net EUR per hour. Lexware accepts at most four quantity decimals, so that adapter
rounds the outgoing hours to four decimals while preserving exact minutes in the description.
Lexware exports refuse more than 300 positions with HTTP 422; narrow the booking-date range
when that limit is reached. The destination computes invoice totals; review its rounding before finalizing the invoice.
FastBill's documented item fields do not define a unit field; its description therefore
includes both exact minutes and decimal hours. Configure the intended hour display in the
receiving application rather than assuming an undocumented API field.

Tax percentage alone does not select a legal tax case. The destination's invoice-level tax
treatment must match the actual transaction. `0` does not automatically mean small-business
exemption, reverse charge or any particular exemption. Lexware's adapter accepts only its
documented percentage values (0, 5, 7, 16, 19); other adapters accept 0–100 with two decimals.
Rates and tax treatment are never inferred from ticket metadata or a user's previous export.

## Create a draft directly in your invoicing tool

Choose **Open invoice drafts** in the account modal or the installation's invoice
settings. Add an account for Lexware Office, sevdesk, easybill or FastBill. Enter a
company name and a company identifier to distinguish accounts. These are local labels;
the credentials select the actual issuer, whose address and tax details remain managed
in the provider account. A customer ID identifies the recipient, a separate company.
Multiple accounts per provider are supported. FastBill also requires the API account email.

Accounts have two billing scopes:

- **Personal:** your own settled hours, on a board you can currently read.
- **Company:** everyone's settled hours on the selected board. Managing/using shared
  accounts requires installation `settings.manage` and exporting another person's hours
  additionally requires the board's `export` permission. Installation rights alone do
  not bypass project access. Separate personal and company claims allow a contractor's
  submission and the employer's subsequent customer invoice for the same work.

Select the account, one board, optional inclusive UTC booking dates, the existing recipient's
provider ID, invoice title and date, explicit service dates, EUR net hourly rate and VAT
treatment. The API workflow currently supports standard German VAT at 7/19% and the
small-business treatment at 0%; other tax cases remain outside this workflow. sevdesk
uses system 2.0 and additionally requires the account's `Unity`, `SevUser` and invoice
address `StaticCountry` IDs. Its service period must not end after the invoice date.

The preview reads the existing customer from the provider and shows its returned name,
issuer labels, recipient ID, service dates and positions. Bookings already reserved or
handed over **in the selected billing scope** are omitted. Confirming the immutable preview
creates one draft. Editing a browser request cannot change the prepared positions or
recipient. The preview expires after one hour. API exports allow at most 300 positions
and 5000 unclaimed bookings; narrow the dates for larger selections. Exact minutes and
people remain in each position description; the provider computes totals and rounding.

| Provider | Draft operation | Authentication |
| --- | --- | --- |
| Lexware Office | `POST https://api.lexware.io/v1/invoices?finalize=false` | Bearer API key |
| sevdesk | `POST https://my.sevdesk.de/api/v1/Invoice/Factory/saveInvoice`, status `100`, `taxRule` 1/11 | API key in `Authorization` |
| easybill | `POST https://api.easybill.de/rest/v1/documents`, type `INVOICE` | Bearer API key |
| FastBill | `POST https://my.fastbill.com/api/1.0/api.php`, service `invoice.create` | Basic auth with email and API key |

No adapter finalizes, locks, sends or marks a document paid. easybill's `is_draft` is
read-only; creating the document makes a draft, and Board never calls `/done`. Review
and complete the resulting draft in your provider account before finalizing it there.
The provider account needs an API-enabled subscription and appropriate read/create rights.

### Configure encrypted account storage

The host must provide `nafinity:exports:key`, a base64-encoded random 32-byte key stored
outside the database, and PHP sodium/cURL must be available. In the skeleton this belongs
in `app/src/config.php` (a generic NAF host normally uses `app/config.php`):

```php
'nafinity' => [
    // Preserve the installation's other nafinity settings.
    'exports' => ['key' => 'ENV:NAFINITY_EXPORT_KEY'],
],
```

Generate the environment value with `php -r 'echo base64_encode(random_bytes(32)), PHP_EOL;'`.
Keep it with the installation's secrets and backups. XChaCha20-Poly1305 uses NAF's existing
OAuth-client `Cipher` and binds each credential to its account row, owner and provider.
Missing/changed keys refuse account storage or decryption; there is no plaintext fallback.
Never place keys in a settings field that can be read by browsers. Listing accounts and
rendering the page return only labels and scope, never the API key. Disconnect erases the
stored credential; separately revoke the key at the provider. An already started handover
may still finish. Reconnect after losing or deliberately rotating the encryption key.

### Duplicate prevention and uncertain results

Before the external write, Board commits unique booking claims and a `sending` state.
Within a billing scope a booking can be claimed once across all providers and accounts.
Concurrent confirmations of overlapping previews therefore cannot create two documents.
A repeated confirmation of a completed draft returns its receipt. The network request
uses native `naf/client` with retries disabled, verified HTTPS and no redirects; provider
URLs are fixed. A disconnected account or lost board/installation rights refuses a new send.

An interruption, rejected request or malformed response retains the claims and shows
**Check in invoicing tool**. A saved `sending` state also survives a process crash.
Board cannot know whether the remote server accepted a timed-out write, so it does not
retry automatically. Find the reference `Nafinity <preview-id>` in the correct provider
account. After at least 60 seconds, explicitly record either the existing draft ID or
that no draft exists. Only the latter releases the claims; prepare a new preview afterwards.
Check carefully before releasing: providers do not offer a shared transactional idempotency
contract. A rejection may originate at a proxy after the provider accepted the write.

Recent handovers remain available with their status and external ID. Personal history is
private; authorized installation operators share company history, still subject to current
board export rights. File downloads remain independent: they neither reserve bookings nor
establish that a downloaded file was actually billed. Do not mix manual file imports and
API handovers without checking your external account.

### Add an API adapter

The existing `ExporterDefinition` accepts optional `draftAdapter: YourAdapter::class`.
It appears in `ExporterRegistry::forDrafts()` only when the definition supports `invoice-items`.
The class implements `Contracts\InvoiceDraftAdapterInterface`: `fields()` declares additional
required invoice fields, `customer()` reads the existing recipient, `payload()` wraps the
existing writer's positions, and `create()` makes one external attempt and returns its ID.
Resolve transport through native NAF DI and keep credentials out of responses and exceptions.
Provider-specific payload, authentication and response checks belong in the adapter; account
ownership, encrypted storage, immutable previews and claims remain in the central services.

`InvoiceExportService::rows()` reuses `TimeExportService::bookings()` and `InvoiceItems`;
company grouping additionally separates the original author. `render()` passes rows through
the existing renderer/events/writers. Its optional observer captures transformed positions
for the preview after each writer succeeds. `ExportFinished` describes rendering a completed
position document; it does not claim a remote invoice was created. The durable draft state
and external ID establish the latter.

## Verified import routes

Checked against official provider documentation on 2026-10-10. These are distinct routes:
an article catalogue import does not import the quantity worked into an invoice.

- **Lexware Office:** [CSV imports](https://help.lexware.de/de-form/articles/548268-welche-daten-kann-ich-in-lexware-office-importieren)
  cover contacts and products/services. Invoice positions are mapped from the
  [Invoices API](https://developers.lexware.io/docs/#invoices-endpoint).
- **sevdesk:** [Manual CSV imports](https://hilfe.sevdesk.de/de/articles/9382293-importmoglichkeiten-in-sevdesk)
  cover contacts and products. The [official API](https://api.sevdesk.de/) documents
  invoice creation with `invoicePosSave` and account-specific `Unity` references.
- **easybill:** [CSV/Excel service imports](https://support.easybill.de/hc/de/articles/15010075418012-Dienstleistungen-hochladen)
  populate the catalogue. This adapter instead uses the `DocumentPosition` schema in the
  [official REST specification](https://api.easybill.de/rest/v1).
- **FastBill:** [CSV imports](https://support.fastbill.com/hc/de/articles/201715677-Produkte-und-Leistungen)
  cover products/services. The [invoice API](https://apidocs.fastbill.com/fastbill/de/invoice.html)
  and [item fields](https://apidocs.fastbill.com/fastbill/de/item.html) describe invoice positions.

## DATEV is a downstream accounting target

The [DATEV format](https://developer.datev.de/de/file-format/details/datev-format/importexport)
imports accounting data into Rechnungswesen. The
[Rechnungsdatenservice 1.0](https://www.datev.de/web/de/berufsgruppenuebergreifend/mydatev/datenservices/rechnungsdatenservice-1_0-einrichten)
transfers structured voucher data and/or documents. Neither is a neutral hours-to-draft
positions format. A valid handover additionally needs complete invoice/customer information
and, depending on the route, accounting accounts, advisor/client scope, fiscal-year context,
tax keys and the original invoice document. Board deliberately offers no misleading
DATEV file made from raw hours. Create/review the invoice in the billing tool, then use its
supported DATEV handover. A later DATEV adapter belongs on a complete invoice/accounting
dataset, still using the same Board exporter registry and renderer.

## Extending the adapters

`Naf\Board\Export\InvoiceItems::SOURCE` is `invoice-items`. Register another `ExporterDefinition`
with `sources: ['invoice-items']`; it appears in the invoice card and stays out of ticket/time
menus. Implement Board's existing `ExporterInterface` and map the normalized line data.
For JSON position fragments, extend `Naf\Board\Export\Invoice\JsonItemsExporter`, provide the provider's
array property name and map one item. Writers have no database access or network side effects.
Sources authorize through `TimeExportService::bookings()` before the renderer reads rows.
The shared `ExportStarted`, `ExportLine` and `ExportFinished` events identify `invoice-items`.
The aggregator buffers one selected board's bookings to sum exact integer minutes by ticket.

## Checking changes

Use PHP 8.3+ and the package's installed dependencies:

```sh
composer validate --strict
composer style:check
composer test
npm test
npm run style:check
```

Unit fixtures check provider fields, cents/euros, decimal validation, grouping, empty files
and Lexware precision. Database and HTTP tests verify own-user/project isolation, inclusive
date selection, all adapters, the installed profile card, spoofed user ids and private downloads.
Tests use a disposable `nafinity_test` database and never need live billing accounts.

Direct API regressions: `InvoiceDraftAdapterTest`, `InvoiceDraftServiceTest` and
`InvoiceDraftsOverHttpTest` use synthetic provider responses, encrypted credential tests,
separate billing scopes and simultaneous HTTP confirmations. No real provider account
is contacted. Provider draft operations were checked against official documentation on
2026-10-10.
