#!/bin/bash
# Run with: sudo bash apache/fix-storage-permissions.sh
# Fixes "Permission denied" when Laravel (PHP-FPM/Apache) writes to storage/logs/laravel.log

set -e
APP_PATH="/var/www/html/demo_novustream"

# Ensure storage and bootstrap/cache exist
mkdir -p "$APP_PATH/storage/logs"
mkdir -p "$APP_PATH/storage/framework/cache/data"
mkdir -p "$APP_PATH/storage/framework/sessions"
mkdir -p "$APP_PATH/storage/framework/views"
mkdir -p "$APP_PATH/bootstrap/cache"

# APK upload (upload-apk.php): www-data must write to public/apk and public/app-version
mkdir -p "$APP_PATH/public/apk"
touch "$APP_PATH/public/app-version"
chown -R www-data:www-data "$APP_PATH/public/apk" "$APP_PATH/public/app-version"
chmod -R 775 "$APP_PATH/public/apk"
chmod 664 "$APP_PATH/public/app-version"

# Give Apache/PHP-FPM (www-data) group ownership and allow group write
chgrp -R www-data "$APP_PATH/storage" "$APP_PATH/bootstrap/cache"
chmod -R g+rw "$APP_PATH/storage" "$APP_PATH/bootstrap/cache"

# Optional: setgid so new files in these dirs inherit group www-data
find "$APP_PATH/storage" "$APP_PATH/bootstrap/cache" -type d -exec chmod g+s {} \;

echo "Done. storage, bootstrap/cache, and public/apk + app-version are writable by www-data."
