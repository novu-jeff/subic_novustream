#!/bin/bash
# Run with: sudo bash apache/fix-storage-permissions.sh
# Fixes "Permission denied" when Laravel (PHP-FPM/Apache) writes to storage/logs/laravel.log

set -e
APP_PATH="/var/www/html/subic_novustream"

# Ensure storage and bootstrap/cache exist
mkdir -p "$APP_PATH/storage/logs"
mkdir -p "$APP_PATH/storage/framework/cache/data"
mkdir -p "$APP_PATH/storage/framework/sessions"
mkdir -p "$APP_PATH/storage/framework/views"
mkdir -p "$APP_PATH/bootstrap/cache"

# Give Apache/PHP-FPM (www-data) group ownership and allow group write
chgrp -R www-data "$APP_PATH/storage" "$APP_PATH/bootstrap/cache"
chmod -R g+rw "$APP_PATH/storage" "$APP_PATH/bootstrap/cache"

# Optional: setgid so new files in these dirs inherit group www-data
find "$APP_PATH/storage" "$APP_PATH/bootstrap/cache" -type d -exec chmod g+s {} \;

echo "Done. storage and bootstrap/cache are writable by www-data."
