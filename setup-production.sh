#!/usr/bin/env bash

# Exit immediately if a command fails
set -e

echo "=========================================================="
echo "🚀 First-Time Production Setup for Mentor LMS"
echo "=========================================================="

# Check if .env file exists, if not copy from .env.example
if [ ! -f .env ]; then
    echo "📄 Creating .env file from .env.example..."
    cp .env.example .env
    echo "⚠️  IMPORTANT: Please edit .env with your database credentials & production APP_URL!"
fi

# 1. Install PHP Composer dependencies
echo "📦 Installing Composer dependencies (production mode)..."
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# 2. Generate Application Encryption Key if not already present
if ! grep -q "APP_KEY=base64:" .env; then
    echo "🔑 Generating application encryption key..."
    php artisan key:generate --force
fi

# 3. Create Storage Symlink
echo "🔗 Creating storage symlink..."
php artisan storage:link || true

# 4. Install Node.js dependencies and build frontend assets
echo "🎨 Installing NPM dependencies & building production assets..."
if [ -f package-lock.json ]; then
    npm ci
else
    npm install
fi
npm run build

# 5. Run Database Migrations and Seeders
echo "🗄️  Running database migrations and initial seeders..."
php artisan migrate --force --seed

# 6. Set correct permissions for storage and bootstrap/cache
echo "🔒 Setting directory permissions..."
sudo chown -R www-data:www-data storage bootstrap/cache || chown -R $USER:www-data storage bootstrap/cache || true
chmod -R 775 storage bootstrap/cache

# 7. Optimize Application Caching
echo "⚡ Caching configurations, routes, and views..."
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 8. Ensure deployment scripts are executable
chmod +x deploy.sh
chmod +x setup-production.sh

echo "=========================================================="
echo "🎉 Initial Setup Completed Successfully!"
echo "Your application is ready to serve."
echo "For future updates, simply run: ./deploy.sh"
echo "=========================================================="
