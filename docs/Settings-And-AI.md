# Settings, custom roles and local AI

Personal settings live in the account modal, reached through the profile picture. A board's
Settings tab holds its shared project settings; it has no separate **For me in this project**
card. General notification preferences belong to the account modal. Its **Project notifications**
section lists the projects the person belongs to, including archived ones, with their current mute
state. Each switch immediately saves the person's `project_user` setting `muted` through the
existing settings API while keeping the modal open. A failed save restores the last confirmed
switch state and displays the error. Without JavaScript, each form has a Save button. The
Notifications page also retains its mute and enable controls. Extensions can still contribute
`project_user` cards.

A project settings card opens as a large dialog and slides back on the X or Escape. Input is
preserved on close, and after a successful save the same card is reopened. The animation honours
the operating system's reduced-motion option. On small screens the dialog takes up nearly the
whole area.

The top bar shows the current project's name on project pages, including board,
ticket and project settings pages. Other pages omit the context label because their
heading already names the area. The label does not imply a clickable breadcrumb
hierarchy. Navigation stays in the sidebar and the project's tabs. Long project names
truncate in the bar so its actions remain available on narrow screens.

The top bar's workspace search finds readable projects by name/key and tickets by title,
plain description or ticket reference. Autocomplete is grouped by board, including matching
archived or closed records. Each response replaces the entire result container; there is no
fixed set of boards or ticket slots. Typing is debounced; clearing, closing or changing the
query invalidates earlier responses. Arrow keys select results, Enter follows the selection
(or opens the result page), and Escape closes the suggestions. The native GET search page
also works without JavaScript. Suggestions return at most eight tickets and six additional
matching boards; the result page allows 50 tickets and 20 matching boards, with a refinement
hint when more exist. Both endpoints use current project authorization and private, uncached
responses. SQL wildcard characters are treated literally.

**New ticket** follows the search and reuses the existing ticket dialog. In a writable project
it targets that project directly; outside a project it first offers the writable, unarchived
boards. Read-only project views and the creation page do not offer another creation action.
The previous duplicate action in the large project heading is removed. Extensions can replace
the `core.topbar.workspace` contribution or add controls to the `topbar.tools` slot.

## Switches

On/off controls share the same switch appearance, including personal notifications,
live updates, presence, the local assistant, live answers, invitation delivery, and permissions
for project and installation roles. Registered `boolean` settings use it too. Switches
keep their native checkbox behavior, keyboard activation, visible focus and disabled state.
Personal settings, roles and invitations still take effect through their form's Save or submit
button; project mute switches retain their immediate save behavior. Multiple-choice controls,
such as ticket assignees, keep checkboxes.

## Installation configuration

Global settings use the same typed fields and cards as personal/project settings.
Every leaf of the merged server configuration is described automatically; provider
metadata supplies choices, ranges and secret fields, including SMTP configuration.
Lists and structured values use JSON. Existing application definitions and extension
replacements take precedence over generated fields.

Installation settings initially show the standard fields and cards. The native
**Advanced settings** switch reveals generated configuration cards below them and
an **Advanced settings** fieldset below standard fields in mixed dialogs. The view
preference is kept per administrator in the current browser tab; it does not change
configuration. Hidden advanced controls are disabled and omitted from saves, so a
standard-field save preserves all advanced overrides. Direct links to advanced cards
reveal the advanced view. Extensions can opt fields in with `advanced: true` on
`SettingDefinition` (the default remains false).

New scalar, nested and list entries in the host's `config.php` are discovered on the
next request without a schema registration; lists use JSON and secret-like paths
retain the shared write-only treatment. A central display glossary capitalizes known
abbreviations such as MCP, RBAC, API, SMTP and IMAP; unknown words are humanized and
explicit provider labels take precedence. Literal configuration paths remain unchanged.

The source next to each field distinguishes administration overrides, environment
references, server configuration and declared defaults. Editing a field selects an
administration override. Choosing **Server configuration** deletes that override;
**Clear value** stores an explicit null where allowed. Passwords are write-only: a
blank password input retains the previous value. Secrets never appear in HTML, JSON
read responses or audit payloads.

Writes require installation `settings.manage` and native CSRF validation. The global
endpoint is `GET/POST /api/settings/application`; values cannot be written into personal
preferences. Form revisions reject stale edits with 409. Validation is all-or-nothing;
database changes must connect to an existing application database where the current
administrator remains active and authorized. Existing mail adapters validate active
mail configuration without sending a message. The audit records changed/reset keys.

Overrides load before any plugin boots through the framework's `config_sources.php`
convention, so database, sessions, auth and mail see the same values. They live outside
the database in `storage/configuration/values.enc`, encrypted through the existing token
cipher, with a generated `key` beside it. The directory is private (0700), files are 0600,
and writes are locked and atomic. All application processes/replicas must share this
private storage; back it up with its key. The key's protection rests on filesystem access.

New requests read the saved configuration; restart already-running queue/schedule/socket
workers after changing their configuration. If a start-critical override prevents boot,
set the process environment `NAFINITY_CONFIG_OVERRIDES=0` and restart affected processes
to recover using server configuration. Restore a valid encrypted file and its key, or
move `storage/configuration/values.enc` aside, before removing the recovery override.
The fixed storage location and recovery switch deliberately remain outside editable config.

Custom mail transports may implement `ConfigurableTransportInterface::configurationFields()`
to return colon-separated config paths with label, type, default, index, options and sensitive
metadata. This optional contract extends the existing transport interface; it introduces
no dependency on Board or its renderer. Extensions can also declare application
`SettingDefinition` values with `configKey` using the existing registry.

## Project management

The General card ends with a quiet **Manage project** section for owners. Archiving keeps
the project readable and can be undone with Restore. **Delete project** opens a collapsed
form requiring the project's current name and an explicit confirmation. The server checks
actual ownership under the project lock; ticket deletion or management permissions alone
do not allow project deletion. Archived projects can also be deleted by their owners.

Deletion permanently removes the project, its tickets, comments, attachment records,
structure, notifications, memberships, settings and project-specific role grants in one
transaction. Other projects and account settings remain. Audit entries retain their scope
and the deletion records the project's name; only installation audit readers can reach the
detached history. Private files become inaccessible immediately and are removed by the
existing orphan sweep once older than 24 hours. A failure rolls back the entire deletion.

## Appearance

Personal settings keep the color palette and brightness independent. **Classic** is the
unchanged default palette; **Anthracite** uses calmer gray and sage surfaces in both light and
dark. Brightness can follow the device or be fixed to light or dark. The sidebar shortcut toggles
only brightness and saves the choice for the signed-in account. The login uses the last choice
on that device until a user signs in; authenticated pages use the account's saved values.
Existing preference rows gain a `palette` column defaulting to `classic`, so upgrades keep their
current appearance.

## Board filters

The account modal has a **Board filters** section, also reached through the sliders
beside the board's filters. Each registered filter with a control has a switch. The
selection belongs to the signed-in user across projects and is stored through the
existing user settings service, under `board_filters` as a map of filter ids to booleans.
An omitted id is visible, so newly installed filters appear automatically. Search stays
available. A hidden filter on a shared URL stays visible while it is active and can be
cleared normally; visibility never changes the card query or another user's settings.

Choosing a filter applies it immediately. Fulltext typing applies after a short pause;
the native GET form and its **Filter** button remain available without JavaScript.
The board, counts, drag restrictions and shareable URL use the same server query.

## Cards and permissions

- Personal: appearance, language, time zone, notifications and configured external accounts.
- Local AI: connection, models, live test, extra prompts, memory and feedback.
- General: project details and project management according to your own permissions.
- Roles & permissions: view the default roles; owners can create, change and delete custom ones.
- Users: assign existing accounts by email, change roles and revoke access.
- Columns, swimlanes and labels: edit the existing board structure.

Custom roles apply only inside their project. Read permission is the shared basis. On top of
that, tickets, comments, comment moderation, attachments, project details, user assignment and
board structure can be granted. Moderation requires the comment permission. Owner management,
role management and archiving stay reserved for owners. The default roles are immutable
templates, and at least one active owner always remains.

User managers can only grant or revoke permissions they hold themselves. Owners and managers can
still only be assigned or changed by owners. A custom role that is in use can only be deleted
after a different assignment. Versions prevent overwriting role changes made in the meantime. A
revocation takes effect on the next action, including in the AI tools and in upload recovery.

In Global settings' Users card, role summaries show the board's current name beside the
role. Installation-wide roles are marked **Global**; wildcard project grants say
**All projects**. Long names wrap. Archived boards retain their names, and inaccessible or
missing places show **Unavailable**. Additional scope kinds use the labels supplied by their
registered RBAC scope source.

## Ollama

1. Start Ollama locally and install at least one model with tool support.
2. Set the address under "Local AI", normally `http://localhost:11434`.
3. "Load models" checks the actual capabilities through `/api/show`. Chat models need `tools`;
   embedding models need `embedding`.
4. Pick a chat model, enable the assistant and save. The chat appears at the bottom right.
5. Pick an embedding model to pre-filter tools semantically. Without an embedding model, or on
   an error, a limited keyword search stays available.

## Semantic selection for large tool catalogues

The chat first loads the currently authorized catalogue from NAF's MCP registry. The browser
computes a local vector index from it and hands the chat model only the selected tool
definitions. The selection defaults to eight tools and, independently, to 16,000 characters of
tool schemas. The count limit is adjustable between three and 16. A higher minimum similarity
selects more strictly; in addition, results more than 0.15 below the best cosine score are
excluded. The default threshold is 0.35. These values are heuristics, not a guarantee of a
correct selection by the model.

Required read tools count against the same budget. "Create ticket", for example, needs the
board; editing and moving additionally need the ticket details. Those relationships live as
`meta.requires` on the native tool definitions. If an authorized prerequisite tool is missing, or
the group does not fit the budget, the action is not offered. `meta.keywords` adds search terms
for the keyword search where needed. Packages still have to integrate their tools deliberately
into the authorized application catalogue; public MCP tools are not enabled wholesale as browser
tools.

The index uses SHA-256 fingerprints of the complete definition including schema and metadata.
User, project, Ollama address, model name and the current model digest form the namespace. The
digest is fetched before every selection so that a model replaced under the same tag gets new
vectors. Only missing or changed definitions are embedded, in batches of at most 32 inputs. After
that a new question needs a single query vector. EmbeddingGemma and Nomic receive their
respective query and document prefixes.

Vectors and fingerprints live in IndexedDB and survive a reload. The cache holds no chats and no
tool results; it is capped at 2,000 entries and persisted entries expire after 30 days. Blocked
browser storage falls back to a volatile cache. On an embedding error, matching keyword hits are
selected under the same count and schema limits. The complete catalogue is never sent to the chat
model, not even on error. With no hits the model gets no domain tools at all.

The chat shows "semantic selection" or "keyword selection" and the number selected. Expanded, the
display names the tools as well as reused and newly computed index entries. Execution still
re-checks permissions on the server; a stale cache cannot restore revoked rights.

Verified with a catalogue of seven real and 493 synthetic tool descriptions: the first build with
`embeddinggemma:latest` took about seven seconds, and the following selection with an existing
index 66 ms. The column question returned the board tool only. That is a local selection
measurement, not a statement about 500 implemented actions or about the duration of the chat
answer that follows. `npm test` re-runs the selection checks behind it.

API and prefix contracts: [Ollama Embed](https://docs.ollama.com/api/embed),
[EmbeddingGemma retrieval](https://ai.google.dev/gemma/docs/embeddinggemma/inference-embeddinggemma-with-sentence-transformers),
[Nomic model card](https://huggingface.co/nomic-ai/nomic-embed-text-v1.5).

Ollama has to allow the origin `https://localhost` — for example through
`OLLAMA_ORIGINS=https://localhost`, then restart Ollama. Depending on the browser, permission for
local network access is needed. Nafinity allows only the loopback hosts `localhost` and
`127.0.0.1`, with HTTP or HTTPS and a configurable port. It sends neither cookies nor credentials
to Ollama and follows no redirects there.

The browser talks to Ollama directly. There is no cloud sign-in, no API key and no server-side
HTTP proxy. The nginx CSP allows the local targets named above. A Nafinity development
certificate that is not yet trusted has to be approved once through the generated local CA in
your browser or keychain; TLS checks stay active.

## Chat and tools

The small star icon at the bottom right opens the chat. The surface unfolds straight out of the
44-pixel button and slides back there on X or Escape. The content fades in with an offset without
scaling the type, and the reduced-motion option skips the animation. After closing, keyboard
focus returns to the entry icon.

The header holds the icons for a new chat, AI settings and close. Three entry points in the empty
chat match the current project context; a click only puts the suggestion into the input field,
and sending happens on the arrow or Enter. Shift+Enter inserts a line break. The field grows with
the text and the send arrow stays disabled while the input is empty. During an answer the stop
icon takes the same place. The small live switch in the footer controls streaming.

The chat supports streamed and complete answers, stopping, Markdown with tables and code blocks,
copying, new conversations and feedback on individual answers. The history holds up to 40
messages and the model receives a bounded excerpt of the most recent ones. At most eight tool
rounds are possible per answer. Model answers are rendered but never rewritten by a second model
call.

Write calls show the concrete action with its arguments for confirmation in the chat. The server
additionally requires that confirmation and checks the current sign-in and project authorization
on every execution. The actions use the same application services, transactions, version checks
and events as the interface. The HTTP routes under `/ai` use the native NAF session, CSRF and
rate limit. The local registration is separate from the public `/mcp` endpoint, which still
requires a token; it opens no anonymous access.

Tool arguments must match the tool's input schema. Unknown properties, missing required
arguments, invalid types and values outside the allowed range return HTTP 422 with a translated
validation message. A rejected call does not execute the tool, even after write confirmation.

Nafinity itself provides:

- List your own projects when no project is selected.
- Within a project: read the board, ticket details and activity.
- With the matching permissions: create, edit or move tickets and write comments.

## What an extension can add here

The settings page is itself registered: cards, fields and field types live in
`extensions()->settingSections()`, `->settings()` and `->fieldTypes()`, and a package adds a card
without this page ever knowing its name.

```php
$context->settingSections()->add(new SettingSectionDefinition(
    id:    'example.reports',
    label: 'Reports',
    index: 400,
));

$context->settings()->add(new SettingDefinition(
    key:     'example.retention_days',
    scope:   'project',
    section: 'example.reports',
    type:    'integer',
    default: 30,
));
```

Settings exist in four scopes — `user`, `project`, `project_user` and `application` — and a value
resolves as stored value, then configured key, then declared default. The PHP access is
`Nafinity\settings()` with `get()`, `all()`, `has()` and `collection()`, plus the contexts
`forProject()`, `forProjectUser()` and `forApplication()`, and there are guarded JSON endpoints
for the same values. Existing values stay in the tables they already live in, and a contributed
setting needs no migration.

The AI catalogue is equally open. Nafinity's own tool list is one registered provider among
several: a package contributes its own tools through `extensions()->aiTools()`, and
`Nafinity\Contracts\ProjectToolInterface` extends NAF's `ToolInterface` with a title, a
permission, prerequisites and search terms.

```php
$context->aiTools()->add(new AiToolDefinition(
    id:         'example.report.read',
    tool:       ReadReportTool::class,
    permission: 'example.reports.read',
));
```

Permissions are checked before the catalogue is served and again before execution, and two
providers cannot accidentally claim the same tool name. A contributed tool therefore appears only
for someone who holds its permission.

**The local AI keeps its browser storage.** `settings()->all()` cannot read localStorage, and
neither chat history nor memory nor prompts are transferred to the server — a contributed setting
cannot reach into them. See [Extensibility](Extensibility.md#settings) for the full API.

## Storage and what came from nixcms

The Ollama transport functions, tool conversion and safe Markdown rendering were deliberately
taken from the NAF version of nixcms (`naf/cms`, MIT). The licence text sits next to the
shipped modules in `public/assets/ai/LICENSE`. Transport abort and visible streaming errors
extend that implementation. CMS-specific page builder, article and system tools are replaced by
project-bound Nafinity tools.

Connection, model choice and personal prompts live per user in local browser storage. History,
draft, feedback and memory are additionally separated per project. There is no synchronisation
between browsers. "Analyse feedback" proposes notes for the memory; only an explicit accept and
save changes it. The shipped rules for project boundaries and confirmations stay part of the
system prompt.

## Development and tests

`composer test` runs the database, HTTP and worker tests; `npm test` runs the AI transport and
router tests in `tests/js`. They check streaming, Unicode across packet boundaries, tool answers, errors, aborts, allowed local URLs and
separated storage areas, plus selection from 500 tools, cache invalidation, permission
revocation, dependencies and the fallback and schema limits. The optional live benchmark runs
with `node tests/benchmarks/ai-routing-live.mjs PATH_TO_AUTHORIZED_TOOL_ARRAY.json`; it uses an already
installed local model and executes no domain tools.

The native migration `M202609150001ProjectRoles` adds custom roles, permissions and a
project-bound optional membership assignment. Existing memberships are preserved.

## Ticket export

The Export card now lives in the board's settings and exports only that board. Installation
settings have a separate Export card with a searchable board selector, including **Alle** for
all active boards the actor may export. Both cards offer the registered formats (CSV, JSON and
formats from installed extensions), ticket status, archived ticket inclusion, an inclusive UTC
updated-date range, descriptions as stored HTML, and readable extension fields. Selections are
per download and do not change saved board settings. The default form excludes archived tickets
and descriptions. Combined files identify each row's board.

Installation settings require `settings.manage`; both paths additionally enforce each board's
`export` permission. Archived boards remain read-only and outside the selection. Existing
installation role grants are preserved; a role without `export` must be granted that permission
in the role editor. There is no implicit escalation from managing installation settings.

This is a ticket export, not a backup: attachments, comments and board structure are not included.
The extension contract and request parameters are documented in [Extensibility](Extensibility.md#export).

## Interface language

The account language applies to HTML pages, ticket fragments, JSON validation messages,
activity labels and AI tool titles. Browser status messages use the same translation
catalog through an inert JSON block rendered in the page. Project names, columns, labels,
ticket text and extension-owned content retain their own wording.
