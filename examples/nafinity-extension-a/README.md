# example/nafinity-extension-a

An example extension for the Nafinity ticket application. It exists to show what
a real extension can do, and every Nafinity acceptance test that claims a
registry works uses it.

**Host requirement:** this package extends the `fkde/nafinity` application. It
registers an extension provider during its Composer plugin bootstrap and does
nothing on its own — there is no separate front controller to run.

**NAF packages it needs:** `naf/framework` for the container and routes,
`naf/cli` for its command, `naf/database` for its migration, `naf/i18n` for its
translations, and `naf/queue` plus `naf/schedule` for its job and its schedule
entry.

## What it contributes

| Contribution | Where |
|---|---|
| A reports page with its own controller and service | route `example.reports`, rendered through Nafinity's page renderer |
| The project permission `example.reports.view` | role editor; granted to nobody by being registered |
| A sidebar entry for the current project | slot `sidebar.project` |
| Personal and project settings, in their own card | settings surface, scopes `user` and `project` |
| The ticket fields `example.external_id` and `example.reviewed` | ticket sidebar group `details` |
| A ticket widget between links and attachments | slot `ticket.main.widgets`, index 150 |
| A board badge and a metadata filter | slot `board.card.badges`, `filters[example.reviewed]` |
| An AI tool | local chat, `read` permission |
| A French translation | language picker, without touching the application |
| A command, a migration, a job and a schedule entry | native NAF registration |

## Installation

Nafinity finds the package through Composer. In a local checkout, add a path
repository and require it:

```json
{
  "repositories": [
    { "type": "path", "url": "../examples/nafinity-extension-a" }
  ],
  "require": { "example/nafinity-extension-a": "*" }
}
```

After installing, apply migrations and publish its assets:

```sh
php vendor/bin/naf db:migrate up
php vendor/bin/naf nafinity:assets:publish --package=example/nafinity-extension-a
```
