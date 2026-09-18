Du arbeitest an der **Ticket-/Kanban-Anwendung Nafinity im Repository `/Users/flo/PhpstormProjects/nafinity`**, Composer-Projekt `fkde/nafinity`. Die Composer-Anwendung liegt in dessen Unterverzeichnis `app/`; ihr PHP-Code liegt in `app/app/`. Die NAF-Pakete liegen daneben unter `/Users/flo/PhpstormProjects/nafphp`.

**Verbindliche Korrektur:** Nafinity ist nicht `naf/cms`. `naf/cms` ist in dieser Anwendung nicht installiert und wird für diesen Auftrag nicht hinzugefügt oder verändert. Ältere CMS-Prompts aus diesem Audit sind verworfen. Ausschließlich dieser Text und der korrigierte `REPORT.md` sind Grundlage.

**Auftrag**

Implementiere die nachfolgend festgelegte Plugin-Erweiterbarkeit vollständig. Das Ergebnis muss zwei unabhängig installierte Composer-Plugins tragen, die eigene Controller/Routen/Services, Menüeinträge, Settings, Ticket-Metadaten, Widgets und weitere Beiträge liefern und ausdrücklich vorhandene Erweiterungsdefinitionen ersetzen können. Plugin-Erweiterbarkeit ist eine zentrale Produkteigenschaft. Liefere echte Integration in die bestehenden Benutzerabläufe, Tests und ausführbare Dokumentation; bloße Registries, ein weiterer Plan oder Beispielcode ohne produktive Verbraucher genügen nicht.

Titel, Beschreibung und Kommentare bleiben feste Ticket-Hauptbereiche. Verknüpfungen, Uploads, Verlauf und zusätzliche Widgets werden modular. Beiträge werden über einen einheitlichen Index sortiert. Der gewünschte PHP-Zugang heißt `settings()` und bietet mindestens `get()` und `all()`.

Neue Klassen und APIs in diesem Auftrag sind Zielvorgaben, keine Behauptungen über vorhandenen Code. Folge den festgelegten Entscheidungen. Falls sich der Ausgangscode inzwischen geändert hat, erhalte neue Funktionen und verwende eine bereits identische API statt einer parallelen Implementierung; dokumentiere die genaue Zuordnung. Arbeite A–L in derselben Aufgabe ab, ohne zwischen den Schritten eine erneute Beauftragung abzuwarten.

**A — Richtiger Ausgangspunkt, Arbeitsumfang und Testaufbau**

1. Prüfe als Erstes, dass `app/composer.json` den Namen `fkde/nafinity` enthält und `app/app/views/ticket.phtml` existiert. Lies `AGENTS.md`, `README.md`, `docs/Implementation.md`, `docs/Nafinity-Prototypplan.md`, `docs/Code-Style.md` und die tatsächlichen Tests/Make-Ziele in diesem Repository. Audit-Referenz: `main`, Commit `016931e`; nicht ungeprüft als aktuellen Head übernehmen.
2. Lies `/Users/flo/PhpstormProjects/nafinity/docs/audits/2026-09-17-plugin-extensibility/REPORT.md`, `nafinity-probe.php` und `nafinity-probe-results.json`. Die verworfene frühere CMS-Prüfung liefert keine Nafinity-Abnahme und ist nicht Teil dieser Arbeitsgrundlage.
3. Erhalte fremde Änderungen. Arbeite für Nafinity auf `feat/plugin-extensibility`, neu von aktuellem `origin/main` oder auf der vorhandenen passenden Arbeitsbranch. Dieser Auftrag autorisiert Commit, Push und eine PR für Nafinity; keinen Merge in dessen `main`. Bei Änderungen generischer NAF-Pakete deren eigene AGENTS-, Versions-/RC- und Dokumentationsverfahren befolgen. Keine Paketcode-Merges oder Releases ohne gesonderten Auftrag.
4. Composer läuft gemäß Projektvorgabe im Docker-Container. Keine Änderungen in `vendor/`, keine produktiven Datenbank-Resets, keine generierten Source-Manifeste/Locks, Secrets oder private Dateien committen. PHP-Untergrenze aus dem Manifest erhalten; MariaDB und PostgreSQL bleiben unterstützt. Kein Umbau auf CMS, Laravel, einen zweiten Container/Router/Event-Bus, ORM oder Plugin-Metaframework.
5. Lege `docs/Plugin-Erweiterbarkeit-Status.md` mit A–L und T01–T32, Status, Testnamen, Ergebnissen und Commit/PR-Zuordnung an. Dokumentiere offene Schritte ehrlich; nach Kontextwechsel daraus fortsetzen.
6. Baue zwei installierbare Test-/Beispielpakete `example/nafinity-extension-a` und `example/nafinity-extension-b`, jeweils `type: naf-plugin`, PSR-4-Autoloading und eigener Bootstrap. B benötigt A; beide dokumentieren Nafinity als Host-Voraussetzung und ihre tatsächlich benötigten NAF-Dependencies. Kein gegenseitiger Composer-Zyklus mit dem Root-Projekt. Test-Host erhält beide als echte Composer-Pakete über ausschließlich lokale Test-Path-Repositories.
7. Verwende den echten Nafinity-Boot und seine vorhandenen isolierten Datenbank-/HTTPS-Harnesses. Die Audit-Probe ist nur ein Ausgangsregressionstest, kein Ersatz für den vollständigen App-Boot. Tests müssen auch ohne A/B sowie mit vertauschter Composer-Auflistung funktionieren, solange die explizite Provider-Reihenfolge gleich bleibt.

**B — Expliziter Zeitpunkt für Nafinity-Erweiterungen**

Problem: `app()` bootet Composer-Plugins und danach App-Routen, bevor der restliche `app/bootstrap.php` App-Defaults registriert. Sofortige Plugin-Overrides können dadurch wieder verloren gehen. Behalte den NAF-Boot; ergänze einen kleinen, App-eigenen nachgelagerten Registrierungsdurchlauf.

Öffentliche API unter Namespace `Nafinity`, PSR-4-Zuordnung `"Nafinity\\": "app/Extensions/"` zusätzlich zu `App\\`. Neue Helper liegen in `app/app/functions.php`, Namespace `Nafinity`; lade diese Datei über `autoload.files` im App-Manifest. Diese Datei deklariert nur Funktionen und startet weder `app()` noch DB-Arbeit beim Include.

- `Nafinity\extensions(): ExtensionRegistry` liefert dieselbe containergebundene Registry. Sie muss schon während eines Composer-Plugin-Bootstraps erreichbar sein, ohne rekursiven App-Boot: Der Helper fragt am aktuellen Container `has(ExtensionRegistry::class)` ab, bindet bei Bedarf eine neue, ausschließlich metadatenhaltende Instanz und liefert `get()`. AppHolder hält während Plugin-Boot bereits die aktuelle App. Spätere Defaultregistrierung ersetzt diese Instanz nicht.
- `ExtensionRegistry::register(string $id, string $providerClass, int $index = 100, bool $replace = false): void` merkt einen Provider vor. Der Provider implementiert `Nafinity\Contracts\ExtensionProviderInterface::register(ExtensionContext $context): void` und wird erst nach App-Defaults über den Container aufgelöst.
- ExtensionContext enthält den vorhandenen Container sowie die nachfolgend benannten Registries. Es trägt keinen aktuellen Benutzer und führt keine Autorisierung im Boot aus. Registry-Metadaten sind Code-Definitionen; Benutzerdaten werden erst im jeweiligen Request gelesen.
- Feste Reihenfolge in `app/bootstrap.php`: Composer/NAF-Boot und bisherige App-Routen → lazy App-Service-Defaults, Policies und native Infrastruktur → eingebaute Nafinity-Beitragsdefinitionen → vorgemerkte ExtensionProvider aufsteigend nach Index und ID → optional `app/app/extensions.php` als letzte explizite Host-Override-Datei → `app()->run()`.
- Befreie App-Defaultregistrierung von vermeidbarer vorzeitiger Auflösung austauschbarer App-Dienste. Erforderliche NAF-Registry-Zugriffe sind zulässig; Domain-/DB-Arbeit gehört nicht in den Provider-Boot.
- Ein zweiter Initialisierungsaufruf führt Provider nicht erneut aus. Erkenne rekursive Initialisierung. Provider nach abgeschlossenem Durchlauf anzumelden wirft eine klare LogicException; neue Definitionen in vorhandenen Registries vor Request-Verarbeitung bleiben möglich. Kein Dateiscan und keine zusätzliche Plugin-Manifestdatei.
- Provider-Ausnahmen melden Provider-ID und Ursache und lassen den Boot scheitern; kein stiller Teilbetrieb. Alle Provider-Registrierungen sind Code und stammen aus installierten vertrauenswürdigen Paketen, niemals aus HTTP-Daten.

Gemeinsame Registrierungsregeln für alle folgenden neuen Registries:

- IDs: nicht leer, stabil, neue Plugin-IDs namensräumlich wie `example.reports`; keine Klassennamen oder PHP-Strings aus Requests ausführen.
- `add($definition, bool $replace = false)`, `get(string $id): ?Definition`, `all(): array`, `remove(string $id): bool`. Duplikate ohne expliziten Ersatz werfen; unbekanntes Remove liefert false. Bei Listen bleibt der ID-Schlüssel erhalten. Ersatz ersetzt genau die Definition und ist keine undefinierte rekursive Array-Mischung.
- **Ein Sortierwert: `index`, aufsteigend; bei Gleichstand ID mit `strcmp()` aufsteigend.** Default 100, negative Werte erlaubt. Jede sichtbare Liste und jede Feld-/Gruppenliste folgt dieser Regel. Es gibt kein zusätzliches konkurrierendes `priority`-Feld. NAFs bereits vorhandene Event-Priorität mit höheren Werten zuerst bleibt unverändert. Board-Kartenpositionen sind Nutzerdaten und behalten ihren eigenen Sortiervertrag.
- Defaults werden genau einmal vor Plugins registriert; die letzte explizite Registrierung mit `replace: true` gewinnt. Host-Overrides laufen zuletzt. Remove löscht nur Definitionen, keine gespeicherten Werte/Dateien/Grants.
- Definitionen und Registry-Instanzen dürfen wiederverwendet werden; ausgewertete Rechte, Projekt-/Benutzerkontexte und Abfrageergebnisse werden nicht global gecacht.

**C — Routen, Controller, Services und Seitenrenderer**

1. Plugins verwenden weiterhin `Naf\route()->add()` in ihrem nachgelagerten Provider. Dadurch können sie bestehende benannte Nafinity-Routen tatsächlich ersetzen. Neue Route-Namen sind eindeutig. Gleicher Pfad unter anderem Namen wird nicht als Ersatz versprochen; teste und dokumentiere NAFs bestehende Reihenfolge. HTTP-Parameter werden weiterhin nach Namen übergeben, Constructor Injection liefert Dienste.
2. Ergänze im owning Framework-Paket `Route::remove(string $name): bool`. Ändere im Dispatcher nur die Controller-Auflösung: gebundene Zielklasse über `get()`, sonst wie bisher `make()` beziehungsweise bisherigen PSR-11-Fallback. Prüfe gültiges Objekt/aufrufbare Methode und lasse Factory-Fehler sichtbar. Die Bedeutung von `make()` als neue Konstruktion bleibt unverändert. Bestehende Callable-/Closure-Routen und ResponseInterface bleiben unterstützt.
3. Neue `Nafinity\Contracts\<Name>Interface` für die tatsächlichen Austauschgrenzen: `Access`, `AccountService`, `ProjectService`, `TicketService`, `BoardQuery`, `CommentService`, `AttachmentService`, `NotificationService`, `PreferenceService`, `RoleService`, `TimerService`, `AiService`. Übernimm jeweils alle vorhandenen öffentlichen Instanzmethoden mit ihren tatsächlichen Namen, Argumentnamen, Defaults und Rückgabetypen; keine Constructor/private Methoden. Defaultklassen implementieren die Contracts. Bestehende konkrete Klassen/Constructor-Aufrufe bleiben verwendbar.
4. Binde Defaults lazy und stelle **alle** produktiven Verbraucher auf diese Contracts um: App-/Profile-/AI-Controller, Services untereinander, Jobs, Seed/Commands, Readiness und neue Provider. Insbesondere FinalizeAttachmentJob und MaintenanceJob dürfen einen Override nicht durch direkte konkrete Auflösung umgehen. Die finale Defaultklasse kann final bleiben; Ersatz ist über den Contract möglich.
5. Bestehende NAF-Verträge für Auth, Session, Storage, Client, Mail und Logging bleiben die Integrationsgrenzen. Keine zweiten Implementierungen. Unveränderliche Datenobjekte brauchen keine Interfaces. Constructor-gebundene konkrete Instanzen werden nicht nachträglich umverdrahtet; Overrides müssen vor ihrer Nutzung registriert sein. Teste tatsächlichen Ersatz und Wrapper-Dekoration im HTTP-/Job-Pfad.
6. Extrahiere `AppController::page()` in einen injizierbaren `Nafinity\Contracts\PageRendererInterface::render(string $template, array $data = []): ResponseInterface`. Der Default übernimmt genau Login-Verhalten, Sprache, eigene Projekte, Timer, Benutzer und Preferences. Die Plugins können normale Seiten mit derselben Shell rendern. Gemeinsame Kontextdaten stammen vom Renderer; ein fremdes `$data['user']` überschreibt sie nicht. Projekt-/Datensatzautorisierung bleibt vor Übergabe an die View erforderlich.
7. Ergänze `extensions()->views()` für explizite logische View-Zuordnung: `ViewOverride(string $id, string $template)`, wobei ID der bisherige logische Name wie `ticket` oder `ticket/field` ist. Ein gezieltes Override ersetzt diesen Namen durch einen namespaced Plugin-Templatenamen. Wende die Auflösung auf PageRenderer, alle App-Partial-Aufrufe, Login-/Error-/Profile-Views und `layout` an. PageRenderer ergänzt hierfür `fragment(string $template, array $data = []): string` als Teil seines Contracts; nur render() erzeugt Response/Shell-Daten, fragment() löst Mapping und NAF-Partial auf. Alle Layout-Setzungen verwenden dieselbe Mapping-Auflösung. Keine direkte Änderung des globalen NAF-View-Suchverhaltens: Host-`app/views` bleibt vor den normalen Plugin-Pfaden; das explizite Mapping wählt ein anderes Ziel. Erkenne Mapping-Zyklen. Host-Code kann ein Mapping zuletzt ersetzen/entfernen. Ein Plugin braucht für einen additiven Widget-Beitrag ausdrücklich kein komplettes View-Override.

**D — Plugin-Rechte mit vorhandener Projekt-Autorisierung**

- Ergänze `extensions()->permissions()` mit Definition `PermissionDefinition(id, label, description = '', index = 100, ownerOnly = false)`. Registriere die sieben bestehenden `ProjectPermissions::LABELS` und die vier speziellen Owner-Aktionen `roles`, `owners`, `archive`, `restore` als Defaults; `read` bleibt die vorhandene Mitgliedschaftsprüfung.
- Öffne `Access::permissions()`, `RoleService::save()`, Rollenauswahl/-Anzeige, Rechte-Zähler und alle Allowlist-Verbraucher für registrierte Plugin-Permissions. Ein eigener DB-Grant darf nicht mehr bloß wegen fehlendem Eintrag in der alten Konstante verschwinden.
- Neue Plugin-Permissions erhalten **keine automatischen Grants** an vorhandene Rollen, auch nicht an Owner/Manager. Owner kann sie einer eigenen Rolle zuweisen; der Beispieltest vergibt explizit passende Grants. Bestehende Standardrollen behalten genau ihre bisherigen Rechte. Die bestehende `ProjectPermissions`-API bleibt als kompatibler Zugang zu den eingebauten Defaults erhalten.
- Owner-only-Rechte bleiben für eigene Rollen unzulässig. Nicht-Owner dürfen über Mitgliedschaftsverwaltung weder stärkere Rollen vergeben noch stärkere Mitglieder entfernen. `moderate` benötigt weiterhin `comment`. Archivierte Projekte behalten die bisherigen Schranken.
- Unbekannte gespeicherte Grants fehlender Plugins werden nicht gelöscht oder wirksam autorisiert. Beim Speichern einer Rolle bleiben unverfügbare gespeicherte Grants erhalten; neue unbekannte Namen im Payload werden abgewiesen. UI markiert sie als nicht verfügbare Definition. Neuinstallation reaktiviert sie erst zusammen mit der wieder vorhandenen Definition; das ist dokumentiert.
- UI-Verbergen ersetzt keine API-/Service-Prüfung. Jeder Provider und jede Mutation verwendet die aktuelle Access-/ProjectScope-/NAF-Policy-Kette. Fehlerstatus 404 bei fremdem Projekt und 403 bei fehlendem Recht bleiben erhalten.

**E — Einheitliche UI-Beiträge und linkes Menü**

`extensions()->ui()` verwaltet `UiContribution(id, slot, template, index = 100, provider = null, permission = 'read', modes = ['detail'], module = null)`. Provider ist ein Container-Service mit `UiDataProviderInterface::data(UiContext $context): array`. UiContext enthält geprüften Actor, optionalen ProjectScope, optionalen Datensatz/Datensatz-ID, aktuellen Routennamen und Modus `page`, `detail` oder `create`. Er enthält keine globalen Queryergebnisse anderer Projekte.

Registries bieten zusätzlich `forSlot(string $slot, UiContext $context): array`; Rechte-/Modusfilter werden **vor** Auflösung/Ausführung des Providers angewandt. Beiträge mit Projektberechtigung brauchen ProjectScope. Globale Beiträge brauchen mindestens Anmeldung; `permission: null` bedeutet hier keine zusätzliche Projektaktion, niemals anonymes Produktlesen. Sichtbarkeit ist keine Schreibberechtigung.

Feste Slots, vollständig in die vorhandenen Ansichten integrieren:

| Slot | Einbauort |
|---|---|
| `sidebar.workspace` | Workspace-Einträge im linken Menü |
| `sidebar.project` | Zusätzliche Navigation für das ausgewählte Projekt, nach der Projektliste |
| `sidebar.footer` | Unterer Menübereich |
| `topbar.actions` | Aktionen in der vorhandenen oberen Leiste |
| `projects.actions`, `projects.card.badges` | Projektübersicht |
| `board.actions`, `board.card.badges`, `board.card.details`, `board.card.actions`, `board.column.summary` | Boardkopf, Karten und Spaltenkopf |
| `ticket.actions` | Vorhandenes Ticket-Aktionsmenü |
| `ticket.main.widgets` | Zwischen fester Beschreibung und festen Kommentaren |
| `ticket.sidebar.panels` | Gruppen in der rechten Ticketleiste |
| `profile.panels` | Bestehender Profil-Dialog, zusätzlich zu den unveränderten Sicherheitsformularen |
| `notifications.actions`, `activity.actions` | Bestehende Benachrichtigungs-/Verlaufsseiten |

Registriere Seiten-/Menübeiträge mit Modus `page`, Ticketbeiträge ausdrücklich mit `detail` beziehungsweise zusätzlich `create`. Verlangt ein sichtbarer Slot mehrere Modi, muss die Definition sie alle nennen. Die jeweils vorhandenen Aktionen an diesen Stellen werden explizite Defaultbeiträge; Bezeichnungen, URLs, native Formulare, Rechte, Dialogtrigger und Tastaturbedienung bleiben erhalten. Zusätzliche Aktionen besitzen eigene registrierte Route und passenden HTTP-/CSRF-Vertrag; keine Methodenwahl aus unkontrollierten Requestdaten. Boardkarten bleiben insgesamt anklickbar, die neuen inneren Buttons/Links lösen nicht gleichzeitig Ticketöffnung/Drag aus.

Für Navigation ergänze eine kleine typisierte Definition `NavigationItem(id, slot, label, routeName, routeParams = [], icon = null, activeRoutes = [], index = 100, permission = null)`, konsumiert über `extensions()->navigation()` in denselben Sidebar-Slots. Das ist die semantische Menü-API; Templates/Widgets können weiterhin über UiRegistry in die Slots beitragen. `routeParams` ist ein Array oder eine PHP-Closure `fn(UiContext $context): array`, die erst nach Autorisierung ausgewertet wird; Projekt-/Ticket-IDs werden nicht beim Boot festgehalten. Gemeinsame Zusammenführung sortiert **beide** Beitragsarten nach Index und ID, kollidierende IDs werden abgewiesen. Registriere vorhandene Workspace- und Footer-Einträge als Defaults. Die dynamische Liste tatsächlich zugänglicher Projekte bleibt aus BoardQuery, keine Plugin-Registry aller Projekte. Material Symbols über vorhandenes `Icon::mark()` verwenden; kein neues Icon-System.

Titel, Beschreibung und Kommentare bleiben im Ticket außerhalb der austauschbaren Widget-Liste. Reserviere `core.ticket.title`, `core.ticket.description`, `core.ticket.comments`; Versuche, sie über Ui-/Feld-Registries zu ersetzen oder zu entfernen, werfen eine LogicException. Die Beschreibungsbearbeitung und Kommentarfunktion bleiben bestehen. Plugins können benachbarte Widgets ergänzen; ein vollständiges, ausdrücklich registriertes Template-Override bleibt eine bewusste separate Möglichkeit und muss die festen Bereiche bewahren.

**F — Settings-Definitionen, Wertezugang und HTTP-API**

Neue Helper-Signatur in `app/app/functions.php`:

```php
namespace Nafinity;

function settings(): Settings;
```

`Settings` ist eine containerbezogene, zustandsarme Fassade, kein Untertyp von Collection. Registriere Definitionen separat über `extensions()->settings()`; `settings()->all()` darf nicht versehentlich Definitionsobjekte liefern.

Verbindliche PHP-Werte-API:

```php
settings()->get('theme', 'system');                 // eigener Benutzer
settings()->all();                                 // array<string, mixed>
settings()->has('theme');                          // registriert und lesbar, auch bei null
settings()->collection();                         // Naf\Support\Collection als Snapshot
settings()->forProject($projectId)->get('name');
settings()->forProjectUser($projectId)->get('muted');
settings()->forApplication()->get('mail_enabled'); // nur deklarierte, lesbare Konfigwerte
```

- Kontextmethoden liefern neue unveränderliche Settings-Instanzen; sie verändern nicht das Helper-Objekt. Kein impliziter Projektwechsel anhand eines URL-Parameters. User-/ProjectUser-Kontext verwendet ausschließlich den aktuell authentifizierten Benutzer. `forProject()` prüft aktuelle Mitgliedschaft. Keine öffentliche `forUser($fremdeId)`-Abkürzung. Worker mit Systemaufgaben verwenden den internen Store nach ihrer vorhandenen expliziten Autorisierung, nicht einen gefälschten Login.
- `SettingDefinition` enthält `key`, `scope` (`user`, `project`, `project_user`, `application`), `section`, `label`, `type`, ausdrücklich angegebenen `default`, `index = 100`, `options = []`, `readPermission = null`, `writePermission = null`, `sensitive = false` und optional `configKey`. Bei `scope=user`/`project_user` bedeutet fehlendes Extra-Recht Zugriff auf eigene Werte; bei `project` bedeutet fehlendes Schreibrecht standardmäßig `manage`, ein ausdrücklich angegebenes Plugin-Schreibrecht gilt für dessen eigenen Key. Legacy-Projektfelder erfordern unveränderlich mindestens `manage`. Projektlesezugriffe benötigen immer Mitgliedschaft. `application` ist ausschließlich ein serverseitiger, explizit deklarierter Config-Zugang und kann in CLI ohne User-Session gelesen werden; Schreiben ist verboten. Identität ist `(scope, key)`; derselbe Schlüssel in verschiedenen Scopes ist erlaubt. Neue Plugin-Schlüssel verwenden einen Präfix wie `example.reports.limit`. Doppelte Schlüssel innerhalb desselben Scopes werden auch über verschiedene Abschnitte abgewiesen. Ein Ersatz in anderem Abschnitt verschiebt exakt eine Definition.
- Wertepriorität pro Scope: tatsächlich gespeicherter Wert → explizit deklarierter NAF-Config-Key, wenn vorhanden → Definitionsdefault. Nur bei unbekanntem Schlüssel liefert `get()` den Aufruferdefault. Bekannte, aber unberechtigte Einzelzugriffe werfen 403; `all()` lässt sie aus. `null`, `false`, `0` und `''` sind gültige Werte und dürfen nicht per `empty()`/`??` durch Defaults ersetzt werden. Punkte im Key sind wörtliche Schlüssel, keine verschachtelte Property-Suche.
- `all()` liefert nur registrierte und lesbare Definitionen **dieses** Scopes mit ihren effektiven typisierten Werten. Kein automatischer Scan von `config()` oder Export aller Tabellen. `collection()` kapselt genau diesen Array-Snapshot mit der vorhandenen `Naf\Support\Collection`. `add()` am Snapshot speichert nichts. Deren vorhandene Null-Semantik dokumentieren; die neue Settings-API verwendet ihre eigene Präsenzprüfung, ohne die Core-Collection inkompatibel zu ändern.
- Trusted serverseitiger PHP-Code darf sensible registrierte Werte nach normaler Scope-Prüfung einzeln lesen. HTTP und generische Formularausgabe liefern sensible Werte niemals zurück, auch nicht in `all()`, Logs, Events oder Fehlern. Sensible Eingaben bleiben im Formular leer; nicht mitgesendete Werte bleiben unverändert. Die explizite Löschaktion wird als `resetKeys` übergeben und serverseitig autorisiert.

Speicher und bestehende Daten:

- `Nafinity\Contracts\SettingsStoreInterface::read(SettingsContext $context, array $keys): array` liefert vorhandene Werte als Map; `write(SettingsContext $context, array $values, array $resetKeys = []): void` schreibt eine Teilmenge atomar. Vorhanden/fehlend muss unabhängig vom Wert unterscheidbar sein. Store ist austauschbar/dekorierbar; Definition/Typ-/Rechtevalidierung bleibt in SettingsService.
- Die bestehenden persönlichen Schlüssel `theme`, `locale`, `timezone`, `notify_in_app`, `notify_mail` bleiben in `user_preferences`. `muted` bleibt in `project_preferences`. `name`, `description`, `color`, `icon`, `ticket_key`, `estimation_scale` bleiben im bisherigen Projektdatensatz und werden über ProjectService validiert/geschrieben. Kein zweiter widersprüchlicher Speicher für bestehende Werte.
- Neue Plugin-Werte kommen per NAF-Migration in `user_settings(user_id, setting_key, value_json, updated_at)`, `project_settings(project_id, setting_key, value_json, updated_at)` und `project_user_settings(project_id, user_id, setting_key, value_json, updated_at)`. Primärschlüssel sind die jeweilige Owner-ID-Kombination plus `setting_key`; Fremdschlüssel auf users/projects beziehungsweise `(project_id,user_id)` in project_members. `setting_key VARCHAR(190)`, `value_json TEXT`, nicht-null, JSON im PHP-Code kanonisch mit Fehlerprüfung codieren. Pro Wert höchstens 16 KiB JSON. Beide DB-Typen unterstützen dieselbe Semantik. Keine DDL für jedes neue Plugin-Feld.
- Application-Scope ist in dieser Runde bewusst read-only: registrierte Config-Werte `mail_enabled` und `mail_from` aus vorhandener Nafinity-Konfiguration sowie Plugin-Config-Definitionen. Es gibt bisher keine globale Administratorrolle. Erfinde keine globale Settings-Schreibberechtigung. DB-Passwörter, OAuth-/LDAP-Secrets werden nicht als öffentliche Settings registriert.
- Neue `SettingsServiceInterface::save(SettingsContext $context, array $values, array $resetKeys = []): void` validiert Scope, alle Keys/Typen/Rechte vor Schreiben. Fehler als vorhandene Failure mit Feldfehlern. Nicht übermittelte Werte bleiben erhalten. Ein Key darf nicht zugleich gesetzt und zurückgesetzt werden. Ein Fehler verhindert sämtliche Writes der Anfrage. `resetKeys` entfernt Plugin-Werte; Legacy-Schlüssel werden auf ihre definierten Defaults über ihre bestehenden Servicepfade zurückgesetzt. Bei Projektdaten vorhandene Locks erhalten; ProjectService::update() hat im Ausgangsstand keine eigene Versionsprüfung, behaupte daher keinen vorhandenen Settings-409-Vertrag. Für Legacy-Projektteiländerungen aktuelle vollständige Projektdaten unter derselben Projektsperre laden und mit der autorisierten Teilmenge an ProjectService übergeben, damit fehlende Felder nicht überschrieben werden. Legacy- und Pluginwerte einer gemischten Anfrage stehen unter derselben äußeren Access-/EntityManager-Transaktion; innere Service-Savepoints committen nicht die äußere Operation. Für Userwerte sperre vor gemeinsamem Schreiben die Userzeile; ProjectUser-Writes sperren Projekt und prüfen aktuelle Mitgliedschaft. Kein Store darf eine bereits fremd geöffnete PDO-Transaktion committen. App-SettingsService ist Transaktionseigentümer, der Defaultstore beteiligt sich daran. Requests einer neuen generischen Settings-Form enthalten nur ihre eigenen Definitionskeys; Rollen-/Mitglieder-/Strukturaktionen behalten ihre eigenen Endpunkte.
- `PreferenceService::save()/language()/mute()`, BoardQuery-/PageRenderer-Lesepfad und neue SettingsService verwenden gemeinsame Definitionen/Typen und dieselben gespeicherten Werte. Alte Vollformularsemantik bleibt für bestehende Aufrufe erhalten; neue generische Saves sind ausdrücklich partial. Keine Rekursion zwischen SettingsService und Legacy-Adapter. Implementiere gemeinsame interne Persistenzoperationen, welche von beiden Servicefassaden genutzt werden. NotificationService darf seine gebündelten SQL-Joins auf die unveränderten Legacy-Tabellen behalten.
- Verwende zunächst keinen langlebigen Wertecache. Wenn Request-Batching eingeführt wird, muss es Scope und Actor enthalten und bei jedem erfolgreichen Write/Reset sowie Rechte-/Identitätswechsel invalidiert werden. Schreibzugriff gefolgt von `get()/all()` im selben Request liefert neue Werte. Container-/Worker-Reset übernimmt keine ausgewerteten Werte in den nächsten Job.

Oberfläche und Typen:

- `extensions()->settingSections()` enthält `SettingSection(id, scope, label, template = null, provider = null, index = 100, permission = null, icon = null)`. Migriere die vorhandenen Karten mit denselben IDs `personal`, `ai`, `general`, `roles`, `users`, `column`, `swimlane`, `label`. Bestehende Spezialformulare bleiben als registrierte Templates nutzbar; eine Standard-Section kann neue definierte Felder automatisch rendern. Karten und Felder sind unabhängig sortierbar, ersetzbar und entfernbar.
- Teile die Typenmechanik mit Ticket-Metadaten: `extensions()->fieldTypes()` und `FieldTypeInterface::id(): string`, `normalize(mixed $value, array $options): mixed`, `validate(mixed $value, array $options): array`, `view(): string`, `module(): ?string`. Validierung liefert Liste von Meldungen; Invalides wird nicht still in einen Default umgewandelt. Registry-ID ist der von `id()` gelieferte Typname; `add()` erhält das FieldTypeInterface-Objekt. Typvalidierung prüft zuerst die erlaubte Rohdarstellung, normalisiert danach und prüft die normalisierte Domäne; ungültige Eingaben werden nie durch Normalisierung verschluckt. Standardtypen: text, textarea, boolean, integer, date, select, multiselect. Rich-Description, Duration und Core-Spezialfelder behalten ihre vorhandenen Adapter. Bei Settings erlaubt `options['nullable'] = true` ausdrücklich null; sonst ist null ungültig. Die TicketField-Flags nullable/required werden in denselben Validator-Kontext übernommen. `required=true` verlangt einen wirksamen nicht leeren Wert; 0/false bleiben bei entsprechenden Typen gültig. Plugin-Typen müssen JSON-kompatible Werte liefern.
- Boolean erlaubt bool sowie Formularstrings `'0'`/`'1'`; Integer erlaubt Integers und kanonische Dezimalstrings innerhalb definierter min/max; Datum ist gültiges `YYYY-MM-DD` oder nullable gemäß Definition; Select prüft deklarierte Optionsschlüssel; Multiselect ist eine deduplizierte Liste solcher Schlüssel und keine Sprachcode-Normalisierung. Fehlende Checkboxen müssen vom Formular als explizites false gesendet werden. Feld-Defaults durchlaufen dieselben Typregeln. Sensible Felder verwenden verdeckte Eingaben ohne Rückfüllung.
- Die `ai`-Karte behält ihren bisherigen Browser-Speicher, Loopback-Regeln, Modellverbindung und JS-Save-Pfad. Dokumentiere ausdrücklich: `settings()->all()` kann Browser-localStorage nicht lesen; keine automatische Übertragung von Chatverlauf, Gedächtnis oder Prompts auf den Server.

Geschützte neue HTTP-Endpunkte, getrennt von den vorhandenen HTML-Routen:

| Route-Name | Methode/Pfad | Kontext |
|---|---|---|
| `api.settings.user.read` / `.write` | GET / POST `/api/settings/user` | eigener Benutzer |
| `api.settings.project.read` / `.write` | GET / POST `/api/projects/{project}/settings` | Projekt |
| `api.settings.project_user.read` / `.write` | GET / POST `/api/projects/{project}/settings/user` | Projekt + eigener Benutzer |

GET liefert `{"values": {...}}`; optional `?key=example.reports.limit` begrenzt auf einen registrierten, lesbaren, nicht sensiblen Key. Nicht vorhandener/sensibler Key liefert 404 ohne Wert, fehlendes Recht 403. Ohne Key nur lesbare, nicht sensible Werte. POST erwartet JSON-Objekt `{"values": {...}, "resetKeys": [...]}` und liefert dieselbe gefilterte Antwort nach erfolgreicher Mutation. Bestehendes Input::body, Failure, Auth, CSRF und Response-Helper verwenden; keine ungeprüfte Massenzuweisung. Alle Antworten `Cache-Control: private, no-store`; keine Application-Scope-HTTP-Route. UI und PHP verwenden dieselbe SettingsService-/Definitionslogik.

**G — Ticket-Metadaten als echte erweiterbare Daten**

Die Definition zusätzlicher Metadaten erfolgt im Plugin-Code. Es wird in diesem Auftrag kein Admin-Editor zum Erzeugen von PHP-Klassen/DB-Spalten gebaut.

- `extensions()->ticketFields()` verwaltet `TicketFieldDefinition(key, label, type, group = 'details', index = 100, default = null, nullable = true, required = false, readOnly = false, readPermission = 'read', writePermission = 'write', options = [], showOnCreate = true)`. Neue Plugin-Keys müssen einen Präfix wie `example.external_id` besitzen. Ihre Typen stammen aus derselben FieldTypeRegistry wie Settings; keine zweite Validator-/Widget-Implementierung.
- Identität ist der Key, nicht das Label. Neue Felder dürfen keine Core-Felder oder reservierten Systemattribute wie `project_id`, `created_by`, `version`, `board_revision` überschreiben. Die festen Ticket-Hauptfelder sind zusätzlich durch E geschützt.
- Definiere rechte Gruppen in der UiRegistry als Default-Panels: `core.ticket.primary` Index 100, `core.ticket.details` 200, `core.ticket.planning` 300, `core.ticket.information` 400. TicketField-Gruppen heißen `primary`, `details`, `planning`, `information` und verweisen auf das jeweilige Panel. Neue Gruppen werden ausdrücklich als Panel plus dazugehöriger Group-ID registriert; unbekannte Gruppen schlagen beim Abschluss der Provider-Initialisierung mit konkreter Diagnose fehl.
- Registriere die bisherigen Metadaten als Core-Definitionen in der vorhandenen Reihenfolge: primary = column_id, assignee_ids; details = priority, swimlane_id, label_ids, color; planning = estimate_points, start_date, due_date, estimate_minutes, spent_minutes; information = creator, project, created_at, updated_at, closed_at, archived_at, version. Index je Gruppe 100, 200, 300 usw. Informationswerte bleiben read-only. Core-Definitionen verwenden Adapter zu den vorhandenen Services/Partials, nicht die neue Metadata-Tabelle. Plugin-Code darf ihre Darstellung/Reihenfolge über expliziten Definitionsersatz ändern; dies hebt niemals Core-Validierung oder Schreibschutz auf.
- `column_id`/`swimlane_id` nutzen weiterhin move, Zuständige/Labels weiterhin Pivots, Statusaktionen weiterhin state, Timer weiterhin TimerService. Schätzungs-/Zeit-/Datumsregeln bleiben bestehen. Kein beliebiges SQL-UPDATE anhand eines übergebenen Feldnamens.

Neue NAF-Migration im App-Repository:

```text
ticket_metadata
  project_id BIGINT NOT NULL
  ticket_id BIGINT NOT NULL
  meta_key VARCHAR(190) NOT NULL
  value_json TEXT NOT NULL
  updated_at TIMESTAMP NOT NULL
  PRIMARY KEY(project_id, ticket_id, meta_key)
  FOREIGN KEY(project_id, ticket_id) REFERENCES tickets(project_id, id)
```

Verwende dieselben MariaDB-/PostgreSQL-Migrationskonventionen wie die App, keine Änderung früher bereits angewandter Migrationen. Readiness nimmt die neue Migration auf. `down()` entfernt ausschließlich die neue eigene Tabelle und gehört nur in die isolierten Migrationstests, nicht in einen automatischen Plugin-Uninstall. Pro Wert höchstens 16 KiB, insgesamt höchstens 64 KiB Metadaten je Ticket und höchstens 100 Keys; zähle bestehende unveränderte/orphaned Werte mit. JSON-Typen bleiben über Speichern/Laden identisch; verbiete Ressourcen/Objekte, NaN und unendliche Zahlen.

- `Nafinity\Contracts\TicketMetadataStoreInterface::read(int $projectId, array $ticketIds): array` liefert `array<int,array<string,mixed>>`; `write(int $projectId, int $ticketId, array $values, array $resetKeys = []): void` arbeitet innerhalb der bereits geöffneten Domain-Transaktion, startet/committet keine eigene. Alle SQL-Abfragen enthalten `project_id`. Store ist die interne Persistenzgrenze, keine öffentliche Autorisierungsabkürzung.
- Öffentlicher, injizierbarer `TicketMetadataReaderInterface::get(int $projectId, int $ticketId, string $key, mixed $default = null): mixed` und `all(int $projectId, int $ticketId): array` prüft Mitgliedschaft, Ticketzugehörigkeit und Field-Leserecht. Unbekannter Key liefert Aufruferdefault; bekannt aber verboten wirft 403. `all()` enthält nur lesbare aktive Definitionen. Der Reader wird aus Plugins verwendet und steht außerdem über `extensions()->ticketMetadata()` zur Verfügung; die Fassade speichert keine ausgewerteten Requestdaten.
- HTTP-/AI-/Domain-Writes gehen über `TicketService::create()/update()` mit zusätzlichem Payload-Objekt `metadata`, zum Beispiel `{"metadata":{"example.external_id":"CRM-42"}}`. Explizites Zurücksetzen heißt `metadata_reset: ["example.external_id"]`. Native HTML-Namen sind `metadata[example.external_id]` beziehungsweise `metadata_reset[]`. Fehlendes `metadata` ändert nichts. `null` ist ein Wert nur bei nullable Definition; Reset entfernt den gespeicherten Wert und lässt den Default greifen. Set/Reset desselben Keys ist ungültig.
- Bei create: Definitions-/Wertvalidierung einschließlich erforderlicher Felder, Core-Ticket speichern, Metadata speichern, Pivots und Change in **derselben** Access-/EntityManager-Transaktion. Im angelegten Ticket können Widgets anschließend die neue ID verwenden. Vorher keine Uploads oder separaten Widget-Schreibrequests für eine nicht existierende ID.
- Bei update: innerhalb der bestehenden Projektsperre aktuelle Werte laden, Ticket-Version und Boardrevision prüfen, ausschließlich übergebene Metadaten normalisieren/validieren und mit Core-Änderungen atomar speichern. Die vorhandene Ticketversion und Boardrevision steigen wie bisher **einmal je akzeptierter Ticketänderung**, keine zweite widersprüchliche Versionszählung. Metadaten-only Updates zählen ebenfalls. Bei 409/422/403/DB-Fehler bleiben Ticket, Metadaten, Pivots und Activity unverändert.
- Ein Plugin-Feld benötigt zusätzlich zu Core-`write` sein definiertes Schreibrecht; readOnly und archivierte Tickets bleiben gesperrt. Lese-/Schreibrechte gelten auch bei direktem Service-/HTTP-/AI-Aufruf. Requestdaten können Definitionen, Gruppen oder Validatoren nicht austauschen.
- Ergänze BoardQuery::detail() um `metadata` und Field-Definitionen/-Anzeige ohne unberechtigte Werte. Für Board-Badges/Filter lade Metadaten projektgebunden gebündelt für die vorhandenen Karten; kein Query pro Karte. Eine reine Core-Ticketänderung darf unbekannte gespeicherte Plugin-Metadaten nicht löschen oder neu normalisieren.
- Bei fehlendem Plugin werden seine Werte erhalten. Generische Ticket-UI/API geben unbekannte Rohwerte nicht aus; die UI meldet bei vorhandenen unbekannten Keys eine fehlende Erweiterung ohne Werte offenzulegen. Erneute Installation zeigt die alten Werte nach erneuter Rechte-/Typprüfung. Mit neuer Definition inkompatible Altwerte verursachen eine klare Diagnose und werden nicht automatisch umgeschrieben. Erforderliche Felder eines nicht vorhandenen Plugins blockieren keine sonst gültigen Core-Änderungen.

**H — Ticket-Widgets, Upload-Modul und Browser-Lifecycle**

Extrahiere die festen mittleren Ticketbereiche in Templates mit Datenprovidern und registriere sie über **dieselbe** UiRegistry wie Fremdplugins:

| Default-ID | Slot | Index | Anzeige |
|---|---|---|---|
| `core.ticket.links` | `ticket.main.widgets` | 100 | gespeichertes Ticket |
| `core.ticket.attachments` | `ticket.main.widgets` | 200 | gespeichertes Ticket |
| `core.ticket.activity` | `ticket.main.widgets` | 300 | gespeichertes Ticket |

Titel und Beschreibung bleiben davor, Kommentare inklusive Composer danach. Plugins können etwa Index 150 zwischen Verknüpfungen und Uploads verwenden. Gleicher Index sortiert nach ID. Rechtefilter dürfen bei Uploads nicht das gesamte Widget für reine Leser ausblenden: bestehende Dateiliste bleibt mit Leserecht sichtbar, Upload-/Delete-Aktionen benötigen weiterhin `upload`.

- Eingebautes `App\Modules\Attachments\AttachmentsModule` implementiert ExtensionProviderInterface und wird im Default-Durchlauf aus B registriert. Es registriert Widget, Template, Datenprovider und Upload-Asset. Es nutzt AttachmentServiceInterface, vorhandenes AttachmentStorage/NAF Storage und Jobs; kein neues Datei-Backend und keine Migration bestehender Dateien. Backend-Routen, Tabellen und Recovery bleiben kompatibel. Dieses Modul ist zunächst mitgelieferter App-Code, nicht ein zusätzlich zu veröffentlichendes Composer-Paket. Ein Fremdplugin kann seinen Beitrag mit derselben ID ausdrücklich ersetzen/entfernen.
- Beim Entfernen des **Widgets** verschwinden Oberfläche und nicht mehr benötigte Widget-Assets; das ist keine Löschung von Dateien und kein Widerruf serverseitiger Rechte. Ein Service-/Route-Ersatz ist davon getrennt. Dokumentiere diese Grenze.
- BoardQuery bzw. neue Datenprovider vermeiden unnötiges Laden entfernter Widgetdaten beim normalen Rendern. Der vorhandene AI-Ticket-Lesepfad darf weiterhin zulässige Attachment-Metadaten über seinen expliziten Datenvertrag beziehen; keine unbeabsichtigte Backend-Deaktivierung durch eine UI-Änderung.
- Erhalte Upload bei Dateiauswahl, Fortschritt, native Multipart-Fallbacks, private Downloadantwort, Dateinamen, erlaubte MIME-/Extension-Regeln, 10-MiB-Dateigrenze, 30-MiB-Ticket-/200-MiB-Projektquota, staged/ready/deleting, transaktionales Enqueue und erneute Rechteprüfung im Finalizer. Keine öffentlichen Storage-URLs.

Browser-Erweiterungs-API in `app/public/assets/extensions.js`:

- Ein registriertes Modul exportiert `mount(root, context, api)`, Rückgabe ist optional eine Dispose-Funktion. Root ist nur der jeweilige Beitrags-/Feldknoten. Context enthält IDs/Modus, keine zusätzlichen ungeprüften Rechte. API stellt bestehendes Refresh, Toast und die Ticket-Schreibwarteschlange bereit. Kein zweiter Auto-Save-/Fetch-/Versionsmanager für Metadaten.
- Marker `data-extension-id` und registrierte Modulzuordnung aus serverseitigen Definitionen; Modul-URL niemals aus ungeprüften Ticket-/Settingswerten. Stabile Formularfelder bleiben die Quelle von FormData; komplexe Plugin-Typen synchronisieren ihre nativen/hidden Felder vor dem Absenden und validieren serverseitig im FieldType.
- Mount einmal pro DOM-Knoten; Dispose vor Entfernen/Ersetzen. Initiale Seite, Ticket geöffnet, Create→Detail, Settings-Dialog, Fragment-Refresh und Schließen durchlaufen denselben Lifecycle. Importfehler ergeben sichtbare Beitragsdiagnose und blockieren nicht die festen Ticketbereiche. Abhängigkeiten von ES-Modulen sind explizite Imports; Reihenfolge von Tags ersetzt keinen Dependency-Vertrag.
- Integriere neue Metadatenfelder in `ticket/field.phtml` beziehungsweise daraus extrahierte Type-Partials und `ticket.js`, einschließlich erforderlicher Hidden-Token/Version/Boardrevision. Titel-spezifisches required/max200 darf nicht auf jedes neue Textfeld übertragen werden. Keys mit Punkten korrekt in DOM-Selektoren maskieren.
- Bestehende Auto-Save-Serialisierung, 700-ms-/Sofort-Speichern, Native-Form-Fallback, Entwurfserhalt, Fokus, Escape, laufendes Speichern, Boardrevision und 409-Verhalten bleiben bestehen. Keine stillen Wiederholungen nach Konflikt oder nach erfolgreichem Write mit anschließend gescheitertem Refresh.
- Refaktorisiere `ticket.js::refresh()` und den Upload-Refresh in einen gemeinsamen Fragment-Aktualisierungspfad. Neue/entfernte Widgets müssen anhand IDs hinzukommen/verschwinden; aktuelle IDs allein zu durchlaufen genügt nicht. Für einen dirty aktiven Editor wird nur sichere Lesedarstellung/Versionszustand aktualisiert; sein Entwurf bleibt gemountet. Bei Rechteentzug sperre neue Saves und verlange Reload, ohne den Entwurf still zu verlieren. Kommentarentwurf und Antwortbezug bleiben erhalten.
- Plugin-Widgets in `create` sind standardmäßig deaktiviert; ein ausdrücklich für create zugelassener Beitrag kann Anzeige oder Felder des **gemeinsamen** Create-Formulars enthalten, keine verschachtelten Formulare und keine Ticketmutation vor Existenz der Ticket-ID. Unabhängige Widgetformulare sind nur im Detailmodus erlaubt und verwenden geschützte eigene Routen.

**I — Assets und Übersetzungen**

Assets:

- `extensions()->assets()` registriert `AssetDefinition(id, publicPath, kind, index = 100, module = false)` und explizite Paketverzeichnisse. Keine fremden CDN-Skripte nötig. NAFs vorhandenen Asset-Service für unterstützte CSS-/JS-Tags wiederverwenden; das Layout muss dessen Ausgabe tatsächlich rendern. Bestehende statische App-Assets und deren Cache-Buster bleiben funktionsfähig. Vermeide, Querystrings an eine API zu geben, die den Dateityp nur mit pathinfo bestimmt.
- `extensions()->assetPackages()->add(AssetPackage(packageName, directory))` ordnet ein explizites Plugin-Public-Verzeichnis zu. Neue CLI-Kommandos `nafinity:assets:publish`, `nafinity:assets:check`, `nafinity:assets:remove`, jeweils optional `--package=vendor/name`, veröffentlichen nach `app/public/plugins/<vendor>/<name>/`. Reuse NAF CLI, kein eigener Dispatcher. Implementiere nur die hier fehlende Dateiveröffentlichung; kein Asset-Bundler.
- Quellen sind ausschließlich explizite Public-Dateien mit Endungen css/js/json/png/jpg/jpeg/webp/svg/woff/woff2/ico; keine PHP-/Config-/privaten Dateien, keine Traversals oder Symlinks außerhalb der Quellwurzel. Exakte Hash-/Eigentumsliste in privatem `app/storage/plugin-assets/`. Prüfe alle Zielkonflikte vor Änderungen; Host-veränderte Dateien nicht überschreiben/entfernen. Wiederholung ist idempotent. Remove ohne installiertes Plugin funktioniert aus dem gespeicherten Manifest und löscht ausschließlich unveränderte eigene Dateien. Assets sind öffentliche Daten, nie Uploads.
- Production-/Candidate-Build kopiert/veröffentlicht registrierte Plugin-Assets über denselben Befehl vor Fertigstellung des Images. Prüfung in einem Image ohne Source-Mounts; lokale Source-Symlinks sind kein Distributionsnachweis.

Übersetzungen:

- Der generische Translator liest derzeit nur App-Dateien. Ergänze deshalb in **naf/i18n**, nach dessen eigenem RC-Workflow, `Naf\I18n\translation_paths(): TranslationPathRegistry` mit `add(string $id, string $directory, int $index = 100, bool $replace = false)`, `all()` und `remove()` nach B-Regeln.
- Lese registrierte Verzeichnisse aufsteigend nach Index/ID, danach das bisherige App-Verzeichnis. Späterer Wert gewinnt, App gewinnt zuletzt. Vorhandene JSON-/Sprach-/Fallback-Regeln erhalten. Defekte Dateien melden den konkreten Pfad; keine Dateien im Plugin verändern. Registry-Revision invalidiert Übersetzungscache bei Registrierung/Entfernung.
- Locales::available() in Nafinity erfasst zusätzlich Sprachen aus den registrierten Verzeichnissen und bietet nur tatsächlich vorhandene Übersetzungsdateien an. Vorhandene Language-Namen/Flags weiterverwenden. Locale-Auswahl, PreferenceService und PageRenderer verwenden dieselbe Liste. Ein Plugin mit französischer Übersetzung muss ohne Änderung einer App-Allowlist auswählbar sein; App-Overrides gewinnen nachweisbar.

**J — Weitere tatsächlich vorhandene Erweiterungsbereiche**

Board-Filter:

- `extensions()->boardFilters()` enthält Definitionen mit ID, Label, Index, View, `normalize(mixed): mixed` und `condition(mixed $value, BoardFilterContext $context): SqlCondition`. SqlCondition enthält ausschließlich SQL-Fragment plus Positionsparameter; Definitionen sind vertrauenswürdiger Code. Kontext nennt den festgelegten Ticketalias `t` und das bereits autorisierte Projekt.
- Migriere die vorhandenen Filter aus TicketFilter/BoardQuery in explizite Definitionen. Das gemeinsame normalisierte Resultat wird für Filterdarstellung, Count und Kartenquery verwendet. Plugin-Filter kommen als `filters[example.external_id]`, Core-Queryparameter bleiben kompatibel. Ein unbekannter neuer Filter meldet 422 statt still ignoriert zu werden.
- Die zentrale Bedingung `t.project_id = ?` und vorhandene Archiv-/Limit-Regeln bleiben außerhalb des Pluginfragments bindend. Kombiniere jedes Pluginfragment geklammert mit AND und Parametern; keine direkten Benutzereingaben als SQL/Spaltennamen. Beispiel A filtert seine Metadata über projekt-/ticketgebundenes EXISTS. Ein Filter aktiviert dieselbe DnD-Einschränkung und denselben Filterhinweis wie bestehende Filter. Kartenmaximum 300, Count und Reihenfolge bleiben erhalten.

Schätzung:

- `extensions()->estimationScales()` mit `EstimationScale(id, label, unit, values, index = 100)`. Defaults none/complexity/points aus Estimation übernehmen. Values sind eindeutige aufsteigend sortierte Integer zwischen 0 und bestehendem MAX; none ist einzige leere Default-Skala. Projektvalidierung, Settingsauswahl, Ticketfeld, Boardkarten und Spaltensumme konsumieren dieselbe Definition.
- Bestehende Estimation-Methoden bleiben als Fassade kompatibel. Ein Skalenwechsel verändert weiter keine gespeicherte Schätzung ohne explizites Remap; Remap verwendet den bisherigen nächsten Wert mit kleinerem Wert bei Gleichstand. Bei fehlendem Plugin bleiben Skalen-ID und Werte gespeichert, sichtbar als nicht verfügbare Skala; kein stilles Umschreiben auf none und kein automatisches Remap.

Events, Activity und Notifications:

- Nutze das vorhandene `Naf\event()->listen('nafinity.changed', ..., priority: ...)` und `App\Domain\Change`. **Der Event läuft innerhalb der Domain-Transaktion.** ActivityListener und NotificationService benötigen dies. Ändere ihn nicht in einen After-Commit-Event und verschlucke seine Exceptions nicht: Fehler müssen die Gesamtänderung weiterhin zurückrollen.
- Neue Ticket-Metadatenänderungen ergänzen den vorhandenen Change-Payload um betroffene Key-Namen; keine sensiblen Settingswerte oder vollständigen Requestdaten. Projektbezogene Settingsänderungen erzeugen einen passenden Change innerhalb derselben Transaktion. Benutzerpersönliche Einstellungen erzeugen keinen gefälschten Projekt-Change.
- Plugin-Listener können mit NAF Queue innerhalb derselben PDO-Transaktion einen eigenen Job einstellen; erst der Job erledigt externe Arbeit. Keine Mail/Webhooks während des Change-Listeners. Bestehendes transaktionales Queue-Enqueue nutzen, keine neue Outbox/After-Commit-Infrastruktur erfinden. Duplikate/Fehler/Retry und Rechteentzug im Job berücksichtigen.
- `extensions()->activityTypes()` mit ID=Eventtyp, Label, Icon und optionalem Data-Provider/View öffnet die bisher feste ActivityLabel-Liste. Unbekannte Typen erscheinen mit ihrem escapten Namen statt irreführend als „Projekt aktualisiert“. Projekt-/Ticketgrenzen und die bestehende Empfängerlogik bleiben erhalten; zusätzliche Benachrichtigungskanäle werden bei Bedarf durch den NotificationService-Contract und eigene Jobs umgesetzt, kein paralleles Notify-System.

Lokale AI und MCP:

- `extensions()->aiTools()` registriert `AiToolProviderDefinition(id, provider, index = 100)`. Provider implementiert `AiToolProviderInterface::tools(AiToolContext $context): iterable`; Kontext enthält den aktuellen autorisierten Actor und optionalen ProjectScope. Rückgabe ist `iterable<Nafinity\Contracts\ProjectToolInterface>`. Dieser neue Contract erweitert das bestehende NAF ToolInterface um `title(): string`, `permission(): string`, `requires(): array` und `keywords(): array`; die beiden Arrays sind Listen von Strings. `App\Ai\ProjectTool` implementiert ihn mit zusätzlichen Gettern und erhält seine bestehenden öffentlichen Properties/Constructor-Aufrufe. AiService benutzt nur den Contract, damit keine konkreten finalen Typannahmen neue Tools sperren.
- Die bisherige feste AiService-Liste wird ein Standardprovider. AiService baut pro Anfrage daraus dieselbe native NAF ToolRegistry, filtert Rechte **vor Auslieferung und erneut vor Ausführung**, prüft aktuell autorisierte Abhängigkeiten und blockiert fehlende prerequisites. Doppelte Toolnamen benötigen expliziten Ersatz in der Provider-Definition; kein zufälliger Last-Wins-Effekt. Ergänze dafür `replaceNames: list<string>` an AiToolProviderDefinition.
- Ein Tool mit Schreibrecht benötigt weiterhin serverseitig `confirmed === true`. Browser-Router/Keywords und Sortierindex erteilen keine Berechtigung. Rate Limit, Argumentvalidierung, Projektgrenzen und Versionskonflikte bleiben. Ein eigenes Plugin-Tool funktioniert auch ohne Änderung am AI-JavaScript-Katalog. Keine fremden sensiblen Settings automatisch an Modelle geben.
- Öffentlicher tokenbasierter `/mcp`-Zugang bleibt getrennt vom Session-Chat und wird durch Pluginregistrierung nicht geöffnet. Native NAF-MCP-Registrierung außerhalb dieses Chats bleibt nutzbar.

Andere NAF-Integrationen:

- Beispiel A liefert einen normalen Command, eine Plugin-Migration, einen Queue-Job und einen Scheduler-Eintrag über die vorhandenen NAF-APIs. Domain-Code in App-/Plugin-Services, keine neuen Dispatcher. Beispiel B dekoriert einen tatsächlichen App-Service und ergänzt/ersetzt sichtbare Beiträge.
- Native Auth-/Session-/LDAP-/OAuth-/HTTP-/Mail-/Storage-Verträge und CSS-Variablen weiterverwenden. Für diese Bereiche werden keine zusätzlichen Login-, Icon-, Theme- oder Transport-Registries ohne tatsächliche Lücke gebaut. Verifiziere, dass die eingeführten Service-Contracts auch Hintergrund-/AI-Verbraucher erreichen.

**K — Lebensdauer, Beispiele und Dokumentation**

- Definiere eine frische App-Initialisierung als Grenze der Provider-/Registry-Lebensdauer. Ein Registry-Reset baut Defaults und aktuell installierte Provider neu auf. Bereits laufende Prozesse werden nach Code-/Pluginwechsel über `make restart-background` neu gestartet. Niemals fremde Benutzer-/Projektwerte im Singleton halten; Context-Objekte sind unveränderlich.
- Deinstallation entfernt keine Settings, Metadaten, Ticketdateien, Rollen-Grants, Activities oder Jobs automatisch. Bereits eingereihte Jobs eines fehlenden Plugins dürfen nicht als erfolgreich verworfen werden; bestehende Queue-Failure/Retry-/Deadletter-Wege verwenden. Nach Neuinstallation sind erhaltene Daten wieder verfügbar, sofern Definition und Rechte dies erlauben.
- Beispiel A enthält: eigene Reports-Page mit Controller-DI und PageRenderer; Projektpermission `example.reports.view`; Sidebar-Eintrag; persönliche und Projekt-Settings; zusätzliche Settingskarte; Ticketfelder `example.external_id` (Text) und `example.reviewed` (Boolean); Widget Index 150 zwischen Links und Anhängen; Boardbadge und Metadata-Filter; eigenes Tool; französische Übersetzung; Command/Migration/Job/Scheduler-Eintrag.
- Beispiel B mit Provider-Index 200 dekoriert TicketServiceInterface, ersetzt ein benanntes UI-Default durch einen kompatiblen Beitrag, ergänzt ein zweites Ticketwidget mit demselben Index wie A und ersetzt einen ausdrücklich benannten View-/Settings-Beitrag. Ein Host-Override in `app/app/extensions.php` setzt einen ausgewählten Wert zuletzt. Reales Add/Replace/Remove und Gleichstandsreihenfolge sind sichtbar.
- Dokumentiere in `docs/Plugin-Erweiterbarkeit.md` Installation, echte Composer-Dateien, vollständige Namespaces/Signaturen, Provider-Boot, Index-/Konfliktregeln, Scope-/Grant-Modell, Settings-PHP-/HTTP-Beispiele, Snapshot-Collection, Metadatenpersistenz, Upload-Modul, Browser-Lifecycle, Assets, Migrationen, Jobs und Deinstallation. Jeder neue Erweiterungspunkt braucht ein ausgeführtes Beispiel und einen negativen Testfall.
- Aktualisiere `docs/Settings-AI.md`, `docs/Ticket-Details.md`, `docs/Implementation.md`, README und den Statusnachweis. Korrigiere veraltete Aussagen dort, wo die neuen Funktionen sie ändern. Reine Framework-Dokumentation gehört in den NAF-Docs-Workflow; Nafinity-Dokumentation bleibt in Nafinity und wird nicht automatisch als NAF-Website veröffentlicht.
- Bei generischen Framework-/i18n-Änderungen deren Tests/Docs/RC-Workflow vollständig erfüllen. Anleitung für neue unveröffentlichte Paket-APIs darf vorbereitet und geprüft werden, aber keine bereits verfügbare stabile Installation vortäuschen. Keine ungesicherte Übernahme alter Versions-/Release-Aussagen aus bestehenden Dokumenten.

**L — Verbindliche Abnahme und Übergabe**

T01–T32 sind Pflicht. Weise im Statusdokument jeweils echte Testnamen und Ergebnisse nach:

| ID | Nachweis |
|---|---|
| T01 | Echter Nafinity-Boot mit installierten A/B; App-Defaults → A → B → Host; idempotente Initialisierung und verständlicher Providerfehler |
| T02 | Eigene Controller-DI und benannte Parameter über echte HTTPS-Route; App-Shell über PageRenderer |
| T03 | Spätes gleichnamiges Route-Override wirkt, Remove wirkt, ursprüngliche Callable-/Core-Routen bleiben erreichbar |
| T04 | Explizites Controller-Binding wird dispatcht; ungebundener Fallback und echte Factory-Exceptions funktionieren |
| T05 | Jeder Contract aus C wird durch einen produktiven HTTP-/AI-/Job-Konsumenten ersetzt/dekoriert, nicht nur per Container-get getestet |
| T06 | Menübeitrag in Workspace/Projekt/Footer, aktiver Zustand, Index-Gleichstand, Replace/Remove und eigener Permissionfilter |
| T07 | Plugin-Grant wird gespeichert/geladen; ohne Grant 403, fremdes Projekt 404; Owner-/Delegationsschutz bleibt |
| T08 | View-Mapping wirkt auf Vollseite/Partial/Layout; Host gewinnt zuletzt; Zyklus wird diagnostiziert |
| T09 | Settingskarte und Feldtypen aus A/B erscheinen sortiert und speichern; keine neue if-Liste je Plugin |
| T10 | settings()->get/all/has und Collection-Snapshot liefern dieselben Werte; dokumentierte Collection-Null-Semantik, Config/Defaults, false/0/Leerstring/Punktkeys getestet |
| T11 | User/Project/ProjectUser/Application-Kontexte sind getrennt; fremder Benutzer/Projekt und Rechteentzug lecken keine Werte |
| T12 | Settings-Teiländerung/Reset, Legacy-Language-Save, gleicher Request und Actorwechsel; keine alten Cachewerte oder verlorenen Fremdfelder |
| T13 | Settings-HTTP liest nur freigegebene Keys, keine Secrets/Config-Dumps; POST-Validierung/CSRF; kein Teilwrite bei Fehler |
| T14 | Collection-Snapshot ist bestehende NAF-Collection; lokale Mutation persistiert nichts; browserlokale AI bleibt browserlokal |
| T15 | Ticket-Metadaten über Create/Read/Update/Reset auf MariaDB und PostgreSQL, JSON-Typen und Größenlimits |
| T16 | Metadaten-only und kombinierte Ticketänderung sind atomar; Version/Boardrevision einmal; 409/422/403 erzeugen keine Teiländerung/Activity |
| T17 | Metadata-Leserechte/Schreibrechte, fremde IDs, Archiv, erforderliche/read-only Felder, gesperrte Core-Attribute |
| T18 | Zwei Widgets mit gleichem Index, Einfügung bei 150, Replace/Remove; Titel/Beschreibung/Kommentare bleiben fest |
| T19 | Rechte Sidebargruppen und Core-/Plugin-Felder sind sortierbar; move/Pivots/Timer/Schätzung behalten vorhandene Fachregeln |
| T20 | Upload-Modul nutzt echte Registry; Auswahl/Progress/Native-Fallback, private Downloads, Quotas, Recovery und Rechteentzug weiterhin wirksam |
| T21 | Vollseite, Drawer, Create→Detail, Settingsdialog, Refresh und Dispose funktionieren ohne doppelte Listener/Assets |
| T22 | Metadaten-Auto-Save, Entwurf/Fokus/Kommentarantwort, langsame Antworten, Schließen, 409 und erfolgreicher Write mit fehlgeschlagenem Refresh |
| T23 | Plugin-Filter wirkt auf Count/Karten/Badge und DnD-Hinweis; SQL-Parameter und Projektgrenze bleiben bindend |
| T24 | Plugin-Schätzungsskala ist überall dieselbe; fehlendes Plugin/Skalenwechsel erhält Werte; Remap nur ausdrücklich |
| T25 | nafinity.changed bleibt transaktional; Plugin-Listener/Queue-Job, Rollback und lesbare eigene Activity getestet |
| T26 | Eigenes AI-Tool erscheint/arbeitet nur mit aktuellem Grant, passenden prerequisites und Schreibbestätigung; Token-MCP bleibt getrennt |
| T27 | Plugin-Übersetzung/Locale funktioniert, App-Override gewinnt; Registrywechsel invalidiert Cache; keine Vendor-Datei verändert |
| T28 | Asset-Publish/Check/Remove über echten CLI-Harness, zwei Pakete, Hostkonflikt, Pfadgrenzen, idempotent und nach Uninstall |
| T29 | Plugin-Command/Migration/Job/Scheduler funktionieren nach echtem Boot; Service-Ersatz erreicht Worker |
| T30 | Neustart ohne A/B entfernt Registrierungen, erhält Werte/Dateien/Grants; fehlende Typen/Jobs werden nicht still erfolgreich verworfen |
| T31 | Reale Browserprüfung Desktop/320px/390px, Light/Dark, Tastatur und native Formularfallbacks mit echten App-Templates/Assets |
| T32 | Vollständige Dokumentationsbeispiele und Source-Candidate mit veröffentlichten Plugin-Assets; tatsächliche Distribution klar von Source-Snapshot getrennt |

Nutze vorhandene Make-Ziele und erweitere deren Harnesses: `make test` umfasst HTTP/MariaDB, Profile, PostgreSQL, Worker und AI; `bin/style check`/`make style-check`; `git diff --check`; Composer-Validierung im Container für echte und generierte Testmanifeste. Keine erfundenen `analyse`-/PHPUnit-Scripts für Nafinity. Für geänderte NAF-Pakete deren tatsächliche Composer-Scripts und unterstützte PHP-Versionen prüfen. Browser-/Pluginprüfungen, die noch nicht im Makefile existieren, als reproduzierbaren zusätzlichen Harness und `make test-plugins` integrieren und in die Gesamtabnahme aufnehmen.

Datenbank-/HTTP-Tests laufen ausschließlich im vorhandenen `nafinity_test`-Kontext. Nicht einfach `tests/run.php` mit produktiver Konfiguration starten. Isolierte Test-/Candidate-Dienste lassen Hintergrundprozesse gemäß bestehendem Setup ausgeschaltet. Keine echte Mailzustellung, externen Webhooks oder laufenden Modelle für die automatisierte Abnahme. Bestehende lokale Testtransporte verwenden.

Nach allen Änderungen: CI/Tests für den aktuellen Commit prüfen, fachlich verständliche Commits erstellen, Branches pushen, Nafinity-PR und gegebenenfalls NAF-RC-PRs erstellen/aktualisieren und gegenseitig verknüpfen. Kein eigenständiger Merge/Release der Anwendung oder Pakete. Dokumentationsrechte nicht zwischen unabhängigen Repositories übertragen.

Die Abschlussantwort nennt die tatsächlich nutzbaren APIs, A–L/T01–T32-Ergebnisse, Branches/Commits/PRs, ausgeführte Laufzeiten/Prüfungen, Dokumentationsstand und konkrete verbleibende Maintainer-Aktionen. Nicht ausgeführte oder fehlgeschlagene Prüfungen bleiben offen; kein pauschales „alles erweiterbar“. Der Auftrag ist fachlich erst abgeschlossen, wenn alle neuen Registries im realen Produktablauf konsumiert und mit beiden Fremdplugins getestet sind.
