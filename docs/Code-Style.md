# Code style

PHP follows [PHP-FIG PER Coding Style 3.0](https://www.php-fig.org/per/coding-style/meta/),
the modern successor to PSR-12, with a small set of explicit readability rules in
`.php-cs-fixer.dist.php`. The application remains compatible with PHP 8.3 syntax.

- Use four spaces, one statement per line, separate imports and readable multiline calls.
- Align `=` and `=>` within related local groups. Separate unrelated steps with a blank line;
  do not align across entire methods.
- Give intermediate values a name when they explain a decision, remove repetition or
  shorten a nested expression. Preserve evaluation order and transaction boundaries.
- Use descriptive names such as `$statement`, `$entityManager` and `$retryAfter`.
- Keep complex SQL in indented SQL blocks. Bind values and preserve project predicates.
- Keep PHP control flow and HTML structure visible in templates. Preserve escaping and
  whitespace inside form values, especially textareas.
- JavaScript and CSS use Prettier; Python uses Black with a line length of 100.
  Their conventional formatting takes precedence over PHP-specific alignment rules.

The formatter versions are isolated under `tools/style`, with Composer and npm lock files.
They are development tools and are excluded from the Docker build context and runtime image.
The PHP tool dependencies resolve for PHP 8.3; the local Alpine runtime executes them.
No Composer plugin or install script is required.

From the project root, with Docker, Node/npm and Python 3 available:

```sh
bin/style install
bin/style fix
bin/style check
```

The check covers application PHP, PHP in templates, tests, the formatter configuration,
JavaScript, CSS and Python scripts. HTML layout in mixed templates is reviewed manually;
formatting is not a substitute for browser or integration checks. Embedded shell/Python
backup snippets are kept readable manually.

The cleanup also applies the same PHP rules to the files changed by the Nafinity integration
in the existing RC packages and the two local limiter/LDAP plugins. Unrelated framework files
and the separately maintained Storage work are outside this cleanup.
