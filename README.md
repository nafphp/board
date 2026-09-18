# Nafinity

Eine laufende Ticket-/Kanban-Anwendung auf NAF. Projektort: `/Users/flo/PhpStormProjects/nafinity`.
Die Geschaeftsregeln liegen in App-Services; Routing, Auth, Policies, Views, Form/CSRF,
PDO/Migrationen, ORM, Events, Queue, Scheduler, Mail und Uebersetzung kommen aus NAF.

## Starten

Voraussetzungen: Docker mit Compose, Make, OpenSSL und Python 3 auf dem Host und die lokalen
NAF-Pakete unter `../nafphp`.

```sh
cd ~/PhpStormProjects/nafinity
make first-install
```

Das richtet eine fehlende `.env` mit zufälligen lokalen Datenbankpasswörtern ein,
erzeugt das lokale TLS-Zertifikat, baut das Image, installiert Composer-Abhängigkeiten im Container, führt die
NAF-Migrationen und den Demo-Seed aus und startet App, Datenbank, Worker und Scheduler.
Eine vorhandene `.env` bleibt erhalten. `NAFINITY_PORT`, `NAFINITY_HTTPS_PORT` und `NAF_SOURCE_ROOT` können
dort angepasst werden. Die installierte Umgebung lässt sich danach mit `make run`
starten; nach Änderungen an Docker-Dateien `make build-app run` verwenden.

```sh
make                       # Alle verfügbaren Befehle
make status                # Container und Health-Status
make logs                  # App-, Worker- und Scheduler-Logs verfolgen
make ssh                   # Shell als www im App-Container
make composer ARGS='show naf/framework'
make naf                   # Verfügbare NAF-Befehle
make stop                  # Alle Nafinity-Container anhalten
make down                  # Container/Netz entfernen; Datenbank und Dateien behalten
```

Der Seed ist nur fuer eine leere Entwicklungs-/Testdatenbank erlaubt. Bestehende Daten
bleiben bei einem erneuten Aufruf erhalten; der Befehl ueberspringt die Initialisierung.

Öffnen: **https://localhost** (Port 443). HTTP auf Port 8088 leitet mit Status 308 dorthin weiter. Demo-Passwort fuer alle drei Konten:
`Nafinity-Demo-2026!`.

| Konto | Rolle / Projekt |
|---|---|
| alice@example.test | Owner, Nafinity und Archiv & Ideen |
| bob@example.test | Owner, Studio Nord |
| viewer@example.test | Viewer, Nafinity |

## Was funktioniert

Mehrere isolierte Projekte, Mitgliedschaften und Rollen, konfigurierbare Spalten und
Swimlanes, Labels, Mehrfach-Zuweisung, Prioritaet und Termin. Tickets haben eigene URLs,
einen optionalen Drawer, Bearbeitung, Verschieben, Schliessen/Wiederoeffnen und Archiv.
Ein Ticket laesst sich ausserdem in ein anderes Projekt verschieben: Kommentare, Anhaenge,
Verlauf und erfasste Zeit kommen mit, es bekommt dort eine neue Nummer, und Labels,
Verknuepfungen sowie Zustaendige ohne Zugriff bleiben zurueck.
Kommentare und Aktivitaeten folgen den Projektgrenzen. Veraltete Schreibzugriffe enden
mit 409; die Oberflaeche bietet das Nachladen des aktuellen Stands an.

Die [Ticketdetails](docs/Ticket-Details.md) bieten eine ruhige Leseansicht mit Inline-
Bearbeitung mit Auto-Save, formatierbarer Beschreibung, Metadatenleiste, Startdatum und
Zeitaufwand. Kompakte Eingaben behalten die Schriftgröße bei. Die ganze Boardkarte öffnet
das Ticket; ihr Menü bleibt separat bedienbar.
Verknüpfte Tickets erscheinen beidseitig; Kommentare unterstützen @-Erwähnungen und
Antworten unter dem jeweiligen Kommentar. Die Nachrichteneingabe bleibt sichtbar.
Spalten und Personen lassen sich über dasselbe durchsuchbare Dropdown direkt auswählen;
mehrere Zuständige bleiben möglich und werden kompakt mit einem zusätzlichen Zähler angezeigt.
Neue Tickets entstehen in einem Modal mit demselben Template, Editor und Metadatenfeldern.

Filter und echte Volltextsuche, private Anhaenge ueber den benannten NAF-Storage-Datentraeger mit Quoten und Wiederanlauf,
In-App-Benachrichtigungen, Einstellungen, Light/Dark und mobile Darstellung sind integriert.
Die Settings öffnen sich als animierte Karten und enthalten eigene Projektrollen sowie einen
lokalen Ollama-Chat. Der Embedding-Layer wählt aus großen Werkzeugkatalogen passende Aktionen
aus; Details stehen in [Settings und lokale AI](docs/Settings-AI.md).
Boards liefern maximal 300 Karten und die gesamte Trefferzahl; bei groesseren Bestaenden
die Filter verwenden. Deutsch ist die vollstaendige Basissprache; Englisch deckt die
wichtigsten Oberflaechentexte ab, einige Meldungen bleiben im Prototyp deutsch.

## Docker-Struktur

Wie in `website`, `weonlywalk` und `nafphp/studio` bildet `docker/rootfs` die Pfade
im Container ab. Das Dockerfile kopiert diesen Baum nach `/`:

```text
docker/
├── Dockerfile
├── rootfs/etc/
│   ├── nginx/
│   │   ├── nginx.conf
│   │   ├── conf.d/app.conf
│   │   └── ssl/                 # Zertifikat und Schlüssel: nur lokal
│   ├── php85/
│   │   ├── conf.d/99-nafinity.ini
│   │   ├── php-fpm.conf
│   │   └── php-fpm.d/www.conf
│   ├── ssl/nafinity.cnf         # Lokale Zertifikatserzeugung
│   └── supervisor/
│       ├── conf.d/supervisord.conf
│       └── programs/
│           ├── nginx.conf
│           ├── php-fpm.conf
│           ├── queue-worker.conf
│           └── schedule-ticker.conf
├── rootfs/usr/local/bin/
│   ├── nafinity-entrypoint
│   └── nafinity-healthcheck
└── development/rootfs/etc/php85/conf.d/zz-development.ini
```

Das Development-Target ergänzt die OPcache-Einstellungen für direkt sichtbare
Source-Änderungen. Runtime und Production verwenden nur den gemeinsamen Baum.
Supervisor steuert nginx, PHP-FPM, `naf queue:consume` und `naf schedule:ticker`
gemeinsam im App-Container. MariaDB bleibt ein eigener Dienst. Mit
`make supervisor-status` sieht man alle vier Prozesse; `make restart-background`
startet gezielt Worker und Ticker neu. Der Container-Healthcheck prüft HTTPS,
die beiden Supervisor-Prozesse und ihre NAF-Heartbeats. Test- und Candidate-Dienste
setzen `NAFINITY_BACKGROUND_ENABLED=false`, damit sie keine zusätzlichen Aufgaben
ausführen beziehungsweise isolierte Test-Fixtures verändern.
Der Build-Kontext bleibt das Projektverzeichnis, damit auch die App in das
Production- beziehungsweise Snapshot-Image kopiert werden kann.

## Lokales HTTPS

`make certificates` erzeugt mit OpenSSL eine private Entwicklungs-CA und ein von ihr signiertes Serverzertifikat für
`localhost`, `nafinity.local`, `127.0.0.1` und `::1`. Es gilt 365 Tage; ein noch
gültiges zusammengehöriges Zertifikat/Schlüsselpaar bleibt beim erneuten Aufruf
erhalten. `make first-install`, `make run` und die Test-/Candidate-Startziele rufen
die Erzeugung automatisch auf. Nach einer Erneuerung `make restart` ausführen.

Der private CA-Schlüssel liegt ausschließlich unter `work/tls` und wird nicht in
den Container eingebunden. Die Server-TLS-Dateien liegen unter `docker/rootfs/etc/nginx/ssl`, sind von Git und beiden
Image-Builds ausgeschlossen und werden nur lesbar eingebunden. Das private
Schlüsselmaterial bleibt lokal. Der Schlüssel hat Dateimodus 0600.

Für einen Browser ohne Zertifikatswarnung das öffentliche CA-Zertifikat `ca.pem` in der
macOS-Schlüsselbundverwaltung importieren und für SSL als vertrauenswürdig markieren.
Private Schlüssel werden dafür nicht importiert. Das Setup verändert
den System-Schlüsselbund nicht. CLI- und Integrationstests prüfen TLS mit dem
öffentlichen CA-Zertifikat als explizitem Vertrauensanker.

Port 443 muss frei sein. Alternativ `NAFINITY_HTTPS_PORT=8443` in `.env` setzen;
die HTTP-Weiterleitung folgt diesem Port. Der Testdienst verwendet HTTPS auf 8444,
der Candidate auf 8445. Im Container bindet nginx den unprivilegierten Port 8443
und läuft weiterhin als `www`.

## Lokale Framework-Quellen

`packages -> ../nafphp` dient der IDE. Compose bindet die App unter `/workspace/app`
und die NAF-Quellen unter `/workspace/packages` ein. `bin/dev-composer` erzeugt ein
ignoriertes Source-Manifest mit ausdruecklichen Development-Versionen. Composer laeuft
im Container und erzeugt relative Vendor-Symlinks, die auf Host und Container aufgehen.
Die App-Konfiguration verwendet NAFs native `ENV:VARIABLE_NAME`-Referenzen, auch
für die optionalen LDAP-/OIDC-Zugangsdaten im Konfigurationsbeispiel. Standardwerte
stehen in Compose; außerhalb von Compose die Variablen über das Environment oder
NAFs `.env`/`.env.local` im App-Verzeichnis bereitstellen. PHP verwendet im Container
`variables_order=EGPCS`, damit NAF die Werte aus `$_ENV` auflösen kann.

FPM liest Source-Aenderungen beim naechsten Request. Nach Aenderungen an Hintergrundcode:

```sh
make restart-background
```

## Pruefungen und Betrieb

Der Code folgt PER Coding Style 3.0 mit lokal ausgerichteten Zuweisungen; die genauen
Regeln stehen in `.php-cs-fixer.dist.php` und zusammengefasst in `AGENTS.md`.
Einmalig `make style-install`, danach `make style-check` oder `make style-fix`.
Geprueft werden PHP, PHP in Templates, JavaScript, CSS und die Python-Skripte.
Die Formatter benötigen zusätzlich Node.js/npm auf dem Host, sind unter `tools/style` mit
eigenen Locks festgelegt und bleiben ausserhalb des Build-Kontexts und der Runtime.

Siehe [Implementierung und Abnahme](docs/Implementation.md) fuer Ergebnisse, Grenzen,
Release-Branches und Wiederholung der Tests. Health: `/health/live` und `/health/ready`.
Die Readiness prueft Datenbank, erforderliche App-Migrationen, Hintergrundtabellen und die native Storage-Anbindung.

```sh
make test                  # Alles: MariaDB, PostgreSQL, HTTP, Profil, Worker, AI und Erweiterungen
make test-profile          # Kontowechsel, Code-Verifizierung und Sitzungswiderruf
make mailpit               # Lokales Testpostfach auf Port 8025 starten
make test-ai               # AI-Transport und semantischer Router, ohne laufendes Modell
make test-down             # Testdienste anschließend anhalten
make backup
make verify-restore BACKUP=work/backups/ZEITSTEMPEL
make supervisor-status     # Status aller vier App-Prozesse
```

Die Tests legen `nafinity_test` bei Bedarf an und setzen nur diese Testdatenbanken zurück.
`make test-http` bereitet seine Fixtures bei jedem Aufruf neu vor.

Backups enthalten Datenbank und private Dateien. Die Restore-Probe schreibt ausschliesslich
nach `nafinity_restore_test`; sie ersetzt keine laufende Anwendung. Backups sind privat zu
behandeln. Sicherheitsmails laufen lokal über NAFs MailTransport und Mailpit; das Testpostfach
liegt auf http://localhost:8025. Es wird nichts ins Internet versendet. Projektbenachrichtigungen
per Mail bleiben deaktiviert. [Eigenes Profil und Kontoverifizierung](docs/Profile.md) beschreibt
die Bedienung, SMTP-Konfiguration und Sicherheitsgrenzen.

## Source-Modus und Distribution

Die verwendeten Fixes liegen auf RC-Branches, Limiter und LDAP lokal. Storage wurde parallel auf seinem RC-Branch weiterentwickelt. `app/composer.json`
beschreibt die benoetigten zukuenftigen Mindestversionen. Ein sauberer Install aus
veroeffentlichten Paketen und ein stabiler Lock sind erst nach deren Releases moeglich.
Es wurden keine Pakete gemergt oder veroeffentlicht.

`make candidate-build` baut jetzt schon einen eingefrorenen lokalen Source-Snapshot ohne
Source-Mounts, Vendor-Symlinks oder Composer im Runtime-Image. Er ist ausdruecklich
`unreleased-source-snapshot`, kein Nachweis einer veroeffentlichten Distribution.
`make candidate-up` startet den gebauten Snapshot auf https://localhost:8445;
`make candidate-down` hält ihn wieder an.
Das Production-Target verlangt dagegen einen echten `composer.lock`, linkfreies Vendor
und den Marker `vendor/.nafinity-distribution` nach verifiziertem Dist-Install.

Lokale Anmeldung bleibt aktiv. LDAP/OIDC sind optional vorbereitet und abgeschaltet;
externe Konten werden nur explizit verknuepft. `app/identity.example.php` dokumentiert die
Konfiguration, private Werte gehoeren in die ignorierte `identity.local.php`.

Plan, Analyse und Review von vor der Umsetzung liegen als eingefrorene
[Entscheidungshistorie](docs/history/) unter `docs/history/`.

Nafinity ist durch installierte Composer-Pakete erweiterbar: eigene Controller, Routen und
Dienste, Menueeintraege, Settings, Ticketfelder, Widgets, Board-Filter, Uebersetzungen,
AI-Werkzeuge, Commands, Migrationen und Jobs. Wie das geht, steht in
[Plugin-Erweiterbarkeit](docs/Plugin-Erweiterbarkeit.md); zwei vollstaendige Beispielpakete
liegen unter `examples/`. `make test-plugins` installiert beide als echte Composer-Pakete in
einen Wegwerf-Host, prueft sie in-process, ueber HTTP und ueber die Asset-Kommandos und
bootet dieselbe Datenbank danach noch einmal ohne sie.

`bin/check-plugin-stack` prueft den minimalen und den kompletten bestehenden Plugin-Stack
in isolierten Source-Hosts. Die Upload-Regeln gehoeren zu `App\Support\AttachmentStorage`;
Datei-I/O laeuft ueber `Naf\Storage\storage('attachments')`. Die neue allgemeine Storage-API
wurde parallel im Storage-Paket vorbereitet; dessen entfernte Legacy-Klasse wird nicht mehr benoetigt.
