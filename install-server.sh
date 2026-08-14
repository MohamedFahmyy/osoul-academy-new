#!/usr/bin/env bash

# ==============================================================================
# Full Automated Production Installer for Osoul Academy (Mentor LMS)
# From bare server -> Database creation -> Git clone -> Setup -> Nginx & SSL
# Supported OS: Ubuntu 22.04 / 24.04 LTS, Debian 11 / 12
# ==============================================================================

set -e

# Colors for terminal output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo -e "${BLUE}==============================================================================${NC}"
echo -e "${BLUE}🚀 Osoul Academy - Complete Production Server Installer${NC}"
echo -e "${BLUE}==============================================================================${NC}"

# Check if script is run as root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}❌ Please run this script as root or with sudo.${NC}"
    exit 1
fi

# 1. Interactive Inputs (Domain & Database Setup)
echo -e "\n${YELLOW}📋 Step 1: Configuration Details${NC}"

read -rp "Enter your Domain Name (e.g., example.com): " DOMAIN_NAME
while [ -z "$DOMAIN_NAME" ]; do
    read -rp "Domain name cannot be empty. Enter Domain Name: " DOMAIN_NAME
done

read -rp "Enter Database Name [default: osoul_academy]: " DB_NAME
DB_NAME=${DB_NAME:-osoul_academy}

read -rp "Enter Database User [default: osoul_user]: " DB_USER
DB_USER=${DB_USER:-osoul_user}

# Generate random secure password if not provided
RANDOM_PASS=$(openssl rand -base64 16 | tr -dc 'a-zA-Z0-9' | head -c 16)
read -rp "Enter Database Password [default: $RANDOM_PASS]: " DB_PASS
DB_PASS=${DB_PASS:-$RANDOM_PASS}

read -rp "Enter App Name [default: Osoul Academy]: " APP_NAME_INPUT
APP_NAME_INPUT=${APP_NAME_INPUT:-Osoul Academy}

INSTALL_DIR="/var/www/osoul-academy"
REPO_URL="https://github.com/MohamedFahmyy/osoul-academy-new.git"

echo -e "\n${GREEN}Summary:${NC}"
echo "----------------------------------------"
echo "Domain:      $DOMAIN_NAME"
echo "Install Dir: $INSTALL_DIR"
echo "Database:    $DB_NAME"
echo "DB User:     $DB_USER"
echo "DB Password: $DB_PASS"
echo "----------------------------------------"
read -rp "Proceed with installation? (y/n): " CONFIRM
if [[ ! "$CONFIRM" =~ ^[Yy]$ ]]; then
    echo -e "${RED}Installation aborted.${NC}"
    exit 0
fi

# 2. System Update & Dependencies Installation
echo -e "\n${YELLOW}📦 Step 2: Installing Server Packages (Nginx, PHP 8.3, MySQL, Node.js 20, Composer)...${NC}"

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common curl wget git unzip zip ufw

# Add PHP Repository
add-apt-repository -y ppa:ondrej/php || true
apt-get update -y

# Install PHP 8.3 & Extensions
apt-get install -y php8.3 php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-xml \
    php8.3-bcmath php8.3-curl php8.3-zip php8.3-gd php8.3-intl php8.3-cli \
    php8.3-soap php8.3-tokenizer

# Install Composer
if ! command -v composer &> /dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Install Node.js 20.x and NPM
if ! command -v node &> /dev/null; then
    curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
    apt-get install -y nodejs
fi

# Install Nginx and MySQL Server
apt-get install -y nginx mysql-server certbot python3-certbot-nginx

# Start and enable services
systemctl enable nginx --now
systemctl enable mysql --now
systemctl enable php8.3-fpm --now

# 3. Create MySQL Database and User
echo -e "\n${YELLOW}🗄️ Step 3: Setting up MySQL Database and User...${NC}"

mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

echo -e "${GREEN}✓ Database '${DB_NAME}' and user '${DB_USER}' created successfully.${NC}"

# 4. Clone Repository
echo -e "\n${YELLOW}📥 Step 4: Cloning Repository from GitHub...${NC}"

if [ -d "$INSTALL_DIR" ]; then
    echo -e "${YELLOW}Directory $INSTALL_DIR exists. Backing up to ${INSTALL_DIR}_backup_$(date +%s)...${NC}"
    mv "$INSTALL_DIR" "${INSTALL_DIR}_backup_$(date +%s)"
fi

git clone "$REPO_URL" "$INSTALL_DIR"
cd "$INSTALL_DIR"

# 5. Configure .env File
echo -e "\n${YELLOW}⚙️ Step 5: Configuring Environment File (.env)...${NC}"

cp .env.example .env

# Replace variables in .env
sed -i "s|^APP_NAME=.*|APP_NAME=\"${APP_NAME_INPUT}\"|" .env
sed -i "s|^APP_ENV=.*|APP_ENV=production|" .env
sed -i "s|^APP_DEBUG=.*|APP_DEBUG=false|" .env
sed -i "s|^APP_URL=.*|APP_URL=https://${DOMAIN_NAME}|" .env
sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
sed -i "s|^DB_USERNAME=.*|DB_USERNAME=${DB_USER}|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=\"${DB_PASS}\"|" .env
sed -i "s|^MENTOR_INSTALLED=.*|MENTOR_INSTALLED=true|" .env
if ! grep -q "MENTOR_INSTALLED" .env; then
    echo "MENTOR_INSTALLED=true" >> .env
fi

# 6. Install Composer Dependencies
echo -e "\n${YELLOW}📦 Step 6: Installing Composer Packages...${NC}"
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

# Generate App Key
php artisan key:generate --force

# 7. Install NPM Dependencies & Build Assets
echo -e "\n${YELLOW}🎨 Step 7: Compiling Frontend Assets (Vite)...${NC}"
if [ -f package-lock.json ]; then
    npm ci
else
    npm install
fi
npm run build

# 8. Run Database Migrations and Seeders
echo -e "\n${YELLOW}🗄️ Step 8: Running Database Migrations & Seeders...${NC}"
php artisan migrate --force --seed

# 9. Storage Link and Permissions
echo -e "\n${YELLOW}🔒 Step 9: Setting File Permissions and Storage Link...${NC}"
php artisan storage:link || true
chown -R www-data:www-data "$INSTALL_DIR"
chmod -R 775 "$INSTALL_DIR/storage" "$INSTALL_DIR/bootstrap/cache"

# 10. Cache Application Config
echo -e "\n${YELLOW}⚡ Step 10: Optimizing Cache...${NC}"
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# 11. Configure Nginx
echo -e "\n${YELLOW}🌐 Step 11: Configuring Nginx...${NC}"

NGINX_CONF="/etc/nginx/sites-available/$DOMAIN_NAME"

cat > "$NGINX_CONF" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN_NAME www.$DOMAIN_NAME;
    root $INSTALL_DIR/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php index.html;

    charset utf-8;
    client_max_body_size 500M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php\$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
EOF

ln -sf "$NGINX_CONF" "/etc/nginx/sites-enabled/$DOMAIN_NAME"
# Remove default site if exists
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl reload nginx

# 12. Make executable
chmod +x "$INSTALL_DIR/deploy.sh"
chmod +x "$INSTALL_DIR/setup-production.sh"

echo -e "\n${GREEN}==============================================================================${NC}"
echo -e "${GREEN}🎉 Production Installation Complete!${NC}"
echo -e "${GREEN}==============================================================================${NC}"
echo -e "Your application is live at: ${BLUE}http://${DOMAIN_NAME}${NC}"
echo -e "\n${YELLOW}🔑 Database Details Saved:${NC}"
echo -e "  Database: ${GREEN}${DB_NAME}${NC}"
echo -e "  User:     ${GREEN}${DB_USER}${NC}"
echo -e "  Password: ${GREEN}${DB_PASS}${NC}"
echo -e "\n${YELLOW}🔒 Admin Login Details:${NC}"
echo -e "  URL:      ${BLUE}http://${DOMAIN_NAME}/login${NC}"
echo -e "  Email:    ${GREEN}admin@asap.com${NC} or ${GREEN}admin@example.com${NC}"
echo -e "  Password: ${GREEN}password${NC}"
echo -e "\n${YELLOW}📜 To install free SSL (HTTPS), run:${NC}"
echo -e "  ${BLUE}sudo certbot --nginx -d ${DOMAIN_NAME} -d www.${DOMAIN_NAME}${NC}"
echo -e "\n${YELLOW}🔄 For future code updates, run:${NC}"
echo -e "  ${BLUE}cd ${INSTALL_DIR} && ./deploy.sh${NC}"
echo -e "${GREEN}==============================================================================${NC}"
