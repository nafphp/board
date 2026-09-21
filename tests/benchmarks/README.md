# Benchmarks

Not tests, and not run by `make test`.

Both of these measure rather than assert: one times the board query against a
large disposable dataset, the other asks a real local model how well the tool
router picks from a catalog. They need something a test may not need -- a
populated database, a running Ollama, a catalog file passed as an argument --
and they answer with numbers rather than with pass or fail.

    php tests/benchmarks/board-query.php
    node tests/benchmarks/ai-routing-live.mjs <authorized-tool-catalog.json>
