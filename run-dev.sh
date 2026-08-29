#!/bin/bash

# Exit immediately if a command exits with a non-zero status.
set -e

# --- Clean up existing Docker environment ---
echo "🧹 Cleaning up previous Docker environment..."
docker compose down --remove-orphans
echo "✅ Docker environment cleaned."

# --- Start Docker Containers ---
echo "🚀 Starting Docker containers..."
docker compose up -d --build
echo "✅ Docker containers are up and running."

# --- Install Composer Dependencies ---
echo "📦 Installing Composer dependencies..."
docker compose exec php composer install
echo "✅ Composer dependencies are installed."

# --- Prepare Development Database ---
echo "🛠️ Preparing development database..."
echo "  -> Creating database (if it doesn't exist)..."
docker compose exec php bin/console doctrine:database:create --if-not-exists
echo "  -> Running migrations for dev environment..."
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
echo "✅ Development database is ready."

# --- Prepare Test Database ---
echo "🧪 Preparing test database..."
echo "  -> Dropping test database (if it exists) to ensure a clean slate..."
docker compose exec php bin/console doctrine:database:drop --env=test --if-exists --force
echo "  -> Creating test database..."
docker compose exec php bin/console doctrine:database:create --env=test
echo "  -> Running migrations for test environment..."
docker compose exec php bin/console doctrine:migrations:migrate --env=test --no-interaction
echo "✅ Test database is ready."

# --- Prepare browser E2E suite ---
# The e2e environment (php-e2e) shares the database-test Postgres, so the schema
# is already migrated by the step above. Only the Playwright deps are missing.
echo "🎭 Preparing Playwright E2E suite..."
docker compose exec -T playwright npm ci
echo "✅ Playwright is ready.  Run:  docker compose exec playwright npx playwright test"

echo "🎉 All done! Your development environment is ready to use."