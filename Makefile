.PHONY: up down build config logs test check worker-check upload-check import-api-check recovery-check frontend-check auth-ui-check products-ui-check product-detail-ui-check import-ui-check report-ui-check e2e-check workflow-check docker-build-check docs-check acceptance-check git-safety-check migrate schema-validate fixtures

PYTHON ?= python

up:
	docker compose up -d --build --wait --wait-timeout 180

down:
	docker compose down

build:
	docker compose build

config:
	docker compose config --quiet

logs:
	docker compose logs --tail=100

test:
	$(PYTHON) scripts/verify.py test

check:
	$(PYTHON) scripts/verify.py check

migrate:
	docker compose exec -T app php bin/console migrations:migrate --no-interaction

schema-validate:
	docker compose exec -T app php bin/console orm:validate-schema

fixtures:
	docker compose exec -T --user www-data -e APP_ENV=development app php bin/console fixtures:load

worker-check:
	$(PYTHON) scripts/check-worker.py

upload-check:
	$(PYTHON) scripts/check-upload.py

import-api-check:
	$(PYTHON) scripts/check-import-api.py

recovery-check:
	$(PYTHON) scripts/check-recovery.py

frontend-check:
	$(PYTHON) scripts/check-frontend.py

auth-ui-check:
	$(PYTHON) scripts/check-auth-ui.py

products-ui-check:
	$(PYTHON) scripts/check-products-ui.py

product-detail-ui-check:
	$(PYTHON) scripts/check-product-detail-ui.py

import-ui-check:
	$(PYTHON) scripts/check-import-ui.py

report-ui-check:
	$(PYTHON) scripts/check-report-ui.py

e2e-check:
	$(PYTHON) scripts/check-e2e.py

workflow-check:
	$(PYTHON) scripts/check-workflow.py

docker-build-check:
	$(PYTHON) scripts/check-docker-build.py

docs-check:
	$(PYTHON) scripts/check-docs.py

acceptance-check:
	$(if $(strip $(XLSX)),,$(error Set XLSX to the control workbook path))
	$(PYTHON) scripts/check-acceptance.py --xlsx "$(XLSX)"

git-safety-check:
	$(PYTHON) scripts/check-git-safety.py
