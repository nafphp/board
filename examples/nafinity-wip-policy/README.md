# example/nafinity-wip-policy

An optional Nafinity extension that enforces column WIP limits inside the native ticket
transaction. It contributes a project setting and a change listener.

## Documentation

See [workflow policies](https://github.com/nafphp/board/blob/main/docs/Workflow-Policies.md)
and the [extension API](https://github.com/nafphp/board/blob/main/docs/Extensibility.md).

## Install

Add this directory as a Composer path repository in a development host and require
`example/nafinity-wip-policy`. NAF discovers its bootstrap through `type: naf-plugin`.
The workflow guide includes the complete host configuration.

## License

MIT.
