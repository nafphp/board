# Nafinity – Implementierung und Abnahme

Stand: 14. September 2026. Der Prototyp liegt unter
`/Users/flo/PhpStormProjects/nafinity` und läuft auf **http://localhost:8088**.
Er verwendet echte Daten, lokale NAF-Quellen und projektgebundene Rechte.

## Einstieg

Demo-Passwort: `Nafinity-Demo-2026!`.

| Konto | Zugriff |
|---|---|
| alice@example.test | Owner des Projekts Nafinity |
| bob@example.test | Owner des getrennten Projekts Studio Nord |
| viewer@example.test | Lesender Zugriff auf Nafinity |

Die im Browser angelegte Karte „Showcase-Abnahme im Browser“ hat eine eigene Ticket-URL,
wurde über das Verschiebemenü nach Review bewegt und enthält eine private Testdatei.
Die Demo enthält keine echten Kundendaten.

## Ergebnis des Plans

| Bereich | Umsetzung |
|---|---|
| P0 | Projektumzug, Alpine/PHP-Runtime, Compose, Source-Symlinks, Paketkorrekturen, Migrationen und Auth/PDO-Grundpfad |
| P1 | Lokale Anmeldung, getrennte Projekte, echte Tickets, Versionen/409-Konflikte, Activity, SSR-Board und Ticketdetail/Drawer |
| P2 | Mitgliedschaften/Rollen, Spalten/Swimlanes/Labels, mehrere Verantwortliche, Kommentare, Archiv, Filter/Volltext, Einstellungen |
| P3 | Private Dateien, Quoten, Staging/Recovery, PDO-Queue, Worker/Ticker, In-App-Benachrichtigungen und getesteter Mail-Zustellpfad |
| P4 | Limiter, Health/Schema-Prüfung, Backup/Restore und Runtime-Snapshot geprüft; externe Anmeldung vorbereitet und auf Nutzerwunsch deaktiviert |

Die noch nicht veröffentlichten Pakete verhindern derzeit einen stabilen Install aus
öffentlichen Mindestversionen. Das ist ein eigener Release-Schritt, kein erfolgreicher
Distributionsnachweis. LDAP/OIDC bleiben entsprechend der bestätigten Kontenregel optional;
es gibt weder automatische Kontoanlage noch eine Verknüpfung allein anhand der E-Mail-Adresse.

## Framework als Grundlage

Nafinity verwendet NAF-Routing und Responses, den Container, Auth und Policies, Views und
Escaping, Form/CSRF/Validierung, PDO und die Migration Registry, ORM-Modelle/Repositories,
Events, CLI Commands, Queue, Scheduler, i18n und den Mail-Transportvertrag.
Geschäftsregeln liegen in App-Services. Allgemeine Fehler wurden in den zuständigen Paketen
korrigiert. Es gibt keine veränderten Vendor-Kopien.

Die neuen Limiter- und LDAP-Pakete bleiben auf ausdrücklichen Wunsch lokal. Das Storage-Paket
wurde parallel auf `v0.1.0-rc` weiterentwickelt und vom anderen Arbeitskontext auf GitHub
gesichert. Nafinity wurde an dessen aktuelle API angepasst: `Naf\Storage\storage('attachments')`
liefert den konfigurierten privaten Datenträger. Stream-I/O, Verschieben und Löschen
übernimmt das Plugin; `App\Support\AttachmentStorage` enthält Upload-Regeln, sichere
Schlüssel und die lokale Aufräumstrategie. Die SQL-/Dateizustände bleiben im AttachmentService.

Wesentliche Paketkorrekturen betreffen CSRF bei unsicheren Methoden, portable Migrationen
und Sessions, den ORM-Tabellenvertrag und das Eigentum an Transaktionen, Null-Bindings im
Container, Event-Callables, sichere Fehlerausgabe, begrenztes Response-Streaming,
dauerhafte Queue-Reservierungen und den tatsächlichen Ticker-/CLI-Aufruf.

## Prüfergebnisse

| Prüfung | Ergebnis |
|---|---|
| Acht geänderte NAF-Pakete | 371 Tests, 833 Assertions erfolgreich, Host-PHP 8.5.4 |
| App auf MariaDB 11.4 | 28 Integrationsszenarien erfolgreich, Container-PHP 8.5.10 |
| App auf PostgreSQL 17 | Dieselben 28 Szenarien erfolgreich |
| Echte HTTP-Anfragen | 28 Prüfungen erfolgreich, getrennte Cookie-Jars und parallele Schreibzugriffe |
| Neue Plugin-Verträge | Private Upload-Grenzen, Limiter und LDAP-Provider mit Fake-Directory erfolgreich |
| Aktuelle Storage-Paketsuite | Isolierte PHP-8.5.10-Runtime: 123 Tests, 498 Assertions, ein plattformabhängiger Skip; Host-Discovery, DI und Beispiele erfolgreich |
| Minimaler NAF-Stack | Sieben Plugins gebootet; erforderliche PDO-Bindung auch ohne OAuth vorhanden |
| Alle bestehenden relevanten Plugins | 15/15 gebootet, 20 Commands und 14 Routen unter PHP 8.5.10 |
| Worker-Prozessabbruch | SIGKILL während einer Aufgabe, dauerhafte Reservierung erhalten, frischer Worker übernimmt und bestätigt; Lease im Test gezielt vorgezogen |
| Deadletter | Fehlende Jobklasse liefert Fehlerstatus und bleibt als fehlgeschlagene Aufgabe sichtbar |
| Upload-Ausfall | Unterbrochene Promotion bleibt staged, Download gesperrt, Wiederholung erzeugt genau eine erfolgreiche Activity |
| Mail-Ausfall | Fehler sichtbar, Retry erfolgreich, normale Doppelzustellung unterdrückt, entzogene Mitgliedschaft verhindert Versand; ausschließlich Testtransporte |
| Backup/Restore | 24 Tabellen, drei Konten, neun Tickets und sechs Migrationen in isolierter Restore-DB; private Testdatei mit gleichem Hash |
| Source-Live-Reload | Zwei Änderungen derselben Framework-Klasse über FPM beobachtet; Reflection zeigt `/workspace/packages/framework` |
| Download | 64 MiB tatsächlich übertragen, ohne entsprechende Vergrößerung des PHP-Heap-Peaks; Messung mit PHP-Allokationsseiten, kein Nachweis von null Speicherverbrauch |
| Lokales Runtime-Image | Anmeldung, fünf geschützte Seiten, Fremdprojekt 404 und privater Download erfolgreich; 17 NAF-Pakete, keine Source-Mounts/Vendor-Symlinks |
| Composer | Manifeste der beteiligten Pakete und der App mit `validate --strict` geprüft |

Die HTTP-Abnahme umfasst Fremdprojekt-IDs, Rollen, CSRF einschließlich manipuliertem
Bearer-Header und ungültigen Tokens, gespeichertes HTML als escaped Text, parallele Moves
mit einem Erfolg und einem 409, private Downloads, Dateilöschung, Login-Limit und den
Schutz von `.env`, `vendor`, Composer-Dateien und Storage durch den Public-Webroot.

Im Browser geprüft: Demo-Login, Ticket anlegen, direkte Ticket-URL, Verschieben über das
zugängliche Menü, privater Upload, lesbare Activity, Desktop sowie 390 × 844 Pixel,
gespeichertes helles Theme, Englisch und Zeitzone. Die Einstellungen wurden anschließend
zurückgestellt. Die automatisierte Mauszieh-Geste führte im verwendeten Browserwerkzeug
zu keiner sichtbaren Änderung; der native Drag-and-drop-Pfad ist damit noch nicht manuell
abgenommen. Der alternative Verschiebedialog und derselbe serverseitige Move-Pfad sind geprüft.

## Board-Messung

Messbestand: acht Seed-Tickets plus 5.000 zusätzliche Tickets im isolierten Testprojekt.
Ein Warm-up, danach zehn Messungen. Je Abfrage maximal 300 Karten sowie Gesamtzahl,
Metadaten und Zuordnungen. Das ist kein HTTP-/Browser-Lasttest.

| Abfrage | MariaDB Median | PostgreSQL Median |
|---|---:|---:|
| Board ohne Filter | 3,54 ms | 2,10 ms |
| Volltext „sunflower“ | 10,12 ms | 18,23 ms |

Bei mehr als 300 Treffern fordert das Board zum Filtern auf. Drag-and-drop ist bei
gefilterten oder begrenzten Ansichten deaktiviert, damit unsichtbare Nachbarn keine
falsche Position erzeugen; das Verschiebemenü bleibt verfügbar.

## Lokaler Betrieb

Die Runtime folgt dem ASPX-Aufbau mit Alpine, nativen PHP-Paketen, Nginx, PHP-FPM und
Supervisor. Alpine 3.24.1 ist per Digest festgelegt; PHP 8.5.10 läuft nativ auf ARM64.
App-Prozesse laufen als `www` mit UID 1000. Öffentlich ist ausschließlich `app/public`.
Ports werden an Loopback gebunden. Logs sind größenbegrenzt.

```sh
cd ~/PhpStormProjects/nafinity
docker compose up -d app db
docker compose --profile background up -d worker ticker
docker compose --profile background ps
docker compose --profile background logs --tail=50 worker ticker
```

`packages -> ../nafphp` ist der IDE-Link. Die App und die Quellen werden im Container
separat eingebunden. `bin/dev-composer` erzeugt das ignorierte Development-Manifest und
installiert im Container relative Vendor-Links. FPM sieht Änderungen im nächsten Request.
Langlebige Prozesse nach Source-Änderungen mit `restart worker ticker` neu starten.

NAF-Logs werden über einen PSR-3-Adapter an PHP/FPM und die begrenzten Compose-Logs weitergereicht.
Lokale alte Logdateien werden weder versioniert noch in ein Image kopiert.

`/health/live` prüft den HTTP-Prozess. `/health/ready` prüft PDO, die drei erforderlichen
App-Migrationen, die Hintergrundtabellen und die Auflösung des AttachmentService mit dem nativen Storage-Datenträger. Worker und Ticker haben eigene Heartbeats.
Die bisherige Schema-Kennung ist `202609140003`; sechs Migrationen inklusive Plugins sind angewandt.

`bin/build-candidate` erzeugt einen eingefrorenen lokalen Runtime-Snapshot mit Package-Hashes.
Er enthält keine Vendor-Symlinks, kein Composer und keine Source-Mounts. Der Builder kontrolliert die vollständige Menge aller benötigten NAF-Pakete.
Private Daten- und Logverzeichnisse werden ausgeschlossen; gleichnamige Pakete bleiben enthalten.
Der geprüfte Snapshot `nafinity:candidate` hat **132,23 MiB** (138.656.645 Byte),
Image-ID `sha256:4d32cdfd85dd6906bca905912f5b679f9db0a829d226ab67bc6a09cc2cdc7994`.
Anmeldung, fünf geschützte Seiten, Projektisolation und der private Download wurden über
Port 8090 erfolgreich geprüft. Nur das private Datenverzeichnis ist eingebunden.
Hashes und Einzelresultate stehen in `docs/Snapshot-Evidenz.json`.
Dieser Snapshot ist ausdrücklich keine veröffentlichte Distribution.

App, Datenbank, Worker und Ticker bleiben aktiv; Worker/Ticker wurden nach den finalen
Source-Änderungen neu gestartet. Die zusätzlichen Test- und Candidate-Container wurden
nach der Abnahme gestoppt. Der geprüfte Candidate lässt sich erneut starten:

```sh
docker compose --profile candidate up -d candidate
```

Backups mit `bin/backup` erstellen. `bin/verify-restore VERZEICHNIS` stellt ausschließlich
in `nafinity_restore_test` wieder her und kontrolliert Dateien/Hashes. Niemals Test- oder
Down-Migrationsbefehle gegen die Demo-/Produktivdatenbank umleiten.

## Tests wiederholen

`app/tests/run.php`, `benchmark.php` und `queue_process.php` verlangen ausdrücklich
`APP_ENV=test` und `DB_DATABASE=nafinity_test`. Der Integrationsrunner setzt dieses Schema
zurück. Die Testdatenbank ist bereits angelegt; die Demo-Datenbank heißt `nafinity`.

```sh
docker compose --profile test up -d app-test postgres
docker compose exec -T app-test php tests/run.php
docker compose exec -T -e DB_DRIVER=pgsql -e DB_HOST=postgres -e DB_PORT=5432 app-test php tests/run.php
docker compose exec -T app-test php vendor/bin/naf nafinity:seed
python3 app/tests/http_acceptance.py
docker compose exec -T app-test php tests/queue_process.php
```

Vor einem erneuten HTTP-Test die Testdatenbank mit `tests/run.php` zurücksetzen und erneut
seeden, damit Ratelimits und Testzustände nicht aus dem vorigen Lauf übernommen werden.
Die JSON-Dateien in `docs/` enthalten die einzelnen Szenarien, Messwerte und Runtime-Evidenz.
`bin/check-plugin-stack` wiederholt den Minimal-/Alle-Plugin-Boot in isolierten Hosts
und prüft dort auch eine tatsächlich aufgelöste PDO-Verbindung.

## Verbleibende Grenzen

- Stabile Paket-Releases, der daraus erzeugte Distributions-Lock und ein frischer
  Install ohne lokale Paketquellen stehen aus. Im Rahmen dieser Umsetzung wurden keine Pakete gemergt oder als Release veröffentlicht.
- Kein echter LDAP-Server, OIDC-Issuer oder SMTP-Dienst wurde kontaktiert. Für deren
  Aktivierung fehlen noch Deployment-Konfiguration und End-to-End-Abnahme.
- Mail ist ausgeschaltet. Die Ledger-/Queue-Kombination begrenzt normale Duplikate;
  ein externes SMTP-Ergebnis lässt sich nicht atomar mit einer PDO-Transaktion bestätigen.
- Deutsch ist die vollständige Basissprache. Englisch ist für zentrale UI-Texte vorhanden;
  einige Meldungen und dynamische Texte bleiben im Prototyp deutsch.
- Datei-Allowlist und MIME-Prüfung sind kein Virenscanner. Maximal 10 MiB je Datei,
  30 MiB je Ticket und 200 MiB je Projekt; Storage bleibt privat.
- Ein Board pro Projekt, keine WIP-Erzwingung, Saved Filters, WebSockets, öffentliche
  Voll-API oder eingehende E-Mail-Verarbeitung. Diese Punkte waren ausdrücklich außerhalb des MVP.

Die zentrale NAF-Dokumentation ist als [Release-abhängiger Entwurf](https://github.com/nafphp/docs/compare/main...docs/nafinity-integration-rc) vorbereitet. Sie darf
erst nach Veröffentlichung und Prüfung der jeweiligen Paketversionen als Stable-Anleitung
erscheinen.

## Lokales Projekt und Dokumentation

Nafinity ist auf dem lokalen Branch `main` versioniert. Für die Anwendung wurde kein
Remote angelegt. `.env`, private Dateien, Logs, Vendor und generierte Entwicklungs-Locks
sind ausgeschlossen; der relative IDE-Symlink ist versioniert.
Der NAF-Dokumentationsentwurf liegt auf `docs/nafinity-integration-rc`, Commit `9036e2e`.
Er bleibt bis zu den erforderlichen Paket-Releases außerhalb der öffentlichen Anleitungen.

## Git-Übergabe der bestehenden NAF-Pakete

Die folgenden geprüften RC-Branches wurden nach dem vereinbarten NAF-Workflow gepusht.
Der Maintainer übernimmt Merge und Release; es wurde keine neue Veröffentlichung angelegt.

| Paket | Branch | Commit | Review |
|---|---|---|---|
| framework | `v0.2.4-rc` | `719cb1c` | [Vergleich](https://github.com/nafphp/framework/compare/main...v0.2.4-rc) |
| database | `v0.2.2-rc` | `50ec941` | [Vergleich](https://github.com/nafphp/database/compare/main...v0.2.2-rc) |
| form | `v0.2.3-rc` | `b71e88f` | [Vergleich](https://github.com/nafphp/form/compare/main...v0.2.3-rc) |
| session | `v0.2.2-rc` | `8913cd2` | [Vergleich](https://github.com/nafphp/session/compare/main...v0.2.2-rc) |
| orm | `v0.2.2-rc` | `3df42e7` | [Vergleich](https://github.com/nafphp/orm/compare/main...v0.2.2-rc) |
| queue | `v0.2.3-rc` | `b8c394c` | [Vergleich](https://github.com/nafphp/queue/compare/main...v0.2.3-rc) |
| schedule | `v0.2.3-rc` | `5f674ab` | [Vergleich](https://github.com/nafphp/schedule/compare/main...v0.2.3-rc) |
| cli | `v0.2.2-rc` | `6beaf63` | [Vergleich](https://github.com/nafphp/cli/compare/main...v0.2.2-rc) |
