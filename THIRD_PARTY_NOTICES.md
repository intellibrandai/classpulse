# Third-party notices

ClassPulse's own source code, design and brand assets are all rights reserved (see `LICENSE.md`). This file lists the
third-party components the project builds on. **Third-party components keep their own licenses**; nothing in
`LICENSE.md` re-licenses them, and this repository does not grant any rights to them beyond what their own licenses give.

The package tables below were generated from `composer.lock` with `composer licenses` (run inside the project's Docker
container) and reflect the license field each package declares. Where a package declares several licenses, they are
all shown. This is informational, not legal advice; the authoritative text is the `LICENSE` file shipped with each package
(after `composer install`, in `vendor/<package>/`).

## Laravel framework and application skeleton

- `laravel/framework` v13.34.0: MIT License (Copyright (c) Taylor Otwell).
- The application skeleton this project started from (`laravel/laravel` 13.x: `artisan`, `bootstrap/`, `config/`, `public/index.php`,
  `routes/` layout, `.editorconfig`, `.gitattributes`, `phpunit.xml` and `pint.json` conventions, default migrations)
  is MIT licensed (Copyright (c) Taylor Otwell). That license applies to the skeleton portions only; the ClassPulse
  application code written on top of it is not MIT licensed.

## Composer runtime packages (`composer licenses --no-dev`): 77 packages

| Package | Version | License |
|---|---|---|
| `brick/math` | 1.0.0 | MIT |
| `carbonphp/carbon-doctrine-types` | 3.2.1 | MIT |
| `dflydev/dot-access-data` | v3.0.3 | MIT |
| `doctrine/inflector` | 2.1.0 | MIT |
| `doctrine/lexer` | 3.0.2 | MIT |
| `dragonmantank/cron-expression` | v3.6.0 | MIT |
| `egulias/email-validator` | 4.0.4 | MIT |
| `fruitcake/php-cors` | v1.4.0 | MIT |
| `graham-campbell/result-type` | v1.2.0 | MIT |
| `guzzlehttp/guzzle` | 8.2.0 | MIT |
| `guzzlehttp/promises` | 3.0.2 | MIT |
| `guzzlehttp/psr7` | 3.1.0 | MIT |
| `guzzlehttp/uri-template` | v2.0.1 | MIT |
| `laravel/framework` | v13.34.0 | MIT |
| `laravel/prompts` | v0.3.24 | MIT |
| `laravel/serializable-closure` | v2.1.0 | MIT |
| `laravel/tinker` | v3.0.2 | MIT |
| `league/commonmark` | 2.10.3 | BSD-3-Clause |
| `league/config` | v1.2.0 | BSD-3-Clause |
| `league/flysystem` | 3.36.0 | MIT |
| `league/flysystem-local` | 3.35.3 | MIT |
| `league/mime-type-detection` | 1.17.0 | MIT |
| `league/uri` | 7.8.1 | MIT |
| `league/uri-interfaces` | 7.8.1 | MIT |
| `monolog/monolog` | 3.12.1 | MIT |
| `nesbot/carbon` | 3.14.1 | MIT |
| `nette/schema` | v1.3.6 | BSD-3-Clause / GPL-2.0-only / GPL-3.0-only |
| `nette/utils` | v4.1.5 | BSD-3-Clause / GPL-2.0-only / GPL-3.0-only |
| `nikic/php-parser` | v5.9.0 | BSD-3-Clause |
| `nunomaduro/termwind` | v2.4.0 | MIT |
| `phpoption/phpoption` | 1.10.0 | Apache-2.0 |
| `psr/clock` | 1.0.0 | MIT |
| `psr/container` | 2.0.2 | MIT |
| `psr/event-dispatcher` | 1.0.0 | MIT |
| `psr/http-client` | 1.0.3 | MIT |
| `psr/http-factory` | 1.1.0 | MIT |
| `psr/http-message` | 2.0 | MIT |
| `psr/log` | 3.0.2 | MIT |
| `psr/simple-cache` | 3.0.0 | MIT |
| `psy/psysh` | v0.12.24 | MIT |
| `ramsey/collection` | 2.1.1 | MIT |
| `ramsey/uuid` | 4.9.4 | MIT |
| `symfony/clock` | v7.4.8 | MIT |
| `symfony/console` | v7.4.20 | MIT |
| `symfony/css-selector` | v7.4.18 | MIT |
| `symfony/deprecation-contracts` | v3.7.1 | MIT |
| `symfony/error-handler` | v7.4.20 | MIT |
| `symfony/event-dispatcher` | v7.4.17 | MIT |
| `symfony/event-dispatcher-contracts` | v3.7.1 | MIT |
| `symfony/finder` | v7.4.20 | MIT |
| `symfony/http-foundation` | v7.4.20 | MIT |
| `symfony/http-kernel` | v7.4.20 | MIT |
| `symfony/mailer` | v7.4.19 | MIT |
| `symfony/mime` | v7.4.19 | MIT |
| `symfony/polyfill-ctype` | v1.37.0 | MIT |
| `symfony/polyfill-intl-grapheme` | v1.41.0 | MIT |
| `symfony/polyfill-intl-idn` | v1.42.0 | MIT |
| `symfony/polyfill-intl-normalizer` | v1.42.0 | MIT |
| `symfony/polyfill-mbstring` | v1.38.2 | MIT |
| `symfony/polyfill-php80` | v1.37.0 | MIT |
| `symfony/polyfill-php82` | v1.38.1 | MIT |
| `symfony/polyfill-php83` | v1.41.0 | MIT |
| `symfony/polyfill-php84` | v1.38.1 | MIT |
| `symfony/polyfill-php85` | v1.41.0 | MIT |
| `symfony/polyfill-php86` | v1.41.0 | MIT |
| `symfony/polyfill-uuid` | v1.37.0 | MIT |
| `symfony/process` | v7.4.19 | MIT |
| `symfony/routing` | v7.4.20 | MIT |
| `symfony/service-contracts` | v3.7.3 | MIT |
| `symfony/string` | v7.4.19 | MIT |
| `symfony/translation` | v7.4.17 | MIT |
| `symfony/translation-contracts` | v3.7.1 | MIT |
| `symfony/uid` | v7.4.20 | MIT |
| `symfony/var-dumper` | v7.4.18 | MIT |
| `tijsverkoyen/css-to-inline-styles` | v2.4.0 | BSD-3-Clause |
| `vlucas/phpdotenv` | v5.7.0 | BSD-3-Clause |
| `voku/portable-ascii` | 2.1.1 | MIT |

## Composer development-only packages: 33 packages

Used for tests, formatting and local tooling; not part of the Hostinger release packages. Licenses declared: BSD-3-Clause (24), MIT (9).

| Package | Version | License |
|---|---|---|
| `fakerphp/faker` | v1.24.1 | MIT |
| `filp/whoops` | 2.18.5 | MIT |
| `hamcrest/hamcrest-php` | v3.0.0 | BSD-3-Clause |
| `laravel/agent-detector` | v2.0.2 | MIT |
| `laravel/pail` | v1.2.7 | MIT |
| `laravel/pao` | v1.1.5 | MIT |
| `laravel/pint` | v1.32.1 | MIT |
| `mockery/mockery` | 1.6.15 | BSD-3-Clause |
| `myclabs/deep-copy` | 1.14.0 | MIT |
| `nunomaduro/collision` | v8.9.5 | MIT |
| `phar-io/manifest` | 2.0.4 | BSD-3-Clause |
| `phar-io/version` | 3.2.1 | BSD-3-Clause |
| `phpunit/php-code-coverage` | 12.5.7 | BSD-3-Clause |
| `phpunit/php-file-iterator` | 6.0.2 | BSD-3-Clause |
| `phpunit/php-invoker` | 6.0.0 | BSD-3-Clause |
| `phpunit/php-text-template` | 5.0.0 | BSD-3-Clause |
| `phpunit/php-timer` | 8.0.0 | BSD-3-Clause |
| `phpunit/phpunit` | 12.5.37 | BSD-3-Clause |
| `sebastian/cli-parser` | 4.2.1 | BSD-3-Clause |
| `sebastian/comparator` | 7.1.8 | BSD-3-Clause |
| `sebastian/complexity` | 5.0.0 | BSD-3-Clause |
| `sebastian/diff` | 7.0.1 | BSD-3-Clause |
| `sebastian/environment` | 8.1.2 | BSD-3-Clause |
| `sebastian/exporter` | 7.0.3 | BSD-3-Clause |
| `sebastian/global-state` | 8.0.3 | BSD-3-Clause |
| `sebastian/lines-of-code` | 4.0.1 | BSD-3-Clause |
| `sebastian/object-enumerator` | 7.0.0 | BSD-3-Clause |
| `sebastian/object-reflector` | 5.0.0 | BSD-3-Clause |
| `sebastian/recursion-context` | 7.0.1 | BSD-3-Clause |
| `sebastian/type` | 6.0.4 | BSD-3-Clause |
| `sebastian/version` | 6.0.0 | BSD-3-Clause |
| `staabm/side-effects-detector` | 1.0.5 | MIT |
| `theseer/tokenizer` | 2.0.1 | BSD-3-Clause |

## Self-hosted fonts (`public/fonts/`)

| Font | Files | License |
|---|---|---|
| Plus Jakarta Sans (variable, weights 400-800) | `plus-jakarta-sans-latin.woff2`, `plus-jakarta-sans-latin-ext.woff2` | SIL Open Font License 1.1. Copyright The Plus Jakarta Sans Project Authors |
| JetBrains Mono (variable, weights 400-600) | `jetbrains-mono-latin.woff2`, `jetbrains-mono-latin-ext.woff2` | SIL Open Font License 1.1. Copyright The JetBrains Mono Project Authors |

The files were downloaded on 2026-09-30 from Google Fonts (latin and latin-ext subsets, WOFF2) and are referenced only from
`public/css/fonts.css`; the application makes no external font requests. The OFL 1.1 text is published at
https://openfontlicense.org (OFL-1.1). Under the OFL the fonts may be bundled and redistributed with software, and they are
not sold on their own; the font names and the notice in `public/fonts/README.txt` are kept with the files.

## Docker images (development and test tooling only)

These images are pulled by `Dockerfile` and `docker-compose.yml`; they are not redistributed in this repository, and each
is distributed by its upstream under its own terms.

| Image | Where | Purpose | Upstream license (generic) |
|---|---|---|---|
| `php:8.3-cli-bookworm` | `Dockerfile` | PHP runtime for the app container | PHP License / the licenses of the bundled Debian packages |
| `composer:2.10.3` | `Dockerfile` (`COPY --from`) | Composer binary | MIT |
| `mariadb:10.11` | `docker-compose.yml` | Local database | GPL-2.0 (MariaDB Server) |
| `node:24-alpine` | `docker-compose.yml` (profile `test`) | Runs `node --test` for the JavaScript unit tests and syntax checks only | MIT (Node.js) / Alpine Linux package licenses |

The application does not use Node, npm or any JavaScript package at runtime; all front-end code is hand-written.

## Design reference

The user interface follows a design reference generated with Google Stitch. The Stitch mock-up images carry a different
brand and are third-party generated material; they are **not redistributed** in this repository (see
`docs/design-reference/README.md`). The ClassPulse logo (`public/brand/`, `docs/design-reference/logo-source-280x144.png`)
and all other brand assets are part of the project and covered by `LICENSE.md`.

## Other

- The inline SVG icon family in `resources/views/components/icons.blade.php` is hand-authored for this project (see the header comment of that file).
- No third-party JavaScript library, CDN script, analytics or remote font is loaded at runtime.
