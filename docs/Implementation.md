# Implementation and acceptance

What is delivered, what was verified last, and what is deliberately missing.

The rules for working on the project are in [`AGENTS.md`](../AGENTS.md), operation and daily
use in the [Nafinity guide](https://nafphp.github.io/docs/built-with/nafinity/), the extension API in
[`Extensibility.md`](Extensibility.md). This document is the acceptance record.
**Every number in it carries the date of its run** — if you need one today, run the command
next to it instead of copying the number from here.

## What is delivered

| Phase | Delivered |
|---|---|
| P0 | Alpine/PHP runtime, Compose, source symlinks, package fixes, migrations, the auth and PDO base path |
| P1 | Local sign-in, separate projects, real tickets, versions and 409 conflicts, activity, the SSR board, ticket detail and drawer |
| P2 | Memberships and roles, columns, swimlanes and labels, multiple assignees, comments, archive, filters and full-text search, settings |
| P3 | Private files with quotas, staging and recovery, the PDO queue, worker and ticker, in-app notifications, a verified mail delivery path |
| P4 | Limiter, health and schema checks, backup and restore, the runtime snapshot; external sign-in prepared and switched off on request |
| — | Extensibility through installed Composer packages; described in [`Extensibility.md`](Extensibility.md) |

## What NAF carries

Routing and responses, the container, auth and policies, views and escaping, form/CSRF and
validation, PDO and the migration registry, ORM models and repositories, events, CLI commands,
queue, scheduler, i18n, the MCP tool contracts and the mail transport contract. Business rules
live in application services. Generic defects were fixed in the packages that own them; there
are no modified vendor copies.

The limiter and LDAP packages have been published since 19 September 2026. Their activation
is an installation decision. Storage provides the private
volume through `Naf\Storage\storage('attachments')`: stream I/O, moving and deleting belong to
the plugin, `Naf\Board\Support\AttachmentStorage` holds the upload rules, safe keys and the cleanup
strategy, and `AttachmentService` owns the SQL and file states.

## Verification

Current package checks are `composer test`, `composer style:check`, `npm test` and
`npm run style:check`. The suite starts its isolated MariaDB and refuses another schema.
MariaDB and MySQL are supported; the PostgreSQL rows below are historical prototype evidence.

On **6 October 2026**, PHP 8.5.10 passed 538 tests with 1,767 assertions, Node passed
38 browser-module tests, and both formatting checks passed. The framework minimum is
`^0.2.8`; HTTP and worker subprocesses no longer change `variables_order` to expose
process environment values.

Browser acceptance on that date used the isolated package host:

- X and Escape return focus to the ticket card. Continuing keeps an unsaved comment;
  discarding clears it when the drawer is reopened.
- An instrumented extension recorded three mounts and three disposals across close,
  reopen and fragment refresh. No owned timers, listeners or root references remained.
  The module tests additionally cover a mount resolving after its root was disposed.
- Keyboard moves across columns and swimlanes persist after reload. The move dialog
  works at a 390 × 844 viewport. Pointer dragging has a touch hold path, but no physical
  touch-device acceptance is claimed.
- Classic remains the migration default. Anthracite and brightness persist separately
  through reload, sign-out and sign-in; switching brightness retains the palette.
- English covers navigation, ticket fragments, validation, tool titles and browser
  messages. The narrow language picker exposes visible, named options.
- Native Chrome and Firefox mouse gestures remain an acceptance blocker: the desktop
  input tool cannot associate the visible browser window with a drag target
  (`noWindowsAvailable` / `windowNotFoundAtPosition`). No native drag success is claimed.

The following is a historical run using the skeleton's former commands.

Full run on **18 September 2026** on `feat/plugin-extensibility`, everything green:

| Check | Command | Result |
|---|---|---|
| Application on MariaDB 11.4 | `make test-mariadb` | 74 checks |
| Application on PostgreSQL 17 | `make test-postgres` | 74 checks |
| Real HTTPS requests | `make test-http` | 102 checks |
| Account changes, SMTP, session revocation | `make test-profile` | 25 checks |
| Worker kill, lease recovery, dead letters | `make test-worker` | 3 checks |
| AI transport and semantic router | `make test-ai` | both suites |
| Extensions with and without both example packages | `make test-plugins` | 65 checks: 42 in-process, 6 over HTTP, 4 with the listing reversed, 7 assets, 6 without the packages |
| Style and whitespace | `bin/style check`, `git diff --check` | green |

At that time `make test` ran those targets together. These targets are no longer the
package test entry points; use the commands above.

On the same day in the changed NAF packages, each with `composer test` on its RC branch:
naf/framework 137 tests with 280 assertions, naf/i18n 39 tests with 108 assertions — both green.

The HTTP acceptance covers foreign project ids, roles, CSRF including a tampered bearer header,
stored HTML rendered as escaped text, parallel moves with one success and one 409, private
downloads, file deletion, the login limit, and the public web root shielding `.env`, `vendor`,
the Composer files and storage.

## Measurement

Board queries on 15 September 2026 against eight seeded tickets plus 5,000 additional tickets
in an isolated test project; one warm-up, then ten measurements. Not an HTTP or load test.

| Query | MariaDB median | PostgreSQL median |
|---|---:|---:|
| Board without filters | 3.54 ms | 2.10 ms |
| Full-text "sunflower" | 10.12 ms | 18.23 ms |

Hence the limit of 300 cards per query. Drag and drop is disabled on filtered or truncated
views so that invisible neighbours cannot produce a wrong position; the move menu stays
available.

## Remaining limits

- The skeleton resolves released packages from Packagist at installation/build time.
  Committing a distribution lock is not the chosen installation policy.
- No external LDAP server, OIDC issuer or SMTP service was contacted. Enabling those still needs
  deployment configuration and end-to-end acceptance. Local SMTP through Mailpit is verified for
  account verification and security notices.
- Project mail is switched off. The ledger and queue combination bounds ordinary duplicates, but
  an external SMTP result cannot be confirmed atomically with a PDO transaction.
- German is the base language. English covers the core UI, validation, activity vocabulary
  and browser messages. Project-owned content and extension-provided text keep their wording.
- The file allowlist and MIME check are not a virus scanner. At most 10 MiB per file, 30 MiB per
  ticket and 200 MiB per project; storage stays private.
- One board per project, no WIP enforcement, no saved filters, no public full API and no inbound
  mail processing. All explicitly outside the MVP.
- Live updates arrive over a socket when an installation runs naf/websocket, which is optional and
  off by default. What travels is that a board changed and at which revision; the board itself is
  fetched over the ordinary authorised path, so a page without a socket is behind and never wrong.
- The native drag-and-drop path is not manually accepted; the automated drag gesture produced no
  visible change in the browser tooling used. The move dialog and the same server-side move path
  are verified.

## Distribution

Every NAF package Nafinity requires is released. On **19 September 2026** the manifest resolved
entirely from Packagist for the first time: eighteen NAF packages, all as `zip`, no path
repository anywhere in the lock.

| Released that day | |
|---|---|
| framework `v0.2.5` · i18n `v0.2.2` | cli · client · database · mail · orm · session · auth · view `v0.2.2` |
| form · queue · schedule · mcp · oauth-client `v0.2.3` | storage · rate-limit · auth-ldap `v0.1.0`, first releases |

Rather than trusting this list, ask the resolver:

```sh
docker compose run --rm --no-deps -T --volume "$PWD/app:/dist:ro" --workdir /tmp app \
  sh -c 'cp /dist/composer.json . && composer update --dry-run --no-install'
```

A clean install no longer depends on local package sources; the skeleton's production image
installs its dependencies from Packagist while it is built.

The extensibility work is on `main`, merged from
[nafphp/nafinity#1](https://github.com/nafphp/nafinity/pull/1).

## Measurement on 6 October 2026

PHP 8.5.10 on the local host, MariaDB 11.4 in the isolated test container, no CLI
OPcache: 5,000 additional tickets plus the suite's current demo data, one warm-up
and ten measurements. The benchmark removes only IDs it inserted.

| Query | p50 | p95 | Cards returned |
|---|---:|---:|---:|
| Unfiltered | 5.25 ms | 5.74 ms | 300 |
| Full text “sunflower” | 12.31 ms | 12.95 ms | 300 |

Peak PHP memory was 8 MiB. These are query timings, not HTTP or browser throughput.

The real authorized AI catalog contains seven owner tools and three viewer read tools.
Ten German/English tasks, repeated three times, retained all required tools with both
installed embedding models. Warm selection p50/p95 was 33.82/38.08 ms for embeddinggemma
and 32.07/37.94 ms for nomic-embed-text. The keyword fallback improved from 9/10 to
10/10 complete cases after adding bilingual action keywords. Viewer selections never
included unavailable writes. An additional 500-entry run explicitly uses synthetic
measurement distractors; it does not establish recall for 500 implemented tools.

Exact model digests, cold/warm rows and the before/after result are in
[`tests/benchmarks/results/2026-10-06.json`](../tests/benchmarks/results/2026-10-06.json).
Reproduce with the commands and environment options in
[`tests/benchmarks/README.md`](../tests/benchmarks/README.md). No tool writes were executed.
