# Ticket details

The ticket view shows the key at the top, then title and description. Below that come linked
tickets, attachments, the collapsible history and finally the comments. The input for a new
comment stays visible at the bottom edge. On the desktop the metadata bar sits on the right; on
small screens it follows the description.

The same view works on the ticket's own URL and in the board drawer. The whole ticket card opens
the ticket, description and empty space included. The card menu stays independently operable,
including right after a drag gesture, and the native title link still supports the keyboard, the
context menu and opening in a new tab.

## Creating a ticket in the modal

**+ New ticket** on the board opens the right-hand ticket drawer. It renders the same
`app/app/views/ticket.phtml` and the same field components as the detail view: title and Quill
description on the left, column, assignees and the remaining metadata on the right. Start date,
due date, estimate, logged minutes, labels and colour are all available at creation time. On
small screens the sidebar follows the description.

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
narrow screens and opens upwards when space is short. `ticket/choice.phtml` and
`ticket-choice.js` extend the existing native form values; endpoints, NAF validation, CSRF and
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
Attributes and associations that are not submitted are preserved. `version` and `board_revision`
are still required. Status changes use the existing state service. System values such as creator
and change date are read-only.

Time tracking is a manually editable **total in minutes**; it is not a stopwatch and not a
per-person booking journal. The display converts to hours and minutes. When an estimate exists,
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

## Links and comments

**Link ticket** expects the number of another ticket in the same project, for example `42` for
`NAF-42`. A link appears on both sides; duplicates and self-links are prevented. Removing one
affects the relationship only.

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

In source mode, from the project directory:

```sh
bin/dev-composer install --no-interaction
make backup
make migrate
make restart-background
```

Quill including its licence lives in `app/public/assets/vendor/quill/`; browsers load no editor
files from a CDN. `symfony/html-sanitizer:^7.4` and `ext-dom` are explicit Composer requirements
and support the application's PHP 8.3 floor.

The NAF migration `M202609160001TicketDetails` adds `description_html`, `start_date`,
`estimate_minutes`, `spent_minutes`, `comments.parent_id` and `ticket_links` with project-bound
foreign keys. `M202609160002RichTextStorage` widens the description fields to MEDIUMTEXT on
MariaDB so the character limits also cover Unicode and HTML; PostgreSQL's TEXT needs no widening.
Readiness checks both migrations. A down of the second migration assumes the old, smaller limit
and can be refused when large descriptions were created afterwards — do not use it as an
operational rollback.

## Verification

Covered by the standard suite; run the command rather than trusting a number written here.

| What | Command |
|---|---|
| Services, migrations and metadata on both databases | `make test-mariadb`, `make test-postgres` |
| Ticket endpoints, permissions, conflicts and private files over real HTTPS | `make test-http` |
| Contributed fields, widgets and the fixed areas | `make test-plugins` |
| Style, JavaScript syntax and whitespace | `bin/style check`, `git diff --check` |

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
