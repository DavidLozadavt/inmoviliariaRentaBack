#!/bin/bash
set -e

# ===== INSTALAR DEPENDENCIAS DE POSTGRESQL =====
echo "Installing PostgreSQL PHP extension..."
apt-get update -qq && apt-get install -y --no-install-recommends libpq-dev
docker-php-ext-install pdo_pgsql pgsql
echo "PostgreSQL extension installed."

# ===== CONFIGURAR LÍMITES DE UPLOAD PHP =====
echo "Configuring PHP upload limits..."
cat > /usr/local/etc/php/conf.d/uploads.ini << 'EOF'
upload_max_filesize = 512M
post_max_size = 550M
max_execution_time = 600
max_input_time = 600
memory_limit = 768M
EOF
echo "PHP upload limits configured."

# ===== GENERAR CLAVES SI NO EXISTEN =====
if [ -z "$APP_KEY" ]; then
  echo "APP_KEY not set, generating..."
  php artisan key:generate --force
else
  echo "APP_KEY already set, skipping."
fi

if [ -z "$JWT_SECRET" ]; then
  echo "JWT_SECRET not set, generating..."
  php artisan jwt:secret --force
else
  echo "JWT_SECRET already set, skipping."
fi

# Deshabilitar TODOS los MPM primero
echo "Disabling all MPM modules..."
a2dismod mpm_event mpm_worker mpm_prefork worker event 2>/dev/null || true

# Habilitar solo mpm_prefork
echo "Enabling mpm_prefork..."
a2enmod mpm_prefork

# Habilitar módulo headers de Apache
echo "Enabling Apache headers module..."
a2enmod headers

# Verificar qué MPM están habilitados
echo "Currently enabled MPM modules:"
ls -la /etc/apache2/mods-enabled/mpm_* 2>/dev/null || echo "No MPM symlinks found"

# Configurar el puerto
PORT=${PORT:-8080}
echo "Configuring Apache to listen on port $PORT..."
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/*.conf

# ===== CONFIGURAR CORS EN APACHE =====
echo "Configuring CORS headers..."
cat >> /etc/apache2/apache2.conf << 'EOF'

# CORS Configuration
<IfModule mod_headers.c>
    Header unset Access-Control-Allow-Origin
    Header unset Access-Control-Allow-Methods
    Header unset Access-Control-Allow-Headers
    Header unset Access-Control-Max-Age

    Header set Access-Control-Allow-Origin "*"
    Header set Access-Control-Allow-Methods "GET, POST, PUT, PATCH, DELETE, OPTIONS"
    Header set Access-Control-Allow-Headers "Content-Type, Authorization, X-Requested-With, Accept, Content-Disposition"
    Header set Access-Control-Max-Age "3600"
</IfModule>
EOF

# ===== CREAR DIRECTORIOS DE LOGS Y CONFIGURAR PERMISOS =====
echo "Setting up Laravel storage directories..."
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/storage/framework/cache
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/bootstrap/cache

# >>> STORAGE FIX: carpeta pública real
mkdir -p /var/www/html/storage/app/public

# Configurar permisos
chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# >>> STORAGE FIX: permisos explícitos para archivos públicos
chmod -R 775 /var/www/html/storage/app/public

# >>> STORAGE FIX: crear symlink public/storage
echo "Linking Laravel storage..."
rm -rf /var/www/html/public/storage
php artisan storage:link || true

# >>> STORAGE FIX: permitir acceso desde Apache
cat >> /etc/apache2/apache2.conf << 'EOF'

# Laravel public storage access
<Directory "/var/www/html/public/storage">
    Options FollowSymLinks
    AllowOverride None
    Require all granted
</Directory>
EOF

# ===== LIMPIAR TODO =====
echo "🧹 Cleaning ALL caches, logs, and old files..."

rm -rf /var/www/html/bootstrap/cache/*.php
rm -rf /var/www/html/storage/framework/cache/data/*
rm -rf /var/www/html/storage/framework/sessions/*
rm -rf /var/www/html/storage/framework/views/*.php
rm -f /var/www/html/storage/logs/*.log

touch /var/www/html/storage/logs/laravel.log
chown www-data:www-data /var/www/html/storage/logs/laravel.log
chmod 666 /var/www/html/storage/logs/laravel.log

# ===== VERIFICAR VARIABLES DE ENTORNO =====
echo "📧 Verificando variables de correo..."
echo "MAIL_MAILER: ${MAIL_MAILER}"
echo "MAIL_HOST: ${MAIL_HOST}"
echo "MAIL_PORT: ${MAIL_PORT}"
echo "MAIL_USERNAME: ${MAIL_USERNAME}"
echo "MAIL_ENCRYPTION: ${MAIL_ENCRYPTION}"

# Esperar base de datos
echo "Waiting for database connection..."
timeout=30
counter=0
until php artisan migrate:status > /dev/null 2>&1 || [ $counter -eq $timeout ]; do
  echo "Database not ready yet... waiting ($counter/$timeout)"
  sleep 2
  counter=$((counter + 2))
done

if [ $counter -lt $timeout ]; then
  echo "Running migrations..."
  php artisan migrate --force || echo "Migrations failed or not needed"
else
  echo "Warning: Could not connect to database, skipping migrations"
fi

# ===== LIMPIAR Y CACHEAR CONFIGURACIÓN =====
echo "Clearing Laravel caches..."
php artisan config:clear
php artisan cache:clear
php artisan view:clear
php artisan event:clear 2>/dev/null || true
php artisan route:clear

echo "🔧 Caching configuration..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Limpiar cola (solo si las tablas existen)
echo "Clearing queue..."
php artisan tinker --execute="
if (Schema::hasTable('jobs')) {
    DB::table('jobs')->truncate();
    echo 'jobs table truncated.' . PHP_EOL;
} else {
    echo 'jobs table does not exist, skipping.' . PHP_EOL;
}
if (Schema::hasTable('failed_jobs')) {
    DB::table('failed_jobs')->truncate();
    echo 'failed_jobs table truncated.' . PHP_EOL;
} else {
    echo 'failed_jobs table does not exist, skipping.' . PHP_EOL;
}
" 2>/dev/null || true

# ===== VERIFICAR CONFIGURACIÓN DE MAIL =====
echo "🔍 Verificando configuración de Mail..."
php artisan tinker --execute="
echo 'MAIL_MAILER: ' . config('mail.default') . PHP_EOL;
echo 'MAIL_HOST: ' . config('mail.mailers.smtp.host') . PHP_EOL;
echo 'MAIL_PORT: ' . config('mail.mailers.smtp.port') . PHP_EOL;
echo 'MAIL_USERNAME: ' . config('mail.mailers.smtp.username') . PHP_EOL;
echo 'MAIL_ENCRYPTION: ' . config('mail.mailers.smtp.encryption') . PHP_EOL;
echo 'MAIL_FROM: ' . config('mail.from.address') . PHP_EOL;
"

# Iniciar Apache en segundo plano
echo "Starting Apache on port $PORT..."
apache2-foreground &

# Iniciar worker de cola (default + threed-processing para modelos 3D)
echo "Starting queue worker..."
php artisan queue:work --queue=default,threed-processing --tries=3 --timeout=120 --sleep=3 --max-jobs=1000 &

wait