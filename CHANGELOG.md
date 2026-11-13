# Changelog

## Unreleased

### Differences from the Moloni documentation found against the real API

The sandbox suite (`vendor/bin/pest --group=sandbox`) checks the package against a Moloni test company.

- `creditNotes/insert` accepts a credit note without `expiration_date` and without `maturity_date_id`; Moloni stores it with an empty expiration date. Credit notes no longer send either field, so they no longer need a 0-day maturity date in the settings.
