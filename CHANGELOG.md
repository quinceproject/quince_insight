# Changelog

All notable changes to this module are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the versioning follows
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.7] - 2026-11-26

### Fixed

- **Tag Manager: no second Google tag when the container already has one.** When the
  container already holds a Google tag for the same measurement ID (for example one Tag
  Manager added for the linked Analytics property), the module no longer creates its own
  "QI - Google tag": Tag Manager refused to create a version with two Google tags for one
  destination ("Conflicting Google tag"). The module now uses the existing tag, only adds
  `send_page_view: false` to it so page views are not counted twice, and removes its own
  Google tag if it had already created one. The existing tag is never deleted by the
  module. The list of changes shows it as "Will be updated".

## [0.1.6] - 2026-11-26

### Changed

- **Tag Manager: the list of container changes says what the module will do.** The column
  is called "Action" instead of "Change", and its labels read "Will be created", "Will be
  updated", "Will be removed" and "No change" instead of "Create", "Update", "Remove" and
  "Unchanged", which looked like buttons to click. In the wizard and on the Events screen.

## [0.1.5] - 2026-11-26

### Fixed

- **Tag Manager: the reason why a container version was not created is now shown.**
  When Tag Manager does not return the new version, the wizard and the Events screen
  show what Google answered (compiler error, workspace conflict, which fields came
  back) and the module writes it to its log. Before, the message only said to open the
  workspace, which did not help when the workspace had no errors.
- **Tag Manager: a version returned without its path is no longer reported as a
  failure.** The module builds the path from the container and the version number, or
  finds the new version by its name when Google already replaced the workspace.

## [0.1.4] - 2026-11-16

### Changed

- New module logo: the letter Q in the project gradient.
- Files in `src/` stop when opened directly instead of through PrestaShop.

## [0.1.3] - 2026-11-15

### Security

- **Stored secrets are tied to the place they belong to.** The Google client secret, API
  key, tokens and the Measurement Protocol secret are encrypted together with the
  connection or shop and the field they are stored in. A value copied in the database
  to another connection, field or shop can no longer be read. The update re-encrypts
  the secrets saved by earlier versions.
- **"Another tool" consent source: categories listed as refused are no longer read as
  granted.** A consent cookie such as `{"accepted":["necessary"],"rejected":["analytics"]}`
  used to grant analytics. Branches named `rejected`, `denied`, `declined`, `refused`,
  `disallowed` or `opt_out` are now skipped.

## [0.1.2] - 2026-11-15

### Security

- **Google sign-in finishes only in the browser that started it.** Starting the
  sign-in sets a short-lived cookie, and the return from Google is refused without it.
  Before, anyone who got hold of the sign-in link within its ten minutes (pasted in a
  chat, on a screenshot) could finish the sign-in with their own Google account and
  connect the shop to it.

## [0.1.1] - 2026-11-15

### Security

- **In a multistore, an employee limited to some shops cannot reach the others
  through the setup wizard**: the wizard lists, accepts and keeps only shops the
  employee may manage, and only Google connections used by those shops.
- **The public event endpoint stores only the e-commerce fields the shop's script
  sends**, up to about 2 KB per event, with a limit of 600 events a minute per shop.
  Before, it accepted any content up to 16 KB.
- **The event log no longer stores secret parameters of the page address**, such as
  the order key on the order confirmation page.

## [0.1.0] - 2026-11-13

First version, for testing on a real Google account.

### Added

- Setup wizard: Google Cloud project, Google sign-in with the granted permissions,
  service steps, site verification, summary with the cron addresses. One shop, several
  shops at once, or a shop added later (with "Copy settings from another shop").
- Overview: what needs attention, one card per service, the Google connection,
  information left after a deleted shop, and a card per shop in the "All shops" context.
- Analytics: GA4 property, web stream, Measurement Protocol secret, key events, data
  retention, Google Ads link (read only); Overview, Traffic, Funnel and Property tabs.
- Events: Consent Mode v2 defaults, consent source (Quince CMP, another tool, none),
  Tag Manager snippet, `dataLayer` for 15 e-commerce events, server-side purchases and
  refunds, order log, event log, missing orders sent automatically, Google Customer
  Reviews opt-in.
- Tag Manager: container plan, change preview, apply and publish in the background,
  Google Ads conversion, remarketing and enhanced conversions, shared container across
  shops, "I manage the container myself".
- Merchant Center: account, developer registration, website claim, feeds per country
  and language, product data with the feed currency and tax, filters, queue and
  synchronisation, product statuses; Mapping, Rules, Promotions, Shipping and Reports.
- Search Console: property, daily search data, sitemap generated by the module
  (products, categories, CMS pages, brands, suppliers, Quince Blog) with hreflang,
  submission and status from Google, optional `Sitemap:` line in robots.txt.
- Page speed: Core Web Vitals from the Chrome UX Report and PageSpeed Insights tests of
  up to 5 pages, on a schedule.
- Structured data: scan of the shop pages and JSON-LD types added by the module.
- Settings (connection, shops, services, cron, module log) and Support (diagnostics,
  changelog).
- Five scheduled task groups with recommended frequencies, plus one address for
  everything.
- Polish translation.
