# Nafinity — Implementierung und Abnahme

Was geliefert ist, was zuletzt geprüft wurde und was bewusst fehlt.

Die Regeln für die Arbeit am Projekt stehen in [`AGENTS.md`](../AGENTS.md), Bedienung und
Betrieb in der [`README.md`](../README.md), die Erweiterungs-API in
[`Plugin-Erweiterbarkeit.md`](Plugin-Erweiterbarkeit.md). Dieses Dokument ist der
Abnahmenachweis. **Jede Zahl darin trägt das Datum ihres Laufs** — wer sie heute braucht,
führt den danebenstehenden Befehl aus, statt sie von hier abzuschreiben.

## Was geliefert ist

| Phase | Umsetzung |
|---|---|
| P0 | Alpine/PHP-Runtime, Compose, Source-Symlinks, Paketkorrekturen, Migrationen, Auth/PDO-Grundpfad |
| P1 | Lokale Anmeldung, getrennte Projekte, echte Tickets, Versionen und 409-Konflikte, Activity, SSR-Board, Ticketdetail und Drawer |
| P2 | Mitgliedschaften und Rollen, Spalten/Swimlanes/Labels, mehrere Verantwortliche, Kommentare, Archiv, Filter und Volltext, Einstellungen |
| P3 | Private Dateien mit Quoten, Staging und Wiederanlauf, PDO-Queue, Worker und Ticker, In-App-Benachrichtigungen, geprüfter Mail-Zustellpfad |
| P4 | Limiter, Health- und Schemaprüfung, Backup/Restore, Runtime-Snapshot; externe Anmeldung vorbereitet und auf Wunsch abgeschaltet |
| — | Erweiterbarkeit durch installierte Composer-Pakete; Stand und offene Punkte in [`Plugin-Erweiterbarkeit-Status.md`](Plugin-Erweiterbarkeit-Status.md) |

## Was NAF trägt

Routing und Responses, Container, Auth und Policies, Views und Escaping, Form/CSRF und
Validierung, PDO und Migration Registry, ORM-Modelle und Repositories, Events, CLI-Commands,
Queue, Scheduler, i18n, MCP-Werkzeugverträge und den Mail-Transportvertrag. Geschäftsregeln
liegen in App-Services. Allgemeine Fehler wurden in den zuständigen Paketen korrigiert; es
gibt keine veränderten Vendor-Kopien.

Limiter und LDAP bleiben auf ausdrücklichen Wunsch lokale Pakete. Storage liefert über
`Naf\Storage\storage('attachments')` den privaten Datenträger; Stream-I/O, Verschieben und
Löschen übernimmt das Plugin, `App\Support\AttachmentStorage` hält Upload-Regeln, sichere
Schlüssel und die Aufräumstrategie, der `AttachmentService` die SQL- und Dateizustände.

## Prüfung

Vollständiger Lauf am **18. September 2026** auf `feat/plugin-extensibility`, alles grün:

| Prüfung | Befehl | Ergebnis |
|---|---|---|
| Anwendung auf MariaDB 11.4 | `make test-mariadb` | 74 Prüfungen |
| Anwendung auf PostgreSQL 17 | `make test-postgres` | 74 Prüfungen |
| Echte HTTPS-Anfragen | `make test-http` | 102 Prüfungen |
| Kontowechsel, SMTP, Sitzungswiderruf | `make test-profile` | 25 Prüfungen |
| Worker-Abbruch, Lease-Wiederaufnahme, Deadletter | `make test-worker` | 3 Prüfungen |
| AI-Transport und semantischer Router | `make test-ai` | beide Suiten |
| Erweiterungen mit und ohne beide Beispielpakete | `make test-plugins` | 65 Prüfungen: 42 in-process, 6 über HTTP, 4 mit vertauschter Auflistung, 7 Assets, 6 ohne die Pakete |
| Stil und Whitespace | `bin/style check`, `git diff --check` | grün |

`make test` führt diese Ziele gemeinsam aus. Beide Datenbanken müssen grün sein; die
Runner verweigern den Start ohne `APP_ENV=test` und `DB_DATABASE=nafinity_test`.

Am selben Tag in den geänderten NAF-Paketen, jeweils mit `composer test` auf ihrem
RC-Branch: naf/framework 137 Tests mit 280 Assertions, naf/i18n 39 Tests mit 108
Assertions — beide grün.

Die HTTP-Abnahme deckt Fremdprojekt-IDs, Rollen, CSRF einschließlich manipuliertem
Bearer-Header, gespeichertes HTML als escapter Text, parallele Moves mit einem Erfolg und
einem 409, private Downloads, Dateilöschung, Login-Limit und den Schutz von `.env`,
`vendor`, Composer-Dateien und Storage durch den Public-Webroot ab.

## Messung

Board-Abfragen am 15. September 2026 gegen acht Seed-Tickets plus 5.000 zusätzliche Tickets
in einem isolierten Testprojekt; ein Warm-up, danach zehn Messungen. Kein HTTP- oder Lasttest.

| Abfrage | MariaDB Median | PostgreSQL Median |
|---|---:|---:|
| Board ohne Filter | 3,54 ms | 2,10 ms |
| Volltext „sunflower" | 10,12 ms | 18,23 ms |

Daher die Grenze von 300 Karten je Abfrage. Drag-and-drop ist bei gefilterten oder
begrenzten Ansichten abgeschaltet, damit unsichtbare Nachbarn keine falsche Position
erzeugen; das Verschiebemenü bleibt verfügbar.

## Evidenz

Maschinenlesbare Ergebnisse einzelner Läufe. Alle sind Momentaufnahmen mit dem Datum ihres
Laufs, keine Beschreibung des heutigen Standes.

| Datei | Inhalt |
|---|---|
| [`Docker-Evidenz.json`](Docker-Evidenz.json) | Runtime, Ports, Prozesse, Healthcheck |
| [`Snapshot-Evidenz.json`](Snapshot-Evidenz.json) | Candidate-Image ohne Source-Mounts |
| [`Code-Style-Evidenz.json`](Code-Style-Evidenz.json) | Formatterlauf und Commitnachweise |
| [`Profile-Evidenz.json`](Profile-Evidenz.json) | Passwort- und E-Mail-Wechsel, Sitzungen |
| [`Bedienung-UI-Evidenz.json`](Bedienung-UI-Evidenz.json) | Bedienbarkeit, Tastatur, Viewports |
| [`Settings-AI-Evidenz.json`](Settings-AI-Evidenz.json) | Settingskarten, eigene Rollen, Ollama |
| [`AI-Chat-UI-Evidenz.json`](AI-Chat-UI-Evidenz.json) | Chatoberfläche und Werkzeugrunden |
| [`AI-Routing-Benchmark.json`](AI-Routing-Benchmark.json) | Semantische Auswahl aus großen Katalogen |
| [`Analyse-Evidenz.json`](Analyse-Evidenz.json) | Paketinventar der Vorab-Analyse, siehe [`history/`](history/) |

Die fünf `Plugin-Erweiterbarkeit-*.json` sind in
[`Plugin-Erweiterbarkeit-Status.md`](Plugin-Erweiterbarkeit-Status.md) eingeordnet;
`Plugin-Erweiterbarkeit-Evidenz.json` erzeugt `bin/check-extensions` bei jedem Lauf neu.

## Verbleibende Grenzen

- Stabile Paket-Releases, der daraus erzeugte Distributions-Lock und ein frischer Install
  ohne lokale Paketquellen stehen aus. Es wurden keine Pakete gemergt oder veröffentlicht.
- Kein externer LDAP-Server, OIDC-Issuer oder SMTP-Dienst wurde kontaktiert. Für deren
  Aktivierung fehlen Deployment-Konfiguration und End-to-End-Abnahme. Lokales SMTP über
  Mailpit ist für Kontoverifizierung und Sicherheitshinweise geprüft.
- Projekt-Mail ist ausgeschaltet. Die Ledger-/Queue-Kombination begrenzt normale Duplikate;
  ein externes SMTP-Ergebnis lässt sich nicht atomar mit einer PDO-Transaktion bestätigen.
- Deutsch ist die vollständige Basissprache. Englisch deckt zentrale UI-Texte ab; einige
  Meldungen und dynamische Texte bleiben im Prototyp deutsch.
- Datei-Allowlist und MIME-Prüfung sind kein Virenscanner. Maximal 10 MiB je Datei,
  30 MiB je Ticket und 200 MiB je Projekt; Storage bleibt privat.
- Ein Board je Projekt, keine WIP-Erzwingung, keine Saved Filters, keine WebSockets, keine
  öffentliche Voll-API und keine eingehende E-Mail-Verarbeitung. Alles ausdrücklich außerhalb des MVP.
- Der native Drag-and-drop-Pfad ist nicht manuell abgenommen; die automatisierte Zieh-Geste
  löste im verwendeten Browserwerkzeug keine sichtbare Änderung aus. Der Verschiebedialog
  und derselbe serverseitige Move-Pfad sind geprüft.

## Übergabe an den Maintainer

Geprüfte RC-Branches nach dem vereinbarten NAF-Workflow gepusht. Merge und Release liegen
beim Maintainer; es wurde nichts veröffentlicht.

| Paket | Branch | Review |
|---|---|---|
| framework | `v0.2.4-rc` | [Vergleich](https://github.com/nafphp/framework/compare/main...v0.2.4-rc) |
| database | `v0.2.2-rc` | [Vergleich](https://github.com/nafphp/database/compare/main...v0.2.2-rc) |
| form | `v0.2.3-rc` | [Vergleich](https://github.com/nafphp/form/compare/main...v0.2.3-rc) |
| session | `v0.2.2-rc` | [Vergleich](https://github.com/nafphp/session/compare/main...v0.2.2-rc) |
| orm | `v0.2.2-rc` | [Vergleich](https://github.com/nafphp/orm/compare/main...v0.2.2-rc) |
| queue | `v0.2.3-rc` | [Vergleich](https://github.com/nafphp/queue/compare/main...v0.2.3-rc) |
| schedule | `v0.2.3-rc` | [Vergleich](https://github.com/nafphp/schedule/compare/main...v0.2.3-rc) |
| cli | `v0.2.2-rc` | [Vergleich](https://github.com/nafphp/cli/compare/main...v0.2.2-rc) |
| i18n | `v0.2.2-rc` | [Vergleich](https://github.com/nafphp/i18n/compare/main...v0.2.2-rc) |

Nafinity fordert `naf/i18n: ^0.2.2` und arbeitet im Source-Modus gegen diese Branches.
Die stabile Distribution hängt daran, dass der Maintainer sie merged und veröffentlicht;
ein stabiler Alias wird nie vorgetäuscht. Die Erweiterbarkeit liegt auf
`feat/plugin-extensibility`, Pull Request
[nafphp/nafinity#1](https://github.com/nafphp/nafinity/pull/1).
