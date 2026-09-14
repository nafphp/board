# Nafinity — Framework Findings

Stand: 14. September 2026. Dieses Dokument ist das fortzuschreibende Härtungsprotokoll. **Es wurden keine Framework-Fixes implementiert, committed oder veröffentlicht.** Die nachstehenden APIs sind Vorschläge, sofern sie nicht ausdrücklich als vorhanden bezeichnet sind.

Prioritäten: **P0** vor dem betreffenden Prototyp-Kernpfad; **P1** vor produktivem MVP bzw. Aktivierung der Funktion; **P2** nach Bedarf. A/B/C wie in der [Analyse](Nafinity-Analyse.md).

**Achtung, zwei verschiedene Skalen mit gleicher Schreibweise.** Die Prioritäten hier sind Dringlichkeitsklassen und **nicht** die gleichnamigen Lieferphasen P0–P4 des [Prototypplans](Nafinity-Prototypplan.md). Dringlichkeit P1 bedeutet „vor dem produktiven MVP", was je nach Funktion in Planphase P2, P3 oder P4 fällt. Eine Dringlichkeit darf vorgezogen werden, wenn ein Fix ohnehin im selben Release mitläuft; sie darf nicht nach hinten rutschen. Die folgende Abbildung ist die verbindliche Lesart:

| Finding | Dringlichkeit | Geplante Phase | Verhältnis |
|---|---|---|---|
| F01 | P0 | P0 | passend |
| F02 | P0 für PostgreSQL | P0 | passend |
| F03 | P0 | P0 | passend |
| F04 | P1 DB-Sessions; P0 PG-Schemaboot | P0 | passend |
| F05 | P0 bei App-Entities | P0 | passend |
| F06 | P1 vor komponierten Schreibabläufen | P2 | passend |
| F07 | P1 vor verlässlichen Hintergrundfunktionen | P3 | passend |
| F08 | P1 | P3 | passend |
| F09 | P1, kleiner Core-Fix | P0 | vorgezogen, läuft im Core-Release mit |
| F10 | P1 für größere Attachments | P3 | passend |
| F11 | P0 für API-Eingaben | P0 | passend |
| F12 | P1 bei LDAP-Aktivierung | P4 | passend |
| F13 | P1 vor Attachments | P3 | passend |
| F14 | P1 vor produktivem Login | P4 | passend |
| F15 | P1 bei Mail-Aktivierung | P3 | passend |
| F16 | P1 vor Deployment | P0 | vorgezogen, läuft im Core-Release mit |
| F17 | P0 bei Auth/DB ohne OAuth | P0 | passend |
| F18 | P0 | P0 (Database- und Core-Teil getrennt) | passend |

Bei jedem neuen Finding ist diese Zeile mitzuführen. Eine Dringlichkeit ohne zugeordnete Phase ist unvollständig.

## F01 — CSRF-Methoden, Auth-Modus und mehrere Tabs

**B · P0 · EXISTING NAF PLUGIN — naf/form**

- **Problem/Nachweis:** `PATCH` ohne Token wird akzeptiert; `POST` mit `Authorization: Bearer invalid` ebenso. Zweiter Aufruf von `generate()` macht den ersten Page-Token ungültig. In der isolierten Fixture ausgeführt. Ein beliebiger Bearer-Header beweist keine authentifizierte Anfrage; dieser Befund allein behauptet noch keinen vollständig demonstrierten Cross-Origin-Exploit, weil Browser/CORS zusätzlich eine Rolle spielen.
- **Ursache:** feste Methodenliste POST/PUT/DELETE, pauschale Header-Ausnahme, immer neu gesetzter Sessiontoken. [Listener](https://github.com/nafphp/form/blob/598bee7b0f46474fc644acfe293451ca518a2538/src/Events/CsrfListener.php#L24), [Token](https://github.com/nafphp/form/blob/598bee7b0f46474fc644acfe293451ca518a2538/src/Support/Csrf.php#L12).
- **Generalisierbarkeit/Ziel:** jede Session-Webapp mit JSON, mehreren Formularseiten oder PATCH; keine App-Kopie des Listeners.
- **Kleinste Änderung/API:** GET/HEAD/OPTIONS als sichere Methoden behandeln, übrige Mutationen schützen. Vorhandene ausdrücklich benannte Route-Exemptions für eigenständig authentifizierte Protokollendpunkte nutzen. Zusätzlich eine stabile Token-Abfrage wie `token()` anbieten, explizite Rotation beibehalten; Tokenformat strikt prüfen. Rotation an Login/Logout bzw. Vertrauenswechsel koppeln.
- **Migration/BC:** PATCH wird bewusst strenger. Bearer-Nutzer müssen echte Protokollendpunkte benennen. Wenn `generate()` semantisch erhalten bleibt, Templates auf stabile Abfrage migrieren; sonst veränderte Rotationssemantik klar versionieren. Keine globale CSRF-Abschaltung.
- **Tests:** Methodenmatrix, Cookie+Fake-Bearer, echte Route-Exemptions, falsche/nichtskalare Token, zwei Tabs, mehrere Formulare, Login-/Logoutrotation, JSON-Header und Form-Body.

## F02 — PostgreSQL-Verbindung scheitert am DSN

**B · P0 für PostgreSQL · EXISTING NAF PLUGIN — naf/database**

- **Problem/Nachweis:** native PDO-Verbindung zu PostgreSQL 17.11 funktioniert, `Database` meldet `invalid connection option "charset"`. MariaDB 11.4.13 funktioniert im selben Probeaufbau.
- **Ursache:** MySQL-artiger DSN wird auch für pgsql erzeugt. Der vorhandene Test erwartet genau diesen ungültigen PostgreSQL-DSN, ohne eine echte Verbindung herzustellen. [Database](https://github.com/nafphp/database/blob/f2027ff9535bffda95e4dc7b25861d24144c2118/src/Core/Database.php#L38).
- **Generalisierbarkeit/Ziel:** grundlegende zugesagte Datenbankunterstützung, im bestehenden Plugin beheben.
- **API:** keine neue öffentliche Abstraktion; DSN pro wirklich unterstütztem PDO-Driver korrekt erzeugen. Connection-Encoding/Optionen treibergerecht einstellen. Nicht unterstützte Treiber klar ablehnen statt einen vermeintlich passenden DSN zu erzeugen.
- **Migration/BC:** mysql/sqlite unverändert; falsche Aliase nicht still als neue volle Treiberunterstützung deklarieren. PDOException als previous erhalten, Nutzerausgabe weiterhin sanitisiert.
- **Tests:** echte Verbindungen, Sonderzeichen/UTF-8, Ports, fehlende Config, fehlender Driver; erwartete DSN-Tests korrigieren.

## F03 — Migrationen sind noch kein verlässlicher portabler Pfad

**B · P0 · EXISTING NAF PLUGIN — naf/database**

- **Problem/Nachweis:** Tracker-Erstellung scheitert real auf PostgreSQL an Backticks. Weitere Codebefunde: MySQL-DDL für alle Nicht-SQLite-Treiber; Name nur Basename, teilweise VARCHAR(32), ohne Unique-Constraint; Pfade werden nacheinander durchlaufen, kein globaler Ausführungsplan und kein umgekehrter Rückbau.
- **Ursache:** einfacher Runner ohne ausreichend definierte Reihenfolge/Identität/Dialekte. [MigrateCommand](https://github.com/nafphp/database/blob/f2027ff9535bffda95e4dc7b25861d24144c2118/src/Commands/MigrateCommand.php#L39).
- **Generalisierbarkeit/Ziel:** jede Anwendung mit mehreren Plugin-Migrationspfaden. Die App soll keine zweite Migrations-CLI bauen.
- **API:** bestehenden `db:migrate` behalten; deterministischen Plan, eindeutige qualifizierte Migration-ID, ausreichend lange eindeutige Tracking-Spalte, reverse applied order für Down, klaren Fehlerstatus und Schutz vor zwei gleichzeitigen Runnern ergänzen. Einfache explizite Abhängigkeit nur dort, wo Namenssortierung nicht reicht; kein großer Schema-Builder.
- **Migration/BC:** vorhandene Basename-Zeilen nur bei eindeutiger Zuordnung überführen; Ambiguität mit Diagnose stoppen. Aktuelle Down-Semantik nicht still in „letztes Batch“ umdeuten. MariaDB-DDL ist nicht generell atomar; wiederanlaufbare Migrationen und Fehlerbehandlung dokumentieren.
- **Tests:** echter CLI-Aufruf auf MariaDB/PG, frische DB, zweimal Up, Parent-/Child-Rückbau, zwei gleichnamige Migrationen aus verschiedenen Plugins, lange Namen, teilweiser Fehler, paralleler Runner. Die bestehende Database-Suite deckt den Command derzeit nicht direkt ab.

## F04 — Session-DB-Backend: MariaDB, PostgreSQL und Nebenläufigkeit

**B · P1 DB-Sessions; P0 PG-Schemaboot · EXISTING NAF PLUGIN — naf/session**

- **Problem/Nachweis:** Session-Migration auf PostgreSQL scheitert an Backticks. Session-Write auf MariaDB 11.4.13 liefert false; Log meldet SQL-Fehler bei `AS new ON DUPLICATE KEY UPDATE`.
- **Ursache:** MySQL-8-spezifischer Alias wird auch für MariaDB benutzt. Migration bleibt MySQL-orientiert. Der Handler implementiert keine erkennbare Session-Locking-Semantik für read/modify/write. Letzteres ist ein Codebefund und noch kein hier ausgeführter Race-Nachweis. [DB-Handler](https://github.com/nafphp/session/blob/9ace904401e74a5e3b19350666a86f1bf780249b/src/Storage/DatabaseSessionHandler.php#L167), [Migration](https://github.com/nafphp/session/blob/9ace904401e74a5e3b19350666a86f1bf780249b/src/Migrations/SessionTableMigration.php).
- **Generalisierbarkeit/API:** korrekte Upserts/Migrationen für die deklarierten Datenbanken; vorhandenen Session-Vertrag erhalten, konkurrierende Writes/Rotation ausdrücklich regeln. `session:storage=database` darf bei fehlender Unterstützung nicht unbemerkt auf einen anderen Speicher degradieren.
- **Migration/BC:** Tabellen-/Spalten-Konfiguration erhalten. Neues fail-fast-Verhalten bei explizit gewähltem DB-Storage dokumentieren. Die Migration wird schon bei installiertem database-Plugin registriert, selbst wenn File-Sessions verwendet werden: deshalb PostgreSQL-Fix nicht durch bloße Storage-Auswahl umgehen.
- **Tests:** echte Session speichern/laden/rotieren/löschen, Ablauf, zwei parallele Requests, OIDC-State-Consume, MariaDB und PG, konfigurierter Tabellenname. Prototyp darf bewusst persistente File-Sessions verwenden.

## F05 — ORM verwendet zwei verschiedene Tabellennamen-Verträge

**B · P0 bei App-Entities · EXISTING NAF PLUGIN — naf/orm**

- **Problem/Nachweis:** Entity `getTableName()` liefert `analysis_records`, ihr Repository verwendet `analysisrecords`. Ausgeführt; Lesen und Schreiben können somit verschiedene Tabellen treffen.
- **Ursache:** Repository schaut nach `$entity->table` bzw. eigener Pluralisierung, EntityManager verwendet `getTableName()`. [Repository](https://github.com/nafphp/orm/blob/b2f01d7a9cf7457cf298442fdf491b6c0abdc7fe/src/Repository/AbstractRepository.php#L57).
- **Generalisierbarkeit/API:** bestehenden `EntityInterface::getTableName()` als gemeinsame Quelle nutzen; keine App-Overrides in jedem Repository.
- **Migration/BC:** bestehende Nutzer eines öffentlichen `table`-Felds berücksichtigen; Übergangsregel eindeutig definieren. Defaults bleiben gleich. Singular-/Pivot-Namen ebenfalls prüfen.
- **Tests:** Custom Table, normale Defaults, EntityManager save → Repository read, snake_case-Namen, Custom Pivot, whitelist/Identifier-Schutz auf beiden Datenbanken.

## F06 — ORM kollidiert mit einer bereits offenen PDO-Transaktion

**B · P1, vor komponierten Schreibabläufen · EXISTING NAF PLUGIN — naf/orm**

- **Problem/Nachweis:** PDO `beginTransaction()` gefolgt von EntityManager `begin()` wirft `There is already an active transaction`. Ausgeführt auf MariaDB. Relevant z. B. wenn OAuth-Account-Linking eine PDO-Transaktion öffnet und Provisionierung darin ORM-Saves verwendet.
- **Ursache:** EntityManager zählt nur selbst eröffnete Ebenen. [EntityManager](https://github.com/nafphp/orm/blob/b2f01d7a9cf7457cf298442fdf491b6c0abdc7fe/src/Core/EntityManager.php#L30).
- **Generalisierbarkeit/API:** vorhandene begin/commit/rollback/save-Methoden behalten; äußere Fremdtransaktion erkennen, eigene Savepoints verwenden und fremde Transaktion nie committen/rollbacken. Kein zusätzlicher TransactionManager ist zwingend nötig.
- **Migration/BC:** bisheriger selbstverwalteter Ablauf bleibt; Ownership bei Fehlern dokumentieren. App kann im ersten Slice bewusst alle eigenen Transaktionen über denselben EntityManager führen, darf das aber nicht als Behebung der Plugin-Komposition ausgeben.
- **Tests:** verschachtelte eigene/Fremd-Transaktionen, Exception/Savepoint-Rollback, Entity-ID-Snapshot, Commit-Fehler, OAuth-Provisionierung mit ORM und Queue auf gleichem PDO; MariaDB/PG.

## F07 — Queue verliert Arbeit vor Abschluss; transaktionaler Driver fehlt

**B/C · P1 vor verlässlichen Hintergrundfunktionen · EXISTING NAF PLUGIN — naf/queue**

- **Problem/Nachweis:** FileDriver poppt einen Job und löscht die `.lock`-Datei, bevor `execute()` startet. In der Probe sind zu diesem Zeitpunkt weder pending noch reserved Dateien vorhanden. Ein Crash nach Pop kann daher Arbeit verlieren. SQLiteDriver liest und löscht ohne atomare Claim-Transaktion. Dessen Mehrworker-Race ist ein Codebefund, nicht hier per Lasttest bewiesen.
- **Weitere Grenzen:** zufällige Dateinamen plus lexikografische Sortierung sind keine zugesicherte Einlieferungs-FIFO-Reihenfolge. Wiederholungen schlafen im Worker; unbekannte Jobklassen werden verworfen. Kein transaktionaler Enqueue auf der App-MariaDB/PG-Verbindung. [FileDriver](https://github.com/nafphp/queue/blob/699b80dccad86ee85997bbfb35e76f510a575c25/src/Drivers/FileDriver.php#L83), [Worker](https://github.com/nafphp/queue/blob/699b80dccad86ee85997bbfb35e76f510a575c25/src/Commands/QueueConsumeCommand.php).
- **Generalisierbarkeit/Ziel:** jede zuverlässige Mail-/Import-/Webhook-Pipeline. Keine App-Queue und kein verpflichtendes Redis.
- **API-Entwurf:** optionaler ergänzender reservierbarer Driver-Vertrag mit `reserve`, `ack`, `release`/Retry und Fail/Deadletter; Lease-/Reservation-Token verhindert Ack durch veralteten Worker. PDO-Driver für MariaDB/PG mit atomarem Claim, available_at, attempts und Recovery. `Queue::push()` bleibt; Driver darf eine vom Caller gehaltene Transaktion nicht eigenständig committen.
- **Migration/BC:** bisherigen Basisvertrag nicht unvermittelt brechen. Legacy-Driver weiter unterstützen und ihre schwächere Garantie dokumentieren; zuverlässigen Modus explizit konfigurieren. Payload-Schema versionieren. Wiederverwendung als transaktionale Outbox ohne zweite Infrastruktur.
- **Tests:** zwei Worker, Crash vor/nach Execute, Lease-Ablauf, veraltetes Ack, Enqueue-Rollback, Retry/Deadletter, fehlende Klasse, Parallel-Claims auf beiden DBs, idempotenter Consumer. Exactly-once für externe Effekte nicht versprechen.

## F08 — Scheduler-Workername und State sind nicht betriebsfest

**B · P1 · EXISTING NAF PLUGIN — naf/schedule**

- **Problem/Nachweis:** `spawnQueueWorker()` ruft `queue:worker` auf; registriert ist `queue:consume`. Sourcebefund plus echte Command-Registry geprüft. Suite enthält im Wesentlichen CronParser-Tests, keinen entsprechenden Spawn-Nachweis.
- **Weitere Ursache:** globaler Temp-State, nicht atomare Dateiupdates, lokaler In-Memory-State und „last run“ wird vor Queue-Push gespeichert. Push-Fehler kann die Minute dadurch verbrauchen. Verpasste Zeitfenster werden nicht nachgeholt. [Ticker](https://github.com/nafphp/schedule/blob/86732ec210f981910561abe98689eeaf048b4e73/src/Commands/ScheduleTickerCommand.php#L113), [Scheduler](https://github.com/nafphp/schedule/blob/86732ec210f981910561abe98689eeaf048b4e73/src/Core/Scheduler.php#L35).
- **Generalisierbarkeit/API:** richtigen Command verwenden; State-Pfad konfigurieren, atomar und mit klarer Single-Ticker-Ownership schreiben. State erst nach erfolgreicher Einlieferung fortschreiben bzw. atomar mit dauerhaftem Backend koppeln. Fachliche Catch-up-Regeln bleiben bei Reminder-/Import-Jobs.
- **Migration/BC:** alte State-Datei nicht still zwischen Apps teilen; einmalige Übernahme nur ausdrücklich. Ticker- und Queue-Prozesse separat betreiben bleibt zulässig und sinnvoll.
- **Tests:** echter CLI-Worker-Start, Enqueue-Fehler, Neustart in gleicher Minute, zwei Ticker, appspezifischer State, korrekte Max-Lifetime-/Signalbehandlung. Keine zweite Scheduler-Infrastruktur.

## F09 — Objekt-Callables im EventManager brechen

**B · P1, kleiner Core-Fix · NAF CORE**

- **Problem/Nachweis:** `listen('event', [$listenerObject,'method'])` ist ein PHP-callable, erzeugt beim Dispatch aber einen TypeError in `make()`. Ausgeführt. Die Plugin-Dokumentation zeigt genau diese Form.
- **Ursache:** jedes Array wird als Klassenname+Methode behandelt. [EventManager](https://github.com/nafphp/framework/blob/acabfb56a205b9134c1dc61274e2aa9b5fbf574a/src/Core/EventManager.php#L47).
- **Generalisierbarkeit/API:** vorhandenen callable-Vertrag korrekt erfüllen. Klassenname weiter autowiren, vorhandenes Objekt direkt aufrufen, gültige statische Callables prüfen. Keine neue EventBus-Klasse.
- **Migration/BC:** reine Erweiterung vorher kaputten gültigen Inputs. Listener-Timing, Prioritäten und Rückgabeverhalten bleiben gleich.
- **Tests:** Closure, Klassenlistener mit DI, Instanzlistener mit eigenem Zustand, statische Methode, invalider Callback, Reihenfolge und Exception-Propagation.

## F10 — PSR-7-Streams werden bei Ausgabe vollständig materialisiert

**B · P1 für größere Attachments · NAF CORE**

- **Problem/Codebefund:** `ResponseEmitter` verwendet `echo $response->getBody()`; auch ein als File-Stream gelieferter Body kann dadurch vollständig als String gelesen werden. Download-Doku nutzt zusätzlich `file_get_contents()`. Kein hier durchgeführter Peak-Memory-Test.
- **Ursache/Ziel:** Emission ist die generische Verantwortung des Core, nicht eines Ticketcontrollers. [Emitter](https://github.com/nafphp/framework/blob/acabfb56a205b9134c1dc61274e2aa9b5fbf574a/src/Core/ResponseEmitter.php#L28).
- **API:** vorhandenen Emitter intern chunkweise über StreamInterface lesen lassen, Seekbarkeit beachten, HEAD/204/304-Bodyregeln prüfen. Keine Sonderroute mit echo/exit in der App.
- **Migration/BC:** Event-/Header- und Exit-Semantik erhalten; teilweise bereits gelesene/non-seekable Bodies klar behandeln. Range-Downloads nicht vorsorglich versprechen.
- **Tests:** echte Response-Ausgabe großer Datei mit begrenztem Peak Memory, non-seekable Stream, leere Bodies, Reader-Exception vor/nach Headers, Content-Length und Hashvergleich.

## F11 — Formregeln sind zu wenig typbewusst

**B · P0 für API-Eingaben · EXISTING NAF PLUGIN — naf/form**

- **Problem/Codebefund:** eingebaut sind required/email/min/max/boolean. `required` nutzt `empty()`, also gilt auch `0` als leer; min/max casten potenziell Arrays zu Strings. Relation-IDs, Arrays, Enums und Due Dates brauchen mehr als Strings.
- **Generalisierbarkeit/API:** kleine wiederverwendbare Typ-/Formatregeln in der vorhandenen Validator-Registry: string, integer, array, in, date, list-of-scalars nach tatsächlichem Bedarf. Keine vollständige neue Validation-DSL. [Bootstrap](https://github.com/nafphp/form/blob/598bee7b0f46474fc644acfe293451ca518a2538/bootstrap.php).
- **Migration/BC:** existierende required-Semantik nicht ohne Migrationshinweis ändern; neue klar bezeichnete Regeln erlauben schrittweisen Umstieg. Project/Membership/Policy-Prüfungen bleiben `APP`.
- **Tests:** Arrays statt Strings, 0/"0"/false/null, Integeroverflow, unbekannte Enumwerte, ungültige Daten/Zeitzonen, leere Listen, Feld-Allowlist und Fehlermeldungen ohne Warnungen.

## F12 — LDAP-Verifikation als optionaler Provider

**C · P1 bei LDAP-Aktivierung · NEW NAF PLUGIN — Vorschlag naf/auth-ldap**

- **Problem:** kein LDAP-Provider in den relevanten öffentlichen NAF-Packages; `ProviderInterface` ist als Erweiterungsnaht vorhanden. [Provider-Vertrag](https://github.com/nafphp/auth/blob/3b721331c734641deb80287d8c075bc86a04a4a6/src/Provider/ProviderInterface.php).
- **Generalisierbarkeit/Ziel:** LDAP ist kein Ticket-Konzept. Separates optionales Plugin hält ext-ldap aus allen Nicht-LDAP-Apps heraus; keine parallele Auth-Architektur.
- **API-Entwurf:** `LdapProvider implements ProviderInterface`, explizite Directory-Konfiguration, gemappte lokale Identity über Callback; Connection-Erzeugung austauschbar für Vertragstests. Bestehende PasswordCredentials können Login/Passwort transportieren. Lokale User-Provisionierung/Membership bleibt in der App.
- **Migration/BC:** rein optional; aktuelle Auth-Provider bleiben unverändert. Keine automatischen Rechte aus beliebigen Directory-Attributen.
- **Tests:** TLS-Zertifikat, Filter-/DN-Escaping, leeres Passwort, Mehrfachtreffer, ungültiger Bind, Serverausfall, Timeout, stabiles Subject, deaktivierter lokaler User, begrenzte Gruppenabbildung mit Testverzeichnis.

## F13 — Privater Storage fehlt als generisches Package

**C · P1 vor Attachments · NEW NAF PLUGIN — Vorschlag naf/storage**

- **Problem:** PSR-7-Upload-Objekte sind vorhanden, aber kein passender generischer Upload-/Private-Storage-Ablauf. CMS-MediaStorage enthält relevante Vorarbeit, ist jedoch an CMS-Modelle, `EntityStorage` und Public-URLs gekoppelt.
- **Generalisierbarkeit/Ziel:** wiederkehrend bei Ticket-App und CMS. Reine sichere Dateioperationen gezielt extrahieren; nicht das ganze CMS einbinden.
- **API-Entwurf:** konkreter `LocalStorage` mit kontrolliertem Storage-Key, `put`/Upload-Verarbeitung, `openReadStream`, `delete`; wiederverwendbare Upload-Policy für Größe/MIME. Zunächst ein Backend. PSR-7/Streams als Grenzen. Ticket-FKs, Actor, Quotas und Download-Policy `APP`.
- **Migration/BC:** neue optionale Dependency; bestehendes CMS später gesondert migrieren, ohne Public-Media-Verhalten unbemerkt zu verändern.
- **Tests:** Pfadtraversal, symlink escape, MIME-/Dateigrößenabweichung, Uploadfehler, sichere Originalnamen/Content-Disposition, schreibgeschützte Pfade, atomare lokale Finalisierung. SQL/File-Recovery und Project Isolation zusätzlich in App-Tests.

## F14 — Wiederverwendbares Throttling fehlt

**C · P1 vor produktivem Login · NEW NAF PLUGIN — Vorschlag naf/rate-limit**

- **Problem:** Auth lässt Rate Limiting bewusst der Anwendung; eine generische persistente Zähleroperation fehlt. Rate-Limits sind auch für Uploads und spätere API-/Webhook-Endpunkte sinnvoll, nicht nur für Ticket-Logins.
- **Generalisierbarkeit/Abgrenzung:** kleines optionales Plugin statt DB-/Redis-Pflicht in `naf/auth` oder Core. Schwellen, Lockout-UX und Account-Lifecycle bleiben `APP`. Kein MFA-/Risk-Engine-Projekt.
- **API-Entwurf:** eine atomare `consume(key, limit, window)`-Operation mit allowed/remaining/retryAfter. Anfangs genau ein PDO-Backend, SQLite nur Tests nach Bedarf; Account und IP getrennt begrenzen. Algorithmus zunächst dokumentiertes Fixed Window, solange Anforderungen nichts anderes rechtfertigen.
- **Migration/BC:** optional, eigener Migrationspfad; kein heutiger Auth-Methodenvertrag muss verändert werden. Persistente Zähler besitzen TTL/Cleanup und speichern keine Passwörter.
- **Tests:** Parallelität, Fensterwechsel, Race am Limit, nicht umgehbare Schlüsselbildung, aktive/inaktive Accounts mit gleicher Antwort, 429/Retry-After, DB-Ausfallverhalten. IP-Adresse nur aus bestätigter Proxy-Kette ableiten.

## F15 — Mail-Konfiguration: bestehende RC weiterverwenden

**B · P1 bei Mail-Aktivierung · EXISTING NAF PLUGIN — naf/mail**

- **Problem:** veröffentlichte `0.2.1` bindet `Mailer(new MailTransport())`; vorhandener Transportvertrag erlaubt Austausch über DI. Der lokale RC ergänzt `mail:transport` und `DummyTransport`. Das ist keine Lücke, die Nafinity nochmals implementieren sollte.
- **Quelle:** [veröffentlichtes Bootstrap](https://github.com/nafphp/mail/blob/bee947ca99d92705e60391d4f62f5da75bc20b2f/bootstrap.php); lokaler RC `7f6ad246d07517e3ca4e2ff509e4780531729193` wurde separat gelesen und getestet.
- **Kleinste Lösung/API:** bestehende RC nach dem Maintainer-Workflow abschließen, dann korrekte Mindestversion setzen. Falls SMTP benötigt wird, vorhandenen Transportvertrag mit einer bewährten Mailbibliothek adaptieren; keine selbst geschriebene SMTP-State-Machine. Dummy in-memory eignet sich für Tests, eine persistente lokale Outbox für sichtbare Dev-Mails ist davon zu unterscheiden.
- **Migration/BC:** Default-Verhalten und Constructor-Injection erhalten; RC nicht als stable alias verschleiern. Container muss für produktiven Versand explizit konfiguriert werden; PHP `mail()` allein beweist keine Mail-Infrastruktur.
- **Tests:** konfigurierte und explizite Transporte, falsches Binding, Header-/Attachment-Encoding, keine reale Zustellung in Tests, Queue/Delivery-Idempotenz in App.

## F16 — Unbekannte Environment-Werte öffnen Debug-Fehleransichten

**B · P1 vor Deployment · NAF CORE**

- **Problem/Codebefund:** Nur `prod` und `test` werden sicher gerendert; `production`, `staging` oder Tippfehler sind detailliert. Fehlender Environment-Wert ist bereits sanitisiert. [ErrorHandler](https://github.com/nafphp/framework/blob/acabfb56a205b9134c1dc61274e2aa9b5fbf574a/src/Core/ErrorHandler.php).
- **Generalisierbarkeit/API:** Debug-Ansicht nur bei ausdrücklich konfiguriertem `dev`; unbekannte Werte fail closed oder klarer Boot-Konfigurationsfehler ohne Details. Keine neue Umgebungs-DSL.
- **Migration/BC:** Nutzer eigener Debug-Environment-Namen müssen explizit migrieren; sichere Einschränkung dokumentieren. App setzt bis dahin exakt `APP_ENV=prod`.
- **Tests:** dev/test/prod/production/staging/unbekannt/fehlend, Bootfehler und Fatalpfad, JSON-Listener und Headerausgabe.

## F17 — PDO-Binding gehört zum Database-Plugin

**B · P0 bei Auth/DB ohne OAuth · EXISTING NAF PLUGIN — naf/database**

- **Problem/Codebefund:** Database/ORM sind installiert, Auth-Provider verlangen `PDO::class`, aber database bindet nur `Database::class`. OAuth-Client und -Server enthalten bereits jeweils eine eigene PDO-Brücke. Das kann beim Test mit allen Plugins verdecken, dass ein kleinerer App-Stack noch manuelles Wiring braucht.
- **Generalisierbarkeit/API:** lazy PDO-Binding im vorhandenen Database-Plugin, vorhandenes Binding respektieren, fehlende Config klar melden. Kein neues Helper-/Connection-Interface.
- **Migration/BC:** Nutzerbinding hat Vorrang; redundante OAuth-Brücken können später entfernt oder kompatibel behalten werden. [Database-Bootstrap](https://github.com/nafphp/database/blob/f2027ff9535bffda95e4dc7b25861d24144c2118/bootstrap.php).
- **Tests:** auth+database, auth+orm ohne OAuth, alle Plugins, eigenes PDO, fehlende Config, Bootreihenfolge. Dieselbe PDO-Instanz muss Repositories, Auth und transaktionale Queue erreichen.

## F18 — Container verliert Services, deren Factory `null` liefert

**B · P0 · NAF CORE + EXISTING NAF PLUGIN — naf/database**

- **Problem/Nachweis:** `database/bootstrap.php` registriert `Database::class` mit einer Factory, die bei fehlender `database`-Konfiguration `null` zurückgibt. `Container::get()` schreibt dieses `null` in die Service-Map zurück. Da `get()` und `has()` beide `isset()` verwenden und `isset()` auf `null` false liefert, verschwindet der Service nach dem ersten Zugriff. Ausgeführt gegen die realen Klassen, sowohl gegen `Container` allein als auch über den tatsächlich verwendeten `AutoResolvingContainer`; beide Ebenen verhalten sich identisch:

  ```text
  has() vor get():   true
  1. get() liefert:  NULL
  has() nach get():  false
  2. get() wirft:    Naf\Exceptions\ServiceNotFoundException: Service 'Database' not found.
  ```

- **Ursache:** `isset()` statt `array_key_exists()` für die Präsenzprüfung einer aufgelösten Factory; zusätzlich eine Factory, die eine Fehlkonfiguration als `null` statt als Fehler ausdrückt. Der Decorator cacht das `null` ebenfalls und prüft es ebenfalls per `isset()`. [Container](https://github.com/nafphp/framework/blob/acabfb56a205b9134c1dc61274e2aa9b5fbf574a/src/Core/Container.php#L27), [Decorator](https://github.com/nafphp/framework/blob/acabfb56a205b9134c1dc61274e2aa9b5fbf574a/src/Decorators/AutoResolvingContainer.php#L52), [Database-Bootstrap](https://github.com/nafphp/database/blob/f2027ff9535bffda95e4dc7b25861d24144c2118/bootstrap.php#L15).
- **Wirkung:** eine einzige Fehlkonfiguration erzeugt zwei verschiedene, beide irreführende Fehlerbilder. Der erste Zugriff endet in `Call to a member function getConnection() on null`, jeder weitere behauptet, der Service sei nicht registriert. Keines der beiden verweist auf die eigentliche Ursache. Betroffen ist jede Factory, die legitim `null` liefern darf, nicht nur `Database`.
- **Generalisierbarkeit/Ziel:** trifft den P0-Kernpfad. Ein Compose-Setup mit nicht durchgereichter Environment-Variable ist der wahrscheinlichste Fehlerfall der ersten Inbetriebnahme; die [Analyse](Nafinity-Analyse.md) weist in Abschnitt 3 selbst darauf hin, dass `ENV:KEY` aus `$_ENV` liest, während Container-Environment je nach PHP-Konfiguration nur über `getenv()` ankommt. Fehlende einzelne ENV-Werte erzeugen jedoch null-Feldwerte innerhalb der weiterhin vorhandenen Sektion. Fehlende Sektion und unvollständige Verbindungsparameter werden getrennt getestet.
- **Kleinste Änderung/API:** zwei unabhängige Teile. `NAF CORE`: Präsenz aufgelöster Services über `array_key_exists()` bestimmen, in `Container::get()`, `Container::has()` und der Instanz-Cache-Prüfung des Decorators, damit ein legitim gespeichertes `null` den Service nicht entfernt. `naf/database`: den dokumentierten nullable Vertrag von `database(): ?PDO` erhalten. Das neue verpflichtende `PDO::class`-Binding meldet eine fehlende Datenbank verständlich; Nafinity validiert zusätzlich ihre Pflichtparameter. Eine optionale Datenbank darf weiterhin unkonfiguriert bleiben. Keine neue Container-API, kein Lazy-Service-Konzept.
- **Migration/BC:** der Database-Teil ändert bei korrekter Konfiguration nichts und wandelt einen bisher unklaren Fehlerverlauf in einen klaren Bootfehler. Der Core-Teil ist eine reine Korrektur vorher unbrauchbaren Verhaltens; Aufrufer, die sich auf das Verschwinden eines `null`-Service verlassen, sind nicht plausibel. `reset()`-Semantik bleibt unverändert.
- **Reihenfolge:** Database-Teil gemeinsam mit F17 im selben Durchgang. Der Core-Teil wird getrennt bewertet und released, weil er jede Factory betrifft.
- **Tests:** fehlende Config beim ersten und beim zweiten Zugriff, `has()` vor und nach der Auflösung, Factory mit legitimem `null`-Ergebnis, Auflösung über den Decorator und über den nackten Container, `reset()` nach fehlgeschlagener Auflösung, autowired Abhängigkeit auf einen so konfigurierten Service.
- **Herkunft:** ergänzt aus dem [Review der Analyse und Planung](Review-Analyse-und-Plan.md), Abschnitt 5.

## Produktlücken, die bewusst in der App bleiben

| C-Funktion | Ziel | Begründung / Tests |
|---|---|---|
| Projects, Membership, Rollenmatrix, Resource Policies | `APP` | Domain-/Delegationsregeln; negative Cross-Project-Tests und Last-Owner-Race |
| Board-Struktur, WIP-Konfiguration, Kartenposition | `APP` | Kanban-Semantik und Sperrgranularität; Sparse-Ordering-/Rebalance-/Conflict-Tests |
| Activity-Historie | `APP` | Projekt-/Ticketbezug und Darstellungssemantik; gleiche Transaktion und Payload-Minimierung |
| Notifications und Preferences | `APP` | Empfänger, Kanäle und Text sind Business-Regeln; deduplizierte Zustellung und entzogener Zugriff |
| Filter und Volltext | `APP` | Projektgebundene Ticketabfragen und Suchsemantik; MariaDB/PG-Vertragstests |
| LDAP-/OIDC-Usermapping und Provisionierung | `APP` | Lokale Kontenhoheit; verified External Identity allein darf keine Projektrolle erzeugen |
| Ticket-/Kommentar-/Attachment-Services | `APP` | normale Anwendung, keine Framework-Defizite; Service-Verträge und Datenintegrität |
| UI/Design/Tastatur | `APP` | Produktentscheidung; Browser-/Accessibility-Tests |
| E-Mail → Ticket, spätere Automationen | `APP` plus später neu zu prüfender generischer Inbound-Adapter | außerhalb MVP; keine vorsorgliche große Integration |

## Fortführung bei Implementierung

Für jede Änderung ergänzen: betroffenes Release, aktueller Branch/Commit, Status, Regressionstest, Dokumentationsänderung, BC-Hinweis, veröffentlichte Mindestversion und App-Abnahmetest. Versionsnummern erst bei Arbeitsbeginn gegen dann aktuelle Tags/Branches bestimmen. Bestehende Arbeitsbranches respektieren; keine Veröffentlichung ist durch diese reine Analyse ausgeführt worden.

## F19 — CLI-Auflösung eines Composer-Pfads mit ../

**B · P0 · EXISTING NAF PLUGIN — naf/cli · Größe S · Planphase P0**

Bei der echten Container-Probe übergibt Composer `_composer_autoload_path` als `vendor/bin/../autoload.php`. Ein direktes dirname(..., 2) liefert damit vendor/bin statt dem App-Verzeichnis. Der CLI-Einstieg normalisiert den vorhandenen Autoloader jetzt mit realpath() vor der Bootstrap-Ableitung; der bestehende Subprozess-Test verwendet den echten Composer-Pfad. Keine App-Umgehung. RC v0.2.2-rc, Veröffentlichung noch ausstehend.

## F20 — Nullable View-Ausgabe unter PHP 8.5

`Naf\View\s()` akzeptiert `null`, aber der Core-Guard reichte den Wert direkt an `htmlspecialchars` weiter. Ein Ticket ohne Due Date löste deshalb im echten Browser einen 500 aus. Der Core normalisiert leere Werte zu einem leeren String und ersetzt ungültige UTF-8-Sequenzen. Regression prüft den echten registrierten Guard, einschließlich Arraywerten.
