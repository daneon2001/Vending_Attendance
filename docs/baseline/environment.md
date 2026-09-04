# Phase 0Q local environment

| Item | Value |
|---|---|
| PHP | 8.4.15, NTS x64 |
| Composer | 2.9.2 |
| Laravel | 11.47.0 |
| Node | 20.20.2 |
| npm | 11.12.1 |
| DB engine | MySQL 8.4.3, project-isolated instance bound to `127.0.0.1:3307` |
| Development DB | `vending_attendance_dev` |
| Testing DB | SQLite `:memory:`; Fortia auxiliary connections use `vending_attendance_testing_fortia` and `vending_attendance_testing_fortia_mock` |
| Fortia mock DB | `vending_attendance_fortia_mock` |
| Build command | `npm ci` then `npm run build` |
| Test command | `php artisan test` |
| Development startup command | Start the isolated MySQL instance, then `php artisan serve --host=127.0.0.1 --port=8000` |

## Reproducibility and safety

- Backend packages were installed with `composer install --no-interaction --prefer-dist` from the existing `composer.lock`; its SHA-256 remained `9548727800FA3D2124487303469D38B6E750B9DFD6E47807293CF57898AB92D9`.
- Frontend packages were installed with `npm ci` from the existing `package-lock.json`; no dependency update or audit fix was run.
- The local `.env` is ignored and was created specifically for this clone. It uses `APP_ENV=local`, `APP_DEBUG=true`, file cache/session, sync queue, log mail, and no production hosts.
- The existing Laragon MySQL on port 3306 was not used because it requires an unrelated administrative credential. Phase 0Q initialized a separate runtime data directory under ignored `storage/framework/local-mysql/` and used port 3307.
- The dedicated local account has privileges only over this project's development, testing, and Fortia-mock databases. No password is printed or documented.
- On the validation run port 8000 was already occupied by an unrelated process, so the HTTP check used temporary port 8001. The configured normal startup port remains 8000.
- The login returned HTTP 200 and every referenced local JS/CSS asset returned HTTP 200. Browser UI automation was unavailable in the session, so this was an HTTP/resource validation rather than a visual screenshot check.

## Known warnings

- Composer schema warning: exact `maatwebsite/excel` version `3.1.56`.
- npm audit: 12 inherited findings (2 moderate, 8 high, 2 critical); no update/fix was applied in Phase 0Q.
- Vite: Browserslist `caniuse-lite` data is eight months old; it was not updated.
- Login HTML references the public Bunny Fonts CDN. No Fortia, SYBI, or production-business host was contacted.
