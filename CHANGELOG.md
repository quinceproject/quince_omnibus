# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.4] - 2026-11-15

### Security

- The price history chart returns nothing for a product that is inactive or hidden.
- The CSV export neutralises cells a spreadsheet would read as formulas.
- Front controllers refuse to run when opened directly.

## [0.1.3] - 2026-11-15

### Security

- Every back-office screen of the module checks the employee's permissions. Before,
  any logged-in employee, whatever their profile, could open the module's screens,
  import price history and delete it. The screens now belong to the module's own menu
  tab (`AdminQuinceOmnibus`): grant it to a profile under **Advanced Parameters → Team →
  Permissions**. SuperAdmin needs nothing. A profile that had access through the hidden
  `AdminQuince` tab needs access to this tab now.

## [0.1.2] - 2026-11-10

### Changed

- An empty value in a table shows a hyphen instead of a long dash.

## [0.1.1] - 2026-11-10

### Added

- Polish translation of every text the module shows, in the back office and in the shop.

### Changed

- Texts no longer use long dashes.

## [0.1.0] - 2026-11-10

First public release.

### Added

- Price log: every price change of a product and its variants is recorded, either at
  the moment it happens or from a daily schedule address.
- Lowest price note on the product page and on product cards in lists, for a period
  of 30 to 365 days, gross or net, on discounted products only or on every product.
- Optional additions to the note: the difference in percent, an explanation on hover
  and a price history chart in a window over the page.
- Categories excluded from recording and from the note.
- Log screen with filters, CSV export and import, recording all current prices at
  once, deleting selected entries and clearing out entries past the retention period.
- Declared dependency on Quince Engine.
