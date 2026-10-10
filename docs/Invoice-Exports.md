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
and not files accepted by the providers' web CSV upload screens. No credentials are stored
and no provider is contacted. The receiving application must supply the remaining invoice
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
