SHELL := /bin/bash

.PHONY: build docs appstore appstore-signed verify-package verify-signed-package test-upgrade watch install lint test test-e2e test-compat dev-up dev-down dev-logs dev-install dev-reset occ studio-up studio-down studio-restart studio-status studio-logs studio-reset studio-occ studio-library-check studio-seed studio-screenshots studio-browser-check

install:
	npm ci
	composer install

build:
	npm run build

docs:
	npm run build:docs

appstore:
	./scripts/build-appstore.sh

appstore-signed:
	./scripts/build-appstore.sh --signed

verify-package:
	./scripts/verify-appstore-package.sh

verify-signed-package:
	./scripts/verify-appstore-package.sh --signed

test-upgrade:
	./scripts/test-upgrade.sh

watch:
	npm run watch

lint:
	npm run lint
	composer lint

test:
	npm test
	composer test

test-e2e:
	npm run test:e2e

test-compat:
	./scripts/compatibility-matrix.sh

dev-up:
	docker compose up -d
	$(MAKE) dev-install

dev-install:
	@until docker compose exec -T --user www-data nextcloud php occ status --output=json 2>/dev/null | grep -q '"installed":true'; do sleep 2; done
	docker compose exec -T --user www-data nextcloud php occ app:enable proofing_gallery
	docker compose exec -T --user www-data nextcloud php occ app:disable firstrunwizard
	docker compose exec -T --user www-data nextcloud php occ background:cron

dev-down:
	docker compose down

dev-logs:
	docker compose logs -f nextcloud

dev-reset:
	@echo "This removes all Proofing Gallery development containers and volumes."
	@test "$${CONFIRM_RESET:-}" = "yes" || (echo "Run with CONFIRM_RESET=yes to continue."; exit 1)
	docker compose down --volumes

occ:
	docker compose exec -T --user www-data nextcloud php occ $(CMD)

studio-up:
	./scripts/studio-stack.sh up

studio-down:
	./scripts/studio-stack.sh down

studio-restart:
	./scripts/studio-stack.sh restart

studio-status:
	./scripts/studio-stack.sh status

studio-logs:
	./scripts/studio-stack.sh logs

studio-reset:
	./scripts/studio-stack.sh reset

studio-occ:
	./scripts/studio-stack.sh occ $(CMD)

studio-library-check:
	node ./scripts/validate-demo-library.mjs

studio-seed: studio-up studio-library-check
	node ./scripts/studio-seed.mjs

studio-screenshots: studio-seed
	node ./scripts/capture-studio-screenshots.mjs

studio-browser-check: studio-seed
	node ./scripts/verify-studio-browsers.mjs
