# Kirby Omleidingennet plugin

Integrate Omleidingennet detours, areas, points of interest (POIs) and organisation
geometry into Kirby. Includes virtual page models, public cached JSON endpoints,
a native cache-refresh command and server-side Traveler helpers.

## Requirements

- Kirby **5.6.1 or later within 5.x** and PHP **8.2–8.5**.
- Omleidingennet API credentials with access to your organisation's data.
- A writable, non-public Kirby cache directory.
- `getkirby/cli` for scheduled/manual cache refreshes.

Kirby requires its own production license. The plugin does not include frontend
templates, maps, API access rights or a Kirby license.

## Installation

### Git submodule or download

```sh
git submodule add https://github.com/savvycodes/omleidingennet-plugin.git site/plugins/omleidingennet
composer require getkirby/cli:^1.9
```

Alternatively copy the repository to `site/plugins/omleidingennet`.

### Composer

The package name is **`omleidingennet/plugin`**, not the GitHub repository name.
Until a public tagged release and Packagist listing have been confirmed, explicitly
add the VCS repository to your project's `composer.json`:

```json
{
  "repositories": [
    {"type": "vcs", "url": "https://github.com/savvycodes/omleidingennet-plugin"}
  ]
}
```

```sh
composer require omleidingennet/plugin:dev-main getkirby/cli:^1.9
```

Before this readiness work is merged, use `dev-fix/public-release-readiness`
instead of `dev-main`. A private repository still requires GitHub read access.
Composer installs the plugin into `site/plugins/omleidingennet`; the registered Kirby
plugin ID remains `omleidingennet/plugin`. Do not install it twice.

## Configuration

In `site/config/config.php` (or your tenant's equivalent):

```php
<?php
return [
    'omleidingennet.plugin' => [
        'organisationId' => getenv('OMLEIDINGENNET_ORGANISATION_ID') ?: '',
        'clientId' => getenv('OMLEIDINGENNET_CLIENT_ID') ?: '',
        'clientSecret' => getenv('OMLEIDINGENNET_CLIENT_SECRET') ?: '',
    ],
];
```

Set environment variables in your hosting platform. The plugin does not load
`.env` files. Never commit real credentials. Restrict access to backups and cache
files: they include access tokens as well as project data.

| Option under `omleidingennet.plugin` | Default | Purpose |
| --- | --- | --- |
| `url` | `https://www.omleidingennet.nl` | Trusted API origin; do not derive it from user input. |
| `organisationId` | empty | Organisation whose data is refreshed. |
| `clientId` | empty | OAuth client ID. |
| `clientSecret` | empty | OAuth client secret, server-side only. |
| `cache` | `true` | Kirby plugin cache configuration; keep enabled. |

Use the `detours` blueprint/template for a detour listing and
`point-of-interests` for a POI listing. Their virtual children use `detour` and
`point-of-interest`. Add your own templates/snippets to render them. Existing local
detour children remain editable after removal upstream; do not treat them as proof
that a detour remains active in the public API.

## Cache refresh

From the Kirby project root:

```sh
php vendor/bin/kirby omleidingennet:refresh-cache
```

The command refreshes detours, areas, POIs, POI types and organisation data.
Schedule this native command at an interval appropriate to your site. Public
listing endpoints and page models **read caches only**; an empty cache gives empty
listings (organisation is `null`), not a live API request.

Each dataset is replaced only after a complete, validated response. Upstream
failures leave its previous cache intact and make the command fail with a nonzero
exit status. Other datasets are still attempted; the refresh is not a transaction
across all five datasets. Successful empty responses clear old data. Monitor
command failures and freshness; cached public data has no automatic expiration.

Overlapping refreshes for the same cache root and organisation are skipped using
an OS file lock. This protects processes on one host/shared filesystem that
supports `flock`; it is not a distributed lock for independent container filesystems.
Do not delete lock files while processes may be running.

Kirby's HTTP and CLI cache prefixes can differ. The plugin falls back to the
conventional CLI `_` cache namespace for public reads. Host-based multisite projects
must select the correct tenant before running the command (for example via their
bootstrap's `KIRBY_HOST` handling), with isolated tenant cache roots. The plugin
does not choose a tenant. On a shared server, avoid a global overlapping
`omleidingennet:refresh-cache` schedule and use one explicit project/tenant command.
Clear caches and refresh after changing credentials or API origins; the existing
public data cache keys remain organisation-based for compatibility.

`composer omleidingennet:refresh-cache` in this repository uses `command.php` as
a convenience for a conventional project layout. Dependency Composer scripts are
not inherited by consuming projects; prefer the native Kirby CLI above.

## Public JSON API

| GET endpoint | Response key | Filters |
| --- | --- | --- |
| `/omleidingennet/detours` | `detours` | `search_query`, `detour_status`, comma-separated `areas` IDs |
| `/omleidingennet/detours/{id}` | `detours` (one item) | None |
| `/omleidingennet/pois` | `pois` | `search_query`, `poi_type` (type ID) |
| `/omleidingennet/pois/{id}` | `pois` (one item) | None |
| `/omleidingennet/organisation` | `organisation` | None |

Unknown detail IDs return HTTP 404. Filters must be scalar strings of at most
1,000 bytes. Detour listings hide `Afgerond` by default unless a status is selected.
Detail endpoints can still return completed projects.

**These endpoints are unauthenticated.** Detour data includes descriptions,
geometry, project links and infrastructure-manager names, phone numbers and email
addresses supplied by Omleidingennet. Confirm that your organisation is allowed to
publish these fields before enabling the plugin. Escape text/URLs appropriately
in your templates and sanitize any HTML you choose to render.

## Traveler helpers

`Omleidingennet\Plugin\Traveler::transportation($detourId = null)` caches available
transportation modes for 60 minutes, scoped to credentials/organisation.
`Traveler::check($detourId = null)` reads `vehicle`, `from` and `to` from the Kirby
request body. Coordinates are `latitude, longitude`; vehicle IDs are validated.
Unlike the listing API, these helpers make live authenticated upstream requests.
They do not register routes automatically. If you expose them, add suitable request
limits and error handling. Route coordinates are sent to Omleidingennet; document
this in your site's privacy policy. The plugin does not log request coordinates
or full upstream responses. No Sentry dependency is required.

## Development

```sh
composer install
composer validate --strict
composer lint
composer test
composer audit
```

Regression tests use mocked, credential-free HTTP responses. Integration tests boot
a clean Kirby site, check registration/page isolation and exercise the native CLI
against a local mock API. CI runs on PHP 8.2–8.5. Never add live demo credentials or
customer content to fixtures.

## Publication checklist

- Copyright ownership and MIT publication permission have been confirmed by the
  maintainer; the notice in `LICENSE.md` names SavvyCodes.
- Enable GitHub private vulnerability reporting for the repository.
- Review issues, PR discussions, attachments and historical Actions logs for
  sensitive information separately from the code/history scan.
- Review CI results, then have an administrator explicitly approve the visibility
  change. This branch does not change repository visibility or deploy a site.
- Choose a release tag, verify package-name availability and configure Packagist
  before advertising a stable Composer release.

## License and credits

MIT, copyright SavvyCodes; see `LICENSE.md`. Original package author: Daan Rosbergen. See Git
history for contributors. Omleidingennet data and trademarks remain subject to
their owners' terms.
