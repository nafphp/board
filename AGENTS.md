# Working on naf/board

A ticket and kanban application built to show what NAF can carry. Business rules live in
application services; routing, auth, policies, views, form/CSRF, PDO/migrations, ORM,
events, queue, scheduler, mail and translation come from NAF packages.

This package declares `type: naf-plugin` and is discovered after installation in a NAF host.
The repository is not the application's web root -- `naf/nafinity` is the skeleton that
installs it, and that is where the container, the Makefile and the suites are run from.

Before changing code, read the [shared contribution workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md)
and [release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md).
In the multi-repository workspace, the same documents are in the sibling `docs/` checkout;
use the linked copies when working from a standalone clone. Preserve other contributors' work.
Review and update user documentation with every behavior change.

## Layout

This is a package. It has no environment, no container and no installation of its own: a
host installs it, and the [Nafinity skeleton](https://github.com/nafphp/nafinity) is that
host during development, with this working copy symlinked into its vendor.

- `src/` — the source, namespace `Naf\Board\`. `Contracts`, `Definition`, `Registry`,
  `Domain` and the context classes in `Support` are the API; everything marked `@internal`
  is not.
- `public/` — assets the host publishes into its own document root.
- `tests/` — the suite. It boots the host, not this package.
- `examples/` — two extension packages the acceptance run really installs.
- `docs/` — how the pieces fit together, for people working on them.

Generic defects belong in the owning NAF repository, following its own `AGENTS.md`, not in
a workaround here.

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
- German is the complete base language of the product UI; English covers the main
  interface texts. Code, comments and documentation are English.

## Extensibility

An installed Composer package of `type: naf-plugin` contributes routes, controllers,
services, menu entries, permissions, settings, ticket fields, widgets, board filters,
translations, AI tools, commands, migrations and jobs. It registers providers in its
bootstrap through `Naf\Board\extensions()->register(...)`; all providers run in one pass
after the application's own defaults, so a package can replace what the application
registered. The host boots infrastructure first, all installed extension plugins next and
`naf/board` last. Board runs the provider pass in its own bootstrap, followed by the host's
optional `extensions.php`. NAF loads host routes afterwards. Service resolution and Board
overrides belong in providers, not in an extension's early bootstrap or route file.

- Every registry sorts by one `index`, ties broken by `strcmp()` on the id. Replacing an
  existing id is explicit, never accidental.
- Title, description and comments are fixed ticket areas (`TicketFieldRegistry::FIXED_AREAS`,
  `UiRegistry::RESERVED_IDS`) and can be neither replaced nor removed.
- Contributed ticket values live in `ticket_metadata`, one row per key, written inside the
  existing domain transaction, project lock and version check.
- Settings have four scopes: `user`, `project`, `project_user`, `application`.
- Export cards belong to board and installation settings. Both use the exporter registry;
  combined downloads retain per-board export and metadata read permissions.
  `ExportOptions` normalizes selection filters; the existing unfiltered export API stays valid.
- A slot hands its contributions a typed context, not a loose array. Use that context and its
  `value()` and `field` instead of reaching for an own query or form.
- Uninstalling a package takes its contributions away and leaves the stored data alone.

### Events are the other half

Registries say what exists; events are how a plugin takes part in something already running.
There are six, and each **is** a class — `dispatch(new Change(…))`, `listen(Change::class, …)`.
A misspelled class is an error where it is written; a misspelled event name used to be a
listener that never ran and never said so.

| Event | When |
|---|---|
| `Change` | Anything was written — 24 kinds, from `ticket.moved` to `account.created` |
| `GrantsChanged` | Roles or permissions moved (from `naf/rbac`) |
| `SignIn` | Somebody tried to sign in, successfully or not |
| `ExportStarted`, `ExportLine`, `ExportFinished` | An export, at its ends and per record |

Three things about them that are decisions rather than accidents:

- **One write event with 24 kinds, not 24 events.** The listeners that exist mostly want all
  of them, and a plugin wanting one writes one `if`. Splitting it would make the audit log and
  the live updates register twenty-four times each.
- **`Change` is dispatched inside the transaction that did the work**, so a listener that
  throws refuses the write. That is how a rule no permission can express — one depending on
  the data, the time, another system — gets to stop something.
- **`SignIn` is announced after its transaction**, deliberately the other way round. By then
  the session is published and the person is in; rolling that back would leave them signed in
  with no record of it.

Two spellings of `rbac.granted` are **not** events: the stored change type in the audit log and
the activity vocabulary. Those are strings in rows that already exist.

`docs/Extensibility.md` is the reference: every extension point has an executed
example and a negative case.

## Running and checking

Everything runs from the host, because the container is the host's. This package sits with
the other NAF packages; the skeleton is checked out beside that directory, and everything
below runs from there:

```sh
cd ../../nafinity
make first-install      # .env, certificates, image, dependencies, migrations, seed, start
make test               # MariaDB, PostgreSQL, HTTP, profile, worker, AI and extensions
make test-plugins       # installs both example packages, then boots the same database without them
bin/style check         # this package and the host, each with its own rules
make restart-background # after changes to worker or scheduler code
```

The suite lives here and runs there: the Makefile points at this working copy through
`BOARD`, and the tests take the installation to boot from `NAF_HOST`.

Both MariaDB and PostgreSQL have to pass; a change that works on only one is not finished.
The runners refuse to start unless `APP_ENV=test` and `DB_DATABASE=nafinity_test`. The
development database `nafinity` is never a test target and is never reset.

### What runs here and what runs on a push

CI checks what can be checked without a host: the manifest, that every PHP file and every
template parses, and the browser modules. Everything above needs the skeleton — two database
engines, a server answering over HTTPS, throwaway hosts for the examples — so a green run on
GitHub is not a green suite. Before a release, run `make test` and say so.

## Style

PER Coding Style 3.0 with locally aligned `=` and `=>`, descriptive variable names and blank
lines between logical steps; the shared [PHP code style](https://github.com/nafphp/docs/blob/main/CODE_STYLE.md)
and `.php-cs-fixer.dist.php` hold the exact rules. Keep SQL, mixed
PHP/HTML templates and build scripts readable, and preserve escaping, form-value whitespace,
evaluation order and transaction boundaries.

## Operation

How the application is served is the host's business -- supervisor, nginx, PHP-FPM, the
queue consumer and the scheduler ticker all live in its container, and the skeleton's
`AGENTS.md` describes them. What matters here: configuration uses NAF's native
`ENV:VARIABLE_NAME` references with defaults supplied by the host, so do not duplicate that
with `getenv()` in application configuration.

## Never committed or baked into an image

`vendor/`, and anything a host leaves behind while this package is developed inside it.
Secrets, environments, certificates and storage belong to the host and never appear here.

## Release gating

Source mode may build against tested RC branches of the NAF packages. A stable distribution
waits for the maintainer to merge and publish them. Never fake a stable alias. Merging and
releasing is the maintainer's decision, not the agent's.

## Further reading

| Document | Holds |
|---|---|
| `README.md` | What this is, where to install it, the licence — and nothing else |
| [Build with NafPHP](https://nafphp.github.io/docs/built-with/nafinity/) | The skeleton, running it, the commands, how a plugin extends it |
| `docs/Extensibility.md` | The extension API in full, with examples |
| `docs/Tickets.md` | Ticket behaviour, data model, limits |
| `docs/Settings-And-AI.md` | Settings cards, custom roles, local Ollama |
| `docs/Profile.md` | Account changes, verification codes, security boundaries |
| `docs/Implementation.md` | What is delivered, what the last full run proved, what is missing |
| `docs/Extensibility-Status.md` | Acceptance record of the extensibility work, honest about what is open |

Anything dated in `docs/` is a record of a past run, not a description of the current state.
Check the code or run the suite before repeating a number from it.
