#!/bin/bash
# Run on the server to find the correct PHP-FPM socket path and service status.
# Usage: bash apache/check-php-fpm.sh

echo "=== PHP-FPM service (503 = FPM not running) ==="
for fpm in php8.3-fpm php8.2-fpm php8.1-fpm php-fpm; do
  if systemctl is-active --quiet $fpm 2>/dev/null; then
    echo "  $fpm: running"
  elif systemctl list-units --type=service --all 2>/dev/null | grep -q "$fpm"; then
    echo "  $fpm: NOT running  -->  sudo systemctl start $fpm"
  fi
done
echo ""
echo "=== PHP-FPM socket check ==="
for dir in /run/php /var/run/php; do
  if [ -d "$dir" ]; then
    echo "Directory $dir:"
    ls -la "$dir"/*.sock 2>/dev/null || echo "  (no .sock files)"
  fi
done
echo ""
echo "=== Required Apache modules ==="
apache2ctl -M 2>/dev/null | grep -E 'proxy_module|proxy_fcgi_module|rewrite_module' || echo "Run: sudo a2enmod proxy proxy_fcgi rewrite"
