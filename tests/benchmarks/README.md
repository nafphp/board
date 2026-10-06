# Benchmarks

Not tests, and not run by `composer test`.

Both of these measure rather than assert: one times the board query against a
large disposable dataset, the other asks a real local model how well the tool
router picks from a catalog. They need something a test may not need -- a
populated database, a running Ollama, a catalog file passed as an argument --
and they answer with numbers rather than with pass or fail.

    php tests/benchmarks/board-query.php     # after `composer test`, on its demo data
    node tests/benchmarks/ai-routing-live.mjs <authorized-tool-catalog.json>

The catalog is either the tools array from `/ai/tools?project=…` or an object with
`owner` and `viewer` arrays captured from their respective authorized sessions.
The latter checks that unavailable writes never enter a viewer's selection.
The benchmark never executes tools. It reports selection recall separately from
latency, cold/warm indexing, prerequisites, model changes and keyword fallback,
for German, English and multistep requests.

By default it measures only the real catalog with the already installed local
`embeddinggemma:latest` and `nomic-embed-text:latest` models. Options are environment
variables: `NAF_BENCH_MODELS` (comma-separated tags), `NAF_BENCH_RUNS` (default 3),
`NAF_BENCH_OLLAMA_URL` (default `http://localhost:11434`) and
`NAF_BENCH_CATALOG_SIZE`. Setting the last to 500 adds explicitly synthetic
measurement distractors. These are not 500 implemented actions. The JSON output
records native/synthetic counts and exact model digests.
