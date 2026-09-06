# ZuidWest Poll

A WordPress plugin for anonymous, single-choice polls. Polls are embedded in
posts and pages with a shortcode, rendered server-side for page-cache
compatibility, and made interactive with the WordPress Interactivity API.

## Requirements

| Component | Version |
|---|---|
| WordPress | 6.9+ |
| PHP | 8.3+ |
| Node.js | 20+ locally, 24 in CI |

Node.js is only required for linting, WordPress Playground, and Playwright. The
frontend and admin assets in `src/` ship without a build step.

## Getting started

```bash
composer install
npm install
npm run playground
```

The demo runs at `http://127.0.0.1:9400`. The
[Playground blueprint](playground/blueprint.json) activates the plugin and
Classic Editor, then creates an open and a closed demo poll:

- `/poll-demo/`
- `/poll-gesloten-demo/`

To install the plugin in another WordPress environment, build an uploadable ZIP
with a SHA-256 checksum:

```bash
npm run build:plugin
```

The generated files are written to `dist/`. The version is read from
[zw-poll.php](zw-poll.php).

## Usage

1. Create a poll under **Polls** and provide a question, answer options, and a
   status.
2. Copy the shortcode from the **Embed** panel or the poll list.
3. Add it to a post or page:

```text
[zw_poll id="123"]
```

Classic Editor also provides an **Insert poll** button in the visual toolbar
and a compact **poll** button in text mode. The poll title is the question shown
to readers. Option IDs remain stable when labels are renamed, so existing votes
remain valid.

Shortcode usage is listed under **Used in** and is taken into account by the
delete guard.

## Settings

Administrators can configure vote protection, proxy headers, and uninstall data
removal under **Polls → Settings**. Settings are stored per site in
`zw_poll_settings`.

- Rate limiting can be disabled and defaults to 30 requests per 60 seconds.
  Changes apply to newly created transient windows.
- Supported proxy headers are `CF-Connecting-IP`, `X-Forwarded-For`, and
  `X-Real-IP`. A `zw_poll_client_ip` filter in code takes precedence.
- Removing data on uninstall is opt-in. By default, the votes table, salt,
  settings, and capabilities are retained.
- Percentages are always displayed. The numeric vote total is shown after 100
  votes by default; administrators can change the site-wide threshold from 0
  to 1,000,000. Each poll can use that default, always hide the total, or always
  show it.

The visibility decision is rendered on the server and mirrored by the
Interactivity API state. Cached pages therefore keep the configured policy,
while the total appears immediately when a newly submitted vote reaches the
threshold.

## WP-CLI and REST API

```bash
wp zw-poll list
wp zw-poll close-expired
wp zw-poll rebuild <poll_id>
wp zw-poll reset <poll_id> --yes
```

Editors can set an end date and time for each poll in the **Planning** meta box.
Input and display use the WordPress site timezone; storage uses a UTC timestamp.
An idempotent WP-Cron sweep runs every five minutes and closes published, open
polls after their deadline. Drafts, trashed polls, polls without a deadline,
and polls already closed are left unchanged.

WP-Cron is request-driven, so a vote may still be accepted between the deadline
and the next sweep. Sites that need more precise closing can run
`wp zw-poll close-expired` from system cron. Consumers can listen to
`zw_poll_closed` to purge caches or perform other follow-up work; the action
receives the poll ID and its tracked content IDs. Use `rebuild` after manual
database changes or to restore the aggregate cache from the votes table.

REST endpoints:

- Public: `POST /wp-json/zw-poll/v1/vote`
- Editor/admin: `POST /wp-json/zw-poll/v1/poll/{id}/reset`
- Poll management: standard custom post type routes under
  `/wp-json/wp/v2/zw-polls`

The public vote endpoint intentionally does not use a REST nonce. Protection is
provided by same-origin validation, schema validation, rate limiting, and a
unique deduplication index.

## Development and testing

```bash
composer test      # PHPUnit
composer coverage  # PHPUnit with a minimum of 80% line coverage
composer stan      # PHPStan level 8
composer lint      # PHPCS/WPCS
composer lint:fix  # PHPCBF
composer security  # Composer audit
npm run lint       # JavaScript and CSS linting
npm run security   # npm audit; fails on high or critical advisories
npm run make-pot   # Update languages/zw-poll.pot
```

Start Playground in a separate terminal before running the end-to-end tests:

```bash
npm run playground
npm run test:e2e
```

CI also runs WordPress Plugin Check, verifies the translation template, and runs
Playwright against WordPress 6.9 and the latest WordPress release.

## Architecture

The PHP code uses the `ZuidWest\Poll\` namespace under `src/` and is bootstrapped
through `Plugin::boot()`. Its main components are:

- Bootstrap and settings: `Plugin`, `Activation`, `Support\Capabilities`, and
  `Support\Settings`.
- Polls and votes: `PostType\PollPostType`, `Vote\VoteRepository`,
  `Vote\AggregateCache`, and `Vote\RateLimiter`.
- REST API and shortcode: `Rest\VoteController`, `Rest\AdminController`, and
  `Shortcode\PollShortcode`.
- Frontend: `Frontend\PollRenderer`, `Frontend\Assets`,
  `src/Frontend/view.js`, and `src/Frontend/style.css`.
- Admin and CLI: the classes under `Admin\` and `Cli\Commands`.

Polls are stored as `zw_poll` posts. Votes are stored in
`{$wpdb->prefix}zw_poll_votes`; `_zw_poll_aggregate` holds cached counts per
option ID. The poll title is the question and is limited to 200 characters.

`PollShortcode::render()` delegates Interactivity API markup generation to
`PollRenderer` and applies server-side directive processing. Cached pages
therefore show results even without JavaScript. `src/Frontend/view.js` is a
native ES module that loads `@wordpress/interactivity` through the WordPress
import map. The derived state in PHP and the JavaScript getters must remain in
sync.

## Security and privacy

The default protection is intended for informal polls behind page caches.
Cookie tokens and same-origin headers limit ordinary duplicate votes and
mistakes, but they do not provide cryptographic proof against a determined
client. Do not use the plugin for binding elections without additional
controls.

- The plugin does not store a WordPress user ID with a vote.
- Deduplication uses the functional `zwpoll_voted_{poll_id}` cookie and a unique
  index on `(poll_id, cookie_token)`.
- IP addresses are hashed with HMAC-SHA256 and the server-side
  `zw_poll_ip_salt` for rate limiting and auditing.
- SQL queries use `$wpdb->prepare()` and output is escaped.

For more sensitive polls, use these filters to tighten protection:

```php
add_filter( 'zw_poll_rate_limit', function (
    array $limit,
    string $ip_hash,
    int $poll_id
): array {
    return 123 === $poll_id
        ? [ 'max' => 5, 'window' => 5 * MINUTE_IN_SECONDS ]
        : $limit;
}, 10, 3 );

add_filter( 'zw_poll_vote_allowed', function (
    bool|WP_Error $allowed,
    int $poll_id,
    string $option_id,
    WP_REST_Request $request
): bool|WP_Error {
    if ( true !== $allowed ) {
        return $allowed;
    }

    // Validate a WAF challenge or signed edge header here, for example.
    return $allowed;
}, 10, 4 );
```

`zw_poll_rate_limit` can only make a poll more restrictive; the global limit
remains the upper bound. Use positive integers for `max` and `window`.
`zw_poll_vote_allowed` only allows the exact boolean value `true`. Return a
`WP_Error` with an explicit HTTP status for custom errors.

### Reverse proxies

The plugin uses `REMOTE_ADDR` by default. Configure a proxy header only when a
trusted proxy overwrites incoming headers. For `X-Forwarded-For`, the plugin
uses the final value. With proxy chains, prefer a single-value edge header or a
custom filter:

```php
add_filter( 'zw_poll_client_ip', function ( string $ip ): string {
    // Verify that the request passed through a trusted proxy first.
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip;
} );
```

Invalid IP addresses fall back to `REMOTE_ADDR`. Syntax validation alone does
not make a client header trustworthy. An edge rate limit on
`POST /wp-json/zw-poll/v1/vote` remains recommended.

## Theming

The neutral default styles in `src/Frontend/style.css` use CSS custom properties
on `.zw-poll`. To set a custom accent color:

```css
.zw-poll {
    --zw-poll-accent: #c8102e;
}
```

Choose a color with sufficient contrast. The full token list, including header
and vote-button tokens, is documented at the top of
`src/Frontend/style.css`.

## Release process

Manually start the release workflow from `main`. The version comes from the
`Version:` header in [zw-poll.php](zw-poll.php). The workflow runs all quality
checks and publishes a ZIP with its checksum. Only `zw-poll.php`,
`uninstall.php`, `LICENSE`, `README.md`, `src/`, and `languages/` are packaged.
The `force` option can recover a missing release for the current commit; an
existing release is never overwritten.

## License

ZuidWest Poll is free software licensed under the
[GNU General Public License v2.0 or later](LICENSE).

## Out of scope

The plugin does not provide multiple-choice or ranked polls, automatic closing,
an export UI, email notifications, external embeds, A/B tests, a Gutenberg
block, or a non-JavaScript form fallback.
