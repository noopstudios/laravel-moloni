# Changelog

All notable changes to this package are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## Unreleased

### Fixed

- JSON translations are now loaded in `register()`, so hosts that translate before the provider boots (e.g. while registering a Filament panel) get the package strings.
- The "Issue a credit note" action is now hidden once live credit notes cover every line of the document.

## 0.1.0

First release (not yet tagged or dated).

### Added

- Issuing of Moloni documents from any Eloquent model implementing `Invoiceable`: invoice, invoice-receipt, pro-forma (and its conversion into an invoice or invoice-receipt), receipt (one receipt for several invoices) and credit note (whole or by line), plus cancel, reissue, retry, reconcile and an on-demand PDF link.
- Documents are recorded first (with a frozen copy of the customer and lines) and issued by a queued job on the `database` connection. A write whose answer is lost leaves the document in `unknown` and is never repeated without reconciliation; one live invoice or invoice-receipt per invoiceable.
- `moloni:recover` (scheduled every five minutes by the package) for documents whose job was lost, and `moloni:check` for the login, the required settings and every configured Moloni id.
- Settings stored with spatie/laravel-settings (company, fiscal zone, document sets, payment methods, maturity dates, default category/unit/product, tax overrides, exemption reasons, VIES behaviour), with their migrations loaded by the package.
- Optional VIES validation behind a replaceable `TaxRule` (`DefaultTaxRule`), with a choice between charging VAT or blocking when VIES is unreachable.
- Optional Filament v5 plugin (`MoloniPlugin`): a settings page fed by cached Moloni lists, a read-only documents resource and reusable document actions, guarded by the `moloni.permissions.manage` and `moloni.permissions.full_admin` abilities. Filament is not required: without it the package installs and issues documents, and the settings are written from code and validated with `moloni:check`.
- Events `DocumentIssued`, `DocumentFailed` and `DocumentVoided`.
- `Moloni::fake()` for host application tests, with `assertIssued()`, `assertNothingIssued()`, `assertIssuedCount()` and `failNext()`.
- Opt-in sandbox suite against a Moloni test company (`vendor/bin/pest --group=sandbox`, see `.env.sandbox.example`).
- README in European Portuguese.

### Notes

#### Differences from the Moloni documentation found against the real API

The sandbox suite (`vendor/bin/pest --group=sandbox`) checks the package against a Moloni test company.

- `creditNotes/insert` accepts a credit note without `expiration_date` and without `maturity_date_id`; Moloni stores it with an empty expiration date. Credit notes no longer send either field, so they no longer need a 0-day maturity date in the settings.

#### Requirements

- PHP `^8.3`, Laravel `^13.0`, spatie/laravel-settings `^3.0`; Filament `^5.0` only for the panel (optional).
- MySQL/MariaDB. Concurrent receipts and credit notes rely on InnoDB locking; PostgreSQL under `REPEATABLE READ` or `SERIALIZABLE` host transactions is not supported for receipts.
- Licence: MIT.
