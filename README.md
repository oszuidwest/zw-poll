# ZuidWest Poll

WordPress-plugin voor anonieme polls met één antwoord per stem. Polls worden via
een shortcode in berichten en pagina's geplaatst, server-side gerenderd voor
paginacaches en interactief gemaakt met de WordPress Interactivity API.

## Vereisten

| Onderdeel | Versie |
|---|---|
| WordPress | 6.9+ |
| PHP | 8.3+ |
| Node.js | 20+ lokaal, 24 in CI |

Node.js is alleen nodig voor linting, Playground en Playwright. De frontend- en
admin-assets in `src/` worden zonder buildstap uitgeleverd.

## Aan de slag

```bash
composer install
npm install
npm run playground
```

De demo draait op `http://127.0.0.1:9400`. De
[Playground-blueprint](playground/blueprint.json) activeert de plugin en Classic
Editor en maakt een open en een gesloten demo-poll aan:

- `/poll-demo/`
- `/poll-gesloten-demo/`

Maak voor installatie in een andere WordPress-omgeving een uploadbare pluginzip
met SHA-256-controlesom:

```bash
npm run build:plugin
```

De bestanden komen in `dist/`; de versie wordt gelezen uit [zw-poll.php](zw-poll.php).

## Gebruik

1. Maak onder **Polls** een poll met een vraag, antwoordopties en status.
2. Kopieer de shortcode uit het vak **Insluiten** of uit de pollslijst.
3. Plaats deze in een bericht of pagina:

```text
[zw_poll id="123"]
```

Classic Editor heeft ook een knop **Poll invoegen** in de visuele toolbar en een
compacte **poll**-knop in de tekstmodus. De titel van een poll is de vraag aan de
lezer. Optie-ID's blijven bij hernoemen behouden, waardoor bestaande stemmen
geldig blijven.

Shortcode-gebruik verschijnt in **Gebruikt op** en wordt meegenomen door de delete-guard.

## Instellingen

Beheerders vinden onder **Polls → Instellingen** de stembeveiliging,
proxy-header en optie voor gegevensverwijdering. Instellingen worden per site
opgeslagen in `zw_poll_settings`.

- Rate limiting kan worden uitgeschakeld en staat standaard op 30 verzoeken per
  60 seconden. Wijzigingen gelden voor nieuwe transientvensters.
- Ondersteunde proxy-headers zijn `CF-Connecting-IP`, `X-Forwarded-For` en
  `X-Real-IP`. Een `zw_poll_client_ip`-filter in code heeft voorrang.
- Gegevens verwijderen bij uninstall is opt-in. Standaard blijven de stemtabel,
  salt, instellingen en capabilities behouden.
- Percentages worden altijd getoond. Het totale aantal stemmen is per poll aan
  of uit te zetten en staat standaard aan.

## WP-CLI en REST

```bash
wp zw-poll list
wp zw-poll rebuild <poll_id>
wp zw-poll reset <poll_id> --yes
```

Gebruik `rebuild` na handmatige databasewijzigingen of om de aggregate-cache
vanuit de stemtabel te herstellen.

REST-endpoints:

- Publiek: `POST /wp-json/zw-poll/v1/vote`
- Editor/admin: `POST /wp-json/zw-poll/v1/poll/{id}/reset`
- Pollbeheer: standaard CPT-routes onder `/wp-json/wp/v2/zw-polls`

Het publieke vote-endpoint gebruikt bewust geen REST-nonce. Beveiliging bestaat
uit same-origin-controle, schemavalidatie, rate limiting en een unieke
deduplicatie-index.

## Ontwikkeling en tests

```bash
composer test      # PHPUnit
composer coverage  # PHPUnit en minimaal 80% line coverage
composer stan      # PHPStan level 8
composer lint      # PHPCS/WPCS
composer lint:fix  # PHPCBF
composer security  # Composer-audit
npm run lint       # JS- en CSS-lint
npm run security   # npm-audit; faalt op high/critical
npm run make-pot   # languages/zw-poll.pot bijwerken
```

Start voor de end-to-endtests eerst Playground in een aparte terminal:

```bash
npm run playground
npm run test:e2e
```

CI controleert daarnaast Plugin Check, de POT-diff en Playwright tegen WordPress
6.9 en de nieuwste WordPress-versie.

## Architectuur

De PHP-code gebruikt de namespace `ZuidWest\Poll\` onder `src/` en wordt gestart
via `Plugin::boot()`. De belangrijkste onderdelen zijn:

- Bootstrap en instellingen: `Plugin`, `Activation`, `Support\Capabilities` en
  `Support\Settings`.
- Polls en stemmen: `PostType\PollPostType`, `Vote\VoteRepository`,
  `Vote\AggregateCache` en `Vote\RateLimiter`.
- REST en shortcode: `Rest\VoteController`, `Rest\AdminController` en
  `Shortcode\PollShortcode`.
- Frontend: `Frontend\PollRenderer`, `Frontend\Assets`,
  `src/Frontend/view.js` en `src/Frontend/style.css`.
- Beheer en CLI: de klassen onder `Admin\` en `Cli\Commands`.

Polls zijn `zw_poll`-posts. Stemmen staan in
`{$wpdb->prefix}zw_poll_votes`; `_zw_poll_aggregate` bewaart de cache met
aantallen per optie-ID. De polltitel is de vraag en is begrensd op 200 tekens.

`PollShortcode::render()` laat `PollRenderer` de Interactivity API-markup bouwen
en server-side verwerken. Daardoor tonen gecachte pagina's ook zonder JavaScript
resultaten. `src/Frontend/view.js` is een native ES-module die
`@wordpress/interactivity` via de WordPress-importmap laadt. De derived state in
PHP en de getters in JavaScript moeten gelijk blijven.

## Beveiliging en privacy

De standaardbeveiliging is bedoeld voor informele polls achter paginacaches.
Cookie-tokens en same-origin-headers beperken gewone dubbele stemmen en fouten,
maar zijn geen cryptografisch bewijs tegen een doelbewuste client. Gebruik de
plugin zonder aanvullende controles niet voor bindende verkiezingen.

- De plugin slaat geen user-ID op bij een stem.
- Deduplicatie gebruikt de functionele cookie `zwpoll_voted_{poll_id}` en een
  unieke index op `(poll_id, cookie_token)`.
- IP-adressen worden met HMAC-SHA256 en de server-side salt `zw_poll_ip_salt`
  gehasht voor rate limiting en audit.
- SQL gebruikt `$wpdb->prepare()` en uitvoer wordt geëscaped.

Voor gevoeligere polls kunnen deze filters de bescherming aanscherpen:

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

    // Controleer hier bijvoorbeeld een WAF-challenge of signed edge-header.
    return $allowed;
}, 10, 4 );
```

`zw_poll_rate_limit` kan een poll alleen verder beperken; de globale limiet
blijft de bovengrens. Gebruik uitsluitend positieve gehele waarden voor `max` en
`window`. `zw_poll_vote_allowed` laat alleen exact `true` door; retourneer voor
een eigen fout een `WP_Error` met expliciete HTTP-status.

### Reverse proxies

Standaard gebruikt de plugin `REMOTE_ADDR`. Stel alleen een proxy-header in
wanneer een vertrouwde proxy binnenkomende headers overschrijft. Bij
`X-Forwarded-For` gebruikt de plugin de laatste waarde; bij proxyketens heeft een
edgeheader met één waarde of een eigen filter de voorkeur:

```php
add_filter( 'zw_poll_client_ip', function ( string $ip ): string {
    // Controleer eerst of het verzoek via een vertrouwde proxy kwam.
    return $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $ip;
} );
```

Ongeldige IP-adressen vallen terug op `REMOTE_ADDR`. Formaatvalidatie maakt een
client-header niet automatisch betrouwbaar. Een edge-rate-limit op
`POST /wp-json/zw-poll/v1/vote` blijft aanbevolen.

## Theming

De neutrale standaardstijl staat in `src/Frontend/style.css` en gebruikt CSS
custom properties op `.zw-poll`. Voor een eigen accentkleur:

```css
.zw-poll {
    --zw-poll-accent: #c8102e;
}
```

Kies een kleur met voldoende contrast. De volledige lijst, inclusief tokens voor
de header en stemknop, staat bovenin `src/Frontend/style.css`.

## Release

Start de releaseworkflow handmatig vanaf `main`. De versie komt uit de
`Version:`-header in [zw-poll.php](zw-poll.php). De workflow voert alle
kwaliteitscontroles uit en publiceert een zip met controlesom. Alleen
`zw-poll.php`, `uninstall.php`, `LICENSE`, `README.md`, `src/` en `languages/`
worden verpakt. Met `force` kan uitsluitend een ontbrekende release voor de
huidige commit worden hersteld; een bestaande release wordt nooit overschreven.

## Licentie

ZuidWest Poll is vrije software onder de [GNU General Public License v2.0 of
nieuwer](LICENSE).

## Buiten scope

Niet voorzien: multi-choice of ranked polls, automatisch sluiten,
export-UI, e-mailnotificaties, externe embeds, A/B-tests, een Gutenberg-block en
een formulierfallback zonder JavaScript.
