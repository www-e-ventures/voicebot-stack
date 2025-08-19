How to use

Local only (build + smoke):

./scripts/all_in_one.sh --local-only --branch main
# Optionally set LARAVEL_LOCAL_BASE=http://localhost:9000 if you run `php artisan serve`


Remote only (deploy):

./scripts/all_in_one.sh deploy@voicebot.tv.digital --remote-only --branch main


Local then Remote (default):

./scripts/all_in_one.sh deploy@voicebot.tv.digital --branch main


Force API rebuild remotely:

./scripts/all_in_one.sh deploy@voicebot.tv.digital --force-api-rebuild

Notes

The remote smoke now uses -H "Host: voicebot.tv.digital" when curling loopback so Nginx matches your server block. No more “random 404” from the default site.

If you ever add another domain, set DOMAIN=your.domain in the environment when you run the script.

Local Laravel smoke is optional (only runs if you set LARAVEL_LOCAL_BASE), since many folks don’t run Nginx/PHP-FPM locally.
