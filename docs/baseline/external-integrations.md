# External integrations — Phase 0Q

No external request was executed during Phase 0Q. Values below describe code paths and the local isolation applied; no secrets are included.

| Name | Code location | Protocol | Configuration variables | Automatic at startup? | Manual? | Safe locally? | Mock available? |
|---|---|---|---|---|---|---|---|
| Fortia employee source | `app/Services/Fortia/FortiaEmployeeService.php`, `config/database.php`, `config/fortia.php` | MySQL connection selected by Laravel | `FORTIA_SYNC_DRIVER`, `FORTIA_SYNC_CONNECTION`, `FORTIA_SYNC_TABLE`, `FORTIA_DB_*`, `FORTIA_MOCK_DB_*` | NO | YES: Fortia sync/import commands and `POST /api/employees/sync-fortia` | YES in Phase 0Q: driver and connection are forced to the isolated `fortia_mock` database on `127.0.0.1:3307` | YES: `fortia_mock` and `vending_attendance_fortia_mock` |
| Fortia HTTP authentication | `app/Services/Fortia/FortiaAuthService.php`, `config/fortia.php` | Intended HTTPS, not implemented | `FORTIA_BASE_URL`, `FORTIA_USERNAME`, `FORTIA_PASSWORD`, `FORTIA_DUMMY_TOKEN` | NO | Only when a caller invokes the service | YES: implementation returns a dummy token and local URL is `http://127.0.0.1:9` | Placeholder only |
| Fortia attendance upload | `app/Services/Fortia/FortiaAttendanceService.php`, `app/Http/Controllers/Api/AttendanceController.php` | Intended HTTP POST, not implemented | Fortia variables above | NO | YES: endpoint/explicit service call | YES: service contains only a TODO; Phase 0Q did not call send commands/endpoints | NO transport implementation |
| SYBI / unit API | Repository-wide search in `app`, `config`, `routes`, and `resources/js` | None found | None found | NO | NO entry point found | YES: there is no detected SYBI client or configured host | NO / not applicable |
| Browser API calls | `resources/js/bootstrap.js`, `resources/js/utils/url.js` | HTTP(S), Axios | `API_BASE_URL`, `VITE_API_BASE_URL`, `VITE_APP_BASE_PATH` | Axios is configured at frontend boot; no request is emitted by boot itself | Requests occur through user/page actions | YES: both API base variables are empty, resolving to the current local origin | Same-origin local application |
| Laravel mail | `config/mail.php` | Mail transport | `MAIL_*` | NO | Triggered by application actions such as password reset | YES: `MAIL_MAILER=log`; no SMTP delivery | Log driver |
| Laravel queue/cache/session | framework config | Local runtime drivers | `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER` | Drivers initialize locally | Application-driven | YES: `sync`, `file`, and `file`; no Redis/SQS connection | Local drivers |
| Laravel welcome-page links/assets | `resources/js/Pages/Welcome.vue` | Browser HTTPS to Laravel/Laracasts sites if that page is rendered | Hard-coded public URLs | NO server-side request; browser-only | User navigation/page render | Not production-business integration; root currently redirects to login | NO |
| Bunny Fonts | login HTML generated from `resources/views/app.blade.php` | Browser HTTPS stylesheet/font CDN | Hard-coded `fonts.bunny.net` URL | YES when a browser renders login | NO | Public CDN only; it is not Fortia/SYBI, but fully offline typography is not available | NO |

## Startup and scheduler conclusion

- `AppServiceProvider::boot()` registers Vite prefetch, rate limiters, and model observers only; it performs no Fortia, SYBI, HTTP, or remote-database call.
- `routes/console.php` schedules only `audit:cleanup --optimize` daily. No Fortia sync/import command is scheduled.
- Composer package discovery and normal Artisan startup completed without contacting Fortia or SYBI.
- `fortia:sync-employees`, `fortia:sync-operational-catalogs`, and `fortia:import-clocks` were not executed.
