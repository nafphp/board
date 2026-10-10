# Working on naf/board

A ticket and kanban application built to show what NAF can carry. Business rules live in
application services; routing, auth, policies, views, form/CSRF, PDO/migrations, ORM,
events, queue, scheduler, mail and translation come from NAF packages.

This package declares `type: naf-plugin` and is discovered after installation in a NAF host.
`naf/nafinity` is the skeleton that installs it for people. The tests need no skeleton: this
repository boots the board itself, with `tests/Fixtures` as its host.

Before changing code, read the [shared contribution workflow](https://github.com/nafphp/docs/blob/main/AGENT_WORKFLOW.md)
and [release procedure](https://github.com/nafphp/docs/blob/main/RELEASING.md).
In the multi-repository workspace, the same documents are in the sibling `docs/` checkout;
use the linked copies when working from a standalone clone. Preserve other contributors' work.
Review and update user documentation with every behavior change.

## Layout

This is a package. A host installs it; the [Nafinity skeleton](https://github.com/nafphp/nafinity)
is the one people use, and in the development workspace it has this working copy symlinked
into its vendor.

- `src/` — the source, namespace `Naf\Board\`. `Contracts`, `Definition`, `Registry`,
  `Domain` and the context classes in `Support` are the API; everything marked `@internal`
  is not. `src/Resources/public/` holds the assets a host publishes into its document root.
- `tests/` — the suite. `tests/Fixtures` is the host it boots; `tests/compose.yaml` is its
  database.
- `examples/` — two extension packages, written as documentation of the extension API.
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
- Project deletion requires an active owner and confirmation of the current project name,
  including for archived projects. `project.deleted` is dispatched before core cleanup so
  extensions can remove their own dependent data in the same transaction.
- Board and installation invitation forms share `InvitationServiceInterface`. New accounts
  choose their own password, get only the initial board and no installation grants. Token hashes,
  expiry, current sponsor rights, bound email and atomic consumption are security boundaries;
  existing accounts must authenticate and their credentials cannot be overwritten. Member
  autocomplete is project-authorized, bounded and limited to active account names/emails.
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
registered. Board declares its bootstrap prerequisites in `extra.naf.boot.after`; extensions
declare `extra.naf.boot.before: ["naf/board"]`. Framework computes their order without a host
`plugins.php`. Board runs the provider pass in its own bootstrap, followed by the host's
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
  Personal hours live in the profile slot and select only the authenticated account's journal.
  Sources authorize their projects before handing rows to `ExportRenderer`; format definitions
  opt into `sources` (default `tickets`), and export events identify the source. Timer stops
  journal whole minutes atomically; manual totals and historical totals are not backfilled.
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
| `Change` | Anything was written — registered kinds, from `ticket.moved` to `account.created` |
| `GrantsChanged` | Roles or permissions moved (from `naf/rbac`) |
| `SignIn` | Somebody tried to sign in, successfully or not |
| `ExportStarted`, `ExportLine`, `ExportFinished` | An export, at its ends and per record |

Three things about them that are decisions rather than accidents:

- **One write event with registered kinds.** The listeners that exist mostly want all
  of them, and a plugin wanting one writes one `if`. Splitting it would make the audit log and
  the live updates register a listener for every kind.
- **`Change` is dispatched inside the transaction that did the work**, so a listener that
  throws refuses the write. That is how a rule no permission can express — one depending on
  the data, the time, another system — gets to stop something.
- **`SignIn` is announced after its transaction**, deliberately the other way round. By then
  the session is published and the person is in; rolling that back would leave them signed in
  with no record of it.

Two spellings of `rbac.granted` are **not** events: the stored change type in the audit log and
the activity vocabulary. Those are strings in rows that already exist.

`docs/Extensibility.md` is the reference: every extension point has an example and a
negative case.

## Running and checking

The suite runs from this repository, with PHP 8.3 or newer and Docker for the database:

```sh
composer install && npm ci
composer test          # Unit, Database, Worker and Http, against tests/compose.yaml
composer style:check   # php-cs-fixer; style:fix applies it
npm test               # the browser modules, with node's own runner
npm run style:check    # Prettier for the JavaScript and CSS
```

The unit tests need nothing else. The first test that needs the database starts the
MariaDB in `tests/compose.yaml` when nothing answers on `DB_HOST:DB_PORT` (127.0.0.1:33306
by default; see `phpunit.xml`) and migrates it down and up. The suite refuses to start unless `APP_ENV=test` and
`DB_DATABASE=nafinity_test`. HTTP tests talk to PHP's built-in server in front of
`tests/Fixtures/public`; mail lands in `tests/Fixtures/storage/mail`.

MariaDB and MySQL are the supported databases. CI runs the commands above on both
MariaDB 11.4 and MySQL 8.4, with PHP 8.3 and 8.5.

Extensions are not tested by installing them into a second host. The rules they rely on --
provider order, explicit replacement, fixed ticket areas -- are unit tests; the rest shows
when the board is used.

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

`vendor/`, `node_modules/`, `composer.lock` and `tests/Fixtures/storage/`. Secrets,
environments, certificates and storage of a real installation belong to its host and never
appear here.

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

Anything dated in `docs/` is a record of a past run, not a description of the current state.
Check the code or run the suite before repeating a number from it.
