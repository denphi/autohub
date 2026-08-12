SHELL := /bin/bash
.DEFAULT_GOAL := help

COMPOSE     := docker compose
DEV_COMPOSE := docker compose -f docker-compose.yml -f docker-compose.dev.yml

.PHONY: init help up dev down destroy build logs shell muse update migrate admin backup restore ps status templates

init: ## Scaffold .env + hub.yml for a new hub (see scripts/hub-init.sh --help)
	@./scripts/hub-init.sh $(ARGS)

help: ## Show this help
	@grep -hE '^[a-z-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

.env:
	@test -f .env || { echo "No .env found. Run: cp .env.example .env"; exit 1; }

# The bind mount needs a file to exist, and an empty manifest is a no-op.
# Copy hub.yml.example over it when you are ready to declare the hub.
hub.yml:
	@printf '# Declarative hub setup. See hub.yml.example for the full reference.\n# An empty manifest does nothing.\n' > hub.yml
	@echo "Created an empty hub.yml (see hub.yml.example)"

# The image COPYs this directory so cluster deployments carry their templates.
# Projects scaffolded before that existed have none, and the build would abort
# with "COPY failed: file not found in build context".
templates:
	@mkdir -p templates

up: .env hub.yml templates ## Start the production stack (first run installs the hub)
	$(COMPOSE) up -d --build
	@echo "Site: http://localhost:$${HTTP_PORT:-8080}  (first boot takes a few minutes)"
	@echo "Follow along with: make logs"

dev: .env hub.yml templates ## Start the dev stack against the local ./hubzero-cms checkout
	$(DEV_COMPOSE) up -d --build
	@echo "Site:    http://localhost:$${HTTP_PORT:-8080}"
	@echo "Adminer: http://localhost:$${ADMINER_PORT:-8081}"
	@echo "Mailpit: http://localhost:$${MAILPIT_PORT:-8025}"

down: ## Stop everything, keep all data
	$(DEV_COMPOSE) down

destroy: ## Stop everything and delete the source, app data and database
	$(DEV_COMPOSE) down -v

build: templates ## Rebuild the runtime image (only needed for PHP/Apache changes)
	$(COMPOSE) build --pull

logs: ## Follow logs
	$(COMPOSE) logs -f

ps status: ## Show the state of each service
	$(COMPOSE) ps

shell: ## Root shell in the web container
	$(COMPOSE) exec web bash

update: ## Pull the CMS to HUBZERO_REF and migrate, without rebuilding
	$(COMPOSE) exec web hub-update $(REF)
	$(COMPOSE) restart web

provision: hub.yml ## Apply hub.yml (extensions, template, plugins, content)
	$(COMPOSE) exec web hub-provision

assets: ## Recompile the active template's LESS (reports syntax errors)
	$(COMPOSE) exec web hub-assets --clean

migrate: ## Apply pending migrations
	$(COMPOSE) exec web hub-migrate

muse: ## Run a muse command, e.g. make muse ARGS="cron:jobs list"
	$(COMPOSE) exec web hub-muse $(ARGS)

admin: ## Create or reset an administrator: make admin USER=me PASS=secret
	@test -n "$(USER)" && test -n "$(PASS)" || { echo "Usage: make admin USER=<name> PASS=<password>"; exit 1; }
	$(COMPOSE) exec web hub-admin "$(USER)" "$(PASS)"

backup: ## Dump the database into the app volume (app/backups/)
	$(COMPOSE) exec web hub-backup

restore: ## Restore a dump: make restore FILE=dump.sql.gz
	@test -n "$(FILE)" || { echo "Usage: make restore FILE=<dump.sql[.gz]>"; exit 1; }
	@set -a; . ./.env; set +a; \
	if [[ "$(FILE)" == *.gz ]]; then gzip -dc "$(FILE)"; else cat "$(FILE)"; fi \
		| $(COMPOSE) exec -T db sh -c 'MYSQL_PWD="$$MARIADB_PASSWORD" mariadb -u"$$MARIADB_USER" "$$MARIADB_DATABASE"'
	@echo "Restored $(FILE)"
