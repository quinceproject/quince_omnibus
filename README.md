# Quince Omnibus

**Price history and the lowest price note required by the Omnibus Directive, for
PrestaShop 9.2.**

The EU Omnibus Directive asks a shop that announces a price reduction to show the
lowest price the product had in the period before it. Quince Omnibus records every
price change in its own log and works that amount out by itself, with no spreadsheet
and no manual work. The note appears next to the price on the product page and on
product cards in lists.

> Quince Omnibus is a free, open-source module (OSL-3.0). There is nothing to buy.
> If it saves you time, you can support development, see
> [Supporting the project](#supporting-the-project).

---

## Features

- **Price log.** Every price change of a product and its variants is recorded, per
  currency. Recording happens the moment a price changes, or, for shops that import
  large price lists, once a day from a schedule address.
- **Lowest price note.** A line of text next to the price on the product page and on
  product cards in lists. The period runs from 30 days, the shortest the directive
  allows, up to 365 days. The note compares gross or net prices, to match what the
  shop shows, and appears on discounted products only or on every product. Its text
  is set per language.
- **Optional additions.** All switched off by default: the difference in percent
  against the lowest recorded price, an explanation shown on hover, and a price
  history chart opening in a window over the page. The chart library is served from
  the shop itself, nothing is fetched from outside.
- **Excluded categories.** Products in chosen categories are neither recorded nor
  given a note, for example made-to-order goods priced per quote.
- **Log screen.** Every recorded price with filters by product, variant and currency,
  so the number in the note can be checked. Record all current prices at once right
  after installing, export the log to CSV, import history kept by a previous shop,
  delete selected entries and clear out entries past the retention period.
- **Retention.** Entries older than the chosen retention period (30 to 3650 days) are
  removed once a day.

## Requirements

| Requirement | Version |
|-------------|---------|
| PrestaShop  | 9.2 (tested on 9.2.0) |
| PHP         | 8.4 (tested), 8.1 or newer required |
| Quince Engine | required |
| Quince theme | required for the note in the shop |

Quince Engine hosts the settings screens and places the note on the product page; the
Quince theme draws it. The module declares Quince Engine as a dependency, so
PrestaShop refuses to install Quince Omnibus without it.

## Installation

The release ZIP already contains everything the module needs. Composer and the
command line are not required.

**From the back office**

1. Download the latest **`quince_omnibus.zip`** from the
   [**Releases**](https://github.com/quinceproject/quince_omnibus/releases) page.
2. In the back office go to **Modules → Module Manager → Upload a module** and
   choose the ZIP. PrestaShop installs it.

**By copying the files to the server**

1. Unpack the release ZIP. It contains a `quince_omnibus` folder.
2. Upload that folder to the `modules/` directory of the shop, for example by FTP.
3. In **Modules → Module Manager** find Quince Omnibus and click **Install**.

**From Quince Engine**

With Quince Engine installed, **Quince project → Quince modules** installs and updates
this module from the Quince release list, without downloading anything by hand.

**With Composer**

A PrestaShop shop ships without a `composer.json` of its own, so Quince packages are
installed from a separate folder next to the shop. The one-time setup of that folder is
described in the [Quince Engine README](https://github.com/quinceproject/quince_engine#installation).
Then, from that folder:

```sh
composer require "quinceproject/omnibus:*"
php ../bin/console prestashop:module install quince_omnibus
```

> ⚠️ **Do not** use the green **Code → Download ZIP** button on this page. That
> archive contains the source only and is **missing the `vendor/` folder**, so the
> module will not run. Always install from a
> [**Release**](https://github.com/quinceproject/quince_omnibus/releases).

Uninstalling the module deletes the whole price history.

## Configuration

Open **Quince project → Price history** in the back office menu, or click
**Configure** next to the module in the Module Manager.

1. **Right after installing**, open the log screen and click **Record all prices
   now**. A fresh install has no history, so the note stays hidden until prices are
   recorded.
2. **The note next to the price.** Set its text, the period in days, gross or net
   prices and whether it shows on discounted products only.
3. **When prices get recorded.** Keep recording at the moment a price changes, or
   switch to the schedule and call the schedule address once a day, for example from
   cron. Generating a new address stops the old one working.
4. **Excluded categories and retention.** Pick categories to leave out and how long
   the log keeps entries.

## Architecture

- **`PriceLog`** keeps recorded prices in the module's own table; **`PriceHistory`**
  works out the lowest price for a product, variant and currency.
- **Hooks** `actionProductAdd`, `actionProductUpdate` and the specific price hooks
  record changes as they happen; the `cron` front controller records them on a
  schedule and removes old entries.
- **`actionQuincePriceDropped`** is called for other modules when a recorded price is
  lower than the previous one (default customer group), with `id_product`,
  `id_product_attribute`, `id_shop`, `id_currency`, `old_price` and `new_price`.
- **`displayQuinceOmnibus`** draws the note where the Quince theme asks for it; the
  module announces itself to Quince Engine with `actionQuinceRegisterFeature`.
- **`history` front controller** returns the price history for the chart.
- **Symfony controllers and Twig** for the settings and log screens, inside the
  Quince Engine shell.
- **Settings** are stored in PrestaShop configuration under the `QUINCE_OMNIBUS_`
  prefix.

## Development / building from source

This repository tracks **source only**. The `vendor/` directory is git-ignored, so a
`git clone` is **not** directly installable. **Start with `composer install`**: the
development environment mounts module directories straight from disk, and without
that step a fresh clone gives a broken back office.

```bash
git clone https://github.com/quinceproject/quince_omnibus.git
cd quince_omnibus
composer install
```

The folder must be called `quince_omnibus`, the same as the module. A clone of this
repository already has that name.

To build an installable archive, zip the `quince_omnibus/` folder **with its `vendor/`
directory included**, leaving out `.git/` and `.gitignore`.

## License

Code: [Open Software License 3.0 (OSL-3.0)](LICENSE). It is a reciprocal licence:
whoever modifies and distributes this code has to publish the changes under the same
licence and keep the original copyright and author notices.

Chart.js ships with the module under its own MIT licence, listed in
[THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md).

**The name "Quince" is not covered by the code licence.** A fork may use the code; it
may not use the name.

© 2026 Marcin Gajewski

## Supporting the project

Quince modules are free downloads and there is nothing to buy. You can help in two ways.

**Report what you find.** Open an issue in this repository to report a bug, suggest a
change or share a remark: [github.com/quinceproject/quince_omnibus/issues](https://github.com/quinceproject/quince_omnibus/issues).
Reports from working shops decide what gets built next. When reporting a bug, say what
happened, how to repeat it, and which versions of PrestaShop and the module you run.

**Support the development** on Ko-fi:
**[Ko-fi](https://ko-fi.com/quinceproject)**

The whole project, with every module: [quinceproject.github.io](https://quinceproject.github.io/)
