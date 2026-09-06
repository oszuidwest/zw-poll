# Repository Guidelines

## Project Structure & Module Organization

This repository is the ZuidWest Poll WordPress plugin (`zw-poll`). The main plugin entry point is `zw-poll.php`; PHP source lives under `src/` using the `ZuidWest\Poll\` PSR-4 namespace. Frontend assets (the Interactivity API view module and stylesheet) live in `src/Frontend/` and ship unbundled; there is no build step. Admin-only JS/CSS is in `src/Admin/`. Translations are maintained in `languages/zw-poll.pot`. Unit tests are in `tests/phpunit/`, Playwright specs are in `tests/playwright/`, and the local WordPress Playground setup is in `playground/blueprint.json`.

## Build, Test, and Development Commands

- `composer install` installs PHP dev tools and autoloading.
- `npm install` installs lint, Playground, and Playwright tooling.
- `npm run playground` starts WordPress Playground at `http://127.0.0.1:9400`.
- `npm run build:plugin` packages an uploadable plugin zip in `dist/`.
- `composer test` runs PHPUnit.
- `composer coverage` runs PHPUnit with coverage and enforces the minimum line coverage.
- `composer stan` runs PHPStan at the configured level.
- `composer lint` runs PHPCS/WPCS; `composer lint:fix` applies PHPCBF fixes.
- `composer security` audits locked PHP dependencies.
- `npm run lint` runs JS lint and CSS lint.
- `npm run security` fails on high or critical npm advisories.
- `npm run test:e2e` runs Playwright against a running Playground server.

## Coding Style & Naming Conventions

Use PHP 8.3+ with `declare(strict_types=1)` and PSR-4 classes under `ZuidWest\Poll\`. Follow the existing four-space PHP indentation and tab-indented WordPress JavaScript style. Keep WordPress globals prefixed with `zw_poll`, `ZW_POLL`, or the project namespace. Use the `zw-poll` text domain for all translated strings. User-facing strings are Dutch and use the word "poll" (not "lezerspoll").

## Testing Guidelines

Add PHPUnit tests beside related behavior in `tests/phpunit/*Test.php`; test classes should extend `PHPUnit\Framework\TestCase` and use descriptive method names. E2E coverage belongs in `tests/playwright/*.spec.ts`. Start Playground before Playwright: `npm run playground`, then `npm run test:e2e`. The Playground blueprint activates the plugin and seeds open and closed demo polls for the e2e run.

## Commit & Pull Request Guidelines

Use Conventional Commits, for example `fix: ...`, `feat: ...`, `refactor: ...`, and `docs: ...`. Keep commits scoped and imperative. Pull requests should describe the change, list verification commands, link relevant issues, and include screenshots or short recordings for admin or frontend UI changes.

## Release & Configuration Notes

The plugin version source is the `Version:` header in `zw-poll.php`; keep `languages/zw-poll.pot` metadata aligned. Do not add versions to `package.json` or `package-lock.json`; `tests/phpunit/VersionTest.php` verifies this.
