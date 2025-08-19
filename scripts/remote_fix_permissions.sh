cd /opt/chatbot/webapp

# 1) Make sure the dirs exist and are clean (remove stale, root-owned compiled views)
sudo rm -rf storage/framework/{cache,sessions,testing,views}
sudo mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache

# 2) Ensure OWNERSHIP to www-data wherever PHP needs to write
sudo chown -R www-data:www-data storage bootstrap/cache

# 3) Modes: setgid on dirs, group-writable on files
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;

# 4) Recreate the log file with correct owner/perm (avoid “Operation not permitted”)
sudo rm -f storage/logs/laravel.log
sudo install -o www-data -g www-data -m 0664 /dev/null storage/logs/laravel.log

# 5) Rebuild Laravel caches AS www-data (so new cache/view files are writeable)
sudo -u www-data php artisan config:clear || true
sudo -u www-data php artisan route:clear  || true
sudo -u www-data php artisan view:clear   || true
sudo -u www-data php artisan optimize