# Nafinity — Plan für Prototyp und erstes produktives MVP

Stand: 14. September 2026. **Historischer, umgesetzter Plan. Aktueller Stand und offene Release-Gates: [Implementation.md](../Implementation.md).** Projektort nach der Analyse: `/Users/flo/PhpStormProjects/nafinity`. Name ist vorläufig.

Die Entscheidungsvorlage besteht aus [Architektur/Bestandsaufnahme](Nafinity-Analyse.md), Framework Findings und [Prüfevidenz](../Analyse-Evidenz.json).

## Gewählte Richtung

- **APP:** SSR mit NAF-Views, CSS-Design-Tokens und kleinen ES-Modulen; eigene verlinkbare Ticketdetails mit optionalem Drawer.
- **APP:** MariaDB als primäre Prototyp-DB; PostgreSQL durch echte Kompatibilitätstests absichern. Dies ist der vorgeschlagene Default, keine bereits erhaltene Nutzerbestätigung.
- **APP:** lokale Anmeldung zuerst; LDAP und OIDC im folgenden MVP-Ausbau, über vorhandene NAF-Auth-Verträge.
- **APP:** ein Board je Projekt im MVP, frei konfigurierbare Columns/Swimlanes und mehrere Projekte. Schema/Services bleiben für spätere weitere Boards offen.
- **APP:** Sparse BIGINT-Positionen, kurze projektbezogene Schreibsperre, Ticket-Version und Board-Revision, Konflikte als 409.
- **EXISTING NAF PLUGIN:** generische Lücken dort korrigieren, wo sie entstehen; bestehende Mail-RC wiederverwenden.
- **NEW NAF PLUGIN:** kleiner privater Local Storage, optionaler LDAP-Provider, optionaler generischer Limiter. Keine vorsorglichen weiteren Backends.
- **NAF CORE:** vorhandenen Event-/HTTP-Pfad an den nachgewiesenen Vertragsgrenzen verbessern, keine neue Architektur darüberlegen.

## Was der erste Prototyp beweisen muss

**Zwei Browser-Sessions, zwei Projekte, ein vollständiger Arbeitsablauf:**

1. Alice und Bob melden sich lokal an.
2. Alice sieht Project A, Bob Project B. Ein Viewer für A kann lesen, aber keine Karte bewegen.
3. Alice erstellt in A ein Ticket und öffnet es über seine eigene URL.
4. Alice verschiebt es per Maus oder Tastatur; nach Reload bleibt die Position erhalten.
5. Eine zweite Session mit veraltetem Stand verursacht einen verständlichen Konflikt statt verlorener Daten.
6. Jede akzeptierte Änderung erzeugt den passenden Activity-Eintrag; ein fehlgeschlagener Move erzeugt keinen.
7. Manipulierte IDs für Column, Swimlane, Ticket oder Benutzer aus B werden abgelehnt. Das gilt auch für direkte JSON-Aufrufe.
8. Light/Dark und eine brauchbare mobile Ansicht sind vorhanden.
9. Eine lokale NAF-Sourceänderung ist nach dem nächsten HTTP-Request sichtbar; die App nutzt nachweislich die gemounteten Quellen. Langlebige Worker/Ticker werden nach Codeänderungen neu gestartet.

Dieser Prototyp enthält echte Daten und Rechte, aber noch keine vollständige produktive Funktionsabdeckung. Das folgende MVP erweitert ihn gezielt.

## P0 — Reproduzierbare Umgebung und benötigte Package-Fixes

**Ergebnis:** eine leere NAF-App startet mit lokalen Sources und kann eine echte Datenbank sauber initialisieren.

| Arbeit | Zuordnung | Abnahme |
|---|---|---|
| Projektverzeichnis, Docker-/App-Struktur, Dev-/Dist-Dependency-Modus | `APP` | kein Rückgriff auf alte nixphp-Paketnamen; saubere App- und Source-Pfade |
| ASPX-Prinzip mit unterstützter Alpine-/PHP-Basis, Dev-/Runtime-Unterschied | `APP`; wiederverwendbarer Anteil später ASPX-Template | Image startet nativ auf ARM64; reale Extensions geprüft; Größe nach Build messen |
| App-Mount plus echter Packages-Mount, Composer im Container | `APP` | alle Vendor-Links auf Host/Container auflösbar; Reflection zeigt lokale Package-Quelle |
| Nginx auf ausschließlich public, App/Worker/Ticker gleiche Compose-Umgebung | `APP` | erreichbarer Port ohne Konflikt mit Studio; korrekte HTTPS-/Callback-URL |
| PostgreSQL-DSN und Migrationstracker/-reihenfolge | `EXISTING NAF PLUGIN` F02/F03 | leere MariaDB und PG: Up, wiederholtes Up, kontrolliertes Down, Fehlerstatus |
| Session-Migration auf PG; MariaDB-Session-Write bei DB-Storage | `EXISTING NAF PLUGIN` F04 | Plugin-Schema funktioniert; bewusste File-/DB-Storage-Auswahl |
| Einheitlicher ORM-Tabellenvertrag, PDO-Binding und sprechender Config-Fehler | `EXISTING NAF PLUGIN` F05/F17/F18 (Database-Teil) | eigenes snake_case-Model schreiben/lesen; Auth ohne OAuth im Stack; optional unkonfigurierter database()-Helper bleibt nullable; erforderliches PDO-Binding und Nafinity-Pflichtkonfiguration melden Fehler verständlich |
| CSRF und benötigte Typregeln | `EXISTING NAF PLUGIN` F01/F11 | PATCH/POST/JSON/Form/Mehrfach-Tabs und ungültige Payloadtypen geprüft |
| Sicherer Debug-Default, Objekt-Callable und Container-Präsenzprüfung | `NAF CORE` F16/F09/F18 (Core-Teil) | reproduzierte Gegenbeispiele grün; normale Events/Fehler bleiben kompatibel; Service mit `null`-Factory bleibt über mehrere Zugriffe auffindbar |

Framework-Fixes bekommen jeweils ihre eigenen kleinen Regressionstests und folgen dem vorhandenen NAF-Release-/RC-Workflow. Die App bindet sie während der Entwicklung lokal ein. Ein Distributions-Lauf darf erst die tatsächlich veröffentlichten Mindestversionen voraussetzen. Unabhängige Fixes nicht in einen großen anonymen Framework-Patch bündeln.

**Nicht Teil von P0:** vorsorgliches SMTP, kompletter Queue-Umbau, LDAP-Server, Ticket-UI, allgemeiner Schema-Builder. P0 endet, sobald der sichere Kernpfad für P1 verfügbar ist.

## P1 — Vertikaler, klickbarer Prototyp

**Ergebnis:** der oben beschriebene vollständige Ablauf funktioniert.

1. **APP — Schema/Fixtures:** User, Project, ProjectMember, Board, Column, Swimlane, Ticket, Activity; zwei klar getrennte Projekte und Rollen. Migrationsdateien statt Setup-SQL im Controller.
2. **APP — Anmeldung und Isolation:** eigener NAF-User, lokaler Provider, Sessionrotation, Login/Logout, Projektliste und projektgebundene Policies. Login-Fehler einheitlich. Öffentliche Selbstregistrierung bleibt aus.
3. **APP — TicketService:** Create, Edit, Move, Close/Reopen. Titel/Beschreibung, Creator, Priority, Color, Due Date, Status und Zeitstempel. Explizite Transaktionsgrenze, keine Business-Regeln im Controller.
4. **APP — Board:** einfache Default-Columns/Swimlane, persistente Sparse-Position, Versionen, Konfliktanzeige. Erste Tastaturaktion „Verschieben nach…“ wird gleichzeitig mit DnD geliefert.
5. **APP — Events/Activity:** vorhandener EventManager, transaktionaler Activity-Listener, lesbare Historie. Für den Prototyp keine externen Nebenwirkungen im Request.
6. **APP — UX:** Design-Tokens, Light/Dark, Project Switcher, Board, Ticketdetail/Drawer, leere Zustände, Feldfehler, mobile Grundansicht. Kleine GET-basierte Filter für Column/Status und eine ausdrücklich als Teilstringsuche bezeichnete erste Suche sind zulässig.
7. **APP — Abnahme:** zwei Browser, A/B-Angriffe, zeitgleiche Moves, Reload, Tab-CSRF, Rollback/Activity, passende Integrationstests in MariaDB/PG.

**Prototyp-Done:** keine bekannten P0-Fehler im verwendeten Pfad, kein App-Workaround für einen bekannten generischen Defekt, keine statischen Fake-Karten. Source-Modus und definierter Distributionsmodus sind unterscheidbar. Die PostgreSQL-Unterstützung wird nicht als fertig bezeichnet, solange deren echte Tests nicht bestehen.

## P2 — Produktumfang des MVP vervollständigen

**APP:**

- Mehrere Projekte mit Name/Beschreibung, optional Farbe/Icon, Member-Verwaltung mit rollenabhängiger Delegation und Archivierung.
- Frei konfigurierbare Columns und Swimlanes samt Reihenfolge, Default-Lane-Schutz und atomarem Umgang mit vorhandenen Tickets beim Entfernen einer Struktur. WIP-Feld vorbereiten; noch keine komplexe Kapazitätssteuerung.
- Labels, mehrere Assignees und alle Pflichtfelder; Zuordnungstypen bleiben explizit projektgebunden.
- Kommentare mit Autor, Edit-/Delete-Policy, Zeitstempeln und Activity.
- Filter für Assignee, Label, Column, Swimlane, Priority, Status und **echte Volltextsuche** auf Titel/Beschreibung. Validierter `TicketFilter` als gemeinsame Grundlage späterer Saved Filters.
- UserPreference/ProjectPreference, Theme/Locale/Zeitzone und einfache Notification-Preferences.
- Vollständige negative Isolationstests für sämtliche neuen Read-/Write-Endpunkte, inklusive Listen/Counts.

**EXISTING NAF PLUGIN:** F06 vor zusammengesetzten ORM-/PDO-Abläufen korrigieren; keine stillen Wechsel des Transaktionseigentümers.

**Done:** alle Produkt-MVP-Grundfunktionen außer den nachfolgenden separat abgesicherten Integrationen sind benutzbar; Board-Leistung auf einer definierten Datenmenge gemessen.

## P3 — Private Attachments und robuste Hintergrundarbeit

1. **NEW NAF PLUGIN — Storage (F13):** kleinstes lokales privates Backend und wiederverwendbare Uploadprüfung. CMS-Erfahrungen übernehmen, CMS-Abhängigkeit vermeiden.
2. **NAF CORE — Streaming (F10):** tatsächliche begrenzte Speichernutzung beim Download verifizieren.
3. **APP — AttachmentService:** private Staging-/Ready-/Delete-Zustände, Projekt-/Ticket-Policy, Dateiquota, sichere Downloads, Crash-/Cleanup-Fälle.
4. **EXISTING NAF PLUGIN — Queue (F07):** PDO-Driver mit Reserve/Ack/Lease, Recovery, Retry/Deadletter und transaktionalem Enqueue. Ein Queue-Eintrag in derselben Transaktion genügt als Outbox.
5. **EXISTING NAF PLUGIN — Scheduler (F08):** realen Command korrigieren, State und Enqueue-Fehler absichern. Compose startet einen Ticker und einen Worker getrennt.
6. **APP — Notifications:** In-App-Einträge, Empfänger/Preferences, Zugriff beim Anzeigen/Verarbeiten erneut prüfen, Deduplication/Delivery-Ledger für externe Kanäle.
7. **EXISTING NAF PLUGIN + APP — Mail (F15):** vorhandene RC auf korrektem Stand weiterführen, Tests ohne echte Zustellung; produktiver Transport ausdrücklich konfigurieren. Mail-Benachrichtigung erst aktivieren, wenn Queue und Zustellpfad die Fehlerfälle bestehen.

**Done:** Uploads sind nie öffentlich, ein Worker-Neustart verliert keine reservierte Arbeit und fehlgeschlagene Zustellungen sind erkennbar/wiederholbar. Keine Zusage von Exactly-once-SMTP.

## P4 — Externe Anmeldung und produktive Abnahme

- **NEW NAF PLUGIN — optionales `naf/auth-ldap` (F12)** mit bestehenden Auth-Contracts, TLS-/Injection-/Timeout-Vertragstests.
- **EXISTING NAF PLUGIN + APP — OIDC:** vorhandener OAuth-Client, konkreter Test-Issuer, sichere Account-Verknüpfung und Provisionierungsregel; kein eigener Authorization Server für diesen Login.
- **NEW NAF PLUGIN + APP — Rate Limiting (F14):** generischer atomarer Counter, appbezogene Account-/IP-Schlüssel und Grenzen; produktiver Login nicht ungeschützt aktivieren.
- **APP — Betrieb:** Release-Image ohne Source-Mounts, Versions-/Schema-Readiness, Backup und Wiederherstellungsprobe für DB plus private Attachments, logische Konfiguration, Worker/Ticker-Health und begrenzte Logs.
- **APP + Package-Suites — Abnahme:** korrekte Runtime-Matrix, frische Installation aus veröffentlichten Dependencies, Browser-/DB-/Security-Szenarien, Source-Manifest und Distribution getrennt verifiziert.

Für die konkrete LDAP-/OIDC-Integration werden später Directory/Issuer, Client-Konfiguration, vertrauenswürdige CA, erlaubte Konten und Provisionierungsentscheidung benötigt. Die übrige Arbeit hängt nicht von diesen Angaben ab. Falls der erste Prototyp schon eine dieser Anmeldungen zwingend benötigt, wird genau dieser Adapterblock nach P1 vorgezogen.

## Expliziter produktiver MVP-Scope

Projekte, Membership und Projektrollen; ein konfigurierbares Kanban-Board je Projekt; frei sortierbare Columns/Swimlanes; robuste Moves; sämtliche genannten Ticketfelder einschließlich Labels, Mehrfach-Assignees und Due Date; Kommentare; geschützte Attachments; Activity; vollständige geforderte Filter einschließlich Volltext; Light/Dark, Responsive und Tastaturbedienung; lokale Anmeldung sowie optionale LDAP-/OIDC-Konfiguration; In-App-Notifications/Preferences und abgesicherte Erweiterungsnaht für Mail; Migrationen, Tests und betriebsfähige Containerkonfiguration.

Ausgehende Mail wird nur mit konfiguriertem Transport aktiviert. Queue/Scheduler werden als Framework-Integration verifiziert, unabhängig davon, wie viele zeitgesteuerte Produktfunktionen die erste Installation aktiviert.

## Nicht Teil des MVP

- E-Mail-Eingang → Ticket, IMAP-Synchronisation, Inbound-MIME-Pipeline.
- Öffentliche REST-Voll-API, GraphQL, eigene mobile App.
- OAuth-/OIDC-Providerbetrieb durch Nafinity; dafür reicht ein späteres separates Lab-Profil.
- MCP-Produkttools und allgemeine Plugin-Verwaltungsoberfläche; Installation allein ist keine fachlich sichere Toolintegration.
- Saved-Filter-Editor, persönliche Dashboards, frei definierbare Felder.
- Mehrere sichtbare Boards je Projekt, projektübergreifende Ticket-Moves.
- WIP-Erzwingung, komplexe Workflows, Automation-Builder, SLA, Zeiterfassung, Gantt, Reporting-Suite.
- Webhooks als fertiges Produktfeature, umfangreiche Drittanbieter-Integrationen.
- WebSockets/SSE, Multi-Region, Microservices, CQRS, Event Sourcing, eigener EventBus.
- Verpflichtendes Redis, Elasticsearch, S3/CDN und theoretische Storage-Backends.
- Rich-Text-Editor mit freiem HTML; erste Beschreibung/Kommentare bleiben sicherer Text.
- MFA, Self-Service-Registrierung, vollständige Identity-Management-Suite.

## Integrationsmatrix für die Framework-Blaupause

| Modus | Installierte Komponenten | Was er beweist |
|---|---|---|
| Minimaler App-Kern | framework, view, form/session, auth, database/orm, cli | Auth/PDO-Wiring funktioniert ohne zufällige OAuth-Brücke |
| Produkt-MVP | zusätzlich i18n, queue/schedule, mail; storage/LDAP/Limiter je implementiertem Feature, oauth-client bei SSO | reale Anwendung mit begrenztem Featureumfang |
| Alle offiziellen relevanten Plugins | Core plus 15 vorhandene Plugins, inkl. oauth-server und mcp | gemeinsame Discovery, Boot, Konfiguration, Commands, Routen und Vertragskonflikte |
| Distribution | frischer Install aus Versionslock, ohne lokale Repositories | App ist tatsächlich installierbar und nicht nur im Entwicklerworkspace funktionsfähig |

Jedes neu installierte Plugin bekommt einen passenden echten Anwendungsfall bzw. einen isolierten Integrationstest. „Gebootet“ bedeutet nicht „jede Funktion bewiesen“. LDAP/OIDC-Verbindungen, SMTP, Worker-Recovery und Browserpfade haben eigene Prüfungen.

## Historischer Stand vor Implementierungsbeginn

**Erledigt:** Organisations-/Release-Abgleich, Code-/Dokumentationsanalyse, 927 erfolgreiche Package-Tests, 2.118 Assertions; zusätzliche echte DB-Proben und nachgewiesene CSRF-/ORM-/Event-/Queue-Grenzen; Host-Boot aller 15 relevanten Plugins; Nachweis fehlerhafter Host-Composer-Symlinks und erfolgreiche Neuerzeugung im Alpine-Container.

**Docker-Gegenprobe erfolgreich:** 15/15 Plugins unter PHP 8.3.15 gebootet, 20 Commands und 14 Routen registriert. Framework-Source liegt im Container unter `/workspace/packages`, derselbe SHA-256 wie auf dem Host. Vendor-Link `../../../packages/framework/` funktioniert in beiden Umgebungen. Composer installierte die zunächst falsch aufgelösten Path-Abhängigkeiten im Container neu. Das verifiziert das Mount-/Link-Prinzip; neues Image, FPM-Live-Reload, Worker-Neustarts und fertiges Compose-Projekt sind noch P0-Aufgaben. Die lokale Mail-RC wurde für diese Fixture ausdrücklich mit einem synthetischen RC-Versionsalias versehen; das ist kein veröffentlichtes Release.

**Noch nicht implementiert:** Nafinity-App, neues Runtime-Image, Compose-Projekt, Package-Fixes, Produktdatenbank und Oberflächen. Die gestarteten Diagnose-Datenbanken einschließlich ihrer temporären Volumes wurden nach den Proben entfernt. Bestehende Projekte und Git-Arbeitsstände bleiben erhalten.

**Nächster Arbeitsschritt bei Beginn der Umsetzung:** P0 am angelegten Projektort ausführen; zuerst Source-/Distribution-Modus und Migrations-/Auth-Grundpfad reproduzierbar machen, dann unmittelbar den vertikalen P1-Prototyp bauen.

## Umsetzungssteuerung nach Review

Bearbeitung durch einen Implementierer mit automatisierten Prüfungen. Größen sind relative technische Schätzungen, keine Terminzusage: S = kleiner lokaler Fix; M = mehrere Verträge/Dateien; L = neuer Ablauf mit Integrationstests; XL = mehrere Lieferblöcke.

| Finding | Größe | Abhängigkeit / Freigabe |
|---|---|---|
| F01 | M | vor HTTP-Mutationen |
| F02 | S | vor PostgreSQL |
| F03 | L | vor reproduzierbarer Schema-Initialisierung |
| F04 | M | PG-Schema früh; DB-Session-Backend bei Aktivierung |
| F05 | S | vor App-Entity-Repositories |
| F06 | M | vor fremd eröffneten Transaktionen; vorsorglich im ORM-Durchgang |
| F07 | XL | vor verlässlicher externer Hintergrundarbeit |
| F08 | M | vor Ticker-Betrieb |
| F09/F16/F18 Core | S je Fix | gemeinsamer Core-Durchgang, getrennte Regressionen |
| F10 | M | vor privaten Downloads |
| F11 | M | vor Mutationen mit diesen Eingabetypen |
| F12 | L | vor LDAP-Aktivierung |
| F13 | L | vor Attachments |
| F14 | M | vor produktivem Login |
| F15 | S für vorhandene RC-Integration | vor konfigurierter Mail-Zustellung |
| F17/F18 Pflicht-PDO | S | vor Auth/PDO-Injection; nullable Helper erhalten |

| Phase | Größe | Reihenfolge und Ergebnis |
|---|---|---|
| P0 | L | ARM64-Erweiterungen → Runtime/Mounts → Database/Migrationen/ORM → HTTP/CSRF → echter App-Boot |
| P1 | L | lokale Auth → Projektisolation → Ticket/Move/Activity → Browserabnahme |
| P2 | L | Konfiguration, Felder, Kommentare, Filter, Volltext; Messung über mehrere Projekte |
| P3 | XL | Storage/Streaming und Queue/Scheduler → Attachments/Notifications |
| P4 | L | optionale Identity-Adapter, Limiter, Betrieb, Distributionsabnahme |

P0 wird nach Runtime-Boot und nach dem ersten echten Schema-/Auth-Durchlauf neu bewertet. Ein grober Planungsrahmen sind zunächst fünf Personentage; nach zwei Personentagen ohne lauffähigen Datenbankpfad oder bei einer neuen, strukturellen Defektklasse wird vor zusätzlicher Ausweitung Umfang und Reihenfolge neu festgelegt. Das Framework bleibt das ausdrücklich gewünschte Entwicklungs- und Showcase-Ziel; die Neubewertung ist kein stiller Framework-Wechsel.

| Package | Benötigte Änderungen | Lokal nutzbar | Veröffentlichung blockiert |
|---|---|---|---|
| framework | F09/F16/F18; später F10 | geprüfter RC über Sources | entsprechende Distributionsabnahme |
| database | F02/F03/F17 | geprüfter RC über Sources | persistenter Dist-Kern |
| session | F04 | geprüfter RC über Sources | PG-Schema/DB-Session-Dist |
| orm | F05/F06 | geprüfter RC über Sources | ORM-Dist-Pfade |
| form | F01/F11 | geprüfter RC über Sources | sichere HTTP-Dist-Pfade |
| queue/schedule | F07/F08 | nach Recovery-Tests | Hintergrundarbeit in Distribution |
| mail | vorhandene RC | konfigurierter Testtransport | Mail-RC-Funktionen in Distribution |
| neue optionale Plugins | Storage, LDAP, Limiter | lokale separate Composer-Pakete | deren aktivierte Dist-Funktionen |

Unabhängige Pakete sind keine künstlich serielle Release-Warteschlange. Nur echte Manifest-/API-Abhängigkeiten bestimmen die Reihenfolge. Code-RCs werden getestet, committed und gepusht; Paket-Merges und Releases erfolgen gemäß NAF-Maintainer-Workflow. Die lokale Umsetzung darf währenddessen fortgesetzt werden. Unveröffentlichte Fixes werden niemals als verfügbare stabile Version ausgegeben.

Der Unique-Index auf Kartenpositionen bleibt bewusst erhalten: zusätzlicher zweistufiger Rebalance-Aufwand schützt die Invariante auch vor Fehlern in der Service-Implementierung. Fremdschlüssel ersetzen dabei keine Autorisierung oder Projektfilter.
