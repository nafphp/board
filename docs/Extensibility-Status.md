# Extensibility — status

Working record for the extensibility assignment of 17 September 2026, on branch
`feat/plugin-extensibility` off `origin/main` (`a194993`). The assignment itself was
removed once it was implemented; the step and acceptance ids below are its own.
Open steps stay marked open.

The API itself is described in [`Extensibility.md`](Extensibility.md). The machine-readable
results are written by `bin/check-extensions` into `work/` on every run.

## Steps A–L

| Step | State | Evidence |
|---|---|---|
| A — Starting point, scope, test setup | done | `app/composer.json` is `fkde/nafinity`; HEAD was `a194993`, not the audit's `016931e`; `AGENTS.md`, `README.md`, `docs/Implementation.md`, the plan, the style rules, the audit report and its probe were read; branch created; this file. The audit probe was run unchanged as the baseline regression. Acceptance ran with A/B, without A/B and with the plugin listing reversed |
| B — When extensions register | done | `Nafinity\extensions()`, `ExtensionRegistry`, `ExtensionContext`, the order in `app/bootstrap.php`; T01 |
| C — Routes, controllers, services, page renderer | done | `Route::remove()` and dispatcher binding in naf/framework; 17 contracts, each checked against a productive consumer; `ServiceDefaults`; `PageRenderer`; `ViewRegistry`; T02–T05, T08 |
| D — Plugin permissions | done | `PermissionRegistry`, `Access::permissions()`, `RoleService`, the role editor; T07 |
| E — UI contributions and the left menu | done | `UiRegistry`, `NavigationRegistry`, `SlotRenderer`, every slot of the assignment's table in the real views; one typed context per slot family (`PageSlotContext`, `ProjectSlotContext`, `BoardSlotContext`, `TicketSlotContext`); T06, T18 |
| F — Settings | done | `settings()`, `SettingsService`, `DatabaseSettingsStore`, `PreferenceStore`, migration, cards, field types, HTTP API; T09–T14 |
| G — Ticket metadata | done | `ticket_metadata`, `TicketMetadataWriter`/`-Reader`, `TicketService`, `BoardQuery::detail()`; T15–T17, T19 |
| H — Ticket widgets and browser lifecycle | done | three default widgets, `AttachmentsModule`, `fragment.js`, `extensions.js`; T18, T20, T22, and T21 except the dispose observation |
| I — Assets and translations | done | `AssetPublisher` and three CLI commands, publishing in the candidate build, `Naf\I18n\translation_paths()` in naf/i18n, `Locales::available()`; T27, T28, T32 |
| J — Filters, estimation, events, AI | done | `BoardFilterRegistry` in `BoardQuery`, `EstimationScaleRegistry`, `ActivityTypeRegistry`, `AiToolRegistry`; T23–T26 |
| K — Lifetime, examples, documentation | done | two installed example packages, `app/app/extensions.php`, [`Extensibility.md`](Extensibility.md); T30 |
| L — Acceptance T01–T32 | partial | T01–T20 and T22–T32 done; the only thing still open is observing dispose when the drawer closes in T21, because the preview browser used does not fire a `<dialog>`'s `close` event |

## Acceptance T01–T32

Run with `make test-plugins` (`bin/check-extensions`), `make test-mariadb`, `make test-postgres`,
`make test-http` and `composer test` in the changed NAF packages.

| ID | State | Test name / result |
|---|---|---|
| T01 | done | `both extensions boot after the application defaults, in index order`, `a second initialization does not run the providers again`, `registering after the pass reports where it belongs`, `a failing provider names itself and its cause` — green; plus the four `A7` checks with the listing reversed |
| T02 | done | `the contributed page answers over HTTP for someone with the right`, `… refused without the right and hidden from a stranger` — green |
| T03 | done | `a plugin route exists and core routes still answer`, `a later route of the same name replaces, and remove takes it back`, `the same path under another name does not replace anything` — green; framework: `testRemovingANamedRouteTakesItOutOfMatching` and others |
| T04 | done | naf/framework: `testABoundControllerClassIsDispatchedInsteadOfANewInstance`, `testAnUnboundControllerClassIsStillBuiltByTheContainer`, `testAFailingControllerFactoryIsVisible`, `testABoundControllerWithoutTheActionIsReported` — green |
| T05 | done | `extension B decorates the bound ticket service for every consumer`, `every contract reaches a productive consumer, not just the container` (every contract, each with a decorator generated from the interface and the consumer that uses it in production), `the background jobs run through the replacement` — green |
| T06 | done | `the contributed menu entry appears only with the right` — green; index ties through T18 |
| T07 | done | `a plugin grant is stored, loaded and refused without it`, `a grant of a missing extension survives a role save` — green |
| T08 | done | `a view override applies and the host wins last`, `a mapping cycle is reported` — green |
| T09 | done | `the extensions declare settings that read and write`, `extension B moved one field to its own card` — green |
| T10 | done | `values, presence and the collection snapshot agree` — green |
| T11 | done | `contexts stay apart and a stranger reads nothing` — green |
| T12 | done | `a partial save keeps the other fields, and a reset restores the default`, `a write is visible to the next read of the same request`, `switching actor switches the values` — green |
| T13 | done | `the settings endpoints answer with values only`, `a settings write without a token is refused` — green |
| T14 | done | `the snapshot is the existing NAF collection and stores nothing`, `the local AI keeps its values in the browser` — green; plus the existing `make test-ai` |
| T15 | done | `a contributed field is stored, read back and reset` — green on MariaDB; the new tables also run under PostgreSQL through `make test-postgres` |
| T16 | done | `a metadata-only change raises the version exactly once`, `a rejected value changes nothing at all` — green |
| T17 | done | `an unknown field key is refused instead of stored`, `a core attribute cannot be claimed as a contributed field` — green |
| T18 | done | `two widgets share an index and are ordered by id`, `extension B replaced extension A's widget under the same id`, `the contributed widgets render in the ticket, in order` — green |
| T19 | done | `ticket field groups all point at a registered panel`, `panels and fields are sortable without changing what they mean` — green; the domain rules for move, pivots and timer continue to run through the existing suite |
| T20 | done | `the upload widget comes from the registry and removing it keeps the files` — green; plus an interactive browser run: file choice, upload, list refresh, private download with an attachment header and refusal of a `.php` file |
| T21 | **partial** | Verified interactively: full page, drawer, create mode without contributed widgets, fragment refresh and reopening mount the contributed module exactly once, with no duplicate listeners or assets. **Open:** dispose on closing the drawer could not be observed — the preview browser is WebKit and does not fire a `<dialog>`'s `close` event at all, so the application's existing cleanup does not run there either |
| T22 | done | Verified interactively: typing in a contributed field triggers the existing auto-save, the ticket version rises exactly once, the display takes the new value, draft and focus are preserved, and after the refresh the contributed module is still mounted exactly once |
| T23 | done | `a contributed filter reaches the count and the cards` — green |
| T24 | done | `a contributed estimation scale is the same everywhere` — green; `projects.estimation_scale` was widened to VARCHAR(190) for it |
| T25 | done | `a plugin listener enqueues in the same transaction, and a rollback takes it back` — green; example A brings a listener and a queue job |
| T26 | done | `the contributed tool appears only with its grant` — green |
| T27 | done | `the plugin translation is available and the application wins` — green; naf/i18n: `TranslationPathRegistryTest` (7 tests) |
| T28 | done | seven checks: registration, missing files, idempotency, file types, a host conflict, removal without the package, an unknown package — green, plus a real CLI run of all three commands |
| T29 | done | `the plugin command, migration, job and schedule entry are all there`, `the queued plugin job runs and writes only its own table` — green |
| T30 | done | six checks without A/B — green |
| T31 | done | Browser run at 800/390/320 pixels in light and dark with the real templates, assets and fonts. Found and fixed on the way: the attachment form's hidden file input was stretched to full width and pushed every ticket page 34 pixels sideways on a phone |
| T32 | done | `bin/build-candidate --source work/extension-host` builds an image without source mounts or symlinks, carrying both example packages as files and their published assets; the running image serves the contributed page |

## Checks that ran

The numbers of the last full run are in
[Implementation and acceptance](Implementation.md#verification): 74 MariaDB, 74 PostgreSQL,
102 HTTP, 25 profile, 3 worker, both AI suites and 65 extension checks, plus naf/framework
137/280 and naf/i18n 39/108. `composer validate` is green for every manifest, real and generated.
The audit probe was run unchanged as the baseline regression and showed only the two intended
differences; it was removed with the rest of the assignment once that was implemented.

## Changes to NAF packages

| Package | Branch | Commit | State |
|---|---|---|---|
| naf/framework | `v0.2.4-rc` | `3832e14` | `Route::remove()` and bound controller resolution, tests green |
| naf/i18n | `v0.2.2-rc` | `1fa2ee7` | `Naf\I18n\translation_paths()`, tests green |

Both packages are **not published**. Nafinity requires `naf/i18n: ^0.2.2` and works against the
RC branches in source mode. Stable distribution depends on the maintainer merging and publishing
them.

## Not done

- No merge and no release of the NAF packages or the application; that stays explicitly with the
  maintainer.
- No real mail delivery, no external webhooks and no running model in the automated acceptance;
  the existing local transports apply.
- No change to `vendor/`, no production database resets, no secrets or generated
  manifests/locks in the commit.
- No rebuild onto a CMS, Laravel, a second container, a router, an event bus, an ORM or a plugin
  metaframework.

## Remaining maintainer actions

1. Review and merge the NAF RC branches (`naf/framework` v0.2.4, `naf/i18n` v0.2.2).
2. Follow the dispose path when the drawer closes in a browser that fires a `<dialog>`'s `close`
   event (Chrome or Firefox).
3. After the packages are released, verify the distribution without source symlinks.
