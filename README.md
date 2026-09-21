# naf/board

Project-isolated Kanban boards, as a NAF plugin. The application behind a Nafinity
installation: everything the product does lives here, so extending it never means
editing it.

## Documentation

<https://nafphp.github.io/docs/built-with/nafinity/> — what it is and how it is put
together. The extension reference ships with this package, in
[`docs/Extensibility.md`](docs/Extensibility.md).

## Install

It is a plugin, so a NAF host requires it like any other:

```sh
composer require naf/board
vendor/bin/naf db:migrate up
vendor/bin/naf rbac:sync
vendor/bin/naf nafinity:assets:publish
```

Three things the host has to provide: a database, a session store, and a storage
disk named `attachments`. Everything else it needs comes with it.

## License

MIT
