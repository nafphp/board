# Plugin-Erweiterbarkeit — Status

Arbeitsstand zum Auftrag in `docs/audits/2026-09-17-plugin-extensibility/IMPLEMENTATION_PROMPT.md`.
Branch `feat/plugin-extensibility`, ausgehend von `origin/main` (`a194993`).
Diese Datei ist der fortzuschreibende Nachweis: offene Schritte bleiben offen markiert.

Die ausführliche Beschreibung der APIs steht in
[`docs/Plugin-Erweiterbarkeit.md`](Plugin-Erweiterbarkeit.md), die maschinenlesbaren
Ergebnisse in [`docs/Plugin-Erweiterbarkeit-Evidenz.json`](Plugin-Erweiterbarkeit-Evidenz.json).

## Arbeitsschritte A–L

| Schritt | Stand | Nachweis |
|---|---|---|
| A — Ausgangspunkt, Umfang, Testaufbau | erledigt | `app/composer.json` ist `fkde/nafinity`; HEAD war `a194993`, nicht der Auditstand `016931e`; Branch angelegt; diese Datei |
| B — Zeitpunkt der Erweiterungsregistrierung | erledigt | `Nafinity\extensions()`, `ExtensionRegistry`, `ExtensionContext`, Reihenfolge in `app/bootstrap.php`; T01 |
| C — Routen, Controller, Services, Seitenrenderer | erledigt | `Route::remove()` und Dispatcher-Binding in naf/framework; 17 Contracts; `ServiceDefaults`; `PageRenderer`; `ViewRegistry`; T02–T05, T08 |
| D — Plugin-Rechte | erledigt | `PermissionRegistry`, `Access::permissions()`, `RoleService`, Rolleneditor; T07 |
| E — UI-Beiträge und linkes Menü | erledigt | `UiRegistry`, `NavigationRegistry`, `SlotRenderer`, alle Slots der Auftragstabelle in den echten Views; T06, T18 |
| F — Settings | erledigt | `settings()`, `SettingsService`, `DatabaseSettingsStore`, `PreferenceStore`, Migration, Karten, Feldtypen, HTTP-API; T09–T14 teilweise |
| G — Ticket-Metadaten | erledigt | `ticket_metadata`, `TicketMetadataWriter`/`-Reader`, `TicketService`, `BoardQuery::detail()`; T15–T17, T19 teilweise |
| H — Ticket-Widgets und Upload-Modul | erledigt | drei Default-Widgets, `AttachmentsModule`, `fragment.js`, `extensions.js`; T18, T21 teilweise |
| I — Assets und Übersetzungen | erledigt | `AssetPublisher` und drei CLI-Kommandos, Veröffentlichung im Candidate-Build, `Naf\I18n\translation_paths()` in naf/i18n, `Locales::available()`; T27, T28, T32 |
| J — Filter, Schätzung, Events, AI | erledigt | `BoardFilterRegistry` in `BoardQuery`, `EstimationScaleRegistry`, `ActivityTypeRegistry`, `AiToolRegistry`; T23–T26 |
| K — Lebensdauer, Beispiele, Dokumentation | erledigt | zwei installierte Beispielpakete, `app/app/extensions.php`, `docs/Plugin-Erweiterbarkeit.md`; T30 |
| L — Abnahme T01–T32 | teilweise | siehe Tabelle unten; offen bleiben nur die interaktiven Browserpunkte T20 (Uploadpfad), T21 (Drawer-Lifecycle) und T22 (Auto-Save) |

## Abnahmetests T01–T32

Ausgeführt mit `make test-plugins` (`bin/check-extensions`), `make test-mariadb`,
`make test-postgres`, `make test-http` und `composer test` in den geänderten NAF-Paketen.

| ID | Stand | Testname / Ergebnis |
|---|---|---|
| T01 | erledigt | `T01 both extensions boot after the application defaults, in index order`, `… a second initialization does not run the providers again`, `… registering after the pass reports where it belongs`, `… a failing provider names itself and its cause` — grün |
| T02 | erledigt | `T02 the contributed page answers over HTTP for someone with the right`, `… refused without the right and hidden from a stranger` — grün |
| T03 | erledigt | `T03 a plugin route exists and core routes still answer`, `… a later route of the same name replaces, and remove takes it back` — grün; Framework: `testRemovingANamedRouteTakesItOutOfMatching` u. a. |
| T04 | erledigt | naf/framework: `testABoundControllerClassIsDispatchedInsteadOfANewInstance`, `testAnUnboundControllerClassIsStillBuiltByTheContainer`, `testAFailingControllerFactoryIsVisible`, `testABoundControllerWithoutTheActionIsReported` — grün |
| T05 | teilweise | `T05 extension B decorates the bound ticket service for every consumer` — grün. Ein produktiver Konsument je Contract ist umgestellt; ein eigener Ersatztest je einzelnem Contract fehlt noch |
| T06 | erledigt | `T06 the contributed menu entry appears only with the right` — grün; Index-Gleichstand über T18 |
| T07 | erledigt | `T07 a plugin grant is stored, loaded and refused without it`, `… a grant of a missing extension survives a role save` — grün |
| T08 | erledigt | `T08 a view override applies and the host wins last`, `… a mapping cycle is reported` — grün |
| T09 | erledigt | `T09 the extensions declare settings that read and write`, `… extension B moved one field to its own card` — grün |
| T10 | erledigt | `T10 values, presence and the collection snapshot agree` — grün |
| T11 | erledigt | `T11 contexts stay apart and a stranger reads nothing` — grün |
| T12 | erledigt | `T12 a partial save keeps the other fields, and a reset restores the default`, `… a write is visible to the next read of the same request`, `… switching actor switches the values` — grün |
| T13 | erledigt | `T13 the settings endpoints answer with values only`, `… a settings write without a token is refused` — grün |
| T14 | erledigt | `T14 the snapshot is the existing NAF collection and stores nothing`, `… the local AI keeps its values in the browser` — grün; dazu die bestehende `make test-ai` |
| T15 | erledigt | `T15 a contributed field is stored, read back and reset` — grün auf MariaDB; PostgreSQL-Lauf der neuen Tabellen über `make test-postgres` (74 Tests) |
| T16 | erledigt | `T16 a metadata-only change raises the version exactly once`, `… a rejected value changes nothing at all` — grün |
| T17 | erledigt | `T17 an unknown field key is refused instead of stored`, `… a core attribute cannot be claimed as a contributed field` — grün |
| T18 | erledigt | `T18 two widgets share an index and are ordered by id`, `… extension B replaced extension A\'s widget under the same id`, `T18 the contributed widgets render in the ticket, in order` — grün |
| T19 | erledigt | `T19 ticket field groups all point at a registered panel`, `… panels and fields are sortable without changing what they mean` — grün; die Fachregeln von move/Pivots/Timer laufen weiter über die bestehende Suite |
| T20 | teilweise | `T20 the upload widget comes from the registry and removing it keeps the files` — grün; Auswahl, Progress, Quotas, private Downloads und Recovery laufen unverändert über `make test-mariadb` und `make test-http`, ein eigener Browserlauf des Uploadpfads fehlt |
| T21 | teilweise | Lifecycle implementiert (`extensions.js`, `fragment.js`) und im Browser als statischer Seitenzustand geprüft; Drawer, Create→Detail und Dispose nach Refresh sind nicht interaktiv nachgewiesen |
| T22 | offen | Auto-Save für Metadaten ist integriert, aber nicht eigens getestet |
| T23 | erledigt | `T23 a contributed filter reaches the count and the cards` — grün |
| T24 | erledigt | `T24 a contributed estimation scale is the same everywhere` — grün; die Spalte `projects.estimation_scale` wurde dafür auf VARCHAR(190) verbreitert |
| T25 | erledigt | `T25 a plugin listener enqueues in the same transaction, and a rollback takes it back` — grün; Beispiel A bringt Listener und Queue-Job mit |
| T26 | erledigt | `T26 the contributed tool appears only with its grant` — grün |
| T27 | erledigt | `T27 the plugin translation is available and the application wins` — grün; naf/i18n: `TranslationPathRegistryTest` (7 Tests) |
| T28 | erledigt | `T28 …` (7 Prüfungen: Registrierung, Fehlbestand, Idempotenz, Endungen, Hostkonflikt, Entfernen ohne Paket, unbekanntes Paket) — grün, plus echter CLI-Lauf aller drei Kommandos |
| T29 | erledigt | `T29 the plugin command, migration, job and schedule entry are all there`, `… the queued plugin job runs and writes only its own table` — grün |
| T30 | erledigt | `T30 …` (6 Prüfungen ohne A/B) — grün |
| T31 | erledigt | Browserlauf auf 800/390/320 Pixeln in Light und Dark mit den echten Templates, Assets und Schriften; Nachweis in [`docs/Plugin-Erweiterbarkeit-Browser.json`](Plugin-Erweiterbarkeit-Browser.json). Dabei gefunden und behoben: das versteckte Datei-Feld des Anhangsformulars wurde auf volle Breite gestreckt und schob jede Ticketseite auf dem Telefon 34 Pixel zur Seite |
| T32 | erledigt | `bin/build-candidate --source work/extension-host` baut ein Image ohne Source-Mounts und ohne Symlinks, trägt beide Beispielpakete als Dateien und ihre veröffentlichten Assets; das laufende Image liefert die Beitragsseite. Nachweis in [`docs/Plugin-Erweiterbarkeit-Distribution.json`](Plugin-Erweiterbarkeit-Distribution.json) |

## Ausgeführte Prüfungen

| Prüfung | Befehl | Ergebnis |
|---|---|---|
| Framework-Unit-Tests inkl. `Route::remove()` und Dispatcher-Binding | `composer test` in `../nafphp/framework` | 137 Tests, 280 Assertions, grün |
| i18n-Unit-Tests inkl. `translation_paths()` | `composer test` in `../nafphp/i18n` | 39 Tests, 108 Assertions, grün |
| Nafinity-DB-Suite (MariaDB) | `make test-mariadb` | 74 Tests grün |
| Nafinity-DB-Suite (PostgreSQL) | `make test-postgres` | 74 Tests grün |
| Nafinity-HTTP-Suite | `make test-http` | 102 Prüfungen grün |
| Erweiterungs-Abnahme | `make test-plugins` | 57 Prüfungen grün: 38 in-process, 6 über HTTP, 7 Assets, 6 ohne die Pakete |
| Browserprüfung | Snapshots aus dem Erweiterungs-Host, 800/390/320 Pixel, Light und Dark | grün, siehe Browser-Evidenz |
| Distribution | `bin/build-candidate --source work/extension-host` und ein Lauf des Images ohne Source-Mounts | grün, siehe Distributions-Evidenz |
| Stilprüfung | `bin/style check` | grün |
| Whitespace | `git diff --check` | grün |

## Änderungen an NAF-Paketen

| Paket | Branch | Commit | Stand |
|---|---|---|---|
| naf/framework | `v0.2.4-rc` | `3832e14` | `Route::remove()` und gebundene Controller-Auflösung, Tests grün |
| naf/i18n | `v0.2.2-rc` | `1fa2ee7` | `Naf\I18n\translation_paths()`, Tests grün |

Beide Pakete sind **nicht veröffentlicht**. Nafinity fordert `naf/i18n: ^0.2.2`
und arbeitet im Source-Modus gegen die RC-Branches. Die stabile Distribution ist
davon abhängig, dass der Maintainer die Pakete merged und veröffentlicht.

## Verbleibende Maintainer-Aktionen

1. NAF-RC-Branches prüfen und mergen (`naf/framework` v0.2.4, `naf/i18n` v0.2.2).
2. Offene Abnahmepunkte T20, T21 und T22 abarbeiten: ein interaktiver Browserlauf für den Uploadpfad, den Drawer-Lifecycle und das Auto-Save beigetragener Felder.
3. Nach dem Release der Pakete die Distribution ohne Source-Symlinks prüfen.
