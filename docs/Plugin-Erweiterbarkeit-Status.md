# Plugin-Erweiterbarkeit — Status

Arbeitsstand zum Auftrag in `docs/audits/2026-09-17-plugin-extensibility/IMPLEMENTATION_PROMPT.md`.
Branch `feat/plugin-extensibility`, ausgehend von `origin/main` (`a194993`).
Diese Datei ist der fortzuschreibende Nachweis: offene Schritte bleiben offen markiert.

## Arbeitsschritte A–L

| Schritt | Stand | Nachweis |
|---|---|---|
| A — Ausgangspunkt, Umfang, Testaufbau | erledigt | `app/composer.json` ist `fkde/nafinity`; HEAD war `a194993`, nicht der Auditstand `016931e`; Branch angelegt; diese Datei |
| B — Zeitpunkt der Erweiterungsregistrierung | erledigt | `Nafinity\extensions()`, `ExtensionRegistry`, `ExtensionContext`, Reihenfolge in `app/bootstrap.php` |
| C — Routen, Controller, Services, Seitenrenderer | erledigt | `Route::remove()`/Dispatcher-Binding in naf/framework; zwölf Contracts; `ServiceDefaults`; `PageRenderer`; `ViewRegistry` |
| D — Plugin-Rechte | erledigt | `PermissionRegistry`, `Access::permissions()`, `RoleService`, `views/settings/roles.phtml` |
| E — UI-Beiträge und linkes Menü | teilweise | Sidebar-/Topbar-Slots und Navigation stehen; Board-, Projekt-, Ticket-, Profil-, Benachrichtigungs- und Verlaufs-Slots offen |
| F — Settings | offen | — |
| G — Ticket-Metadaten | offen | — |
| H — Ticket-Widgets und Upload-Modul | offen | — |
| I — Assets und Übersetzungen | offen | — |
| J — Filter, Schätzung, Events, AI | offen | — |
| K — Lebensdauer, Beispiele, Dokumentation | offen | — |
| L — Abnahme T01–T32 | offen | — |

## Abnahmetests T01–T32

Alle Einträge stehen auf **offen**, solange kein tatsächlich ausgeführter Testname
mit Ergebnis daneben steht. Bereits laufende Bestandsprüfungen sind eigens genannt.

| ID | Stand | Testname / Ergebnis |
|---|---|---|
| T01–T32 | offen | siehe Fortschreibung unten |

### Bereits laufende Prüfungen auf diesem Branch

| Prüfung | Befehl | Ergebnis |
|---|---|---|
| Framework-Unit-Tests inkl. `Route::remove()` und Dispatcher-Binding | `composer test` in `../nafphp/framework` | 137 Tests, 280 Assertions, grün |
| Nafinity-DB-Suite (MariaDB) | `make test-mariadb` | 74 Tests grün |
| Nafinity-HTTP-Suite | `make test-http` | 102 Prüfungen grün |
| Stilprüfung | `bin/style check` | grün |

## Änderungen an NAF-Paketen

| Paket | Branch | Commit | Stand |
|---|---|---|---|
| naf/framework | `v0.2.4-rc` | `3832e14` | `Route::remove()` und gebundene Controller-Auflösung, Tests grün, Push offen |

## Commits auf diesem Branch

| Commit | Inhalt |
|---|---|
| `70774aa` | Registry, Boot-Reihenfolge, Service-Contracts, PageRenderer, View-Mapping |
| `9297b15` | Rechte-Registry und Menü-Slots |
