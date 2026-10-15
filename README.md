# Composer Package Template

[![Code Standards](https://github.com/limegreentangerine/composer_package/actions/workflows/CodeStandards.yml/badge.svg)](https://github.com/limegreentangerine/composer_package/actions/workflows/CodeStandards.yml)
[![System Tests](https://github.com/limegreentangerine/composer_package/actions/workflows/SystemTests.yml/badge.svg)](https://github.com/limegreentangerine/composer_package/actions/workflows/SystemTests.yml)

Use this repository as a starting point for a Concrete CMS package. Before using
the template, update the package metadata and replace these identifiers
throughout the project (use case-sensitive search):

- `composer_package` -> package handle and PHP namespace prefix.
- `composer_description` -> package description in `composer.json`.
- `ComposerPackage` -> PHP namespace in the Composer autoload configuration.
- `composer_name` -> package display name, where used.

Add contributor details to the `authors` section of `composer.json`.

## Requirements

- PHP 8.4 or later.
- Composer.
- Node.js and npm, used by the Composer post-install script and JavaScript
  formatting commands.
- Access to the private Composer registry at
  `https://packages.limegreentangerine.net` to install private dependencies.

The template currently requires `limegreentangerine/class_kit` and uses
Concrete CMS 9.2 and PHPUnit 11 for development.

## Install

```bash
composer install
```

Composer runs `npm install` after installation. If you need to authenticate to
the private registry, configure Composer to send the Cloudflare Access service
token headers for `packages.limegreentangerine.net`:

- `CF-ACCESS-CLIENT-ID` -> Cloudflare Access service-token client ID.
- `CF-ACCESS-CLIENT-SECRET` -> matching client secret.

Keep these credentials private; do not commit them to the repository.

## Development commands

Run these commands from the repository root:

| Command | Purpose |
| --- | --- |
| `composer test` | Run PHPUnit tests. |
| `composer test-coverage` | Run PHPUnit and print a coverage report. |
| `composer typecheck` | Run PHPStan using `phpstan.neon`. Findings are displayed, but the command exits successfully even when PHPStan reports errors. |
| `composer format:check` | Check PHP and JavaScript formatting. |
| `composer format` | Format PHP and JavaScript files. |
| `composer format:php:check` / `composer format:php` | Check or format PHP files with PHP-CS-Fixer. |
| `composer format:js:check` / `composer format:js` | Check or format files with Prettier. |

PHPStan analyzes `src` and `tests` at level 4, with bootstrap configuration
from `phpstan-bootstrap.php`. If you add package directories, update the
`paths` in `phpstan.neon` and the directories in `.php-cs-fixer.dist.php` so
they are included in analysis and formatting.

## GitHub Actions

- **CodeStandards** runs formatting checks and PHPStan on pushes and pull
  requests.
- **SystemTests** runs PHPUnit on pull requests.

Both workflows install dependencies from the private Composer registry. Add
these **repository secrets** under **Settings > Secrets and variables >
Actions** so the workflows can authenticate:

- `CF_ACCESS_CLIENT_ID` -> Cloudflare Access service-token client ID.
- `CF_ACCESS_CLIENT_SECRET` -> matching client secret.
