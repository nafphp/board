# Optional WIP rules

The core column WIP value is advisory. Install the focused Composer extension in
`examples/nafinity-wip-policy` to make it an admission rule. This keeps an optional team
policy out of installations that prefer advisory limits and demonstrates the same extension
API a separately distributed package uses.

In a development host, add a path repository pointing at that directory and require it:

```json
{
  "repositories": [{ "type": "path", "url": "../board/examples/nafinity-wip-policy" }],
  "require": { "example/nafinity-wip-policy": "*@dev" }
}
```

Run Composer in that host. The package needs Board 0.1.6 or newer; a source development host
must resolve the Board RC explicitly through its normal development manifest. Never add
path repositories or a fake stable version to an installation's release manifest. This
package has no migrations or assets of its own.

Project managers can switch **Enforce WIP limits** off in the extension's **WIP rules**
settings card. With it on, creating, moving, reopening, restoring or transferring an open
ticket into a full column returns 409 and leaves the ticket, board revision, audit entries,
notifications and queue unchanged. The rule counts non-archived open tickets across all
swimlanes, including cards beyond the board's display cap. A null WIP limit is unlimited.
Closing or archiving tickets frees space. Ordinary edits, moves out and reordering within
the same column remain possible when an existing column already exceeds its limit.

The listener runs inside the native transaction while the project lock is held. Concurrent
admissions serialize through that lock; it has no second database, router or transaction
system. It sends no external messages. Uninstalling the package removes enforcement and
leaves the stored setting alone. This is one focused workflow rule, not an automation engine
or an SLA/helpdesk policy.
