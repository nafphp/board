# Settings, custom roles and local AI

Settings are reached through the entry at the bottom of the sidebar. The project picker switches
between personal settings and the settings of a visible project. A card opens as a large dialog
and slides back on the X or Escape. Input is preserved on close, and after a successful save the
same card is reopened. The animation honours the operating system's reduced-motion option. On
small screens the dialog takes up nearly the whole area.

## Cards and permissions

- Personal: appearance, language, time zone, notifications and configured external accounts.
- Local AI: connection, models, live test, extra prompts, memory and feedback.
- General: project details and archiving according to your own permissions.
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
answer that follows. `make test-ai` re-runs the selection checks behind it.

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
shipped modules in `app/public/assets/ai/LICENSE`. Transport abort and visible streaming errors
extend that implementation. CMS-specific page builder, article and system tools are replaced by
project-bound Nafinity tools.

Connection, model choice and personal prompts live per user in local browser storage. History,
draft, feedback and memory are additionally separated per project. There is no synchronisation
between browsers. "Analyse feedback" proposes notes for the memory; only an explicit accept and
save changes it. The shipped rules for project boundaries and confirmations stay part of the
system prompt.

## Development and tests

`make test` includes the database, HTTP, worker and AI transport tests. `make test-ai` checks
streaming, Unicode across packet boundaries, tool answers, errors, aborts, allowed local URLs and
separated storage areas, plus selection from 500 tools, cache invalidation, permission
revocation, dependencies and the fallback and schema limits. The optional live benchmark runs
with `node app/tests/ai_routing_live.mjs PATH_TO_AUTHORIZED_TOOL_ARRAY.json`; it uses an already
installed local model and executes no domain tools.

The native migration `M202609150001ProjectRoles` adds custom roles, permissions and a
project-bound optional membership assignment. Existing memberships are preserved. `make test-up`
applies migrations before waiting for readiness in the isolated test database.
