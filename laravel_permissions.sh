# 0) be on the server
ssh deploy@voicebot.tv.digital
cd /opt/chatbot/webapp

# 1) Ensure required dirs exist
sudo mkdir -p storage/framework/{cache,views,sessions} storage/logs bootstrap/cache

# 2) Give PHP-FPM user full ownership of writable dirs
# (On Ubuntu the PHP-FPM/nginx user is typically www-data)
sudo chown -R www-data:www-data storage bootstrap/cache

# 3) Set permissive bits for smooth writes (dirs g+ws, files g+rw)
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;

# 4) Clear caches and recompile (runs as your user, ok)
php artisan config:clear || true
php artisan route:clear || true
php artisan view:clear || true
php artisan optimize

# 5) (Optional) restart PHP-FPM to drop any stale file handles
# Use whichever exists:
sudo systemctl restart php8.3-fpm || sudo systemctl restart php8.2-fpm || true

# 6) Quick sanity: can the PHP user write a file?
sudo -u www-data touch storage/framework/views/.perm_test && echo "OK"
cd /opt/chatbot/webapp

# owner=user you deploy with; group=www-data (php-fpm)
sudo chown -R deploy:www-data storage bootstrap/cache

# dirs 2775 (rwxrwxr-x + setgid), files 664 (rw-rw-r--)
sudo find storage bootstrap/cache -type d -exec chmod 2775 {} \;
sudo find storage bootstrap/cache -type f -exec chmod 0664 {} \;
# create logs dir & file as www-data (so ownership is right)
sudo -u www-data mkdir -p storage/logs
sudo -u www-data bash -lc 'touch storage/logs/laravel.log && chmod 664 storage/logs/laravel.log'
# Install ACL tools (once)
sudo apt-get update && sudo apt-get install -y acl

# Give both deploy and www-data rwx now and by default for new files/dirs
sudo setfacl -R  -m u:deploy:rwx -m u:www-data:rwx storage bootstrap/cache
sudo setfacl -dR -m u:deploy:rwx -m u:www-data:rwx storage bootstrap/cache
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan optimize
# Laravel → FastAPI
curl -s https://voicebot.tv.digital/api/voicebot/health | jq

# Direct FastAPI
curl -s http://127.0.0.1:8000/ | jq
