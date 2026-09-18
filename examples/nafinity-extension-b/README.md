# example/nafinity-extension-b

A second example extension for the Nafinity ticket application. Where extension
A adds things, this one changes things that already exist — which is the harder
half of being extensible.

**Host requirement:** the `fkde/nafinity` application.
**Depends on:** `example/nafinity-extension-a`, because it replaces that
extension's contributions and adds a widget beside it.

It registers with index 200, so it runs after extension A (index 100) and can
replace what A registered.

## What it does

| Change | How |
|---|---|
| Counts how often a ticket was written | decorates `TicketServiceInterface` and forwards everything else |
| Replaces extension A's ticket widget | `ui()->add(..., replace: true)` under the same id |
| Adds a second widget at index 150 | the same index as A's, so the tie is broken by id |
| Replaces a settings field's section | re-registers `example.reports.limit` in its own card |
| Replaces a view | `views()->add(new ViewOverride('example-a/reports', 'example-b/reports'))` |

Because its provider runs after A's, each of these is an ordinary explicit
replacement rather than a race.
