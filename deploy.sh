#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -e

echo "🚀 Starting deployment..."

# 1. Turn on Maintenance Mode
echo "⏸️  Putting application into maintenance mode..."
(php artisan down --retry=60 --secret="1630542a-246b-4b66-afa1-dd72a4c43515") || true

# 2. Pull latest code from repository
echo "📥 Pulling latest code from Git..."
git pull origin main

# 3. Install/Update Composer dependencies (production only)
echo "📦 Installing Composer dependencies..."
composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev

# 4. Run database migrations
echo "🗄️  Running database migrations..."
php artisan migrate --force

# 5. Install NPM dependencies and build frontend assets
echo "🎨 Building frontend assets..."
if [ -f package-lock.json ]; then
    npm ci
else
    npm install
fi
npm run build

# 6. Clear and cache config, routes, views, events
echo "⚡ Optimizing application caches..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 7. Ensure storage symbolic link exists
echo "🔗 Creating storage symlink..."
php artisan storage:link || true

# 8. Restart queue workers (if queues are used)
echo "🔄 Restarting queue workers..."
php artisan queue:restart || true

# 9. Turn off Maintenance Mode
echo "▶️  Bringing application out of maintenance mode..."
php artisan up

echo "✅ Deployment completed successfully!"
