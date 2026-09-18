# Plugin-Erweiterbarkeit

Nafinity ist durch installierte Composer-Pakete erweiterbar. Ein Paket bringt
eigene Controller, Routen, Dienste, Menüeinträge, Settings, Ticketfelder,
Widgets, Filter, Übersetzungen, AI-Werkzeuge, Commands, Migrationen und Jobs mit
und kann vorhandene Definitionen ausdrücklich ersetzen.

Zwei vollständige Beispiele liegen im Repository und werden von der Abnahme
tatsächlich installiert und ausgeführt:
[`examples/nafinity-extension-a`](../examples/nafinity-extension-a) und
[`examples/nafinity-extension-b`](../examples/nafinity-extension-b).

## Inhalt

1. [Installation](#installation)
2. [Der Registrierungszeitpunkt](#der-registrierungszeitpunkt)
3. [Gemeinsame Registry-Regeln](#gemeinsame-registry-regeln)
4. [Routen, Controller und Dienste](#routen-controller-und-dienste)
5. [Rechte](#rechte)
6. [Oberflächenbeiträge und Menü](#oberflächenbeiträge-und-menü)
7. [Settings](#settings)
8. [Ticket-Metadaten](#ticket-metadaten)
9. [Ticket-Widgets und Browser-Lifecycle](#ticket-widgets-und-browser-lifecycle)
10. [Views](#views)
11. [Board-Filter](#board-filter)
12. [Schätzung, Verlauf und AI](#schätzung-verlauf-und-ai)
13. [Assets](#assets)
14. [Übersetzungen](#übersetzungen)
15. [Migrationen, Commands, Jobs](#migrationen-commands-jobs)
16. [Lebensdauer und Deinstallation](#lebensdauer-und-deinstallation)
17. [Negativfälle](#negativfälle)

## Installation

Ein Erweiterungspaket ist ein gewöhnliches Composer-Paket mit `type: naf-plugin`.
NAF entdeckt es über `InstalledVersions::getInstalledPackagesByType()`; eine
zusätzliche Manifestdatei gibt es nicht.

```json
{
  "name": "example/nafinity-extension-a",
  "type": "naf-plugin",
  "require": {
    "php": ">=8.3",
    "naf/framework": "^0.2.4"
  },
  "autoload": {
    "psr-4": { "Example\\ExtensionA\\": "src/" }
  }
}
```

Nafinity ist die Host-Anwendung. Ein Paket verlangt `fkde/nafinity` nicht als
Composer-Abhängigkeit — das ergäbe einen Zyklus — sondern nennt die Anforderung
in seiner README und in `extra.nafinity.host`. Welche NAF-Pakete es wirklich
braucht, steht dagegen im `require`.

Lokal wird ein Paket über ein Path-Repository installiert:

```json
{
  "repositories": [{ "type": "path", "url": "../examples/nafinity-extension-a" }],
  "require": { "example/nafinity-extension-a": "*" }
}
```

Danach einmalig Migrationen anwenden und Assets veröffentlichen:

```sh
php vendor/bin/naf db:migrate up
php vendor/bin/naf nafinity:assets:publish --package=example/nafinity-extension-a
```

## Der Registrierungszeitpunkt

NAF bootet Composer-Plugins **vor** den Anwendungsdefaults. Wer dort direkt
registriert, wird gleich darauf wieder überschrieben. Deshalb merkt ein Paket in
seiner `bootstrap.php` nur einen Provider vor:

```php
// examples/nafinity-extension-a/bootstrap.php
use Example\ExtensionA\ExtensionAProvider;

use function Nafinity\extensions;

extensions()->register('example.reports', ExtensionAProvider::class, 100);
```

`Nafinity\extensions(): Nafinity\ExtensionRegistry` bindet beim ersten Aufruf
eine reine Metadaten-Registry an den Container. Sie ist schon während des
Plugin-Boots erreichbar und bootet die Anwendung nicht erneut.

```php
public function register(
    string $id,
    string $providerClass,
    int $index = 100,
    bool $replace = false,
): void;
```

Der Provider selbst:

```php
namespace Nafinity\Contracts;

interface ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void;
}
```

`ExtensionContext` trägt den Container und alle Registries. Er trägt **keinen**
Benutzer und führt keine Autorisierung aus: Definitionen sind Code, Benutzerdaten
werden erst im jeweiligen Request gelesen.

Die Reihenfolge in [`app/bootstrap.php`](../app/bootstrap.php) ist festgelegt:

1. Composer-/NAF-Boot und die vorhandenen App-Routen (im `app()`-Konstruktor),
2. lazy gebundene App-Service-Defaults, Policies und native Infrastruktur,
3. Nafinitys eingebaute Beitragsdefinitionen (`App\Modules\NafinityDefaults`),
4. vorgemerkte Provider, aufsteigend nach Index und ID,
5. optional [`app/app/extensions.php`](../app/app/extensions.php) als letzte
   Host-Override-Datei,
6. `app()->run()`.

Eigenschaften dieses Durchlaufs:

- Ein zweiter `initialize()`-Aufruf führt keinen Provider erneut aus.
- Eine Registrierung **nach** dem Durchlauf wirft eine `LogicException`, die die
  Extension-ID nennt. Neue Definitionen in vorhandenen Registries bleiben bis
  zur Requestverarbeitung möglich.
- Eine Ausnahme im Provider nennt Extension-ID, Providerklasse und Ursache und
  lässt den Boot scheitern. Es gibt keinen stillen Teilbetrieb.
- Rekursive Initialisierung wird erkannt und abgewiesen.

## Gemeinsame Registry-Regeln

Alle Beitragsregistries teilen dieselben Regeln
([`DefinitionRegistry`](../app/app/Extensions/Registry/DefinitionRegistry.php)):

```php
$registry->add(object $definition, bool $replace = false): void;
$registry->get(string $id): ?object;
$registry->all(): array;   // nach index, dann ID; ID-Schlüssel bleiben erhalten
$registry->remove(string $id): bool;
$registry->has(string $id): bool;
```

- **Ein Sortierwert:** `index`, aufsteigend; bei Gleichstand `strcmp()` über die
  ID. Default 100, negative Werte erlaubt. Es gibt kein zweites `priority`-Feld.
  NAFs Event-Priorität (höhere Werte zuerst) bleibt davon unberührt, ebenso die
  Kartenpositionen auf dem Board.
- Eine doppelte ID ohne `replace: true` wirft eine `LogicException`.
- `remove()` auf eine unbekannte ID liefert `false`.
- IDs sind nicht leer, stabil und für Plugins namensräumlich: `example.reports`.
- Ein Ersatz ersetzt genau eine Definition; es gibt keine rekursive
  Array-Mischung.
- Defaults werden genau einmal vor den Plugins registriert; die letzte
  ausdrückliche Registrierung gewinnt, und der Host kommt zuletzt.

## Routen, Controller und Dienste

Routen registriert ein Plugin im Provider — also nach den App-Routen, weshalb
ein gleichnamiger Ersatz tatsächlich wirkt:

```php
route()->add('GET', '/projects/{project}/reports', [ReportController::class, 'show'], 'example.reports');
```

Ein Controller ist eine gewöhnliche Klasse. Parameter kommen nach Namen aus der
Route, Dienste über Constructor Injection:

```php
final class ReportController
{
    public function __construct(
        private ReportService $reports,
        private AccessInterface $access,
        private PageRendererInterface $pages,
    ) {
    }

    public function show(string $project): ResponseInterface
    {
        $scope = $this->access->project(Input::id($project), ExtensionAProvider::PERMISSION);

        return $this->pages->render('example-a/reports', ['title' => 'Berichte', 'scope' => $scope]);
    }
}
```

`Naf\Core\Route::remove(string $name): bool` nimmt eine benannte Route zurück.
Der Dispatcher bevorzugt eine **gebundene** Zielklasse (`$container->get()`) und
baut alles Ungebundene wie bisher; ein gebundenes Objekt ohne die Aktion der
Route wird als `DispatcherException` gemeldet. Beides ist in `naf/framework` ab
0.2.4 enthalten.

### Austauschbare Dienste

Zwölf Contracts unter `Nafinity\Contracts` beschreiben die tatsächlichen
Austauschgrenzen: `AccessInterface`, `AccountServiceInterface`,
`ProjectServiceInterface`, `TicketServiceInterface`, `BoardQueryInterface`,
`CommentServiceInterface`, `AttachmentServiceInterface`,
`NotificationServiceInterface`, `PreferenceServiceInterface`,
`RoleServiceInterface`, `TimerServiceInterface`, `AiServiceInterface`. Dazu
kommen `PageRendererInterface`, `SettingsServiceInterface`,
`SettingsStoreInterface`, `TicketMetadataReaderInterface` und
`TicketMetadataStoreInterface`.

Jeder ist lazy gebunden — einmal unter der Defaultklasse, einmal unter dem
Contract. Ersetzen oder dekorieren geschieht im Provider:

```php
$inner = $context->container()->get(TicketServiceInterface::class);

$context->container()->set(
    TicketServiceInterface::class,
    static fn() => new CountingTicketService($inner),
);
```

Alle produktiven Verbraucher fragen den Contract: Controller, Dienste
untereinander, `FinalizeAttachmentJob`, `MaintenanceJob`, `SeedCommand` und der
Readiness-Endpunkt. Ein Ersatz erreicht damit auch den Worker.

Auth, Session, Storage, Client, Mail und Logging bleiben die NAF-Verträge; dafür
gibt es keine zweite Implementierung. Overrides müssen **vor** ihrer ersten
Nutzung registriert sein: eine bereits konstruierte Instanz wird nicht
nachträglich umverdrahtet.

### Seitenrenderer

```php
namespace Nafinity\Contracts;

interface PageRendererInterface
{
    public function render(string $template, array $data = []): ResponseInterface;
    public function fragment(string $template, array $data = []): string;
}
```

`render()` liefert die vollständige Seite mit der App-Shell: Login-Verhalten,
Sprache, eigene Projekte, laufender Timer, Benutzer und Preferences kommen vom
Renderer. Übergebene Daten werden zuerst ausgebreitet, ein fremdes
`$data['user']` überschreibt die Shell-Daten also nicht. `fragment()` löst nur
das View-Mapping auf und rendert ein Partial. Projekt- und
Datensatzautorisierung bleibt Sache des Controllers.

## Rechte

```php
$context->permissions()->add(new PermissionDefinition(
    'example.reports.view',
    'Berichte dieses Projekts ansehen',
    'Erklärungstext',
    800,
    ownerOnly: false,
));
```

- Registrierte Rechte erscheinen im Rolleneditor, werden gespeichert und von
  `Access::permissions()` als wirksam erkannt.
- **Ein neues Recht wird niemandem automatisch erteilt** — auch Owner und
  Manager bekommen es nicht. Ein Owner kann es einer eigenen Rolle zuweisen.
- `read` bleibt die Mitgliedschaftsprüfung und ist kein Definitionsname.
- `ownerOnly: true` bleibt eigenen Rollen verwehrt; `moderate` verlangt weiter
  `comment`; archivierte Projekte behalten ihre Schranken.
- `App\Domain\ProjectPermissions` bleibt als kompatibler Zugang zu den
  eingebauten Defaults bestehen.
- Ein gespeicherter Grant eines fehlenden Plugins wird **nicht gelöscht** und
  autorisiert nichts. Beim Speichern einer Rolle bleibt er erhalten, das
  Formular meldet ihn als nicht verfügbare Definition, und eine erneute
  Installation macht ihn wieder wirksam.
- UI-Verbergen ersetzt keine Prüfung: fremdes Projekt bleibt 404, fehlendes
  Recht 403.

## Oberflächenbeiträge und Menü

```php
$context->ui()->add(new UiContribution(
    id: 'example.reports.widget',
    slot: 'ticket.main.widgets',
    template: 'example-a/ticket-widget',
    index: 150,
    provider: ReviewWidgetProvider::class,
    permission: 'read',
    modes: [UiContext::MODE_DETAIL],
    module: '/plugins/example/nafinity-extension-a/review.js',
));
```

Feste Slots:

| Slot | Einbauort |
|---|---|
| `sidebar.workspace` | Workspace-Einträge im linken Menü |
| `sidebar.project` | Navigation für das ausgewählte Projekt |
| `sidebar.footer` | Unterer Menübereich |
| `topbar.actions` | Aktionen in der oberen Leiste |
| `projects.actions`, `projects.card.badges` | Projektübersicht |
| `board.actions`, `board.card.badges`, `board.card.details`, `board.card.actions`, `board.column.summary` | Board |
| `ticket.actions` | Ticket-Aktionsmenü |
| `ticket.main.widgets` | Zwischen Beschreibung und Kommentaren |
| `ticket.sidebar.panels` | Gruppen der rechten Ticketleiste |
| `profile.panels` | Profil-Dialog |
| `notifications.actions`, `activity.actions` | Benachrichtigungen und Verlauf |

Ein Datenprovider liefert die Templatedaten:

```php
interface UiDataProviderInterface
{
    public function data(UiContext $context): array;
}
```

`UiContext` enthält den geprüften Actor, optional den `ProjectScope`, den Modus
(`page`, `detail`, `create`), den aktuellen Routennamen und optional die
Datensatz-ID. **Rechte- und Modusfilter greifen vor der Auflösung des
Providers** — ein unsichtbarer Beitrag führt keinen Code aus. `permission: null`
bedeutet „keine zusätzliche Projektaktion", niemals anonymes Lesen. Sichtbarkeit
ist keine Schreibberechtigung.

Für Menüeinträge gibt es eine semantische Definition:

```php
$context->navigation()->add(new NavigationItem(
    'example.reports.link',
    'sidebar.project',
    'Berichte',
    'example.reports',
    static fn(UiContext $c) => ['project' => $c->projectId()],
    'insights',
    ['example.reports'],
    150,
    'example.reports.view',
));
```

`routeParams` darf eine Closure sein; sie wird erst **nach** der Autorisierung
ausgewertet, damit keine Projekt- oder Ticket-ID beim Boot festgehalten wird.
Beide Beitragsarten werden gemeinsam nach Index und ID sortiert; kollidierende
IDs werden abgewiesen. Icons kommen aus dem vorhandenen `Icon::mark()`.

Die dynamische Projektliste bleibt ein `BoardQuery`-Ergebnis und wird nicht zur
Registry.

### Feste Ticketbereiche

`core.ticket.title`, `core.ticket.description` und `core.ticket.comments` sind
reserviert. Ein Versuch, sie über die Ui- oder Feldregistry zu ersetzen oder zu
entfernen, wirft eine `LogicException`; ebenso ein Ticketfeld namens `title`,
`description` oder `comments`. Ein vollständiges Template-Override bleibt
möglich, muss diese Bereiche aber bewahren.

## Settings

```php
use function Nafinity\settings;

settings()->get('theme', 'system');                 // eigener Benutzer
settings()->all();                                  // array<string, mixed>
settings()->has('theme');                           // registriert und lesbar, auch bei null
settings()->collection();                           // Naf\Support\Collection als Snapshot
settings()->forProject($projectId)->get('name');
settings()->forProjectUser($projectId)->get('muted');
settings()->forApplication()->get('mail_enabled');  // nur deklarierte Konfigwerte
settings()->save(['theme' => 'dark'], resetKeys: []);
```

Kontextmethoden liefern **neue unveränderliche Instanzen**; das Helper-Objekt
ändert sich nicht. Es gibt keinen impliziten Projektwechsel über einen
URL-Parameter und keine `forUser($fremdeId)`-Abkürzung: Benutzerkontext ist immer
die angemeldete Person. `forProject()` prüft die aktuelle Mitgliedschaft.

### Definitionen

```php
$context->settings()->add(new SettingDefinition(
    key: 'example.reports.limit',
    scope: 'project',            // user | project | project_user | application
    section: 'example.reports',
    label: 'Zeilen im Bericht',
    type: 'integer',
    default: 25,
    index: 100,
    options: ['min' => 5, 'max' => 200],
    readPermission: null,
    writePermission: 'example.reports.view',
    sensitive: false,
    configKey: null,
));
```

- Identität ist `(scope, key)`. Derselbe Schlüssel darf in mehreren Scopes
  existieren; doppelte Schlüssel innerhalb eines Scopes werden auch über
  verschiedene Abschnitte hinweg abgewiesen.
- Punkte im Key sind wörtliche Schlüssel, keine verschachtelte Suche.
- **Wertepriorität je Scope:** gespeicherter Wert → deklarierter
  NAF-Config-Key → Definitionsdefault. Nur bei *unbekanntem* Schlüssel liefert
  `get()` den Aufruferdefault.
- `null`, `false`, `0` und `''` sind gültige Werte und werden nicht durch
  Defaults ersetzt.
- Rechte: bei `user`/`project_user` bedeutet ein fehlendes Extra-Recht Zugriff
  auf eigene Werte. Bei `project` verlangt Schreiben standardmäßig `manage`; ein
  ausdrücklich angegebenes Plugin-Schreibrecht gilt für dessen eigenen Key.
  Legacy-Projektfelder verlangen unveränderlich mindestens `manage`.
  Projektlesezugriffe verlangen immer Mitgliedschaft.
- `application` ist read-only, hat keine HTTP-Route und ist in der CLI ohne
  Session lesbar. Eine globale Schreibberechtigung gibt es nicht.
- `sensitive: true` verlässt den Server nie: nicht in `all()`, nicht über HTTP,
  nicht in Logs, Events oder Fehlern. Trusted serverseitiger Code darf einen
  solchen Wert nach normaler Scope-Prüfung einzeln lesen. Im Formular bleiben
  sensible Eingaben leer; ein nicht mitgesendeter Wert bleibt unverändert.

### Speicher

Bestehende Werte bleiben, wo sie sind: `theme`, `locale`, `timezone`,
`notify_in_app`, `notify_mail` in `user_preferences`; `muted` in
`project_preferences`; `name`, `description`, `color`, `icon`, `ticket_key`,
`estimation_scale` im Projektdatensatz über `ProjectService`. Einen zweiten,
widersprüchlichen Speicher gibt es nicht — `PreferenceService` und
`SettingsService` teilen sich dieselbe interne Persistenz
([`PreferenceStore`](../app/app/Support/Settings/PreferenceStore.php)).

Neue Plugin-Werte liegen in `user_settings`, `project_settings` und
`project_user_settings`: je eine Zeile pro Schlüssel, `value_json TEXT`,
höchstens 16 KiB pro Wert. Keine DDL pro Feld.

Ein gemischter Schreibvorgang läuft in **einer** Transaktion: bei Projektwerten
unter der Projektsperre mit vollständig geladenem aktuellem Datensatz, damit
nicht übergebene Felder nicht überschrieben werden; bei Userwerten mit gesperrter
Userzeile. Ein Fehler verhindert sämtliche Writes der Anfrage. Ein Schlüssel darf
nicht gleichzeitig gesetzt und zurückgesetzt werden.

### HTTP

| Route-Name | Methode/Pfad | Kontext |
|---|---|---|
| `api.settings.user.read` / `.write` | GET / POST `/api/settings/user` | eigener Benutzer |
| `api.settings.project.read` / `.write` | GET / POST `/api/projects/{project}/settings` | Projekt |
| `api.settings.project_user.read` / `.write` | GET / POST `/api/projects/{project}/settings/user` | Projekt + eigener Benutzer |

GET liefert `{"values": {...}}`; `?key=…` begrenzt auf einen registrierten,
lesbaren, nicht sensiblen Schlüssel (sonst 404), fehlendes Recht 403. POST
erwartet `{"values": {...}, "resetKeys": [...]}` und antwortet nach erfolgreicher
Mutation mit derselben gefilterten Liste. Alle Antworten tragen
`Cache-Control: private, no-store`.

> PHPs Session-Modul sendet zusätzlich sein eigenes
> `Cache-Control: no-store, no-cache, must-revalidate`. Beide Header stehen in
> der Antwort; der geforderte ist darunter.

Ein nativer Formular-POST ohne `Accept: application/json` wird nach erfolgreichem
Schreiben auf die Einstellungsseite zurückgeleitet, damit die generische Form
auch ohne JavaScript funktioniert.

### Karten und Feldtypen

`extensions()->settingSections()` verwaltet die Karten; die vorhandenen behalten
ihre IDs `personal`, `ai`, `general`, `roles`, `users`, `column`, `swimlane`,
`label`. Eine Karte ohne `template` rendert ihre registrierten Felder mit der
generischen Form.

`extensions()->fieldTypes()` teilt die Typenmechanik mit den Ticket-Metadaten:

```php
interface FieldTypeInterface
{
    public function id(): string;
    public function normalize(mixed $value, array $options): mixed;
    public function validate(mixed $value, array $options): array;
    public function view(): string;
    public function module(): ?string;
}
```

Standardtypen: `text`, `textarea`, `boolean`, `integer`, `date`, `select`,
`multiselect`. Die Validierung prüft **zuerst die erlaubte Rohdarstellung**,
normalisiert danach und prüft die normalisierte Domäne; Invalides wird nie still
in einen Default verwandelt. `options['nullable'] = true` erlaubt ausdrücklich
`null`. Boolean akzeptiert `bool` sowie `'0'`/`'1'`; Integer akzeptiert Integers
und kanonische Dezimalstrings innerhalb von `min`/`max`; Datum ist `YYYY-MM-DD`;
Select prüft deklarierte Optionsschlüssel; Multiselect ist eine deduplizierte
Liste solcher Schlüssel. Eine fehlende Checkbox sendet das Formular als
ausdrückliches `false`.

Die `ai`-Karte behält ihren Browser-Speicher. **`settings()->all()` kann
localStorage nicht lesen**; Chatverlauf, Gedächtnis und Prompts werden nicht auf
den Server übertragen.

`Naf\Support\Collection` behandelt `null` wie fehlend (`get()`/`has()` nutzen
`??`/`isset()`). `settings()->collection()` ist deshalb ein Snapshot für den
Lesefall; die Präsenzprüfung macht `settings()->has()`. `add()` auf dem Snapshot
speichert nichts.

## Ticket-Metadaten

```php
$context->ticketFields()->add(new TicketFieldDefinition(
    key: 'example.external_id',
    label: 'Externe Nummer',
    type: 'text',
    group: 'details',
    index: 500,
    default: null,
    nullable: true,
    required: false,
    readOnly: false,
    readPermission: 'read',
    writePermission: 'write',
    options: ['max' => 40],
    showOnCreate: true,
));
```

Identität ist der Key. Core-Felder und reservierte Systemattribute
(`project_id`, `created_by`, `version`, `board_revision`, …) sind unzulässig.

Gruppen verweisen auf ein Panel in `ticket.sidebar.panels`. Eingebaut sind
`primary` (100), `details` (200), `planning` (300), `information` (400). Eine
neue Gruppe wird als Panel registriert; ihre Group-ID ist die
Contribution-ID des Panels. Ein Feld mit unbekannter Gruppe lässt den Boot mit
konkreter Diagnose scheitern.

Schreiben läuft über `TicketService::create()/update()`:

```json
{ "metadata": { "example.external_id": "CRM-42" }, "metadata_reset": ["example.reviewed"] }
```

Native HTML-Namen sind `metadata[example.external_id]` und `metadata_reset[]`.
Fehlendes `metadata` ändert nichts. `null` ist nur bei `nullable` ein Wert.
Set und Reset desselben Keys sind ungültig.

- **create:** Validierung, Core-Ticket, Metadaten, Pivots und Change in
  derselben Transaktion. Widgets bekommen die neue ID erst danach.
- **update:** innerhalb der Projektsperre, mit Ticketversion und Boardrevision.
  Beide steigen **einmal je akzeptierter Änderung**, Metadaten-only eingeschlossen.
  Bei 409/422/403/DB-Fehler bleiben Ticket, Metadaten, Pivots und Activity
  unverändert.
- Ein Plugin-Feld verlangt zusätzlich zu Core-`write` sein definiertes
  Schreibrecht. `readOnly` und archivierte Tickets bleiben gesperrt.

Lesen:

```php
interface TicketMetadataReaderInterface
{
    public function get(int $projectId, int $ticketId, string $key, mixed $default = null): mixed;
    public function all(int $projectId, int $ticketId): array;
}
```

Mitgliedschaft, Ticketzugehörigkeit und Feld-Leserecht werden bei jedem Aufruf
geprüft. Unbekannter Key → Aufruferdefault; bekannt aber verboten → 403.
`BoardQuery::detail()` liefert `metadata`, `metaDefinitions` und `metaUnknown`;
Board-Badges lesen die gebündelt geladenen `card_metadata`, nicht eine Query pro
Karte.

Tabelle:

```text
ticket_metadata(project_id, ticket_id, meta_key, value_json, updated_at)
  PRIMARY KEY(project_id, ticket_id, meta_key)
  FOREIGN KEY(project_id, ticket_id) REFERENCES tickets(project_id, id)
```

Höchstens 16 KiB je Wert, 64 KiB je Ticket und 100 Keys — vorhandene unbekannte
Werte zählen mit. Ressourcen, Objekte, NaN und unendliche Zahlen werden
abgewiesen.

## Ticket-Widgets und Browser-Lifecycle

Die mittlere Ticketspalte ist eine Liste:

| Default-ID | Slot | Index |
|---|---|---|
| `core.ticket.links` | `ticket.main.widgets` | 100 |
| `core.ticket.attachments` | `ticket.main.widgets` | 200 |
| `core.ticket.activity` | `ticket.main.widgets` | 300 |

Titel und Beschreibung stehen davor, Kommentare dahinter. Index 150 landet
zwischen Verknüpfungen und Anhängen; gleicher Index sortiert nach ID.

Uploads sind ein mitgeliefertes Modul
([`App\Modules\Attachments\AttachmentsModule`](../app/app/Modules/Attachments/AttachmentsModule.php))
und benutzen dieselbe Registry wie ein Fremdplugin. Der Rechtefilter blendet das
Widget für reine Leser **nicht** aus: die Dateiliste bleibt sichtbar, Upload und
Löschen verlangen weiterhin `upload`. Wird das Widget entfernt, verschwinden
Oberfläche und Asset — keine Datei, keine Route und kein Recht.

Browser-Seite, `app/public/assets/extensions.js`:

```js
export function mount(root, context, api) {
  // root ist der Beitragsknoten, nicht die Seite
  return () => {
    /* dispose */
  };
}
```

- Marker ist `data-extension-id`; die Modul-URL kommt aus `data-extension-module`
  und damit aus der serverseitigen Definition — **nie** aus einem Ticket- oder
  Settingwert.
- `context` trägt IDs und Modus, keine zusätzlichen Rechte. `api` bietet
  `toast`, `refresh` und `save`, wobei `save` die vorhandene
  Ticket-Schreibwarteschlange benutzt. Einen zweiten Auto-Save-, Fetch- oder
  Versionsmanager gibt es nicht.
- Mount genau einmal pro Knoten, Dispose vor Entfernen oder Ersetzen. Erste
  Seite, geöffnetes Ticket, Create→Detail, Fragment-Refresh und Schließen des
  Drawers durchlaufen denselben Lifecycle.
- Ein Importfehler wird am Beitrag gemeldet und blockiert die festen
  Ticketbereiche nicht.
- Stabile Formularfelder bleiben die Quelle von `FormData`; komplexe Typen
  synchronisieren ihre hidden Felder vor dem Absenden und werden serverseitig im
  FieldType validiert.

`ticket.js` und `upload.js` teilen sich einen Fragment-Pfad
([`fragment.js`](../app/public/assets/fragment.js)). Widgets werden **anhand
ihrer IDs** abgeglichen, damit neue hinzukommen und entfernte verschwinden; ein
Knoten mit offenem Entwurf bleibt gemountet.

## Views

```php
$context->views()->add(new ViewOverride('example-a/reports', 'example-b/reports'));
```

Die ID ist der bisherige logische Name (`ticket`, `ticket/field`, `layout`, …);
`ticket.field` und `ticket/field` meinen dasselbe. Die Auflösung gilt für den
PageRenderer, alle App-Partials, Login-, Error- und Profil-Views sowie das
Layout. Templates verwenden dafür `Nafinity\template()` und `Nafinity\partial()`.

Das globale NAF-View-Suchverhalten bleibt unverändert: Host-`app/views` gewinnt
weiterhin vor Plugin-Pfaden; das Mapping wählt ein anderes Ziel. Zyklen werden
erkannt und gemeldet. Der Host kann ein Mapping zuletzt ersetzen oder entfernen.

Ein additiver Widget-Beitrag braucht **kein** View-Override.

## Board-Filter

```php
$context->boardFilters()->add(new BoardFilterDefinition(
    'example.reviewed',
    'Geprüft',
    static fn(mixed $value) => in_array($value, ['1', 1, true, 'true'], true),
    static fn(mixed $value, BoardFilterContext $filter) => new SqlCondition(
        'EXISTS(SELECT 1 FROM ticket_metadata m WHERE m.project_id=' . $filter->alias
        . '.project_id AND m.ticket_id=' . $filter->alias
        . '.id AND m.meta_key=? AND m.value_json=?)',
        ['example.reviewed', $value ? 'true' : 'false'],
    ),
    150,
));
```

`SqlCondition` enthält ausschließlich ein SQL-Fragment plus Positionsparameter.
Jedes Fragment wird geklammert mit `AND` kombiniert; `t.project_id = ?`, die
Archivregel, das Kartenmaximum 300 und die Reihenfolge bleiben außerhalb
bindend. Benutzereingaben sind niemals SQL oder Spaltennamen.

Core-Queryparameter (`column`, `swimlane`, `assignee`, `label`, `status`,
`priority`, `q`) bleiben kompatibel; Plugin-Filter kommen als
`filters[example.reviewed]`. Ein unbekannter Filter meldet **422** statt still
ignoriert zu werden. Das normalisierte Ergebnis speist Filterdarstellung, Count
und Kartenquery gemeinsam und aktiviert dieselbe DnD-Einschränkung wie bisher.

## Schätzung, Verlauf und AI

```php
$context->estimationScales()->add(new EstimationScale('example.tshirt', 'T-Shirt', 'TS', [1, 2, 3, 5, 8]));
$context->activityTypes()->add(new ActivityType('example.reviewed', 'Ticket geprüft', 'fact_check'));
$context->aiTools()->add(new AiToolProviderDefinition('example.reports.tools', ReportTools::class, 200));
```

- Schätzskalen: Werte sind eindeutig und aufsteigend. `none`, `complexity` und
  `points` bleiben die Defaults, `Estimation` bleibt als Fassade kompatibel. Bei
  fehlendem Plugin bleiben Skalen-ID und Werte gespeichert und werden als nicht
  verfügbar angezeigt — kein stilles Umschreiben, kein automatisches Remap.
- Activity-Typen: Ein unbekannter Typ erscheint mit seinem escapten Namen statt
  irreführend als „Projekt aktualisiert".
- AI-Werkzeuge:

```php
interface AiToolProviderInterface
{
    public function tools(AiToolContext $context): iterable;   // ProjectToolInterface
}

interface ProjectToolInterface extends \Naf\MCP\Tools\ToolInterface
{
    public function title(): string;
    public function permission(): string;
    public function requires(): array;
    public function keywords(): array;
}
```

Die bisherige feste Liste ist ein Standardprovider. AiService filtert Rechte
**vor Auslieferung und erneut vor Ausführung**. Doppelte Toolnamen brauchen
`replaceNames` in der Provider-Definition; einen zufälligen Last-Wins-Effekt gibt
es nicht. Ein Schreibwerkzeug verlangt weiterhin serverseitig
`confirmed === true`. Browser-Router und Keywords erteilen keine Berechtigung.
Der tokenbasierte `/mcp`-Zugang bleibt davon getrennt.

Events: `Naf\event()->listen('nafinity.changed', …)` mit `App\Domain\Change`
bleibt unverändert und läuft **innerhalb der Domain-Transaktion**. Ein Listener
darf einen Job über die NAF-Queue in derselben PDO-Transaktion einreihen; externe
Arbeit erledigt erst der Job. Mail oder Webhooks im Listener sind falsch.
Metadatenänderungen ergänzen den Change-Payload um die betroffenen Key-Namen —
keine Werte, keine Requestdaten.

## Assets

```php
$context->assets()->add(new AssetDefinition('example.review.styles', '/plugins/example/nafinity-extension-b/review.css', 'css', 300));
$context->assetPackages()->add(new AssetPackage('example/nafinity-extension-b', __DIR__ . '/public'));
```

Registrierte Assets werden über NAFs vorhandenen Asset-Service als CSS-/JS-Tags
gerendert; das Layout gibt dessen Ausgabe tatsächlich aus. Der Service bestimmt
den Typ über die Dateiendung, deshalb darf ein registrierter Pfad **keinen
Querystring** tragen — Versionierung gehört in den Dateinamen oder das
Verzeichnis. Die statischen App-Assets und ihre Cache-Buster bleiben unberührt.

Veröffentlichung nach `app/public/plugins/<vendor>/<name>/`:

```sh
php vendor/bin/naf nafinity:assets:publish [--package=vendor/name]
php vendor/bin/naf nafinity:assets:check   [--package=vendor/name]
php vendor/bin/naf nafinity:assets:remove  [--package=vendor/name]
```

- Quellen sind ausschließlich Dateien mit den Endungen css, js, json, png, jpg,
  jpeg, webp, svg, woff, woff2, ico. PHP-, Config- und private Dateien werden
  nicht veröffentlicht; Traversals und Symlinks aus der Quellwurzel heraus
  ebenfalls nicht.
- Eigentum und Hash jeder Datei stehen in `app/storage/plugin-assets/`.
  Zielkonflikte werden **vor** jeder Änderung geprüft; host-veränderte Dateien
  werden weder überschrieben noch entfernt.
- Wiederholtes Veröffentlichen ist idempotent. `remove` arbeitet aus dem
  gespeicherten Manifest und funktioniert auch ohne installiertes Plugin.
- Assets sind öffentliche Daten. Uploads sind keine Assets und nehmen diesen Weg
  nie.

## Übersetzungen

`naf/i18n` ab 0.2.2 bringt:

```php
use function Naf\I18n\translation_paths;

translation_paths()->add('example.reports', __DIR__ . '/lang', 100);
translation_paths()->all();      // ID => Verzeichnis, nach Index und ID
translation_paths()->remove('example.reports');
```

Registrierte Verzeichnisse werden aufsteigend gelesen, das App-Verzeichnis
zuletzt — **der Host gewinnt**. Eine Registrierung oder Entfernung erhöht die
Revision der Registry; ein bereits geladener Translator lädt daraufhin neu.
Defekte Dateien nennen ihren konkreten Pfad; in Paketen wird nichts verändert.

`App\Support\Locales::available()` berücksichtigt die registrierten
Verzeichnisse. Ein Paket mit französischer Übersetzung macht Französisch
auswählbar, ohne dass eine App-Allowlist geändert wird. Locale-Auswahl,
`PreferenceService` und `PageRenderer` verwenden dieselbe Liste.

> `naf/i18n` 0.2.2 ist zum Zeitpunkt dieser Dokumentation **nicht
> veröffentlicht**. Nafinity fordert `^0.2.2` und arbeitet im Source-Modus gegen
> den RC-Branch. Die stabile Distribution ist davon abhängig, dass der Maintainer
> das Paket merged und veröffentlicht.

## Migrationen, Commands, Jobs

Migrationen registriert ein Paket in seiner `bootstrap.php` — die Registry ist
eine statische Liste ohne eigene Bootreihenfolge:

```php
Naf\Database\Support\MigrationRegistry::addPath(__DIR__ . '/src/Migrations');
```

Alles, was einen Dienst braucht, gehört in den Provider, der erst nach dem Boot
aller Pakete läuft:

```php
$context->container()->get(JobRepository::class)->add(ReviewReminderJob::class, []);
$context->container()->get(CommandRegistry::class)->add(ReportCommand::class);
```

Ein Plugin-Job hat keine Session. Er arbeitet auf dem, was ihm übergeben wurde,
und schreibt nur in seine eigenen Tabellen.

Nach Code- oder Pluginwechsel müssen laufende Hintergrundprozesse neu starten:

```sh
make restart-background
```

## Lebensdauer und Deinstallation

Grenze der Lebensdauer von Providern und Registries ist eine frische
App-Initialisierung. Ein Registry-Reset baut Defaults und aktuell installierte
Provider neu auf. Fremde Benutzer- oder Projektwerte werden nie im Singleton
gehalten; Context-Objekte sind unveränderlich.

Eine Deinstallation entfernt **nichts** automatisch: keine Settings, keine
Metadaten, keine Ticketdateien, keine Rollen-Grants, keine Activities und keine
Jobs. Stattdessen gilt:

- Die Beiträge verschwinden: Menüeintrag, Widget, Feld, Filter, Settingkarte.
- Gespeicherte Werte bleiben in der Datenbank und werden weder ausgegeben noch
  autorisiert. Die Ticket-UI meldet, dass eine Erweiterung fehlt, ohne Werte
  offenzulegen.
- Eine reine Core-Ticketänderung löscht oder normalisiert unbekannte Metadaten
  nicht.
- Erforderliche Felder eines fehlenden Plugins blockieren keine sonst gültigen
  Core-Änderungen.
- Bereits eingereihte Jobs eines fehlenden Plugins werden nicht als erfolgreich
  verworfen; es gelten die bestehenden Queue-Failure-, Retry- und
  Deadletter-Wege.
- Nach erneuter Installation sind die Werte wieder verfügbar, sofern Definition
  und Rechte das erlauben. Mit einer neuen Definition inkompatible Altwerte
  ergeben eine klare Diagnose und werden nicht automatisch umgeschrieben.

Eine angewandte Plugin-Migration ohne installiertes Paket lässt
`db:migrate` mit „Applied migration source is missing" scheitern. Das ist
beabsichtigt: das Schema enthält etwas, dessen Herkunft gerade fehlt. Entweder
das Paket wieder installieren oder die Migration bewusst zurücknehmen, solange es
noch da ist.

## Negativfälle

Jeder Erweiterungspunkt hat einen ausgeführten Negativtest in
[`app/tests/extensions.php`](../app/tests/extensions.php),
[`extensions_http.php`](../app/tests/extensions_http.php),
[`extensions_assets.php`](../app/tests/extensions_assets.php) und
[`extensions_without.php`](../app/tests/extensions_without.php):

| Punkt | Negativfall |
|---|---|
| Provider | Registrierung nach dem Durchlauf, Provider mit Ausnahme, doppelte Initialisierung |
| Routen | `remove()` einer unbekannten Route liefert `false` |
| Dienste | — der Ersatz wird über einen produktiven Verbraucher geprüft |
| Rechte | ohne Grant 403, fremdes Projekt 404, Grant eines fehlenden Plugins bleibt |
| Menü | Eintrag ohne Recht erscheint nicht |
| Settings | unbekannter Schlüssel 422, ungültiger Wert 422, Set+Reset 422, fehlendes Recht 403, unbekannter Key über HTTP 404, Write ohne CSRF 400 |
| Metadaten | unbekannter Key 422, ungültiger Wert lässt alles unverändert, reservierte Attribute unzulässig |
| Feste Bereiche | Ersetzen oder Entfernen von Titel, Beschreibung, Kommentaren wirft |
| Views | Zyklus wird gemeldet |
| Filter | unbekannter Filter 422 |
| AI | Werkzeug erscheint ohne Grant nicht |
| Assets | host-veränderte Datei bleibt, unbekanntes Paket 404, nur erlaubte Endungen |
| Deinstallation | Definitionen weg, Werte da, nichts autorisiert |

Ausführen:

```sh
make test-plugins
```
