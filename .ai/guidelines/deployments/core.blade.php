# Deployment

- This application is deployed to AWS EC2. Do not suggest Laravel Cloud, Forge, or Vapor.
- Production deploys run `php artisan migrate --force`; never rely on `migrate:fresh` or anything that drops data.
- If production runs `php artisan config:cache`, `env()` returns null outside the `config/` files. Read settings through `config()`, and run `php artisan config:clear` before `php artisan db:seed` (the seeders read `env()`).
