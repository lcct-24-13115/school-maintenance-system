# LCCT Maintenance System — Phase 4C source snapshot

Download `school-maintenance-system-phase4c.zip` (the full working tree of branch
`arena/922dbfb5-school-maintenance-system`, including the Phase 4C changes).

```bash
unzip school-maintenance-system-phase4c.zip
cd school-maintenance-system
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --seed
npm install --ignore-scripts && npm run build
php artisan serve
```

Full setup and test commands are in `HOW_TO_RUN.md` inside the zip.
SHA-256: 51f9ff33bb4005030411c74a58f97acdb3e5cd9ec45b88328b67b4ab278a3d1d
