# Nafinity

A ticket and kanban application built to show what NAF can carry. Business rules live in
application services; routing, auth, policies, views, form/CSRF, PDO/migrations, ORM,
events, queue, scheduler, mail and translation come from NAF packages.

## Layout

- `app/` — the Composer application. `app/app/` is its source; `app/public` is the only web root.
- `packages` — symlink to `../nafphp`. Fix generic defects there, in the owning repository,
  following its own `AGENTS.md`. Never edit `app/vendor/`.
- `examples/` — two extension packages that the acceptance run really installs.
- `docker/rootfs` — mirrors container paths; the Dockerfile copies the tree to `/`.
- `tools/style` — pinned formatters, kept out of the build context and the runtime image.
- `work/` — scratch: backups, TLS, disposable test hosts. Never committed.

## Before adding an abstraction

Read the NAF package source first and use what is already there: helpers, DI, events,
policies, forms, migrations, ORM, queue, scheduler. A new optional capability belongs in a
separate Composer package rather than in the core — the extension platform below exists for
that. Do not rebuild onto a CMS, Laravel, a second container, a router, an event bus, an ORM
or a plugin metaframework.

## Data and authorization

- Every read and write path requires project authorization. Composite foreign keys give
  integrity, not read authorization.
- Writes carry a ticket version; a stale write ends with 409 and the interface offers a reload.
- Attachments are private: 10 MiB per file, 30 MiB per ticket, 200 MiB per project, reached
  only through the application via `Naf\Storage\storage('attachments')`. The allowlist and
  MIME check are not a virus scanner.
- A board query answers with at most 300 cards plus the total count.
- German is the complete base language; English covers the main interface texts.

## Extensibility

An installed Composer package of `type: naf-plugin` contributes routes, controllers,
services, menu entries, permissions, settings, ticket fields, widgets, board filters,
translations, AI tools, commands, migrations and jobs. It registers providers in its
bootstrap through `Nafinity\extensions()->register(...)`; all providers run in one pass
after the application's own defaults, so a package can replace what the application
registered.

- Every registry sorts by one `index`, ties broken by `strcmp()` on the id. Replacing an
  existing id is explicit, never accidental.
- Title, description and comments are fixed ticket areas (`TicketFieldRegistry::FIXED_AREAS`,
  `UiRegistry::RESERVED_IDS`) and can be neither replaced nor removed.
- Contributed ticket values live in `ticket_metadata`, one row per key, written inside the
  existing domain transaction, project lock and version check.
- Settings have four scopes: `user`, `project`, `project_user`, `application`.
- A slot hands its contributions a typed context, not a loose array. Use that context and its
  `value()` and `field` instead of reaching for an own query or form.
- Uninstalling a package takes its contributions away and leaves the stored data alone.

`docs/Plugin-Erweiterbarkeit.md` is the reference: every extension point has an executed
example and a negative case.

## Running and checking

Composer runs in the container, through `make composer` or `bin/dev-composer` — never on the host.

```sh
make first-install      # .env, certificates, image, dependencies, migrations, seed, start
make test               # MariaDB, PostgreSQL, HTTP, profile, worker, AI and extensions
make test-plugins       # installs both example packages, then boots the same database without them
bin/style check         # PER Coding Style 3.0, JavaScript, CSS, Python
make restart-background # after changes to worker or scheduler code
```

Both MariaDB and PostgreSQL have to pass; a change that works on only one is not finished.
The runners refuse to start unless `APP_ENV=test` and `DB_DATABASE=nafinity_test`. The
development database `nafinity` is never a test target and is never reset.

## Style

PER Coding Style 3.0 with locally aligned `=` and `=>`, descriptive variable names and blank
lines between logical steps; `.php-cs-fixer.dist.php` holds the exact rules. Keep SQL, mixed
PHP/HTML templates and build scripts readable, and preserve escaping, form-value whitespace,
evaluation order and transaction boundaries.

## Operation

Supervisor runs nginx, PHP-FPM, `naf queue:consume` and `naf schedule:ticker` together in the
app container. Local HTTPS is port 443; `make certificates` generates the CA and certificate.
Test and candidate services set `NAFINITY_BACKGROUND_ENABLED=false` explicitly. Configuration
uses NAF's native `ENV:VARIABLE_NAME` references with defaults in Compose; do not duplicate
this with `getenv()` in application configuration.

## Never committed or baked into an image

Secrets, `.env`, `vendor/`, generated development manifests and locks, TLS keys or
certificates, private storage, logs, anything under `work/`.

## Release gating

Source mode may build against tested RC branches of the NAF packages. A stable distribution
waits for the maintainer to merge and publish them. Never fake a stable alias. Merging and
releasing is the maintainer's decision, not the agent's.

## Further reading

| Document | Holds |
|---|---|
| `README.md` | Starting the application, demo accounts, ports, daily operation |
| `docs/Plugin-Erweiterbarkeit.md` | The extension API in full, with examples |
| `docs/Ticket-Details.md` | Ticket behaviour, data model, limits |
| `docs/Settings-AI.md` | Settings cards, custom roles, local Ollama |
| `docs/Profile.md` | Account changes, verification codes, security boundaries |
| `docs/Implementation.md` | What is delivered, what the last full run proved, what is missing |
| `docs/history/` | Frozen pre-build analysis, plan and review — why it is built this way |

Anything dated in `docs/` is a record of a past run, not a description of the current state.
Check the code or run the suite before repeating a number from it.
