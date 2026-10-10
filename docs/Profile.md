# Profile and account verification

The avatar in the top right and the whole user card at the bottom of the sidebar open the same
dialog. Name, avatar and arrow form one button that also works from the keyboard. The card and
the header show the role in the current project, including custom roles. Outside a project it
reads "personal account", because roles are project-scoped. The modal lists the assigned project
roles with links to their boards, personal settings, board filter visibility, local AI, password
change, email change and sign-out. **Project notifications** offers a mute switch per assigned
project, with the saved state shown beside its name. The switch saves immediately for the current
account and keeps the modal open; other members' notification preferences remain independent.
Archived memberships stay listed, while administrative access without a membership does not add
a project to this personal list.

The native HTML dialog holds keyboard focus. The X, Escape and a click on the backdrop close it
and return focus to the trigger. Opening and closing animate over 260 and 150 ms;
`prefers-reduced-motion` turns the movement off. Mobile widths, both brightness modes in
both palettes and forced system colours are handled in CSS. Password and code are cleared from the fields on submit and on
close; the application never puts them in LocalStorage or IndexedDB.

## Inviting people and assigning boards

**Invite person** in installation settings and **Invite new person** in a board's
member settings share the same invitation service. Choose an initial board, email and board
role. The default is Member; only a current owner can grant Owner or Manager. Project custom
roles follow the same permission checks as direct membership changes. With email delivery
enabled, the form can send the invitation; otherwise it returns a link to copy and share.
No administrator chooses the recipient's password.

The recipient opens the link, enters a name and their own password, and immediately reaches
the inviting board. The email is bound to the invitation and cannot be changed at registration.
The password follows the same 15-character/72-byte rule as password changes. New invited
accounts receive only the selected board membership, with no installation role or right to
create projects. An invitation does not set `email_verified_at`: administrators may copy links,
so possession alone does not prove delivery to the named mailbox.

Use **Search accounts** in another board's member settings to add that person later. After
two characters, autocomplete finds active accounts by name or email, and selecting a result
fills the email. A complete email also works without JavaScript. Only people allowed to manage
members of that board can use the directory; it returns at most 20 names and email addresses.
Adding existing accounts continues to use the regular membership service and its role checks.
Already existing accounts cannot be recreated through an invitation. If an account is created
after a link was issued, its owner must sign in with that account before joining. Existing active
membership and credentials are preserved.

Links expire after seven days, can be used once and can be revoked under **Pending invitations**.
Inviting the same address to the same board again replaces the previous link. Different boards
can have separate invitations. Acceptance rechecks the inviting person's current membership,
role permissions, the custom role and the board's archive state. Revocation, expiry, loss of
the sponsor's rights, archiving and project deletion prevent registration through that link.
Registration, membership assignment and consuming the token commit together. The database
stores only the SHA-256 token hash; audit events contain no invitation token or email.

The public link and confirmation pages use `Cache-Control: private, no-store` and
`Referrer-Policy: no-referrer`. Creation, searches and acceptance use NAF's persistent rate
limiter; all writes require native CSRF protection. The public URL comes from `app:url`, not
the request's Host header. Email is sent synchronously through NAF's Mailer. A delivery failure
returns 503 and preserves any previous invitation. SMTP and SQL cannot commit atomically:
a delivered link can be unusable if a later event or commit refuses the write.

`M202610100001ProjectInvitations` adds `project_invitations`. The public contract is
`InvitationServiceInterface`; UI entry points call it rather than creating accounts directly.
Installation administrators retain the programmatic `AccountServiceInterface::create()` API
for provisioning. Regression coverage lives in `InvitationTest`, `InvitationOverHttpTest` and
the browser account-search tests.

## Exporting personal hours

**Data export** in the account modal downloads the current account's booked time as PDF,
UTF-8 text, CSV or JSON. Choose one accessible board or all accessible boards and optional
inclusive UTC booking dates. Running and paused timers are not booked. Stopping books whole
minutes; remaining seconds carry forward. Transferring a ticket also settles its clocks.

The time journal begins with `M202610100002TimeEntries`. Older ticket totals and manually
edited totals have no reliable author or booking date and are not backfilled. Bookings retain
the original ticket key and title after a rename, transfer or ticket deletion. Project deletion
removes its bookings. Reading or exporting them still requires current read access to the
original project, including archived projects. Even administrators receive only their own
entries through this personal endpoint; project-wide ticket export permissions remain separate.

`GET /profile/export?source=time&format=pdf&project=all&from=2026-10-01&until=2026-10-31`
uses the shared exporter registry and file renderer. Formats have the same eight columns:
UTC booking timestamp, board, original ticket key/title, person, integer minutes, decimal hours
(rounded to six places) and the unit code `HUR`. Minutes are the exact source quantity. CSV
retains its UTF-8 BOM, semicolon separator and protection against spreadsheet formulas. PDF
renders escaped text locally, repeats the headings on subsequent pages and includes a total.
An empty selection is a valid empty download. All downloads are private and uncached.

The file is a time report, not an invoice. Invoice adapters need additional seller/buyer,
price, tax and invoice data. [EN 16931](https://ec.europa.eu/digital-building-blocks/sites/display/DIGITAL/Compliance+with+eInvoicing+standard)
is the common semantic basis; [XRechnung](https://xeinkauf.de/xrechnung) and
[ZUGFeRD/Factur-X](https://www.ferd-net.de/standards/zugferd-faq) are relevant invoice formats.
Importing draft invoice lines into a specific accounting tool needs its own verified field
mapping or API adapter. A plain time-report PDF is not a structured e-invoice.

## Changing the password

Current password, new password and a repeat are required. The new password needs at least 15
characters and is capped at 72 bytes, so that the native `PasswordHasher` with its current
bcrypt default cannot silently truncate it. Spaces and Unicode are allowed; null bytes are
refused. The service checks the current password under a lock on the account row and uses NAF's
hash and verify contract.

The change raises `users.security_version`, discards pending email changes and atomically
enqueues a security notice to the existing address in the native PDO queue. The current session
is signed out; other sessions are discarded on their next request. Business requests already in
flight are not retroactively terminated. The login page confirms the change through a NAF
session flash and asks for the new password.

## Changing the email address

1. Enter the new address and the current password.
2. Read the twelve-character code in the new mailbox and enter it in the profile.
3. Sign in with the new address and the unchanged password.

Until step 3 the address and the sign-in stay unchanged. A pending change survives reloads and
is visible in a second session of the same account. "Cancel change / request a new code"
discards the previous attempt; sending again asks for the current password once more. Accounts
without a local password are pointed at their external provider.

Codes are random, valid for 15 minutes, bound to the account and a random request id, and
usable once. Only the SHA-256 hash is stored in the database. Five wrong codes delete the
attempt, and NAF's persistent limiter additionally bounds sending, confirmation and password
checks per account. Other accounts can neither read, confirm nor cancel an attempt. A new
request replaces the previous one.

Confirmation re-checks expiry, security version and the availability of the new address under
the same account lock. The unique index also protects concurrent requests from different
accounts. After success `email_verified_at` and NAF's `UserProfile::emailVerified` are set, and
all previous sessions are revoked. The previous address receives a security notice both on
request and on completion. The code itself is only ever sent to the new address.

## What an extension can add here

The profile modal renders the `profile.panels` slot, so a package can contribute its own panel —
an SSO status, an API token list, a team directory entry — without touching the modal:

```php
$context->ui()->add(new UiContribution(
    id:       'example.profile.tokens',
    slot:     'profile.panels',
    template: 'example-a/profile-tokens',
    index:    200,
));
```

The contribution is rendered with a [`PageSlotContext`](Extensibility.md#what-a-slot-hands-over),
which carries the current user and project through `ui()`. Personal values belong in the `user`
settings scope, which keeps them per account and out of any project:

```php
$context->settings()->add(new SettingDefinition(
    key:   'example.signature',
    scope: 'user',
    type:  'textarea',
));
```

Password change, email change and the security notices are deliberately **not** extension points.
They are account security, they run under the account lock described above, and a contribution
that could observe or alter them would undo that guarantee. A package that needs to react to them
listens for the activity instead.

See [Extensibility](Extensibility.md) for the full API.

## NAF building blocks

- `Auth`, `PasswordCredentials`, `PasswordHasher` and the project's own ORM user model.
- `SessionStateStore` behind the native `StateStoreInterface`; the application decorator only
  adds the account version and keeps NAF's session id rotation.
- Native form validation, CSRF, routing, JSON responses, views and escaping.
- Native migrations, PDO and `EntityManager` transactions for locks and atomic updates.
- `Mailer`/`MailTransport`, the PDO queue, job resolution and scheduler cleanup.
- `PdoLimiter` for persistent limits. Security mail does not depend on project mail preferences.

The auth state binding is registered before the first auth resolution. Existing sessions are
accepted as revision zero during the migration. Password sign-in and changes are serialised
through the same account lock. Before security-relevant writes the persisted session is
re-checked through NAF after the lock, so a change in between cannot legitimise a stale session.
Request-only identities in isolated tests remain their own native auth mode.

## Local test mailbox

In the Nafinity skeleton, `make run` starts Mailpit along with everything else; `make mailpit` starts it on its own. The
interface is on **http://localhost:8025** (`NAFINITY_MAILPIT_PORT` in `.env`), bound to loopback
only. SMTP port 1025 stays inside the Compose network. Mailpit forwards nothing to the internet.
Messages live in the local container and can be lost when it is recreated. The test mailbox is
reachable by every user of this local machine.

The delivery path is NAF `MailTransport` → PHP `mail()` → msmtp → Mailpit.
In the skeleton, `docker/rootfs/etc/msmtprc` holds the local SMTP configuration and
`docker/rootfs/etc/php85/conf.d/99-nafinity.ini` the sendmail call. The sender comes from
`ENV:NAFINITY_MAIL_FROM` with a default in Compose. Real SMTP requires a private msmtp
configuration with authentication and TLS; credentials belong neither in the repository nor in
an image. Project notifications stay disabled by default.

The confirmation code is sent synchronously. A delivery failure rolls the pending change back and
returns HTTP 503. SMTP and SQL share no atomic transaction: if SMTP accepts and the commit then
fails, an unusable code can arrive. The interface reports no successful completion in that case.
Security notices instead use NAF's durable queue with retry and dead-lettering; after a worker
crash following SMTP acceptance, duplicate notices are possible. No plaintext codes or passwords
are held in queue payloads.

## Verification and limits

`tests/Http/AccountSecurityOverHttpTest.php` creates its own accounts in `nafinity_test` and
checks password change, CSRF, delivery of the code, address confirmation, concurrent
confirmation, single use, session id rotation and the revocation of two cookie jars. Mail goes
to a file outbox there instead of SMTP. A worker then delivers the security notices to the
original test addresses. Further service cases are in `tests/Database`. Demo passwords and demo
addresses are not modified by these tests; `composer test` runs all of them.

`M202609150002AccountProfile` adds two user fields and `account_email_changes`. Schema
identifier: `202609150002`.

Not included: forgotten-password recovery, MFA and synchronisation of external LDAP or OIDC
credentials. Those are their own account-recovery and identity flows.

Security flows follow the primary OWASP guidance on
[email verification](https://cheatsheetseries.owasp.org/cheatsheets/Email_Validation_and_Verification_Cheat_Sheet.html),
[authentication](https://cheatsheetseries.owasp.org/cheatsheets/Authentication_Cheat_Sheet.html)
and [session management](https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html).
The local delivery options follow the official documentation of
[Mailpit](https://mailpit.axllent.org/docs/install/docker/) and
[msmtp](https://marlam.de/msmtp/msmtp.html).

## Invoice-position export

The account modal also offers **Export invoice items** for one readable board, with explicit
EUR net hourly pricing and tax percentage. Neutral CSV and Lexware Office, sevdesk, easybill
and FastBill API-position JSON use the same authorized personal booking source and exporter
registry. The JSON files contain positions for a receiving integration, not complete invoices
or web-upload imports. See [Invoice exports](Invoice-Exports.md) for required fields, provider
specifications, precision and the downstream DATEV boundary.
