# npm Audit Decision

Last reviewed: 2026-09-04

The release ZIP contains runtime PHP, frontend and admin assets, and translations. It excludes `node_modules` and all npm development tooling. CI nevertheless audits the complete npm tree and fails on every unaccepted high or critical advisory.

## Temporarily accepted advisory

- `GHSA-jmr9-qjv8-65gv`: `extract-zip` unvalidated symlink path traversal.
- Dependency path: `@wordpress/scripts` → `@wordpress/e2e-test-utils-playwright` → `lighthouse` → `puppeteer-core` → `@puppeteer/browsers` → `extract-zip`.
- Scope: transitive development tooling only; it is not shipped in the plugin ZIP.
- Current status: npm and GitHub report no patched `extract-zip` release.
- npm reports the boolean `fixAvailable: true` for the propagated dependency chain, but `npm audit fix --dry-run` proposes zero package changes. The non-vulnerable chain requires newer Puppeteer and Lighthouse versions that `@wordpress/scripts` does not yet provide.

The audit wrapper accepts this advisory only while the dependency remains indirect, the advisory range is exactly `<=2.0.1`, the installed version is exactly `2.0.1`, and npm does not provide a concrete replacement package/version. A changed range, package version, direct dependency, concrete replacement, changed advisory ID, or any other high or critical advisory makes CI fail.

## Tracking

`npm run security` runs on relevant pull requests and pushes, on manual dispatch, and every Monday at 06:24 UTC. Remove this exception as soon as `extract-zip` or the upstream Puppeteer dependency chain publishes a compatible fix.

## Non-blocking upstream constraints

GitHub also reports moderate development-tooling advisories for `uuid` and `@opentelemetry/core`. Dependabot cannot install their patched major versions because `@wordpress/scripts@34.2.0` still requires `uuid@^8.3.2` through `sockjs` and OpenTelemetry 1.x through Lighthouse/Sentry. These packages are excluded from the release ZIP. Keep `@wordpress/scripts` current and remove the advisories when its upstream dependency graph supports the patched majors.
