# Nafinity – Implementierung und Abnahme

Stand: 16. September 2026. Der Prototyp liegt unter
`/Users/flo/PhpStormProjects/nafinity` und läuft auf **https://localhost** (Port 443).
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

## Eigenes Profil

Der Avatar öffnet das Profil-Modal mit der aktuellen Projektrolle, Passwortwechsel,
verifizierter E-Mail-Änderung und Logout. Die Abläufe verwenden NAFs Auth-/Session-,
Formular-, Mail- und Queue-Verträge. Neue Adressen bleiben bis zum Bestätigungscode
inaktiv; erfolgreiche Kontowechsel widerrufen bestehende Sitzungen. Sicherheitshinweise
gehen an die bisherige Adresse, lokal über Mailpit auf http://localhost:8025.

[Profile.md](Profile.md) beschreibt Bedienung, Architektur und Grenzen;
[Profile-Evidenz.json](Profile-Evidenz.json) enthält die aktuellen Prüfergebnisse.
174 Datenbank-/HTTPS-/Worker-Prüfungen, beide AI-Suites und alle Stilprüfungen sind grün.
Die direkte Abnahme im angemeldeten Firefox ist noch offen: Firefox ist auf dem gesperrten Mac
nicht steuerbar; der interne Browser meldet weiterhin einen Zertifikatsfehler. Diese
Zertifikatswarnung wurde nicht umgangen. Die HTTPS-Tests verwenden die lokale CA regulär.

### Nutzerkarte, Rollenanzeige und Bedienhinweise

Die gesamte Nutzerkarte am unteren Rand der Seitenleiste öffnet das native Profil-Modal.
Beide Auslöser teilen dessen Öffnungszustand und geben beim Schließen den Fokus zurück.
Die Karte und die Kopfzeile zeigen `ProjectScope::roleName`; ohne Projektkontext steht
„Persönliches Konto“. Im Modal sind die eigenen Projektrollen aus der bereits autorisierten
Projektabfrage verlinkt. Der frühere Spruch in der Seitenleiste führt jetzt direkt zur
Settings-Karte „Rollen & Rechte“ beziehungsweise zur Projektauswahl.

Anmeldung, Projekte, Board, Ticketformular, Benachrichtigungen, Settings, Profil und AI-Chat
verwenden konkrete Hinweise statt allgemeiner Motivationssätze. Die neuen Texte nutzen
NAFs i18n-Kataloge. Board-Hinweise berücksichtigen Lesezugriff, Filter, Ergebnislimit und
Archivierung. Leere Zellen nennen ihren Zustand und enthalten kein funktionsloses Plus.
Lange Rollennamen werden in kompakten Anzeigen begrenzt; die Seitenleiste scrollt bei wenig Höhe.

11 lokale HTTPS-Prüfungen mit eigenen temporären Sitzungen sind grün: sieben Seiten mit
Owner-Rolle beziehungsweise persönlichem Kontext, Profilabfrage, gefilterter Viewer-Zugriff,
Abmelden der Testsitzungen und identische CSS-/JS-Auslieferung. Keine Kontodaten, Rollen oder
Projektinhalte wurden dabei geändert. Acht synthetische NAF-Ansichten ergänzen die Prüfung,
unter anderem eigene Rollen, archivierte Projekte und Englisch. Stil- und JavaScript-Prüfung
sind grün.

Die isolierte Browser-Vorschau bestätigt die Nutzerkarte, X/Escape und Fokus-Rückgabe,
den Direktlink zum Rollen-Dialog sowie helle/dunkle Darstellung. Bei 390 × 844 und
320 × 568 Pixeln bleiben Profil und Seitenleiste bedienbar; die Seite läuft auch mit
langem Rollennamen nicht horizontal über. [Bedienung-UI-Evidenz.json](Bedienung-UI-Evidenz.json)
trennt diese Vorschau von der noch offenen Firefox-Abnahme. Die Änderungen gelten für
Source-Betrieb; der frühere Candidate wurde für diese UI-Runde nicht neu gebaut.

## Settings und lokale AI

Der Source-Prototyp verwendet außerdem einen eigenen, abgerundeten Kontur-Cursor mit
violetter Hervorhebung über klickbaren Elementen. Vier SVGs mit jeweils 28 × 28 Pixeln passen ihn an helle
und dunkle Darstellung an. Native CSS-Cursor behalten den präzisen Klickpunkt auch in
Dialogen; Textfelder, Ziehen und Wartezustände verwenden weiterhin die passenden Cursor.
Touch-Geräte und erzwungene Systemfarben erhalten die Browser-Standards. Es gibt keinen
zusätzlichen JavaScript-Prozess für Mausbewegungen. SVGs und CSS wurden über verifiziertes
HTTPS geprüft. Firefox bestätigt den Kontur-Cursor für Flächen, die violette Variante für
Buttons und Links sowie den Textcursor in Eingabefeldern. Der aktualisierte Runtime-Snapshot
unten enthält auch die Profiloberfläche und die neuen Kontofunktionen.

Die neue Settings-Seite bündelt persönliche und projektbezogene Einstellungen in acht
kompakten Karten. Beim Öffnen und Schließen animiert der Dialog zwischen Karte und
Inhalt; X, Escape, Fokus-Rückgabe und reduzierte Bewegung sind berücksichtigt.
Owner können eigene projektgebundene Rollen mit konfigurierbaren Rechten anlegen und
Benutzern zuordnen. Native Auth-Policies prüfen die aktuellen Rechte bei jeder Aktion,
einschließlich AI-Aufrufen und der Wiederherstellung unterbrochener Uploads.

Die lokale AI übernimmt kompatible Ollama-, Werkzeug- und Markdown-Bausteine aus der
NAF-Version von nixcms. Modellwahl, Verbindungstest, Prompts, Gedächtnis, Feedback und
ein Chat mit Streaming sind integriert. Die Werkzeuge verwenden NAFs MCP-Verträge und
die bestehenden App-Services. Schreibende Vorschläge benötigen eine ausdrückliche
Bestätigung mit sichtbaren Argumenten. Browserdaten sind nach Nutzer und Projekt getrennt.
Der Embedding-Layer indiziert Werkzeugdefinitionen in einem persistenten Browser-Cache,
begrenzt die Vorauswahl und berücksichtigt benötigte Lesewerkzeuge. Ein lokaler Katalogtest
mit 500 Definitionen ergab rund sieben Sekunden für den ersten Index und 66 ms für die
nächste Auswahl mit vorhandenem Index. [Settings-AI.md](Settings-AI.md) beschreibt die Grenzen.

Im Firefox über vertrauenswürdiges HTTPS geprüft: Kartenübersicht, Rollen-Dialog,
Schließen mit X und Escape sowie Fokus-Rückgabe, Modellabfrage, Speichern der
AI-Einstellungen und Live-Antwort. `gemma4:e2b` hat über das Board-Werkzeug die vier
tatsächlichen Spalten gelesen. Eine vorgeschlagene Ticketanlage zeigte ihre Argumente
und wurde nach „Ablehnen“ nicht ausgeführt. Die AI ist im geprüften Alice-Browser aktiviert.
Diese Settings-Abnahme fand am Desktop statt; die frühere mobile Board-Abnahme unten
ist ein separater Nachweis.

Bedienung und Architektur stehen in [Settings-AI.md](Settings-AI.md), aktuelle
Prüfergebnisse in [Settings-AI-Evidenz.json](Settings-AI-Evidenz.json). Die neue Migration
ergänzt eigene Rollen ohne Änderungen an bestehenden Mitgliedschaften. Vor dem Einspielen
wurde `work/backups/20260915T202311Z` erstellt.

### Überarbeiteter AI-Chat

Der aktuelle Source-Stand ersetzt den breiten Launcher durch ein 44 × 44 Pixel großes
Sternsymbol. Die Chatfläche wächst beim Öffnen aus dessen Position und fährt beim
Schließen zurück; Inhalte blenden separat ein. Eine kompakte Kopfzeile, kontextbezogene
Einstiege und der kleine Sende-/Stopppfeil im mitwachsenden Eingabefeld ergänzen das Layout.
Fokus-Rückgabe, Escape und reduzierte Bewegung sind berücksichtigt.

Das echte NAF-Template wurde mit einem synthetischen, nicht gespeicherten Nutzer in einer
isolierten Browser-Vorschau geprüft: dunkle und helle Darstellung, Öffnen/Schließen,
Fokus-Rückgabe, Entwurf ohne Absenden, neuer Chat, mehrzeilige Eingabe, leerer Sendebutton
und Konfigurationshinweis. Bei 390 × 844 und 320 × 568 Pixeln bleibt die Chatfläche innerhalb
des Viewports; der Verlauf scrollt intern. Es gab keine Browserfehler. Die Vorschau hat
keine realen Konten, Chats oder Modellaufrufe verwendet.

JavaScript-Syntax, PHP-Template, `make test-ai` und `bin/style check` sind grün. Die App liefert
die neuen CSS-/JS-Dateien über CA-verifiziertes HTTPS identisch zum Source aus.
[AI-Chat-UI-Evidenz.json](AI-Chat-UI-Evidenz.json) hält die Prüfung und ihre Grenzen fest.
Die direkte Abnahme im angemeldeten Firefox bleibt wegen des gesperrten Macs offen.
Der unten dokumentierte Runtime-Snapshot enthält noch die vorherige Chatgestaltung;
die aktuelle Oberfläche ist im Source-Betrieb auf https://localhost verfügbar.

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
Events, CLI Commands, Queue, Scheduler, i18n, MCP-Werkzeugverträge und den Mail-Transportvertrag.
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
| App auf MariaDB 11.4 | 36 Integrationsszenarien erfolgreich, Container-PHP 8.5.10 |
| App auf PostgreSQL 17 | Dieselben 36 Szenarien erfolgreich |
| Echte HTTPS-Anfragen | 42 Prüfungen erfolgreich, getrennte Cookie-Jars und parallele Schreibzugriffe |
| AI-Transport | Streaming-Paketgrenzen, Unicode, Werkzeugantworten, Fehler, Abbruch, lokale URLs und Speichertrennung erfolgreich |
| Lokales Ollama | Echte Werkzeugrunde mit `gemma4:e2b`; zusätzlich Chat und Ablehnen einer Schreibaktion im Firefox geprüft |
| Aktueller Code-Stil | 67 PHP-Dateien nach PER Coding Style 3.0, alle JS-/CSS-Dateien und acht Python-Skripte erfolgreich geprüft |
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
| Lokales Runtime-Image | Anmeldung, fünf geschützte Seiten, Fremdprojekt 404 und privater Download erfolgreich; 18 NAF-Pakete, keine Source-Mounts/Vendor-Symlinks |
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
Ports werden an Loopback gebunden. HTTP auf 8088 leitet auf HTTPS/443 weiter. Logs sind größenbegrenzt.

Seit dem 15. September 2026 folgt auch die Verzeichnisstruktur den lokalen Projekten
`website`, `weonlywalk` und `nafphp/studio`: `docker/rootfs/etc` enthält getrennte
nginx-, PHP-FPM- und Supervisor-Konfigurationen. Ein Development-Overlay schaltet
die OPcache-Zeitstempelprüfung ein. `make first-install` richtet eine fehlende private
`.env` ein, baut das Image, installiert die lokalen Composer-Verknüpfungen und startet
die Anwendung über NAF-Migrationen und den wiederholbaren Demo-Seed.
`make` zeigt alle Befehle; Aufbau und tägliche Bedienung stehen in der README.
Der erste Docker-Abnahmelauf wurde mit `make first-install`, `make test`, `make style-check`
und dem Candidate-Build geprüft. Alle 85 MariaDB-/PostgreSQL-/HTTPS-Szenarien und
drei Worker-Prüfungen bestanden. `docs/Docker-Evidenz.json` und
`docs/Tests-Docker-Make.txt` dokumentieren diesen Durchlauf.
Auf Nutzerwunsch steuert Supervisor jetzt auch die nativen NAF-Worker-/Ticker-Befehle
im App-Container. `make restart-background` startet nur diese beiden Prozesse neu.
Der Container-Healthcheck umfasst HTTPS, Supervisor-Status und beide Heartbeats.
Die Test- und Candidate-Dienste deaktivieren Hintergrundprozesse ausdrücklich.

`make certificates` erzeugt eine private Entwicklungs-CA und ein signiertes TLS-Serverzertifikat mit DNS-/IP-SANs.
Zertifikat und privater Schlüssel sind von Git und Images ausgeschlossen und werden
nur lesbar eingebunden. Das Systemvertrauen wird nicht verändert; die Tests prüfen
das Zertifikat ausdrücklich. Die zuvor Port 443 belegende Studio-App wurde mit
ausdrücklicher Nutzerfreigabe angehalten; ihre Datenbank bleibt aktiv.

```sh
cd ~/PhpStormProjects/nafinity
make run
make status
make logs
make supervisor-status
```

`packages -> ../nafphp` ist der IDE-Link. Die App und die Quellen werden im Container
separat eingebunden. `bin/dev-composer` erzeugt das ignorierte Development-Manifest und
installiert im Container relative Vendor-Links. Die PHP-Konfiguration referenziert
App-URL und Datenbankwerte mit NAFs `ENV:...`; das gilt auch für das LDAP-/OIDC-Beispiel.
Standardwerte kommen aus Compose, die Auflösung übernimmt der Framework-Config-Service. FPM sieht Änderungen im nächsten Request.
Langlebige Prozesse nach Source-Änderungen mit `make restart-background` neu starten.

NAF-Logs werden über einen PSR-3-Adapter an PHP/FPM und die begrenzten Compose-Logs weitergereicht.
Lokale alte Logdateien werden weder versioniert noch in ein Image kopiert.

`/health/live` prüft den HTTP-Prozess. `/health/ready` prüft PDO, die fünf erforderlichen
App-Migrationen, die Hintergrundtabellen und die Auflösung des AttachmentService mit dem nativen Storage-Datenträger. Worker und Ticker haben eigene Heartbeats.
Die aktuelle Schema-Kennung ist `202609150002`; acht Migrationen inklusive Plugins sind angewandt.

`bin/build-candidate` erzeugt einen eingefrorenen lokalen Runtime-Snapshot mit Package-Hashes.
Er enthält keine Vendor-Symlinks, kein Composer und keine Source-Mounts. Der Builder kontrolliert die vollständige Menge aller benötigten NAF-Pakete.
Private Daten-, Logverzeichnisse und generierte PHPStan-/Test-/Formatter-Caches werden ausgeschlossen; gleichnamige Runtime-Pakete bleiben enthalten.
Der geprüfte Snapshot `nafinity:candidate` hat **130,03 MiB** (136.351.411 Byte),
Image-ID `sha256:efe0a19b9d0bab04b156549034263f36a7c91d5db93d4a420c9cbace72df904d`.
Anmeldung, fünf geschützte Seiten, Profil-API, Projektisolation und der private Download wurden über
HTTPS-Port 8445 erfolgreich geprüft. Eingebunden sind das private Datenverzeichnis und die lokalen TLS-Dateien.
Hashes und Einzelresultate stehen in `docs/Snapshot-Evidenz.json`.
Dieser Snapshot ist ausdrücklich keine veröffentlichte Distribution.

App und Datenbank bleiben aktiv; Worker und Ticker laufen unter Supervisor im App-Container. Die zusätzlichen Test- und Candidate-Container wurden
nach der Abnahme gestoppt. Der geprüfte Candidate lässt sich erneut starten:

```sh
make candidate-up
```

Backups mit `make backup` erstellen. `make verify-restore BACKUP=VERZEICHNIS` stellt ausschließlich
in `nafinity_restore_test` wieder her und kontrolliert Dateien/Hashes. Niemals Test- oder
Down-Migrationsbefehle gegen die Demo-/Produktivdatenbank umleiten.

## Tests wiederholen

`app/tests/run.php`, `benchmark.php` und `queue_process.php` verlangen ausdrücklich
`APP_ENV=test` und `DB_DATABASE=nafinity_test`. Der Integrationsrunner setzt dieses Schema
zurück. Die Make-Ziele legen die Testdatenbank bei Bedarf an; die Demo-Datenbank heißt `nafinity`.

```sh
make test
make test-down
```

`make test-ai` wiederholt die isolierten AI-Transport- und Speicherprüfungen.
`make test-http` setzt die Testdatenbank vor jedem Aufruf mit `tests/run.php` zurück
und seedet sie neu, damit Ratelimits und Testzustände nicht aus dem vorigen Lauf übernommen werden.
Die JSON-Dateien in `docs/` enthalten die einzelnen Szenarien, Messwerte und Runtime-Evidenz.
`bin/check-plugin-stack` wiederholt den Minimal-/Alle-Plugin-Boot in isolierten Hosts
und prüft dort auch eine tatsächlich aufgelöste PDO-Verbindung.

## Verbleibende Grenzen

- Stabile Paket-Releases, der daraus erzeugte Distributions-Lock und ein frischer
  Install ohne lokale Paketquellen stehen aus. Im Rahmen dieser Umsetzung wurden keine Pakete gemergt oder als Release veröffentlicht.
- Kein externer LDAP-Server, OIDC-Issuer oder SMTP-Dienst wurde kontaktiert. Für deren
  Aktivierung fehlen noch Deployment-Konfiguration und End-to-End-Abnahme. Lokales SMTP
  über Mailpit ist für Kontoverifizierung und Sicherheitshinweise geprüft.
- Projekt-Mail ist ausgeschaltet. Die Ledger-/Queue-Kombination begrenzt normale Duplikate;
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

## Lesbarkeitsrunde nach der Prototyp-Abnahme

Das gesamte Nafinity-Projekt und 44 Dateien unserer bisherigen NAF-Integration wurden
auf einen gemeinsamen PHP-Stil gebracht: PER Coding Style 3.0 mit gruppenweise ausgerichteten
`=` und `=>`. Controller, Services, SQL, Templates, Tests und Build-Skripte sind gegliedert;
Zwischenvariablen erklären Login-Limits, Providerwahl, Upload-Quoten und Board-Zustände.
JavaScript/CSS folgen Prettier, Python folgt Black. Die separaten Storage-Änderungen blieben unberührt.

`bin/style install`, `bin/style fix` und `bin/style check` machen den Stil reproduzierbar.
Die Versionen sind in separaten Tool-Manifests/Locks festgelegt; kein Formatter gelangt in die Runtime.
Der erfolgreiche Check umfasst 54 PHP-Dateien in Nafinity, 44 Paketdateien, JavaScript/CSS
und sechs Python-Skripte. Die 371 Pakettests sowie jeweils 28 Datenbank- und 28 HTTP-Prüfungen
sind erneut grün. Native Sessions, Worker-Recovery, Limiter/LDAP-Verträge sowie Anmeldung und
privater Download im neu gebauten Snapshot sind bestätigt.

Die bestehenden acht RC-Branches wurden aktualisiert; Limiter und LDAP bleiben lokal.
Die Paket-APIs und fachlichen Verträge sind unverändert, daher war keine Änderung der
öffentlichen Paket-Anleitungen notwendig. `docs/Code-Style.md` dokumentiert die Entwicklerregeln;
`docs/Code-Style-Evidenz.json` enthält die neuen Commit- und Prüfnachweise.

## Git-Übergabe der bestehenden NAF-Pakete

Die folgenden geprüften RC-Branches wurden nach dem vereinbarten NAF-Workflow gepusht.
Der Maintainer übernimmt Merge und Release; es wurde keine neue Veröffentlichung angelegt.

| Paket | Branch | Commit | Review |
|---|---|---|---|
| framework | `v0.2.4-rc` | `1166372` | [Vergleich](https://github.com/nafphp/framework/compare/main...v0.2.4-rc) |
| database | `v0.2.2-rc` | `720fc14` | [Vergleich](https://github.com/nafphp/database/compare/main...v0.2.2-rc) |
| form | `v0.2.3-rc` | `9dbea01` | [Vergleich](https://github.com/nafphp/form/compare/main...v0.2.3-rc) |
| session | `v0.2.2-rc` | `27d8014` | [Vergleich](https://github.com/nafphp/session/compare/main...v0.2.2-rc) |
| orm | `v0.2.2-rc` | `d653bfb` | [Vergleich](https://github.com/nafphp/orm/compare/main...v0.2.2-rc) |
| queue | `v0.2.3-rc` | `a19e242` | [Vergleich](https://github.com/nafphp/queue/compare/main...v0.2.3-rc) |
| schedule | `v0.2.3-rc` | `72b35f6` | [Vergleich](https://github.com/nafphp/schedule/compare/main...v0.2.3-rc) |
| cli | `v0.2.2-rc` | `76df414` | [Vergleich](https://github.com/nafphp/cli/compare/main...v0.2.2-rc) |
