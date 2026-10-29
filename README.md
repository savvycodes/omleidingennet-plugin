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

Or directly within the docker container:

```
php vendor/bin/kirby omleidingennet:refresh-cache
```

In this project, you can also run it through Composer without a global `kirby` binary:

```
composer omleidingennet:refresh-cache
```

The command refreshes detours, areas, POIs, POI types and organisation data. Public data cache entries do not expire on visitor requests; the site keeps serving the last complete refresh until the command replaces it. Overlapping refreshes are skipped while a refresh is already running.

The Kirby CLI package must be installed on the server for the `kirby` command to be available.

### Scheduled refreshes on shared servers

Each application and environment needs its own scheduled refresh. The plugin does not install a cron job or automatically recover a missing schedule: without successful refreshes, visitors keep seeing the last imported data, even when records have changed in Omleidingennet.

When deploying multiple websites on the same server, make **both the cron filename and job name unique per application and environment**. An environment-only filename such as `omleidingennet_production` is shared across all websites. A later deployment can overwrite it, leaving only that website refreshing its cache.

For an Ansible-managed Docker Compose deployment, use a task like this (adapt the paths and CLI location to your deployment):

```yaml
- name: Add cronjob for omleidingennet cache refresh
  become: true
  ansible.builtin.cron:
    name: "omleidingennet refresh cache ({{ app_name }} {{ env }})"
    minute: "*/5"
    user: "{{ user }}"
    # cron.d filenames may only contain letters, digits, underscores and hyphens.
    cron_file: "omleidingennet_{{ app_name | regex_replace('[^A-Za-z0-9_-]', '_') }}_{{ env }}"
    job: >-
      docker compose --project-name {{ app_name | replace('.', '') }}_{{ env }}_$(basename $(readlink /srv/{{ app_name }}/{{ env }}/current))
      --project-directory /srv/{{ app_name }}/{{ env }}/current exec --user www-data -T kirby
      php /var/www/vendor/bin/kirby omleidingennet:refresh-cache
    state: present
```

The example assumes `/srv/<app_name>/<env>/current` is a symlink to the release directory, and that the Compose project name matches the application's deployment. The cron user must have permission to execute Docker Compose; `www-data` must be able to write the application's cache. Ensure application identifiers remain unique after filename sanitization.

#### Migrating an existing shared cron file

1. Inspect the existing `omleidingennet_<env>` file to identify which application it currently refreshes.
2. Install application-specific jobs for every affected application and environment. Do not simply overwrite the shared job with another application's command.
3. Remove an application's legacy job only after its replacement exists **and only if the legacy command targets that application**. Leave another application's job intact until its own migration is complete.
4. Run one manual refresh for each affected application to replace stale data immediately.
5. Verify that the next scheduled run updates each application's cache without manual intervention. Check the website's `/omleidingennet/detours` response against the changed source records.

Changing the cron filename in one application's deployment does not migrate the other applications or remove their legacy schedules automatically. Apply the naming convention to all deployment configurations sharing the server. A plugin update alone cannot repair these externally managed jobs.

### Troubleshooting stale data

- Check that the scheduled command targets the correct application, environment and current container, and that the scheduler is running.
- Check the cache modification times and scheduler logs. If the cache has not changed since deployment, verify that another application's deployment has not replaced the cron job.
- If manual refreshes work but scheduled refreshes do not, compare the cron user's permissions, executable paths and Compose project name with the working command.
- If refreshes fail, inspect the command output and API errors before clearing caches. The last complete cache is retained to keep the website available.

## Options

_Document the options and APIs that this plugin offers_

```
[
  'options' => [
    'cache' => true,
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
