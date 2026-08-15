#!/usr/bin/env bash

# ==============================================================================
# Production Server Installer for Osoul Academy (Mentor LMS)
# Automated, idempotent, secure installation for Ubuntu & Debian Linux servers
# Supported: Ubuntu 22.04 LTS, Ubuntu 24.04 LTS, Ubuntu 26.04 LTS, Debian 11, Debian 12
# ==============================================================================

set -Eeuo pipefail

# ------------------------------------------------------------------------------
# Formatting & Colors
# ------------------------------------------------------------------------------
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

CURRENT_STEP="Initialization"

# ------------------------------------------------------------------------------
# Error Handler Trap
# ------------------------------------------------------------------------------
error_handler() {
    local exit_code="$1"
    local line_no="$2"
    local last_command="$3"
    
    echo -e "\n${RED}==============================================================================${NC}"
    echo -e "${RED}❌ Installation Failed!${NC}"
    echo -e "${RED}==============================================================================${NC}"
    echo -e "${YELLOW}Failed Step:${NC}    ${CURRENT_STEP}"
    echo -e "${YELLOW}Line Number:${NC}    ${line_no}"
    echo -e "${YELLOW}Exit Code:${NC}      ${exit_code}"
    # Mask any password-like parameters in error output
    local sanitized_cmd
    sanitized_cmd=$(echo "$last_command" | sed -E 's/(password|DB_PASS|pass)=[^ ]+/\1=***/gI')
    echo -e "${YELLOW}Failed Command:${NC} ${sanitized_cmd}"
    echo -e "${RED}==============================================================================${NC}"
    echo -e "Please check the logs above for specific error details."
    exit "$exit_code"
}

trap 'error_handler $? $LINENO "$BASH_COMMAND"' ERR

# ------------------------------------------------------------------------------
# Root Check
# ------------------------------------------------------------------------------
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}❌ Error: This script must be run as root or with sudo privileges.${NC}"
    exit 1
fi

echo -e "${BLUE}==============================================================================${NC}"
echo -e "${BLUE}🚀 Osoul Academy - Production Server Installer${NC}"
echo -e "${BLUE}==============================================================================${NC}"

# ------------------------------------------------------------------------------
# Helper Functions
# ------------------------------------------------------------------------------
log_step() {
    local step_num="$1"
    local step_title="$2"
    CURRENT_STEP="Step ${step_num}: ${step_title}"
    echo -e "\n${CYAN}==============================================================================${NC}"
    echo -e "${CYAN}▶ Step ${step_num}: ${step_title}${NC}"
    echo -e "${CYAN}==============================================================================${NC}"
}

set_env_value() {
    local env_file="$1"
    local key="$2"
    local value="$3"

    if grep -qE "^${key}=" "$env_file"; then
        php -r "
            \$file = '${env_file}';
            \$key = '${key}';
            \$val = '${value}';
            \$content = file_get_contents(\$file);
            \$pattern = '/^' . preg_quote(\$key, '/') . '=.*/m';
            \$escaped_val = (strpbrk(\$val, ' #\"\'') !== false) ? '\"' . addcslashes(\$val, '\"') . '\"' : \$val;
            \$replacement = \$key . '=' . \$escaped_val;
            if (preg_match(\$pattern, \$content)) {
                \$content = preg_replace(\$pattern, \$replacement, \$content, 1);
            } else {
                \$content .= \"\n\" . \$replacement;
            }
            file_put_contents(\$file, \$content);
        "
    else
        echo "${key}=${value}" >> "$env_file"
    fi
}

# ------------------------------------------------------------------------------
# Step 1: Configuration & Input Validation
# ------------------------------------------------------------------------------
log_step 1 "Configuration & Input Validation"

HOSTNAME_REGEX='^([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$'
MYSQL_IDENTIFIER_REGEX='^[a-zA-Z0-9_]{1,64}$'

# 1.1 DOMAIN_NAME Validation
RAW_DOMAIN="${DOMAIN_NAME:-}"
CLEAN_DOMAIN=$(echo "$RAW_DOMAIN" | sed -e 's|^https\?://||i' -e 's|/.*$||' -e 's|[[:space:]]||g' | tr -d '\r\n')

if [ -n "$CLEAN_DOMAIN" ] && [[ "$CLEAN_DOMAIN" =~ $HOSTNAME_REGEX ]] && [[ ! "$CLEAN_DOMAIN" =~ [\$\{\}\=\;] ]]; then
    DOMAIN_NAME="$CLEAN_DOMAIN"
else
    if [ -n "$RAW_DOMAIN" ] && [ "$RAW_DOMAIN" != "osoul-academy.com" ]; then
        echo -e "${YELLOW}⚠️  Warning: Supplied DOMAIN_NAME ('${RAW_DOMAIN}') is invalid. Falling back to 'osoul-academy.com'.${NC}"
    fi
    DOMAIN_NAME="osoul-academy.com"
fi

# 1.2 DB_NAME Validation
RAW_DB_NAME="${DB_NAME:-}"
if [[ "$RAW_DB_NAME" =~ $MYSQL_IDENTIFIER_REGEX ]] && [[ ! "$RAW_DB_NAME" =~ [\$\{\}\=\;] ]]; then
    DB_NAME="$RAW_DB_NAME"
else
    if [ -n "$RAW_DB_NAME" ] && [ "$RAW_DB_NAME" != "osoul_academy" ]; then
        echo -e "${YELLOW}⚠️  Warning: Supplied DB_NAME ('${RAW_DB_NAME}') is invalid. Falling back to 'osoul_academy'.${NC}"
    fi
    DB_NAME="osoul_academy"
fi

# 1.3 DB_USER Validation
RAW_DB_USER="${DB_USER:-}"
if [[ "$RAW_DB_USER" =~ $MYSQL_IDENTIFIER_REGEX ]] && [[ ! "$RAW_DB_USER" =~ [\$\{\}\=\;] ]]; then
    DB_USER="$RAW_DB_USER"
else
    if [ -n "$RAW_DB_USER" ] && [ "$RAW_DB_USER" != "osoul_user" ]; then
        echo -e "${YELLOW}⚠️  Warning: Supplied DB_USER ('${RAW_DB_USER}') is invalid. Falling back to 'osoul_user'.${NC}"
    fi
    DB_USER="osoul_user"
fi

# 1.4 DB_PASS Validation
RAW_DB_PASS="${DB_PASS:-}"
if [ -n "$RAW_DB_PASS" ] && [[ ! "$RAW_DB_PASS" =~ [\$\{\}\;\'\"\`\\] ]] && [ ${#RAW_DB_PASS} -ge 8 ]; then
    DB_PASS="$RAW_DB_PASS"
else
    DB_PASS=$(openssl rand -hex 24)
fi

# 1.5 APP_NAME & Directory Setup
RAW_APP_NAME="${APP_NAME:-${APP_NAME_INPUT:-}}"
if [ -n "$RAW_APP_NAME" ] && [[ ! "$RAW_APP_NAME" =~ [\$\{\}\;] ]]; then
    APP_NAME_VAL="$RAW_APP_NAME"
else
    APP_NAME_VAL="Osoul Academy"
fi

INSTALL_DIR="/var/www/osoul-academy"
REPO_URL="https://github.com/MohamedFahmyy/osoul-academy-new.git"
MIN_PHP_VER="8.3.0"

# Safe Summary (Never prints DB password or sensitive secrets)
echo -e "${GREEN}Configuration Summary:${NC}"
echo "----------------------------------------------------"
echo "Domain:          ${DOMAIN_NAME}"
echo "Install Dir:     ${INSTALL_DIR}"
echo "Database:        ${DB_NAME}"
echo "DB User:         ${DB_USER}"
echo "DB Password:     ******** (stored securely in .env)"
echo "App Name:        ${APP_NAME_VAL}"
echo "----------------------------------------------------"

# ------------------------------------------------------------------------------
# Step 2: Operating System Detection
# ------------------------------------------------------------------------------
log_step 2 "Operating System Detection"

if [ ! -f /etc/os-release ]; then
    echo -e "${RED}❌ Error: Cannot detect operating system (/etc/os-release not found).${NC}"
    exit 1
fi

. /etc/os-release
OS_ID="${ID:-}"
OS_VERSION_ID="${VERSION_ID:-}"
OS_CODENAME="${VERSION_CODENAME:-}"

SUPPORTED=false
OS_PRETTY=""

if [ "$OS_ID" = "ubuntu" ]; then
    if [[ "$OS_VERSION_ID" == "22.04"* ]]; then
        SUPPORTED=true
        OS_PRETTY="Ubuntu 22.04 LTS (${OS_CODENAME:-jammy})"
    elif [[ "$OS_VERSION_ID" == "24.04"* ]]; then
        SUPPORTED=true
        OS_PRETTY="Ubuntu 24.04 LTS (${OS_CODENAME:-noble})"
    elif [[ "$OS_VERSION_ID" == "26.04"* ]]; then
        SUPPORTED=true
        OS_PRETTY="Ubuntu 26.04 LTS (${OS_CODENAME:-resolute})"
    fi
elif [ "$OS_ID" = "debian" ]; then
    if [[ "$OS_VERSION_ID" == "11"* ]]; then
        SUPPORTED=true
        OS_PRETTY="Debian 11 (${OS_CODENAME:-bullseye})"
    elif [[ "$OS_VERSION_ID" == "12"* ]]; then
        SUPPORTED=true
        OS_PRETTY="Debian 12 (${OS_CODENAME:-bookworm})"
    fi
fi

if [ "$SUPPORTED" = false ]; then
    echo -e "${RED}❌ Unsupported operating system.${NC}"
    echo -e "Detected:  ${OS_ID} ${OS_VERSION_ID} (${OS_CODENAME:-unknown})"
    echo -e "Supported: Ubuntu 22.04 / 24.04 / 26.04 LTS, Debian 11 / 12"
    exit 1
fi

echo -e "${GREEN}✓ Supported OS: ${OS_PRETTY}${NC}"

# ------------------------------------------------------------------------------
# Step 3: Package Repositories & Dependencies Installation
# ------------------------------------------------------------------------------
log_step 3 "Installing System Dependencies & PHP"

export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y --no-install-recommends \
    software-properties-common curl wget git unzip zip ufw \
    lsb-release ca-certificates apt-transport-https gnupg2 dnsutils

# Configure PHP Repository based on OS
if [ "$OS_ID" = "ubuntu" ]; then
    add-apt-repository -y ppa:ondrej/php || true
    apt-get update -y
elif [ "$OS_ID" = "debian" ]; then
    curl -sSLo /etc/apt/trusted.gpg.d/php.gpg https://packages.sury.org/php/apt.gpg
    echo "deb https://packages.sury.org/php/ ${OS_CODENAME} main" > /etc/apt/sources.list.d/php.list
    apt-get update -y
fi

# Install PHP packages (Prefer PHP 8.3 packages; fallback to standard php-fpm if >= 8.3)
if apt-cache show php8.3-fpm &>/dev/null; then
    PHP_PKG_PREFIX="php8.3"
elif apt-cache show php-fpm &>/dev/null; then
    PHP_PKG_PREFIX="php"
else
    PHP_PKG_PREFIX="php8.3"
fi

echo "Installing PHP packages (${PHP_PKG_PREFIX})..."
apt-get install -y \
    "${PHP_PKG_PREFIX}" \
    "${PHP_PKG_PREFIX}-fpm" \
    "${PHP_PKG_PREFIX}-cli" \
    "${PHP_PKG_PREFIX}-mysql" \
    "${PHP_PKG_PREFIX}-mbstring" \
    "${PHP_PKG_PREFIX}-xml" \
    "${PHP_PKG_PREFIX}-bcmath" \
    "${PHP_PKG_PREFIX}-curl" \
    "${PHP_PKG_PREFIX}-zip" \
    "${PHP_PKG_PREFIX}-gd" \
    "${PHP_PKG_PREFIX}-intl" \
    "${PHP_PKG_PREFIX}-tokenizer" \
    "${PHP_PKG_PREFIX}-soap"

# Verify installed PHP version satisfies Laravel 13 requirement (>= 8.3)
INSTALLED_PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
echo "Detected Installed PHP Version: ${INSTALLED_PHP_VER}"

if ! php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);'; then
    echo -e "${RED}❌ Error: Laravel requires PHP >= 8.3.0, but installed version is ${INSTALLED_PHP_VER}.${NC}"
    exit 1
fi
echo -e "${GREEN}✓ PHP version ${INSTALLED_PHP_VER} satisfies application requirements.${NC}"

# Install Composer
if ! command -v composer &> /dev/null; then
    echo "Installing Composer..."
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

# Install Node.js 20.x and NPM
if ! command -v node &> /dev/null; then
    echo "Installing Node.js..."
    if curl -fsSL https://deb.nodesource.com/setup_20.x | bash -; then
        apt-get install -y nodejs || true
    fi
    if ! command -v node &> /dev/null; then
        echo "Falling back to system Node.js and NPM packages..."
        apt-get install -y nodejs npm
    fi
fi

# Install Nginx, MySQL Server, Certbot
apt-get install -y nginx mysql-server certbot python3-certbot-nginx

# Determine actual PHP-FPM service name
PHP_FPM_SERVICE="php${INSTALLED_PHP_VER}-fpm"
if ! systemctl list-unit-files | grep -qE "^${PHP_FPM_SERVICE}\.service"; then
    if systemctl list-unit-files | grep -qE "^php-fpm\.service"; then
        PHP_FPM_SERVICE="php-fpm"
    fi
fi

echo "Detected PHP-FPM Service: ${PHP_FPM_SERVICE}"

systemctl enable --now nginx
systemctl enable --now mysql
systemctl enable --now "$PHP_FPM_SERVICE"

# ------------------------------------------------------------------------------
# Step 4: MySQL Database & User Setup
# ------------------------------------------------------------------------------
log_step 4 "Setting up MySQL Database and User"

mysql -u root <<EOF
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
EOF

echo -e "${GREEN}✓ Database '${DB_NAME}' and user '${DB_USER}' ready.${NC}"

# ------------------------------------------------------------------------------
# Step 5: Repository Installation
# ------------------------------------------------------------------------------
log_step 5 "Cloning Application Repository"

if [ -d "$INSTALL_DIR" ]; then
    BACKUP_DIR="${INSTALL_DIR}_backup_$(date +%Y%m%d_%H%M%S)"
    echo -e "${YELLOW}Target directory $INSTALL_DIR exists. Backing up to ${BACKUP_DIR}...${NC}"
    mv "$INSTALL_DIR" "$BACKUP_DIR"
fi

git clone "$REPO_URL" "$INSTALL_DIR"
cd "$INSTALL_DIR"

# ------------------------------------------------------------------------------
# Step 6: Environment Configuration (.env)
# ------------------------------------------------------------------------------
log_step 6 "Configuring Laravel Environment (.env)"

if [ ! -f .env.example ]; then
    echo -e "${RED}❌ Error: .env.example not found in repository.${NC}"
    exit 1
fi

cp .env.example .env

set_env_value ".env" "APP_NAME" "${APP_NAME_VAL}"
set_env_value ".env" "APP_ENV" "production"
set_env_value ".env" "APP_DEBUG" "false"
set_env_value ".env" "APP_URL" "https://${DOMAIN_NAME}"
set_env_value ".env" "DB_CONNECTION" "mysql"
set_env_value ".env" "DB_HOST" "127.0.0.1"
set_env_value ".env" "DB_PORT" "3306"
set_env_value ".env" "DB_DATABASE" "${DB_NAME}"
set_env_value ".env" "DB_USERNAME" "${DB_USER}"
set_env_value ".env" "DB_PASSWORD" "${DB_PASS}"
set_env_value ".env" "MENTOR_INSTALLED" "true"

chmod 600 .env
echo -e "${GREEN}✓ .env configured securely (permissions 600).${NC}"

# ------------------------------------------------------------------------------
# Step 7: Composer Dependencies & Key Generation
# ------------------------------------------------------------------------------
log_step 7 "Installing Composer Dependencies (Production Mode)"

composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction

if ! grep -qE '^APP_KEY=base64:.+' .env; then
    echo "Generating Application Key..."
    php artisan key:generate --force
fi

# ------------------------------------------------------------------------------
# Step 8: Frontend Build (Vite & Tailwind v4)
# ------------------------------------------------------------------------------
log_step 8 "Compiling Frontend Assets"

if [ -f package-lock.json ]; then
    npm ci
else
    npm install
fi

npm run build

if [ ! -f public/build/manifest.json ]; then
    echo -e "${RED}❌ Error: Frontend build failed: public/build/manifest.json not found.${NC}"
    exit 1
fi
echo -e "${GREEN}✓ Frontend assets compiled successfully.${NC}"

# ------------------------------------------------------------------------------
# Step 9: Database Migration & Initial Seeding
# ------------------------------------------------------------------------------
log_step 9 "Running Database Migrations & Initial Seeders"

php artisan migrate --force
php artisan db:seed --force

mkdir -p storage/app/public
touch storage/app/public/installed

echo -e "${GREEN}✓ Database migrations and core seeders completed.${NC}"

# ------------------------------------------------------------------------------
# Step 10: Permissions & Storage Symlink
# ------------------------------------------------------------------------------
log_step 10 "Configuring Permissions and Storage Symlink"

php artisan storage:link || true

DEPLOY_USER="${SUDO_USER:-root}"
chown -R "${DEPLOY_USER}:www-data" "$INSTALL_DIR"

find "$INSTALL_DIR" -type d -exec chmod 755 {} +
find "$INSTALL_DIR" -type f -exec chmod 644 {} +

chown -R www-data:www-data "$INSTALL_DIR/storage" "$INSTALL_DIR/bootstrap/cache"
chmod -R 775 "$INSTALL_DIR/storage" "$INSTALL_DIR/bootstrap/cache"
chmod 600 "$INSTALL_DIR/.env"

chmod +x "$INSTALL_DIR/deploy.sh" || true
chmod +x "$INSTALL_DIR/setup-production.sh" || true
chmod +x "$INSTALL_DIR/install-server.sh" || true

# ------------------------------------------------------------------------------
# Step 11: Application Cache Optimization
# ------------------------------------------------------------------------------
log_step 11 "Optimizing Laravel Caches"

php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# ------------------------------------------------------------------------------
# Step 12: Nginx Web Server Configuration
# ------------------------------------------------------------------------------
log_step 12 "Configuring Nginx"

PHP_FPM_SOCK=""
if [ -S "/var/run/php/php${INSTALLED_PHP_VER}-fpm.sock" ]; then
    PHP_FPM_SOCK="unix:/var/run/php/php${INSTALLED_PHP_VER}-fpm.sock"
elif [ -S "/run/php/php${INSTALLED_PHP_VER}-fpm.sock" ]; then
    PHP_FPM_SOCK="unix:/run/php/php${INSTALLED_PHP_VER}-fpm.sock"
elif [ -S "/var/run/php/php-fpm.sock" ]; then
    PHP_FPM_SOCK="unix:/var/run/php/php-fpm.sock"
elif [ -S "/run/php/php-fpm.sock" ]; then
    PHP_FPM_SOCK="unix:/run/php/php-fpm.sock"
else
    PHP_FPM_SOCK="unix:/run/php/php${INSTALLED_PHP_VER}-fpm.sock"
fi

NGINX_CONF="/etc/nginx/sites-available/$DOMAIN_NAME"

cat > "$NGINX_CONF" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN_NAME www.$DOMAIN_NAME;
    root $INSTALL_DIR/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";
    add_header X-XSS-Protection "1; mode=block";
    add_header Referrer-Policy "strict-origin-when-cross-origin";

    index index.php index.html;

    charset utf-8;
    client_max_body_size 500M;

    access_log /var/log/nginx/${DOMAIN_NAME}_access.log;
    error_log  /var/log/nginx/${DOMAIN_NAME}_error.log error;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php\$ {
        fastcgi_pass $PHP_FPM_SOCK;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~ ^/(\.env|\.git|storage/logs|vendor) {
        deny all;
    }
}
EOF

ln -sf "$NGINX_CONF" "/etc/nginx/sites-enabled/$DOMAIN_NAME"
rm -f /etc/nginx/sites-enabled/default

nginx -t
systemctl reload nginx

# ------------------------------------------------------------------------------
# Step 13: Firewall & SSL Verification
# ------------------------------------------------------------------------------
log_step 13 "Checking Firewall and SSL Readiness"

if command -v ufw &> /dev/null && ufw status | grep -q "Status: active"; then
    echo "Configuring UFW firewall rules..."
    ufw allow 22/tcp || true
    ufw allow 80/tcp || true
    ufw allow 443/tcp || true
fi

SERVER_IP=$(curl -s https://api.ipify.org || curl -s https://ifconfig.me || echo "")
RESOLVED_IP=$(getent ahosts "$DOMAIN_NAME" | head -n 1 | awk '{print $1}' || echo "")

SSL_STATUS="Pending DNS configuration"
if [ -n "$SERVER_IP" ] && [ -n "$RESOLVED_IP" ] && [ "$SERVER_IP" = "$RESOLVED_IP" ]; then
    echo "DNS resolves to this server ($SERVER_IP). Attempting automatic SSL certificate installation..."
    if certbot --nginx -d "$DOMAIN_NAME" -d "www.$DOMAIN_NAME" --non-interactive --agree-tos -m "admin@${DOMAIN_NAME}" --redirect; then
        SSL_STATUS="Enabled (Let's Encrypt)"
    else
        echo -e "${YELLOW}⚠️  Certbot SSL setup encountered an issue. You can run certbot manually once DNS propagates.${NC}"
    fi
else
    echo "DNS for $DOMAIN_NAME is not pointing to this server IP ($SERVER_IP) yet. SSL setup deferred."
fi

# ------------------------------------------------------------------------------
# Step 14: Final Verification
# ------------------------------------------------------------------------------
log_step 14 "Final Verification"

echo "Verifying installed components:"
php -v | head -n 1
composer --version | head -n 1
node -v | sed 's/^/Node.js /'
npm -v | sed 's/^/NPM /'
mysql -V
nginx -v

systemctl is-active --quiet nginx && echo -e "${GREEN}✓ Nginx service is running.${NC}"
systemctl is-active --quiet mysql && echo -e "${GREEN}✓ MySQL service is running.${NC}"
systemctl is-active --quiet "$PHP_FPM_SERVICE" && echo -e "${GREEN}✓ PHP-FPM service is running.${NC}"

php artisan about > /dev/null
echo -e "${GREEN}✓ Laravel application booted successfully.${NC}"

# ------------------------------------------------------------------------------
# Installation Complete Summary
# ------------------------------------------------------------------------------
echo -e "\n${GREEN}==============================================================================${NC}"
echo -e "${GREEN}${BOLD}🎉 Osoul Academy Production Installation Complete!${NC}"
echo -e "${GREEN}==============================================================================${NC}"
echo -e "Application URL:     ${CYAN}https://${DOMAIN_NAME}${NC} (or http://${DOMAIN_NAME})"
echo -e "Install Directory:   ${INSTALL_DIR}"
echo -e "Database Name:       ${DB_NAME}"
echo -e "Database User:       ${DB_USER}"
echo -e "Database Password:   Stored securely in ${INSTALL_DIR}/.env (permissions 600)"
echo -e "Environment:         production"
echo -e "Debug Mode:          disabled"
echo -e "SSL Status:          ${SSL_STATUS}"
echo -e "------------------------------------------------------------------------------"
echo -e "${YELLOW}📌 Next Steps:${NC}"
if [ "$SSL_STATUS" != "Enabled (Let's Encrypt)" ]; then
    echo -e "1. Ensure DNS A records for ${DOMAIN_NAME} and www.${DOMAIN_NAME} point to ${SERVER_IP:-your-server-ip}"
    echo -e "2. Once DNS is pointed, issue free SSL (HTTPS):"
    echo -e "   ${CYAN}sudo certbot --nginx -d ${DOMAIN_NAME} -d www.${DOMAIN_NAME}${NC}"
fi
echo -e "3. To deploy future updates with one command:"
echo -e "   ${CYAN}cd ${INSTALL_DIR} && ./deploy.sh${NC}"
echo -e "${GREEN}==============================================================================${NC}"
