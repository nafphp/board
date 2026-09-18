# Implementation and acceptance

What is delivered, what was verified last, and what is deliberately missing.

The rules for working on the project are in [`AGENTS.md`](../AGENTS.md), operation and daily
use in the [`README.md`](../README.md), the extension API in
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
| — | Extensibility through installed Composer packages; state and open points in [`Extensibility-Status.md`](Extensibility-Status.md) |

## What NAF carries

Routing and responses, the container, auth and policies, views and escaping, form/CSRF and
validation, PDO and the migration registry, ORM models and repositories, events, CLI commands,
queue, scheduler, i18n, the MCP tool contracts and the mail transport contract. Business rules
live in application services. Generic defects were fixed in the packages that own them; there
are no modified vendor copies.

The limiter and LDAP packages stay local by explicit request. Storage provides the private
volume through `Naf\Storage\storage('attachments')`: stream I/O, moving and deleting belong to
the plugin, `App\Support\AttachmentStorage` holds the upload rules, safe keys and the cleanup
strategy, and `AttachmentService` owns the SQL and file states.

## Verification

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

`make test` runs those targets together. Both databases have to be green, and the runners
refuse to start without `APP_ENV=test` and `DB_DATABASE=nafinity_test`.

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

## Evidence

Machine-readable results of individual runs. All of them are snapshots carrying the date of
their run, not a description of today's state.

| File | Holds |
|---|---|
| [`Docker-Evidenz.json`](Docker-Evidenz.json) | Runtime, ports, processes, healthcheck |
| [`Snapshot-Evidenz.json`](Snapshot-Evidenz.json) | Candidate image without source mounts |
| [`Code-Style-Evidenz.json`](Code-Style-Evidenz.json) | Formatter run and commit evidence |
| [`Profile-Evidenz.json`](Profile-Evidenz.json) | Password and email changes, sessions |
| [`Bedienung-UI-Evidenz.json`](Bedienung-UI-Evidenz.json) | Operability, keyboard, viewports |
| [`Settings-AI-Evidenz.json`](Settings-AI-Evidenz.json) | Settings cards, custom roles, Ollama |
| [`AI-Chat-UI-Evidenz.json`](AI-Chat-UI-Evidenz.json) | Chat interface and tool rounds |
| [`AI-Routing-Benchmark.json`](AI-Routing-Benchmark.json) | Semantic selection from large catalogues |
| [`Analyse-Evidenz.json`](Analyse-Evidenz.json) | Package inventory from the pre-build analysis |

The five `Plugin-Erweiterbarkeit-*.json` files are placed in
[`Extensibility-Status.md`](Extensibility-Status.md);
`Plugin-Erweiterbarkeit-Evidenz.json` is rewritten by `bin/check-extensions` on every run.

## Remaining limits

- Stable package releases, the distribution lock built from them and a fresh install without
  local package sources are still outstanding. No package has been merged or published.
- No external LDAP server, OIDC issuer or SMTP service was contacted. Enabling those still needs
  deployment configuration and end-to-end acceptance. Local SMTP through Mailpit is verified for
  account verification and security notices.
- Project mail is switched off. The ledger and queue combination bounds ordinary duplicates, but
  an external SMTP result cannot be confirmed atomically with a PDO transaction.
- German is the complete base language. English covers the central interface texts; some
  messages and dynamic texts stay German in the prototype.
- The file allowlist and MIME check are not a virus scanner. At most 10 MiB per file, 30 MiB per
  ticket and 200 MiB per project; storage stays private.
- One board per project, no WIP enforcement, no saved filters, no WebSockets, no public full API
  and no inbound mail processing. All explicitly outside the MVP.
- The native drag-and-drop path is not manually accepted; the automated drag gesture produced no
  visible change in the browser tooling used. The move dialog and the same server-side move path
  are verified.

## Handover to the maintainer

Verified RC branches pushed following the agreed NAF workflow. Merge and release are the
maintainer's decision; nothing has been published.

| Package | Branch | Review |
|---|---|---|
| framework | `v0.2.4-rc` | [compare](https://github.com/nafphp/framework/compare/main...v0.2.4-rc) |
| database | `v0.2.2-rc` | [compare](https://github.com/nafphp/database/compare/main...v0.2.2-rc) |
| form | `v0.2.3-rc` | [compare](https://github.com/nafphp/form/compare/main...v0.2.3-rc) |
| session | `v0.2.2-rc` | [compare](https://github.com/nafphp/session/compare/main...v0.2.2-rc) |
| orm | `v0.2.2-rc` | [compare](https://github.com/nafphp/orm/compare/main...v0.2.2-rc) |
| queue | `v0.2.3-rc` | [compare](https://github.com/nafphp/queue/compare/main...v0.2.3-rc) |
| schedule | `v0.2.3-rc` | [compare](https://github.com/nafphp/schedule/compare/main...v0.2.3-rc) |
| cli | `v0.2.2-rc` | [compare](https://github.com/nafphp/cli/compare/main...v0.2.2-rc) |
| i18n | `v0.2.2-rc` | [compare](https://github.com/nafphp/i18n/compare/main...v0.2.2-rc) |

Nafinity requires `naf/i18n: ^0.2.2` and works against these branches in source mode. Stable
distribution depends on the maintainer merging and publishing them; a stable alias is never
faked. The extensibility work is on `feat/plugin-extensibility`, pull request
[nafphp/nafinity#1](https://github.com/nafphp/nafinity/pull/1).
