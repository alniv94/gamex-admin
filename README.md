# GameXSports Admin Portal

Phase 1: administrator mobile/SMS OTP login, authenticated account overview, logout, and legacy administrator migration.

Based on [laravel/vue-starter-kit](https://github.com/laravel/vue-starter-kit), pinned in [docs/starter-revision.txt](docs/starter-revision.txt). Uses Laravel 13, PHP 8.5, Vue 3, TypeScript, Inertia 3, Vuetify **4.3.0**, Vite Plus, and MySQL, matching the adjacent player portal. Admin pages use client rendering.

## Existing UI and assets

The rebuilt UI follows the original `../admin-portal`: the GAME X logo and favicon, Poppins typography, cyan primary color, centered Sign In card, and outlined Mobile Number / OTP fields. Send OTP stays inside the mobile field, and both fields remain on one form. The signed-in shell preserves the original white header, logo drawer, account dropdown, and Logout action.

Existing assets are reused locally. New admin modules should retain the corresponding legacy layouts and assets as they are migrated. See [UI sources and asset provenance](docs/ui.md).

## Existing local installation

Open **http://localhost:8085/login** while the native development server is running.

The ignored `.env` is privately configured against the existing **`gamexsports_portal`** database. Its independent application key protects encrypted administrator archives. **Keep this APP_KEY with your database backups; do not regenerate it.** The source connection uses the existing legacy reader credentials. SMS uses the existing private M360 configuration.

The local import completed on **2026-10-29**:

| Record                                                    | Verified result |
| --------------------------------------------------------- | --------------: |
| Administrators                                            |              85 |
| Active / inactive                                         |         45 / 40 |
| Permission definitions                                    |             122 |
| Valid unique permission assignments                       |           1,273 |
| Orphaned permission rows archived                         |              62 |
| Invalid permission ID 0 rows archived                     |               2 |
| Duplicate permission rows archived                        |               1 |
| Inactive accounts without a mobile                        |               3 |
| Invalid or duplicate emails preserved outside users.email |               2 |

IDs, password hashes, active status, profile fields, role IDs, and permission records were preserved. Source login keys and OTPs are never imported. Existing players remain in `players`; their count remained **72,992**. The import was verified and rerun successfully without duplicating accounts. See [migration details](docs/migration.md).

Only active imported administrators with a valid registered mobile can sign in. Enter a Philippine mobile number in local, 10-digit, or +63 format. There is no public registration or password login. Administration modules beyond account access are not implemented yet.

## Run locally

Requirements: PHP 8.5 with PDO MySQL/SQLite, mbstring, intl, curl, fileinfo, and zip; Composer 2; Node 24.11+; the existing MySQL service. This Windows installation has Herd's `php85` executable and a pinned Node helper.

From this directory:

```powershell
php85 artisan serve --host=127.0.0.1 --port=8085
```

The existing compiled assets work without a Vite server. For frontend development, run this separately:

```powershell
.\scripts\with-node.ps1 npm run dev
```

Vite uses **5175**. The player portal keeps 8084/5174, and the legacy admin stack keeps 8080/8081.

For a fresh checkout, use PHP 8.5 as the default CLI runtime, then:

```shell
composer install
npm ci
```

Copy `.env.example` to `.env`, configure the existing target and SELECT-only source accounts, and generate an APP_KEY **only for a new installation**. Do not replace an existing private environment.

```shell
php artisan key:generate
php artisan migrate
php artisan legacy:import-admins --dry-run
php artisan legacy:import-admins --execute
php artisan legacy:verify-admins
npm run build
```

The adjacent player portal's account-separation migration must already be applied when sharing its database. Admin migrations use their own `admin_migrations` table and preserve the shared `users` table. Destructive Artisan database commands are disabled outside isolated PHPUnit tests. Never wipe or recreate the shared portal database.

## Docker

```powershell
docker compose build app
docker compose up -d --wait --wait-timeout 300
docker compose exec app php artisan migrate
docker compose ps
```

The separate Compose project uses PHP 8.5/Apache and Node 24, with its own vendor/node_modules volumes. Database hosts resolve through `host.docker.internal`. Startup installs locked dependencies but does not create a database, generate a key, migrate, seed, or import accounts. Use the explicit import commands above through `docker compose exec app php artisan ...` when needed.

Docker Desktop was unavailable during implementation; Compose configuration was validated, but the container build and startup were not exercised. Native PHP serves the verified local installation.

## Authentication and API

The frontend and API share one Laravel origin. Authentication uses encrypted server sessions and CSRF-protected requests, with a distinct administrator cookie and session table. [API details](docs/api.md) include the request/response contracts.

Codes expire after five minutes, allow five attempts, and are bound to the browser session that requested them. Resending invalidates previous codes. Phone/IP limits apply. A new login ends the previous admin session; inactive accounts are rejected on every request. Login and logout actions are recorded without codes in `admin_auth_logs`. Inertia history is encrypted and cleared at logout.

SMS defaults to M360. `ADMIN_SMS_DRIVER=capture` is available only in local/testing environments: it writes codes to `storage/app/private/notifications/`, sends no SMS, and displays a test-mode notice. Never use captured OTPs in production. No live SMS was sent during automated verification; provider success/failure behavior was tested with mocked HTTP responses.

For HTTPS deployments, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` to the deployment origin, and `SESSION_SECURE_COOKIE=true`.

## Verification

```powershell
php85 artisan test --compact
php85 vendor/bin/pint --test
php85 vendor/bin/phpstan analyse --memory-limit=512M
.\scripts\with-node.ps1 npm run check
.\scripts\with-node.ps1 npm run types:check
.\scripts\with-node.ps1 npm run build
# With Herd, use the underlying PHP 8.5 executable:
$env:ADMIN_TEST_PHP = "$env:USERPROFILE\.config\herd\bin\php85\php.exe"
.\scripts\with-node.ps1 npx playwright install chromium
.\scripts\with-node.ps1 npx playwright test
```

PHPUnit forces an in-memory SQLite database. The browser suite creates only `storage/framework/testing/admin-browser.sqlite`, uses synthetic accounts and captured SMS, and serves on 8086. It checks actual login/logout and validation at desktop, tablet, and mobile sizes. Screenshots stay in ignored `storage/app/visual-baselines/`. The browser fixtures never import or modify real accounts.
