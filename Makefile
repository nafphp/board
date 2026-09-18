SHELL        := /bin/sh
.DEFAULT_GOAL := help

COMPOSE      := docker compose
ALL_PROFILES := $(COMPOSE) --profile test --profile candidate
SUPERVISOR   := $(COMPOSE) exec -T app supervisorctl -c /etc/supervisor/conf.d/supervisord.conf
ARGS         ?=
SERVICES     ?= app
TAIL         ?= 50
BACKUP       ?=

# Installations and database checks must also run in order with make -j.
.NOTPARALLEL:
.PHONY: help first-install install create-env-file check-env-file config-check \
        build-app run up stop down restart restart-background status logs ssh shell \
        composer composer-install composer-update naf migrate seed health \
        test test-up test-down test-mariadb test-postgres test-http test-profile test-worker test-ai \
        test-plugins \
        style-install style-check style-fix backup verify-restore \
        candidate-build candidate-up candidate-down plugin-check certificates supervisor-status mailpit

help: ## Show available commands (the default)
	@awk 'BEGIN { FS = ":.*## " } /^[a-zA-Z_-]+:.*## / { printf "  %-22s %s\n", $$1, $$2 }' $(MAKEFILE_LIST)

first-install: create-env-file install ## Prepare .env and install the complete local demo

install: check-env-file certificates ## Build, install dependencies, migrate, seed and start all dev services
	@$(MAKE) build-app
	@$(MAKE) composer-install
	@$(COMPOSE) up -d --wait db
	@$(MAKE) migrate
	@$(MAKE) seed
	@$(MAKE) run
	@$(MAKE) health

create-env-file: ## Create a private .env with random local passwords if missing
	@python3 bin/init-env

check-env-file:
	@test -f .env || { echo 'Run make first-install, or copy .env.example and set both passwords.' >&2; exit 2; }

certificates: ## Generate or renew the local CA and HTTPS certificate
	@python3 bin/generate-certificates

config-check: check-env-file ## Validate Compose without printing secrets
	@$(COMPOSE) config --quiet

build-app: config-check ## Build the development image
	@$(COMPOSE) build app

run: config-check certificates ## Start app, database, queue worker and scheduler; wait for health
	@$(COMPOSE) up -d --wait app

up: run ## Alias for run

stop: check-env-file ## Stop all Nafinity services, retaining containers and data
	@$(ALL_PROFILES) stop

down: check-env-file ## Remove Nafinity containers and network, retaining database and files
	@$(ALL_PROFILES) down

restart: check-env-file ## Restart app, worker and scheduler
	@$(COMPOSE) restart app

restart-background: check-env-file ## Reload worker and scheduler after PHP source changes
	@$(SUPERVISOR) restart queue-worker schedule-ticker

mailpit: config-check ## Start the local test mailbox at http://localhost:8025
	@$(COMPOSE) up -d --wait mailpit

supervisor-status: check-env-file ## Show nginx, PHP-FPM, queue worker and scheduler
	@$(SUPERVISOR) status

status: check-env-file ## Show all Nafinity services
	@$(ALL_PROFILES) ps -a

logs: check-env-file ## Follow logs; optional SERVICES='app db' TAIL=100
	@$(ALL_PROFILES) logs --follow --tail=$(TAIL) $(SERVICES)

ssh: check-env-file ## Open a shell as www in the app container
	@$(COMPOSE) exec --user www app /bin/sh

shell: ssh ## Alias for ssh

composer: check-env-file ## Run development Composer; e.g. ARGS='show naf/framework'
	@bin/dev-composer $(ARGS)

composer-install: check-env-file ## Install dependencies using local NAF sources
	@bin/dev-composer install --no-interaction

composer-update: check-env-file ## Update the development lock using local NAF sources
	@bin/dev-composer update --no-interaction

naf: check-env-file ## Run the NAF CLI; e.g. ARGS='db:migrate up'
	@$(COMPOSE) run --rm --no-deps -T app php vendor/bin/naf $(ARGS)

migrate: check-env-file ## Apply app and plugin migrations using NAF
	@$(COMPOSE) run --rm --no-deps -T app php vendor/bin/naf db:migrate up

seed: check-env-file ## Add demo data only when the development database is empty
	@$(COMPOSE) run --rm --no-deps -T app php vendor/bin/naf nafinity:seed

health: check-env-file ## Check app readiness (database, migrations and storage)
	@$(COMPOSE) exec -T app nafinity-healthcheck
	@$(COMPOSE) exec -T app curl --fail --silent --show-error --cacert /etc/nginx/ssl/ca.pem https://localhost:8443/health/ready
	@printf '\n'

test: test-http test-profile test-postgres test-worker test-ai test-plugins ## Run MariaDB, PostgreSQL, HTTP, worker and extension checks in disposable databases

test-up: config-check certificates ## Prepare nafinity_test and start the isolated test services
	@$(COMPOSE) up -d --wait db
	@bin/prepare-test-database
	@$(COMPOSE) --profile test up -d app-test postgres
	@$(COMPOSE) exec -T app-test php vendor/bin/naf db:migrate up
	@$(COMPOSE) --profile test up -d --wait app-test postgres

test-down: check-env-file ## Stop test services, preserving the development environment
	@$(COMPOSE) --profile test stop app-test postgres

test-mariadb: test-up ## Reset and check only the MariaDB nafinity_test schema
	@$(COMPOSE) exec -T app-test php tests/run.php

test-postgres: test-up ## Reset and check only the PostgreSQL nafinity_test schema
	@$(COMPOSE) exec -T -e DB_DRIVER=pgsql -e DB_HOST=postgres -e DB_PORT=5432 app-test php tests/run.php

test-http: test-mariadb ## Reset test fixtures and check HTTP, permissions and private files
	@$(COMPOSE) exec -T app-test php vendor/bin/naf nafinity:seed
	@python3 app/tests/http_acceptance.py

test-profile: test-up ## Check password/email changes, SMTP delivery and session revocation over HTTPS
	@python3 app/tests/profile_http.py

test-worker: test-up ## Check worker termination, lease recovery and dead letters
	@$(COMPOSE) exec -T app-test php tests/queue_process.php

test-ai: ## Check local AI streaming transport and browser storage boundaries
	@node app/tests/ai_transport.mjs
	@node app/tests/ai_routing.mjs

test-plugins: test-up ## Boot Nafinity with and without both example extensions
	@python3 bin/check-extensions

style-install: check-env-file ## Install the pinned development formatters
	@bin/style install

style-check: check-env-file ## Check PER Coding Style, JavaScript, CSS and Python formatting
	@bin/style check

style-fix: check-env-file ## Apply the project's code formatting rules
	@bin/style fix

backup: check-env-file ## Back up the development database and private files
	@bin/backup

verify-restore: check-env-file ## Verify a backup in nafinity_restore_test; BACKUP=work/backups/...
	@test -n "$(BACKUP)" || { echo 'Pass BACKUP=work/backups/TIMESTAMP.' >&2; exit 2; }
	@bin/verify-restore "$(BACKUP)"

candidate-build: ## Build the immutable local RC snapshot image
	@bin/build-candidate

candidate-up: check-env-file certificates ## Start the previously built snapshot at https://localhost:8445
	@$(COMPOSE) --profile candidate up -d --wait candidate

candidate-down: check-env-file ## Stop the local snapshot container
	@$(COMPOSE) --profile candidate stop candidate

plugin-check: check-env-file ## Boot minimal and complete NAF plugin stacks in isolated hosts
	@bin/check-plugin-stack
