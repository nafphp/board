# People and bulk properties

The installation's Users card uses a searchable table, ordered by name and id,
with at most 50 accounts per page. Search matches literal name/email substrings;
`%` and `_` are not wildcards. A row opens an account editor in a separate native
dialog. Editors are fetched on demand rather than rendered for every account.
The primary invitation action sits at the top right above the table.

Select individual rows or all eligible rows on the current page to reveal the
bulk action bar. Search and pagination clear the selection. The confirmation
form states the target count and requires explicitly enabling each property;
unselected properties are omitted, and empty strings, false and null remain
values when their field type accepts them. Own-account rows cannot be selected.

The initial properties add or remove one **global role**. These operations merge
with current grants, preserve other roles and every scoped grant, and are
idempotent. Scoped roles remain in the individual editor: board membership has
additional rules and must not be inferred from a global grant operation.
The role choices are restricted to the actor's current authority. Account names,
credentials, account activation and personal preferences are not mass editable.

## Extension API

`BulkProperty` is an explicit opt-in, separate from settings and ticket-field
definitions. A registered field does not automatically become mass editable.
A provider registers a resource/key pair, existing field type and domain handler:

```php
use Naf\Board\Definition\BulkProperty;

$context->bulkProperties()->add(new BulkProperty(
    key: 'review_state',
    resource: 'example.records',
    label: 'Review state',
    type: 'select',
    handler: ReviewStateBulkHandler::class,
    options: ['choices' => ['pending' => 'Pending', 'reviewed' => 'Reviewed']],
));
```

The registry follows the normal ordering, duplicate/replacement and removal rules.
Identity is `resource:key`; `forResource()` returns definitions keyed by property.
Providers and host overrides can replace/remove definitions through
`bulkProperties()`. Removal removes the capability, without deleting stored data.

Implement `Naf\Board\Contracts\BulkPropertyHandlerInterface`:

- `options(BulkProperty $property, int $actor): array` authorizes the operation
  and resolves server-side type options, including currently permitted choices.
  It must refuse unauthorized use even if the property was visible earlier.
- `prepare(BulkProperty $property, int $actor, array $targets, mixed $value): Closure`
  runs in the batch transaction. Lock aggregates in deterministic order, verify
  every target's existence, authorization and domain invariants, and check expected
  versions for replacement operations. Return a write closure using the same PDO
  connection. Never commit, send mail, perform network calls or write files here.
  For several properties on the same aggregate, the closure must merge with earlier
  writes in this transaction rather than replaying a prepared stale snapshot.

`BulkUpdateService::properties($resource)` returns authorized definitions and
resolved options for a form. It hides only permission denials; unexpected errors
remain visible. `apply($resource, $targets, $values)` accepts 1–50 explicit ids and
1–20 opted-in keys, validates raw values before normalization through the existing
field types, prepares every write before executing any, and commits once. Any
exception rolls back every target, property and database-backed audit event.
Targets are deduplicated and sorted. Omitted keys are unchanged. Unknown keys,
invalid shapes, invalid values and implicit “all results” selections are refused.
No table or column names come from request input.

The service is reusable by other controllers. A new resource still needs its own
read/UI authorization and CSRF-protected endpoint; registering a property creates
no public route. Ticket handlers must retain project authorization, project locks,
ticket versions and board revisions. The framework cannot infer those rules.

## Initial HTTP surface and guarantees

`GET /settings/users` returns the bounded table fragment (`users_q`, `users_page`).
`GET /settings/users/{user}` returns a single editor. Both require current login,
`settings.manage` and `users.view`, and successful fragments are not cacheable.
`POST /settings/users/bulk` additionally requires `users.manage`, `rbac.manage`,
CSRF and the privilege policy for every target. Its body contains only explicit
`targets` and a `values` patch (initial keys: `add_role`, `remove_role`). Adding and
removing the same role in one request is refused as ambiguous.

Global role definitions, their permissions and global assignments are locked
before the privilege snapshot is read; selected accounts are checked under locks.
This deliberately serializes global privilege batches. It avoids widening rights
from a stale role definition. Changes dispatch the existing `GrantsChanged` events
inside the batch transaction, so audit listener failures roll back the grants too.
Listeners must keep external side effects out of this transaction.

Regression coverage checks bounded reads, lazy editors, literal searches, CSRF,
unauthorized reads/writes, malformed selection, self protection, privilege reach,
role preservation, idempotency, multiple-property merging, contributed field types
and full rollback after a later property write fails.
