# naf/board

Project-isolated Kanban boards for NAF, with an extension platform: projects, tickets,
boards with columns and swimlanes, estimates, timers, comments, attachments, activity and
notifications — and fourteen registries a package can add to.

This is the library. It is installed into an application rather than run on its own; the
[Nafinity skeleton](https://github.com/nafphp/nafinity) is such an application, and a
`create-project` from it gives you a working installation to extend.

## Installing

```sh
composer require naf/board
```

It is a `naf-plugin`, so NAF finds it and boots it. Its position matters, because it
registers the defaults an extension replaces — name it in your `app/plugins.php` after the
framework packages it builds on.

## Extending it

A package declares what it adds; nothing here needs editing. The registries cover ticket
fields, board filters, settings, navigation, UI slots, permissions, activity types,
estimation scales, AI tools, assets and views, and a host's `app/extensions.php` has the
last word over all of them.

Contracts live in `Naf\Board\Contracts`, the definitions in `Naf\Board\Definition`, and
the registries in `Naf\Board\Registry`. Anything marked `@internal` is not part of that
surface and may change without notice.

## Documentation

The reference is at <https://nafphp.github.io/docs/> — the Extending chapter covers every
registry, what a plugin may declare, and how ordering between packages is decided.
