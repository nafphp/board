# Extensibility

Nafinity can be extended by installed Composer packages. A package brings its own controllers,
routes, services, menu entries, settings, ticket fields, widgets, filters, translations, AI
tools, commands, migrations and jobs, and can explicitly replace existing definitions.

Two complete examples live in the repository and are really installed and executed by the
acceptance run: [`examples/nafinity-extension-a`](../examples/nafinity-extension-a) and
[`examples/nafinity-extension-b`](../examples/nafinity-extension-b).

## Contents

1. [Installation](#installation)
2. [When registration happens](#when-registration-happens)
3. [Shared registry rules](#shared-registry-rules)
4. [Routes, controllers and services](#routes-controllers-and-services)
5. [Permissions](#permissions)
6. [UI contributions and the menu](#ui-contributions-and-the-menu)
7. [Settings](#settings)
8. [Ticket metadata](#ticket-metadata)
9. [Ticket widgets and the browser lifecycle](#ticket-widgets-and-the-browser-lifecycle)
10. [Views](#views)
11. [Board filters](#board-filters)
12. [Estimation, activity and AI](#estimation-activity-and-ai)
13. [Assets](#assets)
14. [Translations](#translations)
15. [Migrations, commands, jobs](#migrations-commands-jobs)
16. [Lifetime and uninstalling](#lifetime-and-uninstalling)
17. [Negative cases](#negative-cases)

## Installation

An extension package is an ordinary Composer package with `type: naf-plugin`. NAF discovers it
through `InstalledVersions::getInstalledPackagesByType()`; there is no additional manifest file.

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

Nafinity is the host application. A package does not require `fkde/nafinity` as a Composer
dependency — that would be a cycle — but states the requirement in its README and in
`extra.nafinity.host`. Which NAF packages it really needs belongs in `require`.

Locally, a package is installed through a path repository:

```json
{
  "repositories": [{ "type": "path", "url": "../examples/nafinity-extension-a" }],
  "require": { "example/nafinity-extension-a": "*" }
}
```

Then apply migrations and publish assets once:

```sh
php vendor/bin/naf db:migrate up
php vendor/bin/naf nafinity:assets:publish --package=example/nafinity-extension-a
```

## When registration happens

NAF boots Composer plugins **before** the application defaults. Registering directly there means
being overwritten again immediately afterwards. A package therefore only notes a provider in its
`bootstrap.php`:

```php
// examples/nafinity-extension-a/bootstrap.php
use Example\ExtensionA\ExtensionAProvider;

use function Nafinity\extensions;

extensions()->register('example.reports', ExtensionAProvider::class, 100);
```

`Nafinity\extensions(): Nafinity\ExtensionRegistry` binds a pure metadata registry to the
container on first call. It is reachable during the plugin boot and does not boot the
application a second time.

```php
public function register(
    string $id,
    string $providerClass,
    int $index = 100,
    bool $replace = false,
): void;
```

The provider itself:

```php
namespace Nafinity\Contracts;

interface ExtensionProviderInterface
{
    public function register(ExtensionContext $context): void;
}
```

`ExtensionContext` carries the container and all registries. It carries **no** user and performs
no authorization: definitions are code, user data is read in the request that needs it.

The order in [`app/bootstrap.php`](../app/bootstrap.php) is fixed:

1. Composer/NAF boot and the existing application routes (in the `app()` constructor),
2. lazily bound application service defaults, policies and native infrastructure,
3. Nafinity's built-in contribution definitions (`App\Modules\NafinityDefaults`),
4. the noted providers, ascending by index and id,
5. optionally [`app/app/extensions.php`](../app/app/extensions.php) as the last host override,
6. `app()->run()`.

Properties of that pass:

- A second `initialize()` call runs no provider again.
- Registering **after** the pass throws a `LogicException` naming the extension id. New
  definitions in existing registries stay possible until request handling.
- An exception in a provider names the extension id, the provider class and the cause, and fails
  the boot. There is no silent partial operation.
- Recursive initialisation is detected and refused.

## Shared registry rules

All contribution registries share the same rules
([`DefinitionRegistry`](../app/app/Extensions/Registry/DefinitionRegistry.php)):

```php
$registry->add(object $definition, bool $replace = false): void;
$registry->get(string $id): ?object;
$registry->all(): array;   // by index, then id; id keys are preserved
$registry->remove(string $id): bool;
$registry->has(string $id): bool;
```

- **One sort value:** `index`, ascending, ties broken by `strcmp()` on the id. Default 100,
  negative values allowed. There is no second `priority` field. NAF's event priority (higher
  values first) is untouched by this, as are card positions on the board.
- A duplicate id without `replace: true` throws a `LogicException`.
- `remove()` on an unknown id returns `false`.
- Ids are non-empty, stable and namespaced for plugins: `example.reports`.
- A replacement replaces exactly one definition; there is no recursive array merge.
- Defaults are registered exactly once before the plugins; the last explicit registration wins,
  and the host comes last.

## Routes, controllers and services

A plugin registers routes in its provider — that is, after the application routes, which is why
a replacement under the same name really takes effect:

```php
route()->add('GET', '/projects/{project}/reports', [ReportController::class, 'show'], 'example.reports');
```

A controller is an ordinary class. Parameters come from the route by name, services by
constructor injection:

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

        return $this->pages->render('example-a/reports', ['title' => 'Reports', 'scope' => $scope]);
    }
}
```

**The name is the identity.** A route under an already used name replaces the existing one —
that is the only promise. The same *path* under a different name replaces nothing: NAF checks
routes in registration order, so the one registered first still answers. The second stays
reachable through its name and `route()`, but never through that path.

`Naf\Core\Route::remove(string $name): bool` takes a named route back. The dispatcher prefers a
**bound** target class (`$container->get()`) and builds anything unbound as before; a bound
object without the route's action is reported as a `DispatcherException`. Both are in
`naf/framework` from 0.2.4.

### Replaceable services

Contracts under `Nafinity\Contracts` describe the actual replacement boundaries:
`AccessInterface`, `AccountServiceInterface`, `ProjectServiceInterface`,
`TicketServiceInterface`, `BoardQueryInterface`, `CommentServiceInterface`,
`AttachmentServiceInterface`, `NotificationServiceInterface`, `PreferenceServiceInterface`,
`RoleServiceInterface`, `TimerServiceInterface` and `AiServiceInterface`, plus
`PageRendererInterface`, `SettingsServiceInterface`, `SettingsStoreInterface`,
`TicketMetadataReaderInterface` and `TicketMetadataStoreInterface`.

Each is bound lazily — once under the default class, once under the contract. Replacing or
decorating happens in the provider:

```php
$inner = $context->container()->get(TicketServiceInterface::class);

$context->container()->set(
    TicketServiceInterface::class,
    static fn() => new CountingTicketService($inner),
);
```

Every productive consumer asks for the contract: controllers, services among each other,
`FinalizeAttachmentJob`, `MaintenanceJob`, `SeedCommand` and the readiness endpoint. A
replacement therefore reaches the worker too.

Auth, session, storage, client, mail and logging stay the NAF contracts; there is no second
implementation for those. Overrides have to be registered **before** their first use: an
already constructed instance is not rewired afterwards.

### Page renderer

```php
namespace Nafinity\Contracts;

interface PageRendererInterface
{
    public function render(string $template, array $data = []): ResponseInterface;
    public function fragment(string $template, array $data = []): string;
}
```

`render()` returns the complete page with the application shell: login behaviour, language, the
viewer's own projects, a running timer, user and preferences come from the renderer. Data passed
in is spread first, so a foreign `$data['user']` does not overwrite the shell data. `fragment()`
only resolves the view mapping and renders a partial. Project and record authorization remain
the controller's job.

## Permissions

```php
$context->permissions()->add(new PermissionDefinition(
    'example.reports.view',
    'View this project\'s reports',
    'Explanatory text',
    800,
    ownerOnly: false,
));
```

- Registered permissions appear in the role editor, are stored, and are recognised as effective
  by `Access::permissions()`.
- **A new permission is granted to nobody automatically** — owners and managers do not get it
  either. An owner can assign it to a custom role.
- `read` stays the membership check and is not a definition name.
- `ownerOnly: true` stays unavailable to custom roles; `moderate` still requires `comment`;
  archived projects keep their barriers.
- `App\Domain\ProjectPermissions` remains as compatible access to the built-in defaults.
- A stored grant of a missing plugin is **not deleted** and authorizes nothing. It survives
  saving a role, the form reports it as an unavailable definition, and reinstalling makes it
  effective again.
- Hiding something in the UI is not a check: a foreign project stays 404, a missing permission
  403.

## UI contributions and the menu

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

The fixed slots:

| Slot | Where it renders |
|---|---|
| `sidebar.workspace` | Workspace entries in the left menu |
| `sidebar.project` | Navigation for the selected project |
| `sidebar.footer` | The lower menu area |
| `topbar.actions` | Actions in the top bar |
| `projects.actions`, `projects.card.badges` | Project overview |
| `board.actions`, `board.card.badges`, `board.card.details`, `board.card.actions`, `board.column.summary` | Board |
| `ticket.actions` | Ticket action menu |
| `ticket.main.widgets` | Between description and comments |
| `ticket.sidebar.panels` | Groups of the right-hand ticket sidebar |
| `profile.panels` | Profile dialog |
| `notifications.actions`, `activity.actions` | Notifications and history |

### What a slot hands over

Every slot passes a **typed context** that states what is available at that place. A contributed
template is written against it — not against variables the surrounding view happens to have in
scope. The context is available in the template as `$slot`.

| Context | Slots | Holds |
|---|---|---|
| `PageSlotContext` | `sidebar.*`, `topbar.actions`, `projects.actions`, `notifications.actions`, `activity.actions`, `profile.panels` | `ui()` only |
| `ProjectSlotContext` | `projects.card.badges` | `project`, `projectId()` |
| `BoardSlotContext` | `board.*` | `project`, `scope`, `labels`, `members`, `metadata`, `token`, `card`, `column`, `value()` |
| `TicketSlotContext` | `ticket.actions`, `ticket.main.widgets`, `ticket.sidebar.panels` | `ticket`, `project`, `scope`, `board`, `params`, `token`, `editable`, `isNew`, `columns`, `swimlanes`, `labels`, `members`, `metadata`, `fields`, `links`, `attachments`, `activity`, `timer`, `preferences`, `creator`, `field`, `value()`, `fieldsIn()` |

All of them implement `SlotContextInterface` and hand out the checked `UiContext` through
`ui()`. The values are already authorized: `metadata` holds only what this actor may read, and
`fields` only the definitions they may see.

```php
<?php
/** @var \Nafinity\Support\TicketSlotContext $slot */

if ($slot->value('example.reviewed') !== true) {
    return;
}
?>
<p><?= s(t('Reviewed by :who', ['who' => $slot->creator])) ?></p>
```

On the board the context is immutable and derived per card or column:

```php
<?= slot('board.card.badges', $boardSlot->withCard($card)) ?>
```

`TicketSlotContext::field` is the existing inline editor. A contribution that wants to show an
editable value builds **no** form of its own:

```php
<?= $slot->field->render('metadata[example.external_id]', 'External number', 'line', $value, $display) ?>
```

Auto-save, draft preservation, keyboard handling and the version check then apply unchanged.

### Your own data

A data provider supplies what the slot context does **not** have — something from the package's
own table, for example. Whatever is already there is not fetched again:

```php
interface UiDataProviderInterface
{
    public function data(UiContext $context): array;
}
```

`UiContext` holds the checked actor, optionally the `ProjectScope`, the mode (`page`, `detail`,
`create`), the current route name and optionally the record id. **Permission and mode filters
apply before the provider is resolved** — an invisible contribution executes no code.
`permission: null` means "no additional project action", never anonymous reading. Visibility is
not write permission.

Menu entries have a semantic definition of their own:

```php
$context->navigation()->add(new NavigationItem(
    'example.reports.link',
    'sidebar.project',
    'Reports',
    'example.reports',
    static fn(UiContext $c) => ['project' => $c->projectId()],
    'insights',
    ['example.reports'],
    150,
    'example.reports.view',
));
```

`routeParams` may be a closure; it is evaluated **after** authorization, so no project or ticket
id is captured at boot time. Both kinds of contribution are sorted together by index and id, and
colliding ids are refused. Icons come from the existing `Icon::mark()`.

> The bundled Material Symbols file is a **subset** of the symbols this application itself uses.
> A name outside that subset is drawn as its own ligature text — instead of a symbol, the screen
> then reads `rate_review`. A contribution therefore uses one of the available symbols (`add`,
> `arrow_back`, `arrow_downward`, `arrow_forward`, `arrow_upward`, `attach_file`, `auto_awesome`,
> `check_circle`, `close`, `contrast`, `download`, `drive_file_move`, `expand_less`,
> `expand_more`, `folder_open`, `grid_view`, `group`, `history`, `info`,
> `keyboard_double_arrow_up`, `label`, `left_panel_close`, `menu`, `more_horiz`, `notifications`,
> `open_in_new`, `person`, `radio_button_unchecked`, `refresh`, `remove`, `schedule`, `search`,
> `settings`, `shield`, `table_rows`, `tune`, `view_kanban`, `view_week`) or brings its own
> graphic as a published asset.

The dynamic project list stays a `BoardQuery` result and does not become a registry.

### Fixed ticket areas

`core.ticket.title`, `core.ticket.description` and `core.ticket.comments` are reserved. Trying to
replace or remove them through the UI or field registry throws a `LogicException`, as does a
ticket field named `title`, `description` or `comments`. A complete template override stays
possible but has to preserve those areas.

## Settings

```php
use function Nafinity\settings;

settings()->get('theme', 'system');                 // the signed-in user
settings()->all();                                  // array<string, mixed>
settings()->has('theme');                           // registered and readable, even when null
settings()->collection();                           // Naf\Support\Collection as a snapshot
settings()->forProject($projectId)->get('name');
settings()->forProjectUser($projectId)->get('muted');
settings()->forApplication()->get('mail_enabled');  // declared config values only
settings()->save(['theme' => 'dark'], resetKeys: []);
```

Context methods return **new immutable instances**; the helper object itself does not change.
There is no implicit project switch through a URL parameter and no `forUser($someoneElsesId)`
shortcut: the user context is always the signed-in person. `forProject()` checks the current
membership.

### Definitions

```php
$context->settings()->add(new SettingDefinition(
    key: 'example.reports.limit',
    scope: 'project',            // user | project | project_user | application
    section: 'example.reports',
    label: 'Rows in the report',
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

- The identity is `(scope, key)`. The same key may exist in several scopes; duplicate keys within
  one scope are refused even across different sections.
- Dots in the key are literal key characters, not a nested lookup.
- **Value priority per scope:** stored value → declared NAF config key → definition default. Only
  for an *unknown* key does `get()` return the caller's default.
- `null`, `false`, `0` and `''` are valid values and are not replaced by defaults.
- Permissions: for `user` and `project_user`, a missing extra permission means access to your own
  values. For `project`, writing requires `manage` by default; an explicitly given plugin write
  permission applies to that plugin's own key. Legacy project fields immutably require at least
  `manage`. Project reads always require membership.
- `application` is read-only, has no HTTP route and is readable in the CLI without a session.
  There is no global write permission.
- `sensitive: true` never leaves the server: not in `all()`, not over HTTP, not in logs, events
  or errors. Trusted server-side code may read such a value individually after the normal scope
  check. In the form, sensitive inputs stay empty; a value that is not submitted stays unchanged.

### Storage

Existing values stay where they are: `theme`, `locale`, `timezone`, `notify_in_app`,
`notify_mail` in `user_preferences`; `muted` in `project_preferences`; `name`, `description`,
`color`, `icon`, `ticket_key`, `estimation_scale` in the project record through `ProjectService`.
There is no second, contradicting store — `PreferenceService` and `SettingsService` share the
same internal persistence
([`PreferenceStore`](../app/app/Support/Settings/PreferenceStore.php)).

New plugin values live in `user_settings`, `project_settings` and `project_user_settings`: one
row per key, `value_json TEXT`, at most 16 KiB per value. No DDL per field.

A mixed write runs in **one** transaction: for project values under the project lock with the
current record fully loaded, so that fields not submitted are not overwritten; for user values
with the user row locked. An error prevents every write of the request. A key may not be set and
reset at the same time.

### HTTP

| Route name | Method and path | Context |
|---|---|---|
| `api.settings.user.read` / `.write` | GET / POST `/api/settings/user` | the signed-in user |
| `api.settings.project.read` / `.write` | GET / POST `/api/projects/{project}/settings` | project |
| `api.settings.project_user.read` / `.write` | GET / POST `/api/projects/{project}/settings/user` | project + signed-in user |

GET returns `{"values": {...}}`; `?key=…` narrows it to one registered, readable, non-sensitive
key (404 otherwise), and a missing permission gives 403. POST expects
`{"values": {...}, "resetKeys": [...]}` and answers after a successful mutation with the same
filtered list. All responses carry `Cache-Control: private, no-store`.

> PHP's session module additionally sends its own
> `Cache-Control: no-store, no-cache, must-revalidate`. Both headers are in the response, with
> the required one underneath.

A native form POST without `Accept: application/json` is redirected back to the settings page
after a successful write, so the generic form also works without JavaScript.

### Cards and field types

`extensions()->settingSections()` manages the cards; the existing ones keep their ids `personal`,
`ai`, `general`, `roles`, `users`, `column`, `swimlane`, `label`. A card without a `template`
renders its registered fields with the generic form.

`extensions()->fieldTypes()` shares the type mechanics with the ticket metadata:

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

Standard types: `text`, `textarea`, `boolean`, `integer`, `date`, `select`, `multiselect`.
Validation checks **the permitted raw representation first**, then normalises, then checks the
normalised domain; invalid input is never silently turned into a default.
`options['nullable'] = true` explicitly allows `null`. Boolean accepts `bool` as well as `'0'`
and `'1'`; integer accepts integers and canonical decimal strings within `min`/`max`; date is
`YYYY-MM-DD`; select checks declared option keys; multiselect is a deduplicated list of such
keys. A missing checkbox is submitted by the form as an explicit `false`.

The `ai` card keeps its browser storage. **`settings()->all()` cannot read localStorage**; chat
history, memory and prompts are not transferred to the server.

`Naf\Support\Collection` treats `null` as absent (`get()` and `has()` use `??` and `isset()`).
`settings()->collection()` is therefore a snapshot for the reading case; the presence check is
`settings()->has()`. `add()` on the snapshot stores nothing.

## Ticket metadata

```php
$context->ticketFields()->add(new TicketFieldDefinition(
    key: 'example.external_id',
    label: 'External number',
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

The identity is the key. Core fields and reserved system attributes (`project_id`, `created_by`,
`version`, `board_revision`, …) are not allowed.

Groups point at a panel in `ticket.sidebar.panels`. Built in are `primary` (100), `details`
(200), `planning` (300) and `information` (400). A new group is registered as a panel, and its
group id is that panel's contribution id. A field with an unknown group fails the boot with a
concrete diagnosis.

Writing goes through `TicketService::create()` and `update()`:

```json
{ "metadata": { "example.external_id": "CRM-42" }, "metadata_reset": ["example.reviewed"] }
```

The native HTML names are `metadata[example.external_id]` and `metadata_reset[]`. A missing
`metadata` changes nothing. `null` is only a value when the field is `nullable`. Setting and
resetting the same key is invalid.

- **create:** validation, core ticket, metadata, pivots and change in the same transaction.
  Widgets receive the new id only afterwards.
- **update:** inside the project lock, with the ticket version and board revision. Both rise
  **once per accepted change**, metadata-only included. On 409, 422, 403 or a database error,
  ticket, metadata, pivots and activity stay unchanged.
- A plugin field requires its declared write permission in addition to the core `write`.
  `readOnly` and archived tickets stay locked.

Reading:

```php
interface TicketMetadataReaderInterface
{
    public function get(int $projectId, int $ticketId, string $key, mixed $default = null): mixed;
    public function all(int $projectId, int $ticketId): array;
}
```

Membership, ticket ownership and the field's read permission are checked on every call. An
unknown key returns the caller's default; a known but forbidden one gives 403.
`BoardQuery::detail()` returns `metadata`, `metaDefinitions` and `metaUnknown`; board badges read
the bundled `card_metadata` rather than one query per card.

The table:

```text
ticket_metadata(project_id, ticket_id, meta_key, value_json, updated_at)
  PRIMARY KEY(project_id, ticket_id, meta_key)
  FOREIGN KEY(project_id, ticket_id) REFERENCES tickets(project_id, id)
```

At most 16 KiB per value, 64 KiB per ticket and 100 keys — existing unknown values count towards
that. Resources, objects, NaN and infinite numbers are refused.

## Ticket widgets and the browser lifecycle

The middle ticket column is a list:

| Default id | Slot | Index |
|---|---|---|
| `core.ticket.links` | `ticket.main.widgets` | 100 |
| `core.ticket.attachments` | `ticket.main.widgets` | 200 |
| `core.ticket.activity` | `ticket.main.widgets` | 300 |

Title and description come before it, comments after. Index 150 lands between links and
attachments; an equal index sorts by id.

Uploads are a shipped module
([`App\Modules\Attachments\AttachmentsModule`](../app/app/Modules/Attachments/AttachmentsModule.php))
using the same registry as a third-party plugin. The permission filter does **not** hide the
widget from read-only viewers: the file list stays visible, while upload and delete still require
`upload`. If the widget is removed, the interface and its asset disappear — no file, no route and
no permission.

On the browser side, `app/public/assets/extensions.js`:

```js
export function mount(root, context, api) {
  // root is the contribution node, not the page
  return () => {
    /* dispose */
  };
}
```

- The marker is `data-extension-id`; the module URL comes from `data-extension-module` and
  therefore from the server-side definition — **never** from a ticket or setting value.
- `context` carries ids and the mode, no additional permissions. `api` offers `toast`, `refresh`
  and `save`, where `save` uses the existing ticket write queue. There is no second auto-save,
  fetch or version manager.
- Mount exactly once per node, dispose before removing or replacing. First page, opened ticket,
  create → detail, fragment refresh and closing the drawer all run the same lifecycle.
- An import error is reported on the contribution and does not block the fixed ticket areas.
- Stable form fields stay the source of `FormData`; complex types synchronise their hidden fields
  before submitting and are validated server-side in the field type.

`ticket.js` and `upload.js` share one fragment path
([`fragment.js`](../app/public/assets/fragment.js)). Widgets are reconciled **by their ids**, so
new ones appear and removed ones disappear; a node with an open draft stays mounted.

## Views

```php
$context->views()->add(new ViewOverride('example-a/reports', 'example-b/reports'));
```

The id is the existing logical name (`ticket`, `ticket/field`, `layout`, …); `ticket.field` and
`ticket/field` mean the same thing. The resolution applies to the page renderer, all application
partials, the login, error and profile views, and the layout. Templates use `Nafinity\template()`
and `Nafinity\partial()` for it.

NAF's global view lookup is unchanged: the host's `app/views` still wins over plugin paths, and
the mapping selects a different target. Cycles are detected and reported. The host can replace or
remove a mapping last.

An additive widget contribution needs **no** view override.

## Board filters

```php
$context->boardFilters()->add(new BoardFilterDefinition(
    'example.reviewed',
    'Reviewed',
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

`SqlCondition` holds only an SQL fragment plus positional parameters. Every fragment is combined
with `AND` inside parentheses; `t.project_id = ?`, the archive rule, the 300-card maximum and the
ordering stay binding outside it. User input is never SQL and never a column name.

The core query parameters (`column`, `swimlane`, `assignee`, `label`, `status`, `priority`, `q`)
stay compatible; plugin filters arrive as `filters[example.reviewed]`. An unknown filter reports
**422** instead of being silently ignored. The normalised result feeds the filter display, the
count and the card query together, and activates the same drag-and-drop restriction as before.

## Estimation, activity and AI

```php
$context->estimationScales()->add(new EstimationScale('example.tshirt', 'T-shirt', 'TS', [1, 2, 3, 5, 8]));
$context->activityTypes()->add(new ActivityType('example.reviewed', 'Ticket reviewed', 'fact_check'));
$context->aiTools()->add(new AiToolProviderDefinition('example.reports.tools', ReportTools::class, 200));
```

- Estimation scales: values are unique and ascending. `none`, `complexity` and `points` remain
  the defaults and `Estimation` stays compatible as a facade. When the plugin is missing, the
  scale id and values stay stored and are shown as unavailable — no silent rewrite, no automatic
  remap.
- Activity types: an unknown type appears with its escaped name rather than misleadingly as
  "project updated".
- AI tools:

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

The former fixed list is one default provider. `AiService` filters permissions **before serving
and again before execution**. Duplicate tool names require `replaceNames` in the provider
definition; there is no accidental last-wins effect. A write tool still requires
`confirmed === true` on the server. The browser router and keywords grant no authorization. The
token-based `/mcp` access stays separate from this.

Events: `Naf\event()->listen('nafinity.changed', …)` with `App\Domain\Change` is unchanged and
runs **inside the domain transaction**. A listener may enqueue a job through the NAF queue in the
same PDO transaction; external work is done by the job. Mail or webhooks in the listener are
wrong. Metadata changes extend the change payload with the affected key names — no values, no
request data.

## Assets

```php
$context->assets()->add(new AssetDefinition('example.review.styles', '/plugins/example/nafinity-extension-b/review.css', 'css', 300));
$context->assetPackages()->add(new AssetPackage('example/nafinity-extension-b', __DIR__ . '/public'));
```

Registered assets are rendered as CSS and JS tags through NAF's existing asset service, and the
layout really outputs what it produces. The service determines the type from the file extension,
so a registered path must carry **no query string** — versioning belongs in the file name or the
directory. The static application assets and their cache busters are untouched.

Publishing goes to `app/public/plugins/<vendor>/<name>/`:

```sh
php vendor/bin/naf nafinity:assets:publish [--package=vendor/name]
php vendor/bin/naf nafinity:assets:check   [--package=vendor/name]
php vendor/bin/naf nafinity:assets:remove  [--package=vendor/name]
```

- Sources are exclusively files ending in css, js, json, png, jpg, jpeg, webp, svg, woff, woff2
  or ico. PHP, configuration and private files are not published, and neither are traversals or
  symlinks leading out of the source root.
- Ownership and hash of every file are recorded in `app/storage/plugin-assets/`. Target conflicts
  are checked **before** any change; files the host modified are neither overwritten nor removed.
- Publishing repeatedly is idempotent. `remove` works from the stored manifest and also functions
  without the plugin installed.
- Assets are public data. Uploads are not assets and never take this path.

The candidate build publishes registered plugin assets before the image is finished:

```sh
python3 bin/build-candidate --source work/extension-host --tag nafinity:candidate-extensions
```

The result contains no source mounts, no symlinks and no Composer; the packages and their
published files are in it as ordinary files, and `source-snapshot.json` names the mode, the
source, the packages and the published assets. A local snapshot is **not** a published
distribution: the NAF packages inside are RC branches whose release remains a separate step.

## Translations

`naf/i18n` from 0.2.2 provides:

```php
use function Naf\I18n\translation_paths;

translation_paths()->add('example.reports', __DIR__ . '/lang', 100);
translation_paths()->all();      // id => directory, by index and id
translation_paths()->remove('example.reports');
```

Registered directories are read in ascending order with the application directory last — **the
host wins**. Registering or removing one raises the registry's revision, and an already loaded
translator reloads as a result. Broken files name their concrete path, and nothing inside
packages is modified.

`App\Support\Locales::available()` takes the registered directories into account. A package with
a French translation makes French selectable without any application allowlist being changed.
Locale selection, `PreferenceService` and `PageRenderer` all use the same list.

> `naf/i18n` 0.2.2 is **not published** at the time of writing. Nafinity requires `^0.2.2` and
> works against the RC branch in source mode. Stable distribution depends on the maintainer
> merging and publishing the package.

## Migrations, commands, jobs

A package registers migrations in its `bootstrap.php` — the registry is a static list with no
boot order of its own:

```php
Naf\Database\Support\MigrationRegistry::addPath(__DIR__ . '/src/Migrations');
```

Anything that needs a service belongs in the provider, which runs only after every package has
booted:

```php
$context->container()->get(JobRepository::class)->add(ReviewReminderJob::class, []);
$context->container()->get(CommandRegistry::class)->add(ReportCommand::class);
```

A plugin job has no session. It works on what it was handed and writes only into its own tables.

After a code or plugin change, running background processes have to restart:

```sh
make restart-background
```

## Lifetime and uninstalling

The lifetime boundary of providers and registries is a fresh application initialisation. A
registry reset rebuilds the defaults and the currently installed providers. Foreign user or
project values are never held in the singleton, and context objects are immutable.

Uninstalling removes **nothing** automatically: no settings, no metadata, no ticket files, no
role grants, no activity and no jobs. Instead:

- The contributions disappear: menu entry, widget, field, filter, settings card.
- Stored values stay in the database and are neither served nor authorized. The ticket UI reports
  that an extension is missing without revealing any values.
- A pure core ticket change neither deletes nor normalises unknown metadata.
- Required fields of a missing plugin do not block otherwise valid core changes.
- Already queued jobs of a missing plugin are not discarded as successful; the existing queue
  failure, retry and dead-letter paths apply.
- After reinstalling, the values are available again as far as the definition and permissions
  allow. Old values incompatible with a new definition produce a clear diagnosis and are not
  rewritten automatically.

An applied plugin migration without the package installed makes `db:migrate` fail with "Applied
migration source is missing". That is deliberate: the schema contains something whose origin is
currently absent. Either install the package again, or take the migration back deliberately while
it is still there.

## Negative cases

Every extension point has an executed negative test in
[`app/tests/extensions.php`](../app/tests/extensions.php),
[`extensions_http.php`](../app/tests/extensions_http.php),
[`extensions_assets.php`](../app/tests/extensions_assets.php) and
[`extensions_without.php`](../app/tests/extensions_without.php):

| Point | Negative case |
|---|---|
| Provider | Registering after the pass, a provider that throws, double initialisation |
| Routes | `remove()` of an unknown route returns `false` |
| Services | — the replacement is checked through a productive consumer |
| Permissions | 403 without the grant, 404 for a foreign project, a grant of a missing plugin survives |
| Menu | An entry without the permission does not appear |
| Settings | Unknown key 422, invalid value 422, set+reset 422, missing permission 403, unknown key over HTTP 404, write without CSRF 400 |
| Metadata | Unknown key 422, an invalid value leaves everything unchanged, reserved attributes are refused |
| Fixed areas | Replacing or removing title, description or comments throws |
| Views | A cycle is reported |
| Filters | An unknown filter gives 422 |
| AI | A tool does not appear without its grant |
| Assets | A host-modified file survives, an unknown package gives 404, only allowed extensions |
| Uninstalling | Definitions gone, values present, nothing authorized |

Run them with:

```sh
make test-plugins
```

The run boots the same installation four times: with both packages, the same installation over
real HTTP, with the **plugin listing reversed** — NAF then boots B first, and the providers still
run by index and id — and finally without the packages. Asset publishing is checked on top of
that.

The original audit's probe stays unchanged as the baseline regression:

```sh
docker compose run --rm --no-deps -T \
  --volume "$PWD:/workspace/project" \
  --volume "$(python3 -c 'import os;print(os.path.realpath("packages"))'):/workspace/nafphp:ro" \
  --workdir /workspace/project app \
  php docs/audits/2026-09-17-plugin-extensibility/nafinity-probe.php
```

It shows exactly two intended differences from the audited state: the dispatcher now takes the
bound target class, and `Nafinity\settings()` exists. Everything else — including the fact that
Nafinity's own `home` route still replaces a plugin's early route — stays as measured.
