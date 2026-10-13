# Ticket details

The ticket view shows the key at the top, then title and description. Below that come linked
tickets, attachments, the collapsible history and finally the comments. The input for a new
comment follows the discussion in the normal document flow, so it cannot cover attachments,
history or existing comments. On the desktop the metadata bar sits on the right; on small
screens it follows the description.

The same view works on the ticket's own URL and in the board drawer. The whole ticket card opens
the ticket, description and empty space included. The card menu stays independently operable,
including right after a drag gesture, and the native title link still supports the keyboard, the
context menu and opening in a new tab.

Board cards expose the translated priority, ticket status, full assignee names and the
purpose of the due date to assistive technology. Decorative initials and icon names are
hidden. Project member avatars likewise expose full names. The card action menu and shared
icon controls retain at least a 32 × 32 CSS pixel target even at the smallest configured
density. Card titles, descriptions, labels, references and dates use larger reading sizes.

Label text follows the theme's reading color; its colored edge and tinted background keep
the chosen label color visible. The built-in palettes meet 4.5:1 contrast for label text,
including black, white and saturated label colors. The light Classic palette also uses a
darker secondary text color. Custom theme colors and CSS still need their own contrast
review. Regression checks cover the built-in palettes, supported control densities and the
same accessible card markup on the board and live-update endpoint, in German and English.

## Board filters and DQL

The board starts with a compact **Filter** disclosure and **Views** action. Open Filter
for the usual dropdowns; **Advanced** replaces them with a DQL field. Opening Advanced
translates the current filters. Press Enter or **Apply** to run a query. Returning to
**Simple filters** validates and applies the query, filling the representable dropdowns.
`OR`, negation, lists, repeated properties and other complex conditions remain visible
as an additional DQL expression. Switching modes never silently discards them.

```text
status = open AND assignee = me
priority IN (high, urgent) AND due < 2030-01-01
board = NAF AND column = "In Arbeit"
(label = "Design" OR label = "Produkt") AND assignee != none
```

| Property | Values and operators |
|---|---|
| `status` | `open`, `closed`, `archived` |
| `board` | Project id or board ticket key, such as `NAF`; always within the current authorized board |
| `assignee` | `me`, `none`, user id, or exact active project member name/email |
| `column`, `swimlane` | Id or exact displayed name |
| `label` | Id, exact displayed name, or `none` |
| `priority` | Registered priority id, including priorities from extensions |
| `title` | `=` for equality; `~` for a literal substring |
| `text` | `~` or `=` for the existing database fulltext search over title and description |
| `number` | Positive ticket number; also `<`, `<=`, `>`, `>=` |
| `due`, `created`, `updated` | `YYYY-MM-DD`; also `<`, `<=`, `>`, `>=`; `due = none` finds missing due dates |

All properties support `=`, `!=`, `IN (...)` and `NOT IN (...)`. `NOT` binds before
`AND`, then `OR`; parentheses override precedence. Property names and keywords are
case-insensitive; value ids and displayed names use their exact spelling. Quote values
containing spaces with single or double quotes, and escape their quote or a backslash
with a backslash. `ticket.status`, `board.identifier` and `assigned` are aliases for
`status`, `board` and `assignee`; `q` is an alias for `text`.

Archived tickets remain excluded unless the expression explicitly references the
`archived` status. An ordinary open/closed dropdown keeps excluding archived tickets
even when combined with such an expression. Created/updated comparisons use stored UTC
dates. `me` remains the current reader in both modes and in shared links; choosing a
named person keeps that person's fixed id. Existing numeric assignee links still work.

Queries use one line and reject control characters. They are limited to 2000 characters,
256 tokens, 12 nested groups/negations and 20 values per list. Syntax/property/value errors return 422 with a position and field
message. The board keeps the query editable and shows no cards for an invalid query;
it does not fall back to unfiltered results. Live cards, counts and saved views reuse
the same server-side parser, prepared parameters and project authorization.

The board's **Views** action opens the shared dialog and keeps up to 20 named filter
bookmarks per person and project, using the native settings store. Saving validates the filters through the
same authorized board query; stale bookmark writes return 409. Names are escaped. A view
link contains the normalized core and extension filters, so it can be shared without
exposing a private bookmark id. Opening it always checks the recipient's current project
access. Removing an extension leaves its saved filter intact; opening that view reports
the unknown filter instead of silently widening the result.

**My tickets** lists the current person's assignments across readable, active projects.
It excludes archived tickets and projects, starts with open tickets, and offers closed or
all non-archived tickets, due-date or last-change ordering, and pages of 50. Every project
is authorized before querying; an old assignment never grants access after membership is
revoked. It is a separate list, so later pages remain reachable independently of the
board's 300-card limit.

When dragging a ticket, a muted placeholder reserves its original position until the card
lands. The active drop preview uses the accent colour; closing columns retain their completion
highlight. Both placeholders match the carried card's height. Returning to the original
position activates that same placeholder, without adding a second gap. Empty-state hints stay
hidden during dragging, so their larger box does not compete with the card-sized previews.
After dropping, the original gap closes with the board's normal motion; cancellation restores
the card to that reserved position.

## Creating a ticket in the modal

**+ New ticket** on the board opens the right-hand ticket drawer. It renders the same
`src/views/ticket.phtml` and the same field components as the detail view: title and Quill
description on the left, column, assignees and the remaining metadata on the right. Start date,
due date, estimate, logged minutes, labels and colour are all available at creation time. On
small screens the sidebar follows the description.

The title opens for typing immediately. Description and metadata first show their value or a
muted placeholder; **Beschreibung einfügen** opens the description editor on demand. The same
field component and open/close/keyboard flow serve both the draft and the saved ticket, in the
drawer and on a standalone page. Leaving a draft field updates its displayed value locally;
Escape restores that field to its value when opened, leaving the other draft fields intact.
Required fields are opened when native form validation needs them. Quill loads once, on the
first description edit, through the one `core.extensions` browser entry point.

Opening the creation drawer keeps the current board URL, including its filters. A same-URL
history entry lets Back close it through the usual draft confirmation. Reloading returns to
the board. The native browser warning protects changed drafts when reloading or leaving the
page; it stays silent for an untouched draft or after every change was restored. Merely opening
the description editor does not count as a change. Once creation succeeds, the saved ticket's
URL replaces this entry. The direct creation address remains available for a new tab or native
navigation without JavaScript.

The inputs form one shared draft — the column choice, too, is only stored on **Create ticket**.
The action stays reachable at the bottom edge of the modal. On success the modal shows the saved
ticket with its own URL and inline editing, and comments, attachments and links become
available. The board shows the existing prompt to load the updated state.

Cancel, close, Escape, a click on the backdrop and the browser's back button all ask before
discarding changed input. **Keep editing** preserves the draft. The modal stays open while
saving and repeated submits are blocked. Errors and version conflicts keep the input. If
creation was already confirmed and only the reload fails, the offered link leads to the created
ticket; no second one is created.

`GET /projects/{project}/tickets/new?fragment=1` returns the shared form fragment and checks
write permission in the project. The normal URL still renders a complete page. Without
JavaScript a native POST form with a plain-text description stays usable, and that plain text
can also be saved when Quill has not loaded. The existing `TicketService::create()` handles
validation, CSRF is checked by the form plugin, and the board revision guards against stale
writes. There is no additional save logic.

## Reading and editing

Title, description and editable metadata first appear as ordinary content. Clicking, or focusing
with Tab and pressing Enter or Space, opens the matching input. Title, description, dates,
colour, time and labels save automatically after a 700 ms pause in typing. Choices such as
column, assignees, status, priority and swimlane save immediately. Unchanged values trigger no
request. One central status shows pending, successful or failed saves; individual fields need no
save buttons.

Leaving a field saves and restores the reading view. Enter in the title, or Cmd/Ctrl + Enter,
commits as well. Escape discards only the changes made since the last confirmed save; values
already saved automatically remain. The inputs adopt the font size and line height of the
reading view. The title field grows with the text while the description editor and its toolbar
stay compact.

Only ever one metadata field is open. You can keep typing while a save is in flight; editor,
selection and cursor are preserved, and further changes are then sent with the current version.
Other ticket actions are processed in order too. A comment written in parallel is preserved and
only ever sent explicitly. When closing — including by backdrop click — the drawer waits for an
open field to finish saving. A comment draft or a failed save can be edited further or
explicitly discarded.

Invalid values and connection errors stay visible as a draft with an error message and a retry
action. Version conflicts require loading the current state; stale changes are never retried
automatically. If a change was saved but the reload fails, the interface likewise asks for a
reload and does not send the change again. Reading roles get no editing controls. Archived
tickets keep their existing restore action.

The description uses locally served **Quill 2.0.3** (BSD-3-Clause): headings 1–3, bold, italic,
underline, strikethrough, lists, quotes, code blocks, links and clearing formatting. Text is
reduced to the supported formats on paste and on save. The server-side Symfony HTML Sanitizer
allows only the required elements and links with http, https, mailto or relative targets. HTML
attributes for scripts, styles, images, frames and embedded media are not allowed. A description
holds at most 50,000 text characters or 100,000 HTML characters. The additional plain-text column
keeps full-text search and existing integrations usable. Existing descriptions stay plain text
until they are saved through the editor.

## The right-hand sidebar

Column and assignees use the same searchable dropdown. The very first click on the displayed
value opens all entries. For columns exactly one row is chosen and the menu closes afterwards.
People appear as name rows with an avatar, and a subtle tick marks the selection. Clicking again
removes a person and further names can be added. **Unassigned** removes every assignment. The
closed display shows the first name and, for example, **+1**, while the full selection stays
visible in the menu. Multiple assignment remains possible.

The search filters the list only and saves no ticket change. Arrow keys navigate, Enter selects,
Escape closes the menu, and Tab or a click outside leaves it as well. When creating, these
choices are part of the draft until **Create ticket**. The list stays inside the viewport on
narrow screens and opens upwards when space is short. `components/choice.phtml` and
`choice.js` extend the existing native form values; endpoints, NAF validation, CSRF and
the revision check are unchanged. The native
[Popover API](https://developer.mozilla.org/en-US/docs/Web/API/Popover_API/Using) keeps the menu
above the dialog. Without that API it is positioned fixed, and without JavaScript a select and
checkboxes remain as the form fallback when creating.

| Group | Values |
| --- | --- |
| Top | Board column, then assignees (several possible) |
| Details | Open/closed, priority, swimlane, labels, accent colour |
| Planning and time | Start date, due date, estimated and logged minutes |
| Information | Creator, project, created/changed/closed/archived, version |

The dot menu at the top right also moves a ticket to another project. Only destinations where
the person may write are offered; the ticket is given a new number there and therefore a new
key. Comments, attachments, history and logged time come along — running clocks are stopped
first so the work is still booked. Labels, links and assignees without access in the target
project stay behind, because they would designate nothing there. `TicketService::transfer()` is
responsible, and the old address answers with 404 afterwards.

Column changes use `TicketService::move()` including completion status and positioning. Metadata
uses `TicketService::update()` as a partial change inside the existing project transaction.
Attributes and associations that are not submitted are preserved. Updates require the ticket
`version`; an unrelated ticket changing the board revision does not reject them. Creation and
movement retain their board revision checks. Status changes use the existing state service. System values such as creator
and change date are read-only.

The ticket keeps a manually editable **total in minutes** and a server-backed work timer.
Starting or resuming counts elapsed seconds; pausing holds them without booking, and stopping
adds whole minutes to the ticket. Remaining seconds carry into the next session. New timer
bookings also enter a per-person journal used by the [personal hours export](Profile.md#exporting-personal-hours).
Manual edits of the aggregate do not create personal bookings. The display converts to hours
and minutes. When an estimate exists,
progress and remaining time — or an overrun — are shown. The start date may not lie after the
due date.

## What an extension can add here

The sidebar fields and the widgets between description and comments are registered
contributions. The four groups — status, details, planning & time, information — and the three
middle areas — links, attachments, history — sit in the same registry that an installed package
uses, so their order, their presentation and their presence are all determined by it.

A contributed field declares itself once and needs no migration:

```php
$context->ticketFields()->add(new TicketFieldDefinition(
    key:   'example.reviewed',
    group: 'details',
    type:  'boolean',
    label: 'Reviewed',
    index: 150,
));
```

Its value is stored in `ticket_metadata`, one row per key, written inside the same transaction,
under the same project lock and with the same version check as the core fields. Version and
board revision rise exactly once per accepted change, even when only metadata is affected — so a
contributed field inherits auto-save, draft preservation, keyboard handling and conflict
detection without doing anything for them.

A widget between description and comments is registered the same way against
`ticket.main.widgets`, a sidebar panel against `ticket.sidebar.panels`, and an entry in the dot
menu against `ticket.actions`. Each is rendered with a
[`TicketSlotContext`](Extensibility.md#what-a-slot-hands-over) that already carries the ticket,
the project, the board, the permissions, the metadata and the field definitions — so a
contribution does not query for what the page already holds:

```php
/** @var \Nafinity\Support\TicketSlotContext $slot */
if ($slot->value('example.reviewed') !== true) {
    return;
}
echo $slot->field->render('example.note', 'Review note', 'text', $slot->value('example.note'));
```

`$slot->field->render(...)` reuses the built-in inline editor, so a contributed field behaves
exactly like a core one.

**Title, description and comments are exempt.** They are not contributions and can be neither
replaced nor removed through the registries — `TicketFieldRegistry::FIXED_AREAS` and
`UiRegistry::RESERVED_IDS` enforce that. Attempting it throws rather than silently doing nothing.

See [Extensibility](Extensibility.md) for the full API, the slot list and the negative cases.

## Personal sidebar

Choose **Deine Ticketansicht anpassen** at the bottom of a ticket's sidebar to open
**Deine Ticketansicht** in that board's settings. Each person can show or hide registered
attributes for that board, including contributed metadata. Groups and field order stay
as defined by the registry. Newly installed attributes are visible by default;
**Standard wiederherstellen** removes the personal override.

This is a `project_user` setting named `ticket_fields`, stored through the existing
settings service. It changes presentation only: ticket values, versions, permissions,
exports and other people's views stay intact. Viewers can configure their own display.
Creation forms retain their fields, empty groups disappear, and ticket actions and the
customization link remain reachable even when every attribute is hidden. Hiding tracked
time also hides its timer control, without stopping any running timer.

Labels and values share their first text baseline, including inline editors, badges and
read-only information. This keeps their alignment when values wrap or an editor opens.

## Links and comments

Ticket sections share a 32 px vertical separation, with a separate heading-to-content gap.
The comment composer has 20 px inner padding (16 px on phones), a 96 px minimum writing
area and a separated footer whose hint and send action can wrap. Comment text, bylines,
reply/edit actions and expanded edit forms have their own gaps. Linking and upload forms
use the same 16 px content spacing; hidden error messages and reply context leave no gap.
The composer belongs to the comments section but stays outside the refreshed thread, so
loading updated comments preserves the current draft.

**Link ticket** uses the shared searchable choice: type a bare number, a full reference such as
`NAF-42`, or part of the title. It suggests at most 12 tickets from the current board, with exact
numbers first. The current ticket, archived tickets and existing links are excluded. Search
is project-authorized and never cached. Without JavaScript, enter the number in the native field.

Choose the relationship from this ticket's perspective: relates to, blocks / is blocked by,
duplicates / is duplicated by, clones / is cloned by, or resolves / is resolved by. The other
ticket displays the inverse automatically. One canonical pair stores one type and direction;
the write API can replace these without adding another pair. Removing a link affects only the
relationship. Existing pairs remain symmetric. The `M202610110002TicketLinkRelations`
migration adds the type and direction without changing existing pairs. Writes retain CSRF,
project permissions, ticket versions and the existing activity/live-update transaction.

`@` in the comment field opens the list of active project members. Arrow keys navigate, Enter or
Tab commits and Escape closes. Handles are derived from the existing display names
(`Anna Schmid` → `@anna.schmid`), with the user id appended when names collide. There is no
second user directory for this. A later name change leaves already stored comment text unchanged.

**Reply** inserts the handle before the existing draft and shows the reply reference above the
field. The comment additionally stores the concrete parent id. Replies appear underneath the
comment they answer, including replies to replies. After a parent comment is deleted its replies
stay visible under a placeholder and the deleted text is not served. Foreign project and ticket
ids and already deleted reply targets are refused. The existing comment permissions, version
check, activity and project notification settings remain authoritative. Cmd/Ctrl + Enter sends.
An open reply can be released through the × in the reply reference.

## Installation and data model

In a Nafinity installation, from the skeleton's directory:

```sh
make backup
make migrate
make restart-background
```

Quill including its licence lives in `src/Resources/public/assets/vendor/quill/`; browsers load no editor
files from a CDN. `symfony/html-sanitizer:^7.4` and `ext-dom` are explicit Composer requirements
and support the application's PHP 8.3 floor.

The NAF migration `M202609160001TicketDetails` adds `description_html`, `start_date`,
`estimate_minutes`, `spent_minutes`, `comments.parent_id` and `ticket_links` with project-bound
foreign keys. `M202609160002RichTextStorage` widens the description fields to MEDIUMTEXT so the
character limits also cover Unicode and HTML.
Readiness checks both migrations. A down of the second migration assumes the old, smaller limit
and can be refused when large descriptions were created afterwards — do not use it as an
operational rollback.

## Verification

Covered by the standard suite; run the command rather than trusting a number written here.

| What | Command |
|---|---|
| Services, migrations, metadata, ticket endpoints, permissions, conflicts and private files | `composer test` |
| The fixed areas and the registry rules for contributed fields and widgets | `composer test` (`tests/Unit`) |
| Style, JavaScript and whitespace | `composer style:check`, `npm run style:check`, `git diff --check` |

Additionally verified interactively in the browser, with the real templates and assets on
desktop, in the drawer, in light and dark, and at 390 × 844 and 320 × 568 pixels: formatting,
escaping, immediate column selection, draft preservation across delayed responses, consecutive
versions, saving on close, no further POSTs after a 409, the create modal from board entry to
detail view, the shared dropdown with multiple selected people, and auto-save on a contributed
field.

Two defects found that way are fixed: a bug in the resize handler that pushed the dropdown out
of the viewport at 320 pixels, and a hidden file input in the attachment form that stretched to
full width and shifted every ticket page 34 pixels sideways on a phone.

The last full run and its numbers are recorded in
[Implementation and acceptance](Implementation.md#verification).


Inline field buttons expose the field label, current displayed value and edit action together.
Clicking description text or its empty placeholder opens the inline editor directly, just like
the title; links inside the description keep their normal navigation. Rich descriptions remain
readable document content. A visually hidden edit button stays available through Tab and
Enter or Space, with its focus outline shown around the description, so links and headings
are not flattened into one long button name.

A real version conflict keeps the current editor open. “Entwurf sichern” downloads that form's
current values as JSON pairs (including repeated field names), without CSRF tokens or lock
versions. This is a manual recovery aid before reloading; it does not overwrite concurrent edits.

## Replies from an IMAP mailbox

Native Mail-to-Ticket is disabled until the host supplies `mail:imap` configuration.
Set **Anfragende E-Mail-Adresse** on a ticket to associate its requester. With mail enabled,
that authorized change generates a random **Vorgangsnummer** and queues a generic confirmation
with the mailbox as Reply-To. It exposes no internal ticket key, title or description.

Only mail with one known `Vorgangsnummer: V-…` (or `Ticket-Nr: V-…`) and exactly the stored
requester address adds a plain-text comment. Incoming mail never creates tickets. A dedicated,
active integration account needs current read/comment rights in the ticket's project. The
comment names the original sender; internal comments are not automatically forwarded.

Changing or clearing the requester revokes the previous reference, including while polling
is disabled. References survive project transfers; import rechecks the destination's rights.
Unknown, automatic, oversized or mismatched mail remains in the mailbox with its flags
unchanged. Attachments remain there too. Imported comments and their deduplication receipts
share one transaction; a retry after an IMAP flag failure cannot add a second comment.

`nafinity:mail:check` checks the configured TLS connection, login, folder and integration
account without importing. `nafinity:mail:poll` runs one batch; `--rescan` starts again at
the beginning while retaining receipt deduplication. Normal scheduling polls once per minute,
up to 50 UIDs per run. An IMAP UIDVALIDITY change resets the cursor. SMTP and IMAP share the host's `mail` sender and credentials, with protocol-specific overrides. The host owns the sending domain's SPF, DKIM and DMARC records.

The sender address and public reference implement routing, rather than cryptographic sender
verification. Receiving-provider spam and mail authentication controls remain relevant.
OAuth accepts an externally refreshed access token; provider-specific consent and refresh
flows are outside this integration. See the central documentation draft
`drafts/nafinity-mail-to-ticket.md` for environment setup and all configuration keys.

Malformed IMAP date headers and detected sender-spoofing header conflicts are unsupported
messages: polling advances past them without marking them seen. Connection and configuration
failures still stop the poll for retry, so transient failures never silently discard replies.
