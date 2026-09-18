# Nafinity

A ticket and kanban application running on [NAF](https://github.com/nafphp). Business rules
live in application services; routing, auth, policies, views, form/CSRF, PDO and migrations,
ORM, events, queue, scheduler, mail and translation come from NAF packages.

The interface ships German and English. German is the complete base language, English covers
the main interface texts.

## Getting started

You need Docker with Compose, Make, OpenSSL and Python 3 on the host, plus the local NAF
packages in `../nafphp`.

```sh
make first-install
```

That creates a missing `.env` with random local database passwords, generates the local TLS
certificate, builds the image, installs the Composer dependencies inside the container, runs
the NAF migrations and the demo seed, and starts the app, database, worker and scheduler. An
existing `.env` is left alone — `NAFINITY_PORT`, `NAFINITY_HTTPS_PORT` and `NAF_SOURCE_ROOT`
can be adjusted there.

Afterwards `make run` starts the installed environment; after changes to Docker files use
`make build-app run`.

```sh
make                       # every available command
make status                # containers and health
make logs                  # follow app, worker and scheduler logs
make ssh                   # a shell as www inside the app container
make composer ARGS='show naf/framework'
make naf                   # the available NAF commands
make stop                  # stop all Nafinity containers
make down                  # remove containers and network, keep database and files
```

Open **https://localhost** (port 443). HTTP on port 8088 redirects there with status 308.

The seed only runs against an empty development or test database; on an existing one it skips
initialisation and leaves the data untouched. Demo password for all three accounts:
`Nafinity-Demo-2026!`.

| Account | Role and project |
|---|---|
| alice@example.test | Owner of Nafinity and Archiv & Ideen |
| bob@example.test | Owner of Studio Nord |
| viewer@example.test | Viewer on Nafinity |

## What it does

Separate projects with their own memberships and roles, configurable columns and swimlanes,
labels, multiple assignees, priority and due date. Tickets have their own URLs and an optional
drawer, and can be edited, moved, closed, reopened and archived. A ticket can move to another
project: comments, attachments, history and logged time come along, it is given a new number
there, and labels, links and assignees without access stay behind. Comments and activity
follow project boundaries. A stale write ends with 409 and the interface offers a reload.

[Tickets](docs/Tickets.md) describes the reading view, inline editing with auto-save, the
formatted description, the metadata bar, start date and time spent. The whole board card opens
the ticket while its menu stays separately operable. Linked tickets appear on both sides;
comments support @-mentions and threaded replies. Columns and people are picked from the same
searchable dropdown. New tickets are created in a modal using the same template, editor and
metadata fields.

Filters and real full-text search, private attachments on a named NAF storage volume with
quotas and recovery, in-app notifications, settings, light and dark themes and a mobile layout
are all in place. Settings open as animated cards and hold custom project roles and a local
Ollama chat; see [Settings and local AI](docs/Settings-And-AI.md). A board answers with at most
300 cards plus the total count — use the filters on larger sets.

## Extensibility

Nafinity can be extended by installed Composer packages. A package of `type: naf-plugin`
contributes its own controllers, routes and services, menu entries, permissions, settings,
ticket fields, widgets, board filters, translations, AI tools, commands, migrations and jobs,
and can explicitly replace what the application registered.

```php
// In the package's bootstrap.php
Nafinity\extensions()->register('example.reports', ReportsProvider::class, index: 100);
```

Every noted provider runs in one pass after the application's own defaults, ascending by index
and id, so a package can replace a default instead of being overwritten by it. Title,
description and comments stay fixed parts of a ticket. Uninstalling a package removes its
contributions and keeps the data people stored with it.

[Extensibility](docs/Extensibility.md) is the full reference — every extension point has a
worked example and a negative case. Two complete example packages live in `examples/`.

```sh
make test-plugins   # installs both examples as real Composer packages into a throwaway host,
                    # checks them in-process, over HTTP and through the asset commands,
                    # then boots the same database once more without them
```

## Checks and operation

The code follows PER Coding Style 3.0 with locally aligned assignments; the exact rules are in
`.php-cs-fixer.dist.php` and summarised in [`AGENTS.md`](AGENTS.md). Run `make style-install`
once, then `make style-check` or `make style-fix`. The check covers PHP, PHP in templates,
JavaScript, CSS and the Python scripts. The formatters additionally need Node.js and npm on the
host, are pinned under `tools/style` with their own locks, and stay out of the build context
and the runtime image.

```sh
make test                  # everything: MariaDB, PostgreSQL, HTTP, profile, worker, AI, extensions
make test-profile          # account changes, code verification and session revocation
make test-ai               # AI transport and semantic router, without a running model
make test-down             # stop the test services afterwards
make mailpit               # start the local test mailbox on port 8025
make backup
make verify-restore BACKUP=work/backups/TIMESTAMP
make supervisor-status     # all four app processes
```

The tests create `nafinity_test` when needed and reset only those test databases; the
development database `nafinity` is never a test target. `make test-http` prepares its fixtures
on every call. See [Implementation and acceptance](docs/Implementation.md) for results, limits
and release branches.

Health lives at `/health/live` and `/health/ready`; readiness checks the database, the required
application migrations, the background tables and the native storage binding.

Backups cover the database and the private files. The restore check writes exclusively to
`nafinity_restore_test` and never replaces a running application. Treat backups as private.

`bin/check-plugin-stack` boots the minimal and the complete NAF plugin stack in isolated source
hosts. Upload rules belong to `App\Support\AttachmentStorage`; file I/O goes through
`Naf\Storage\storage('attachments')`.

## Docker layout

As in the sibling projects, `docker/rootfs` mirrors the paths inside the container and the
Dockerfile copies that tree to `/`:

```text
docker/
├── Dockerfile
├── rootfs/etc/
│   ├── nginx/
│   │   ├── nginx.conf
│   │   ├── conf.d/app.conf
│   │   └── ssl/                 # certificate and key: local only
│   ├── php85/
│   │   ├── conf.d/99-nafinity.ini
│   │   ├── php-fpm.conf
│   │   └── php-fpm.d/www.conf
│   ├── ssl/nafinity.cnf         # local certificate generation
│   └── supervisor/
│       ├── conf.d/supervisord.conf
│       └── programs/
│           ├── nginx.conf
│           ├── php-fpm.conf
│           ├── queue-worker.conf
│           └── schedule-ticker.conf
├── rootfs/usr/local/bin/
│   ├── nafinity-entrypoint
│   └── nafinity-healthcheck
└── development/rootfs/etc/php85/conf.d/zz-development.ini
```

The development target adds the OPcache settings that make source changes visible right away;
runtime and production use the shared tree only. Supervisor runs nginx, PHP-FPM,
`naf queue:consume` and `naf schedule:ticker` together in the app container, while MariaDB stays
its own service. `make supervisor-status` shows all four processes and `make restart-background`
restarts just the worker and the ticker. The container healthcheck covers HTTPS, both Supervisor
processes and their NAF heartbeats. Test and candidate services set
`NAFINITY_BACKGROUND_ENABLED=false` so they run no extra work and touch no isolated fixtures.

## Local HTTPS

`make certificates` uses OpenSSL to create a private development CA and a server certificate it
signs for `localhost`, `nafinity.local`, `127.0.0.1` and `::1`. It is valid for 365 days; a
still-valid matching certificate and key pair is kept on a repeated call. `make first-install`,
`make run` and the test and candidate targets call it automatically. Run `make restart` after a
renewal.

The private CA key lives only in `work/tls` and is never mounted into the container. The server
TLS files live in `docker/rootfs/etc/nginx/ssl`, are excluded from git and from both image
builds, and are mounted read-only. The key has file mode 0600.

For a browser without a certificate warning, import the public CA certificate `ca.pem` and mark
it trusted for SSL. No private key is imported for that, and the setup does not modify the
system keychain. CLI and integration tests verify TLS against the public CA certificate as an
explicit trust anchor.

Port 443 has to be free. Alternatively set `NAFINITY_HTTPS_PORT=8443` in `.env`; the HTTP
redirect follows that port. The test service uses HTTPS on 8444 and the candidate on 8445.
Inside the container nginx binds the unprivileged port 8443 and still runs as `www`.

## Local framework sources

`packages -> ../nafphp` exists for the IDE. Compose mounts the application at `/workspace/app`
and the NAF sources at `/workspace/packages`. `bin/dev-composer` writes an ignored source
manifest with explicit development versions. Composer runs inside the container and produces
relative vendor symlinks that resolve on the host and in the container alike.

Configuration uses NAF's native `ENV:VARIABLE_NAME` references, including the optional LDAP and
OIDC credentials in the configuration example. Defaults live in Compose; outside Compose,
provide the variables through the environment or NAF's `.env`/`.env.local` in the application
directory. PHP uses `variables_order=EGPCS` in the container so NAF can resolve values from
`$_ENV`.

FPM picks up source changes on the next request. After changes to background code:

```sh
make restart-background
```

## Source mode and distribution

The fixes in use sit on RC branches, with the limiter and LDAP kept local. `app/composer.json`
describes the future minimum versions required. A clean install from published packages and a
stable lock are only possible once those are released — no package has been merged or published.

`make candidate-build` already builds a frozen local source snapshot without source mounts,
vendor symlinks or Composer in the runtime image. It is explicitly an
`unreleased-source-snapshot`, not evidence of a published distribution. `make candidate-up`
starts it on https://localhost:8445 and `make candidate-down` stops it again. The production
target instead requires a real `composer.lock`, a link-free vendor directory and the marker
`vendor/.nafinity-distribution` after a verified dist install.

Local sign-in stays active. LDAP and OIDC are prepared but switched off, and external accounts
are only ever linked explicitly. `app/identity.example.php` documents the configuration; private
values belong in the ignored `identity.local.php`.

Security mail runs locally through NAF's MailTransport and Mailpit, with the test mailbox on
http://localhost:8025. Nothing is sent to the internet, and project notifications by mail stay
disabled. [Profile and account verification](docs/Profile.md) covers the flow, the SMTP
configuration and the security boundaries.
