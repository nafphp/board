# Nafinity

Eine laufende Ticket-/Kanban-Anwendung auf NAF. Projektort: `/Users/flo/PhpStormProjects/nafinity`.
Die Geschaeftsregeln liegen in App-Services; Routing, Auth, Policies, Views, Form/CSRF,
PDO/Migrationen, ORM, Events, Queue, Scheduler, Mail und Uebersetzung kommen aus NAF.

## Starten

Voraussetzungen: Docker mit Compose, Python 3 auf dem Host und die lokalen NAF-Pakete
unter `../nafphp`. `.env` ist lokal bereits eingerichtet und wird nicht versioniert.
Bei einer neuen Einrichtung `.env.example` kopieren und beide Datenbankpasswoerter setzen.

```sh
cd ~/PhpStormProjects/nafinity
docker compose build app
bin/dev-composer install --no-interaction
docker compose up -d db app
docker compose exec app php vendor/bin/naf db:migrate up
docker compose exec app php vendor/bin/naf nafinity:seed
docker compose --profile background up -d worker ticker
```

Der Seed ist nur fuer eine leere Entwicklungs-/Testdatenbank erlaubt. Bestehende Daten
bleiben bei einem erneuten Aufruf erhalten; der Befehl ueberspringt die Initialisierung.

Oeffnen: **http://localhost:8088**. Demo-Passwort fuer alle drei Konten:
`Nafinity-Demo-2026!`.

| Konto | Rolle / Projekt |
|---|---|
| alice@example.test | Owner, Nafinity |
| bob@example.test | Owner, Studio Nord |
| viewer@example.test | Viewer, Nafinity |

## Was funktioniert

Mehrere isolierte Projekte, Mitgliedschaften und Rollen, konfigurierbare Spalten und
Swimlanes, Labels, Mehrfach-Zuweisung, Prioritaet und Termin. Tickets haben eigene URLs,
einen optionalen Drawer, Bearbeitung, Verschieben, Schliessen/Wiederoeffnen und Archiv.
Kommentare und Aktivitaeten folgen den Projektgrenzen. Veraltete Schreibzugriffe enden
mit 409; die Oberflaeche bietet das Nachladen des aktuellen Stands an.

Filter und echte Volltextsuche, private Anhaenge ueber den benannten NAF-Storage-Datentraeger mit Quoten und Wiederanlauf,
In-App-Benachrichtigungen, Einstellungen, Light/Dark und mobile Darstellung sind integriert.
Boards liefern maximal 300 Karten und die gesamte Trefferzahl; bei groesseren Bestaenden
die Filter verwenden. Deutsch ist die vollstaendige Basissprache; Englisch deckt die
wichtigsten Oberflaechentexte ab, einige Meldungen bleiben im Prototyp deutsch.

## Lokale Framework-Quellen

`packages -> ../nafphp` dient der IDE. Compose bindet die App unter `/workspace/app`
und die NAF-Quellen unter `/workspace/packages` ein. `bin/dev-composer` erzeugt ein
ignoriertes Source-Manifest mit ausdruecklichen Development-Versionen. Composer laeuft
im Container und erzeugt relative Vendor-Symlinks, die auf Host und Container aufgehen.
FPM liest Source-Aenderungen beim naechsten Request. Nach Aenderungen an Hintergrundcode:

```sh
docker compose --profile background restart worker ticker
```

## Pruefungen und Betrieb

Der Code folgt PER Coding Style 3.0 mit lokal ausgerichteten Zuweisungen.
[Code-Stil und Formatter](docs/Code-Style.md) dokumentiert die Regeln:
`bin/style install`, danach `bin/style check` oder `bin/style fix`.

Siehe [Implementierung und Abnahme](docs/Implementation.md) fuer Ergebnisse, Grenzen,
Release-Branches und Wiederholung der Tests. Health: `/health/live` und `/health/ready`.
Die Readiness prueft Datenbank, erforderliche App-Migrationen, Hintergrundtabellen und die native Storage-Anbindung.

```sh
bin/backup
bin/verify-restore work/backups/ZEITSTEMPEL
docker compose --profile background logs --tail=50 worker ticker
```

Backups enthalten Datenbank und private Dateien. Die Restore-Probe schreibt ausschliesslich
nach `nafinity_restore_test`; sie ersetzt keine laufende Anwendung. Backups sind privat zu
behandeln. Ausgehende Mail ist deaktiviert; lokal ist ein DummyTransport konfiguriert.

## Source-Modus und Distribution

Die verwendeten Fixes liegen auf RC-Branches, Limiter und LDAP lokal. Storage wurde parallel auf seinem RC-Branch weiterentwickelt. `app/composer.json`
beschreibt die benoetigten zukuenftigen Mindestversionen. Ein sauberer Install aus
veroeffentlichten Paketen und ein stabiler Lock sind erst nach deren Releases moeglich.
Es wurden keine Pakete gemergt oder veroeffentlicht.

`bin/build-candidate` baut jetzt schon einen eingefrorenen lokalen Source-Snapshot ohne
Source-Mounts, Vendor-Symlinks oder Composer im Runtime-Image. Er ist ausdruecklich
`unreleased-source-snapshot`, kein Nachweis einer veroeffentlichten Distribution.
Das Production-Target verlangt dagegen einen echten `composer.lock`, linkfreies Vendor
und den Marker `vendor/.nafinity-distribution` nach verifiziertem Dist-Install.

Lokale Anmeldung bleibt aktiv. LDAP/OIDC sind optional vorbereitet und abgeschaltet;
externe Konten werden nur explizit verknuepft. `app/identity.example.php` dokumentiert die
Konfiguration, private Werte gehoeren in die ignorierte `identity.local.php`.

Der [urspruengliche Plan](docs/Nafinity-Prototypplan.md), die [Analyse](docs/Nafinity-Analyse.md)
und die [Review](docs/Review-Analyse-und-Plan.md) bleiben als Entscheidungshistorie erhalten.

`bin/check-plugin-stack` prueft den minimalen und den kompletten bestehenden Plugin-Stack
in isolierten Source-Hosts. Die Upload-Regeln gehoeren zu `App\Support\AttachmentStorage`;
Datei-I/O laeuft ueber `Naf\Storage\storage('attachments')`. Die neue allgemeine Storage-API
wurde parallel im Storage-Paket vorbereitet; dessen entfernte Legacy-Klasse wird nicht mehr benoetigt.
