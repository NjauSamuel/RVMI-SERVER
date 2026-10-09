# RVMI Media (Laravel)

API for the Flutter app. Production URL: `https://rvmi.deskcheque.top`.

One-time service setup (S3, Google Sign-In, Rekognition, FCM, Namecheap cron): the markdown files in [RVMI-MEDIA](../).

This host has **cron only** — no queue workers, no websockets. Background work is `php artisan schedule:run`.

## Run (local)

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Serves `http://127.0.0.1:8000`. The Android emulator reaches that as `http://10.0.2.2:8000`. Health check: `GET /up`.

Seeded login (Senior Bishop): `admin@rvmi.church` / `password`. There is no public signup — add members from the app (Users screen).

## Production cron

Namecheap cPanel → Cron Jobs, once per minute:

```txt
* * * * * php /home/USERNAME/path/to/server/artisan schedule:run >> /dev/null 2>&1
```

That tick runs `media:process-pending` (every minute) and `notifications:dispatch-digest` (every five minutes). Do not add a queue worker.
