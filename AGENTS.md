# Nafinity implementation

This project is a NAF showcase. Before adding an abstraction, inspect the existing NAF package source and use its helpers, DI, events, policies, forms, migrations, ORM, queue and scheduler where applicable. Business rules belong in application services, reusable optional capabilities in separate Composer plugins. Fix generic defects in their owning NAF repositories under ../nafphp and follow their AGENTS.md workflow. Do not edit vendor copies.

Project root: /Users/flo/PhpStormProjects/nafinity. app/ contains the Composer application; packages is a local symlink to ../nafphp. Run development Composer in the Docker container. Public HTTP root is app/public only. No secrets, generated dev manifests/locks, vendor or private storage are committed. All read and write paths require project authorization; composite foreign keys provide integrity, not read authorization.

Use docs/Nafinity-Prototypplan.md as scope and docs/Implementation.md as the live evidence/status record. Source mode can use tested RC branches; stable distribution is gated on the maintainer merging/publishing those packages. Never silently fake stable aliases in distribution.

Follow docs/Code-Style.md and .php-cs-fixer.dist.php: PER Coding Style 3.0, locally aligned = and =>, descriptive variables and blank lines between logical steps. Run bin/style check after changes. Keep SQL, mixed PHP/HTML templates and build/test scripts readable; preserve escaping, form-value whitespace, evaluation order and transaction boundaries. Formatter tooling is isolated in tools/style and must not enter the runtime image.
