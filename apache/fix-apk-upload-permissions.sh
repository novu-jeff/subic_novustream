#!/bin/bash
# Run on the server: sudo bash apache/fix-apk-upload-permissions.sh
# Makes public/apk and public/app-version writable so upload-apk.php can save uploads from your local.

set -e
APP_PUBLIC="/var/www/html/demo_novustream/public"

mkdir -p "$APP_PUBLIC/apk"
touch "$APP_PUBLIC/app-version"

# Let PHP-FPM (www-data) write to apk/ and app-version
chown -R www-data:www-data "$APP_PUBLIC/apk" "$APP_PUBLIC/app-version"
chmod -R 775 "$APP_PUBLIC/apk"
chmod 664 "$APP_PUBLIC/app-version"

echo "Done. www-data can write to public/apk/ and public/app-version. Upload from local to http://38.226.41.4:9000/upload-apk.php should work."
echo "If you still get 'Permission denied', run: sudo bash apache/fix-storage-permissions.sh"
