# Kirby Omleidingennet plugin

> Variant "Setup with autoloader"

This is a Omleidingennet plugin for Kirby that can be installed via all three [supported installation methods](https://getkirby.com/docs/guide/plugins/plugin-setup-basic#the-three-plugin-installation-methods).

## Installation

### Download

Download and copy this repository to `/site/plugins/omleidingennet`.

### Git submodule

```
git submodule add https://github.com/savvycodes/omleidingennet-plugin.git site/plugins/omleidingennet
```

### Composer

```
composer require savvycodes/omleidingennet-plugin
```

## Setup

_Additional instructions on how to configure the plugin (e.g. blueprint setup, config options, etc.)_

Add `organisationId`, `clientId` and `clientSecret` to your `config.php`

```
[
  'omleidingennet.plugin' => [
    'organisationId' => '<YOUR_ORGANISATION_ID>',
    'clientId' => '<YOUR_CLIENT_ID>',
    'clientSecret' => '<YOUR_CLIENT_SECRET>',
  ]
]
```

## Cache refresh command

Public Omleidingennet routes serve cached data only. They do not call the Omleidingennet API when a visitor loads a page. Refresh the cache manually with the Kirby CLI command:

```
kirby omleidingennet:refresh-cache
```

In this project, you can also run it through Composer without a global `kirby` binary:

```
composer omleidingennet:refresh-cache
```

The command refreshes detours, areas, POIs, POI types and organisation data. Public data cache entries do not expire on visitor requests; the site keeps serving the last complete refresh until the command replaces it. Overlapping refreshes are skipped while a refresh is already running.

The Kirby CLI package must be installed on the server for the `kirby` command to be available.

## Options

_Document the options and APIs that this plugin offers_

```
[
  'options' => [
    'cache' => true,
    'ttl' => 5,
    'url' => 'https://www.omleidingennet.nl',
    'organisationId' => '',
    'clientId' => '',
    'clientSecret' => '',
  ]
]
```

## Development

_Add instructions on how to help working on the plugin (e.g. npm setup, Composer dev dependencies, etc.)_

## License

MIT

## Credits

- [Your Name](https://github.com/ghost)
