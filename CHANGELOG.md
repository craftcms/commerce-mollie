# Release Notes for Mollie for Craft Commerce

## Unreleased

- Mollie for Commerce now supports iDEAL 2.0. ([#57](https://github.com/craftcms/commerce-mollie/issues/57))

## 3.0.2 - 2022-10-06

### Fixed
- Fixed a bug where expired payments weren’t showing a human-readable error message. ([#37](https://github.com/craftcms/commerce-mollie/issues/37))

## 3.0.1 - 2022-03-22

### Fixed
- Fixed a PHP error that could occur when processing a webhook.

## 3.0.0 - 2021-04-20

### Changed
- The plugin now requires Craft 3.6 and Commerce 3.3 or later.
- The plugin now requires Guzzle 7.

### Changed
- Bank transfer payments with an `open`/`processing` status will now complete and order. ([#17](https://github.com/craftcms/commerce-mollie/issues/17))

## 2.1.2.1 - 2021-03-03

### Added
- Added version strings for better support integration.

## 2.1.2 - 2020-06-17

### Added
- Added `craft\commerce\mollie\gateways\Gateway::getTransactionHashFromWebhook()` to support mutex lock when processing a webhook. ([#23](https://github.com/craftcms/commerce-mollie/issues/23))

## 2.1.1 - 2020-02-02

### Changed
- Updated how the plugin handles webhook responses from mollie. ([#22](https://github.com/craftcms/commerce-mollie/issues/22))

## 2.1.0.1 - 2019-07-24

### Changed
- Updated changelog with missing changes for 2.1.0

## 2.1.0 - 2019-07-24

### Changed
- Update Craft Commerce requirements to allow for Craft Commerce 3.

## 2.0.1 - 2019-03-22

### Changed
- The plugin now overrides the default message returned by Omnipay if the payment failed or was canceled. ([#15](https://github.com/craftcms/commerce-mollie/issues/15))

## 2.0.0 - 2019-03-04

### Added
- Added support for pre-selecting payment method.

### Changed
- Mollie for Craft Commerce now requires Craft 3.1.5 or later.
- Mollie for Craft Commerce now uses Omnipay v3.

### Fixed
- Fixed a bug where HTTP 400 would sometimes be triggered for Mollie webhooks. ([#7](https://github.com/craftcms/commerce-mollie/issues/7))

## 1.1.1 - 2019-02-13

### Added
- API Key setting can now be set to an environment variable. ([#9](https://github.com/craftcms/commerce-mollie/issues/9))

## 1.1.0 - 2019-01-22

### Changed
- Switched to an MIT license.

## 1.0.0.1 - 2018-12-11

### Changed
- Added a `craftcms/cms` requirement to `composer.json`.

## 1.0.0 - 2018-04-02

- Initial release.
