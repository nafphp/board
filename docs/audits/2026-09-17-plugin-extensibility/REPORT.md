**Nafinity – tatsächliche Plugin-Erweiterbarkeit, 17. September 2026**

Dieser Bericht ersetzt die vorherige, falsch zugeordnete CMS-Prüfung vollständig. Die tatsächliche Anwendung liegt unter `/Users/flo/PhpstormProjects/nafinity`, Composer-Projekt `fkde/nafinity`, Branch `main`, Commit `016931e`. Der Checkout war bei der Prüfung sauber. **`naf/cms` ist hier nicht installiert.** Die vorherigen CMS-Texte sind verworfen und gehören nicht zu diesem Audit; ihre Testergebnisse sind keine Nafinity-Nachweise.

**Ergebnis:** NAF bietet eine brauchbare Plugin-Grundlage. Nafinity selbst ist derzeit eine fest zusammengesetzte Anwendung und noch keine durchgängig durch Plugins erweiterbare Plattform. Eigene PHP-Klassen, Routen, NAF-Dienste, Events, Commands und Jobs sind nutzbar. Für linkes Menü, Settings-Karten, zusätzliche Ticket-Metadaten, Ticket-Widgets und viele weitere sichtbare Beiträge fehlen die öffentlichen Registries und ihre Integration in die tatsächlichen Abläufe.

**Settings: vorhanden, fehlend und gewünschte API**

Es gibt drei serverseitige Speicherorte: `user_preferences` für Theme, Sprache, Zeitzone und Benachrichtigungsschalter; `project_preferences` für projektbezogenes Stummschalten je Benutzer; und `projects` für Projektdetails einschließlich Ticketkürzel und Schätzungsskala. Die lokale AI verwendet zusätzlich Browser-`localStorage`, teilweise nach Benutzer, teilweise nach Benutzer und Projekt getrennt. Diese Browserwerte sind serverseitig nicht automatisch verfügbar.

- Eine `app/app/functions.php`, ein `settings()`-Helper und eine Settings-Registry existieren in Nafinity nicht.
- `PreferenceService::save()` verarbeitet eine feste Feldliste. Neue Plugin-Felder bekommen dort keine Persistenz. Die Methode schreibt den kompletten persönlichen Formularsatz; fehlende Felder werden durch Defaults ersetzt. Dafür ist ein eigener `language()`-Pfad vorhanden, der nur die Sprache ändert.
- `BoardQuery::preferences()` liest die persönliche Tabelle direkt. Andere Verbraucher, beispielsweise NotificationService, lesen dieselben Werte über eigenes SQL. Eine einheitliche erweiterbare Werte-API fehlt.
- `views/settings.phtml` erzeugt ein lokales `$cards`-Array; Karten, Views und Icons sind fest vorgegeben. Es gibt keine Registrierung neuer Karten/Felder und keine allgemeine Index-Sortierung.
- Die Projektrollen können nur die Namen aus `ProjectPermissions::LABELS` enthalten. Ein Plugin kann deshalb auch seine Settings-Berechtigung nicht ohne App-Änderung verwenden.
- Eine allgemeine `Naf\Support\Collection` ist bereits vorhanden. Sie bietet `get()`, `add()`, `all()` und `has()`. Eine zweite Collection ist unnötig. Sie ist allerdings ein veränderbarer Array-Behälter, kein autorisierter Settings-Speicher; `get()`/`has()` behandeln `null` wie fehlend.

Quellen: [PreferenceService](/Users/flo/PhpstormProjects/nafinity/app/app/Services/PreferenceService.php:18), [Lesepfad](/Users/flo/PhpstormProjects/nafinity/app/app/Services/BoardQuery.php:291), [Settings-Karten](/Users/flo/PhpstormProjects/nafinity/app/app/views/settings.phtml:42), [AI-Speicher](/Users/flo/PhpstormProjects/nafinity/app/public/assets/ai/store.js:33), [Collection](/Users/flo/PhpstormProjects/nafphp/framework/src/Support/Collection.php:5).

Festlegung im neuen Agentenauftrag: `Nafinity\settings()` in `app/app/functions.php`, explizit über Composer geladen. `get($key, $default = null)`, `all(): array` und `collection(): Naf\Support\Collection` lesen dieselben Definitionen und Werte. Der Standardkontext ist der angemeldete Benutzer. Projektkontexte werden ausdrücklich über `forProject($id)` beziehungsweise `forProjectUser($id)` gewählt. Gleichnamige Werte verschiedener Projekte dürfen nie versehentlich ineinanderfallen. HTTP-Lesezugriff erhält eigene geschützte Endpunkte mit derselben Autorisierung; er darf weder den kompletten NAF-Config-Baum noch vertrauliche Werte ausgeben. AI-Verlauf und persönliche Browser-Prompts bleiben im bisherigen Browser-Speicher.

**Ticket: tatsächliche Erweiterungsgrenzen**

Die gemeinsame Detail-/Drawer-/Anlageansicht ist `app/app/views/ticket.phtml`. Titel und Beschreibung stehen oben links. Danach folgen Verknüpfungen, Anhänge, Verlauf und Kommentare. Rechts liegen Status/Zuständige, Details, Planung/Zeit und Informationen. Die Benutzerbeschreibung passt also genau zu diesem Projekt.

1. **Zusätzliche Metadaten haben keine allgemeine Definition oder Persistenz.** Ticket-Model, SQL-Schema und `TicketService::fields()` führen einzelne bekannte Felder auf. `update()` erhält nicht übermittelte bestehende Felder, kennt aber keinen `metadata`-Speicher für Plugins. Ein neues Feld im HTML allein wird deshalb kein dauerhaftes Ticketfeld. Es muss außerdem in Detaildaten, Create/Update, Versionsprüfung, Autorisierung und Auto-Save integriert werden.
2. **Die linke Spalte hat keine Widget-Registry.** Anhänge, Verknüpfungen und Verlauf werden direkt im Ticket-Template gerendert. Ein Plugin müsste die ganze View kopieren/ersetzen. Mehrere unabhängige Plugins können so nicht sauber zusammenarbeiten.
3. **Die rechte Metadatenleiste ist ebenfalls fest.** Es gibt eine wiederverwendete Feld-Partial `ticket/field.phtml`, aber keine Registry der Felder, Gruppen oder Feldtypen. Diese Partial hat zusätzlich Sonderfälle für Titel/Beschreibung sowie Spalte/Zuständige. Sie kann nicht unverändert als beliebiger Plugin-Feldtyp ausgegeben werden.
4. **Das Upload-Backend ist bereits sinnvoll getrennt.** AttachmentService, AttachmentStorage, FinalizeAttachmentJob und NAF Storage/Queue bestehen. Auszulagern ist zunächst die feste Einbindung: eigenes Standardmodul mit Widget, Datenprovider und Assets. Die bewährten privaten Speicher-/Rechte-/Quota-/Recovery-Pfade werden wiederverwendet. Es braucht keine neue Upload-Implementierung.
5. **Dynamische Aktualisierung gehört zum Vertrag.** `ticket.js` serialisiert Schreibzugriffe, aktualisiert Version und Boardrevision und erhält offene Entwürfe. Es aktualisiert bestimmte DOM-Bereiche; ein beliebiges Plugin-Widget ist darin noch nicht vorgesehen. `upload.js` besitzt einen separaten Refresh-Pfad. Neue Slots müssen auf voller Seite, im Drawer, nach Auto-Save und beim Anlegen funktionieren, einschließlich Mount/Unmount und Entwurferhalt.

Quellen: [Ticketansicht](/Users/flo/PhpstormProjects/nafinity/app/app/views/ticket.phtml:103), [Anhänge](/Users/flo/PhpstormProjects/nafinity/app/app/views/ticket.phtml:140), [Metadatenleiste](/Users/flo/PhpstormProjects/nafinity/app/app/views/ticket.phtml:277), [Ticketfelder](/Users/flo/PhpstormProjects/nafinity/app/app/Services/TicketService.php:330), [Teiländerungen](/Users/flo/PhpstormProjects/nafinity/app/app/Services/TicketService.php:86), [Feld-Partial](/Users/flo/PhpstormProjects/nafinity/app/app/views/ticket/field.phtml:10), [Fragment-Aktualisierung](/Users/flo/PhpstormProjects/nafinity/app/public/assets/ticket.js:295), [Upload-Service](/Users/flo/PhpstormProjects/nafinity/app/app/Services/AttachmentService.php:21).

Festlegung im Auftrag: Titel, Beschreibung und Kommentare bleiben feste, über die Beitrags-API nicht entfernbare/ersetzbare Hauptbereiche. Zwischen Beschreibung und Kommentaren werden Verknüpfungen, Uploads, Verlauf und Plugin-Widgets über dieselbe Liste gerendert. Rechts werden Gruppen und Felder registrierbar. Alle sichtbaren Beitragslisten erhalten `index` aufsteigend und bei Gleichstand eine stabile ID-Sortierung. Plugin-Metadaten werden in einer eigenen projekt- und ticketgebundenen Tabelle gespeichert und innerhalb derselben Tickettransaktion und Versionsprüfung geschrieben.

**Weitere geprüfte Bereiche**

| Bereich | Tatsächlicher Stand | Erforderliche Ergänzung |
|---|---|---|
| Composer-Plugins | NAF entdeckt `type: naf-plugin`; Nafinity nutzt lokale NAF-Quellen | Dokumentierter App-Erweiterungszeitpunkt nach App-Defaults; zwei echte Beispielplugins |
| Eigene Controller | Gewöhnliche Klassen und Constructor Injection funktionieren | Gemeinsamer autorisierter Seitenrenderer für Shell-Daten; keine Vererbung von AppController erforderlich |
| Controller-Ersatz | Dispatcher ruft `make()` auf und ignoriert das Binding der Zielklasse | Explizites Binding beim Dispatch zuerst verwenden; `make()` selbst unverändert lassen |
| Eigene Routen | NAF-Routen sind nutzbar | Plugin-Routenüberschreibungen müssen nach Nafinity-Defaults ausgeführt werden |
| Vorhandene Services | Constructor Injection, aber konkrete finale App-Service-Typen | Öffentliche Contracts für tatsächlichen Ersatz/Dekoration; alle HTTP-/AI-/Job-Verbraucher umstellen |
| Linkes Menü | Direktes HTML in `layout.phtml`; Projektliste aus BoardQuery | Registrierbare Workspace-/Projekt-/Footer-Beiträge, stabile IDs, Sichtbarkeit und Index |
| Rechte/Rollen | Projektprüfung über NAF-Auth-Policy vorhanden; Rechte-Allowlist geschlossen | Permission-Metadaten-Registry, vorhandene Owner-Schutzregeln erhalten |
| Settings | Feste DB-Felder, feste Karten; AI teils browserlokal | Definitionen, Karten, Feldtypen, API, Scope-/Cache-Regeln |
| Ticket | Core-Felder vorhanden, keine Plugin-Metadaten oder Widgets | Metadatenpersistenz, Feld-/Gruppen-/Widgetregistrierung und Auto-Save-Integration |
| Board | Feste Filter, Karten, Menüs und Zusammenfassungen | Beiträge für Aktionen/Badges/Details/Spaltenkopf; registrierte Filter und gemeinsame Count-/Query-Anwendung |
| Views | Host-`app/views` gewinnt vor Plugin-Views | Explizite logische View-Overrides in Nafinity; Host-Overrides zuletzt |
| CSS/JS | Layout lädt feste Dateien; NAF Asset-Service existiert | Tatsächlich konsumierte Assets plus paketbezogene Veröffentlichung und Browser-Lifecycle |
| Events | `nafinity.changed` mit typisiertem Change existiert | Wiederverwenden; Payloads/Zeitpunkt dokumentieren, Activity-Darstellung öffnen |
| Transaktionen | Change-Listener läuft vor Commit; Activity/Notifications verlangen aktive Transaktion | Keine Umdeutung als After-Commit. Externe Arbeit über bestehenden transaktionalen Queue-Pfad |
| AI/MCP | AiService baut bei jedem Aufruf eine private ToolRegistry mit fester Tool-Liste | Registrierbare Anbieter für kontextabhängige Tools; Rechte/Bestätigung bleiben bei jedem Aufruf bindend |
| Übersetzungen | Translator liest einen App-Pfad; Locales durchsucht App-Dateien | Plugin-Übersetzungsverzeichnisse, Host-Vorrang und Sprachauswahl integrieren |
| Schätzungsskalen | `Estimation::SCALES/LABELS/UNITS` sind fest | Registry für zusätzliche Skalen; Werte bei fehlender Definition erhalten |
| Icons/Theme | Material-Symbolnamen bereits offen; CSS-Variablen vorhanden | Bestehendes Icon-System weiterverwenden; Styles über Assets; kein unnötiger Icon-/Theme-Subsystem-Neubau |
| Storage/Auth/HTTP/Mail | Vorhandene NAF-Contracts/Adapter nutzbar | App-Konsumenten erreichen Overrides; private Upload- und Auth-Prüfungen erhalten |
| CLI/Migrationen/Jobs/Scheduler | Native NAF-Registrierung vorhanden | Reale Plugin-Integration und Neustart ohne Plugin testen; keine parallele Infrastruktur |
| Lebensdauer/Deinstallation | Kein App-Vertrag für Beiträge/Daten fehlender Plugins | Kein Löschen von Metadaten/Settings/Uploads beim Entfernen; fehlende Definitionen sicher anzeigen |

Wichtige Quellen: [Bootreihenfolge](/Users/flo/PhpstormProjects/nafphp/framework/src/Core/App.php:247), [Nafinity-Bootstrap](/Users/flo/PhpstormProjects/nafinity/app/bootstrap.php:35), [App-Routen](/Users/flo/PhpstormProjects/nafinity/app/app/routes.php:110), [Dispatcher](/Users/flo/PhpstormProjects/nafphp/framework/src/Core/Dispatcher.php:77), [AppController-DI](/Users/flo/PhpstormProjects/nafinity/app/app/Controllers/AppController.php:39), [Menü](/Users/flo/PhpstormProjects/nafinity/app/app/views/layout.phtml:69), [Rechtefilter](/Users/flo/PhpstormProjects/nafinity/app/app/Services/Access.php:73), [Rollenvalidierung](/Users/flo/PhpstormProjects/nafinity/app/app/Services/RoleService.php:70), [AI-Katalog](/Users/flo/PhpstormProjects/nafinity/app/app/Services/AiService.php:71), [transaktionale Activity](/Users/flo/PhpstormProjects/nafinity/app/app/Events/ActivityListener.php:23), [Locale-Auswahl](/Users/flo/PhpstormProjects/nafinity/app/app/Support/Locales.php:43).

**Gezielte Verifikation**

Die [Nafinity-Probe](/Users/flo/PhpstormProjects/nafinity/docs/audits/2026-09-17-plugin-extensibility/nafinity-probe.php) verwendet den tatsächlichen Nafinity-Autoloader und die tatsächliche App-Routendatei in einem temporären Framework-Host. Andere Plugins werden in den ausschließlich prozesslokalen Composer-Metadaten für diese Probe deaktiviert; die Produktumgebung wird nicht gebootet oder verändert. Die Rechteprobe verwendet ausschließlich in-memory SQLite.

[Ergebnis](/Users/flo/PhpstormProjects/nafinity/docs/audits/2026-09-17-plugin-extensibility/nafinity-probe-results.json):

- Lokale Sources: Framework `0.2.4-dev` (`1166372`), View `0.2.1-dev` (`133070c`), Form `0.2.3-dev`, Auth `0.2.1-dev`. Dies sind Development-Metadaten, keine Release-Verifikation.
- Testplugin wird durch den echten Framework-Boot entdeckt und gebootet. Seine eigene Route liefert 200, den benannten Parameter und eine injizierte Abhängigkeit.
- Sein frühes `home`-Override wird anschließend durch Nafinitys echte `home`-Route ersetzt.
- `get(ProbeController::class)` liefert den gebundenen Ersatz; der Dispatcher führt den ursprünglichen Controller aus.
- `Access::permissions()` liefert aus den gespeicherten Werten `comment` und `example.reports.view` nur `comment` zurück.
- Geprüfte zentrale App-Services sind final; kein Settings-Helper; bestehende Collection erfolgreich verwendbar.

Wiederholen vom Nafinity-Projektverzeichnis:

```sh
APP_ENV=test PHP_INI_SCAN_DIR='' PHPRC="$PWD/docs/audits/2026-09-17-plugin-extensibility/php.ini" \
  php docs/audits/2026-09-17-plugin-extensibility/nafinity-probe.php
```

Keine vollständige Nafinity-Test-, HTTP- oder Browser-Suite wurde für dieses Audit ausgeführt. Insbesondere die früher genannten **493 CMS-Tests gehören nicht zu Nafinity**. Die bestehende Nafinity-Suite enthält bereits Isolation, Rechte, Transaktionen, Ticket-Details, Settings, Upload-Recovery, Profile und AI-Prüfungen; der neue Auftrag erweitert diese gezielt. Es wurden weder App-/Paketquellen noch Datenbanken, Git-Branches oder Veröffentlichungen verändert.

Der [korrigierte Implementierungsauftrag](/Users/flo/PhpstormProjects/nafinity/docs/audits/2026-09-17-plugin-extensibility/IMPLEMENTATION_PROMPT.md) enthält die festgelegten APIs, Integrationsstellen, Speicherregeln und Abnahmetests für das tatsächliche Nafinity-Projekt.
