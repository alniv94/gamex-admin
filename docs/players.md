# Player management

The module ports the legacy Player List and Player Details screens from `../admin-portal`, using the existing shared player records in `gamexsports_portal`. Open `/players/list` with a current active administrator session and permission **1 / Player List**.

It includes search, filters, sorting, pagination, CSV export, duplicate-name checks, personal information, private KYC images, PAGCOR NDRP results, verification and rejection, account controls, fund-account review, wallet transactions, and player/admin activity history. Fund-account review does not create accounts or transfer money. Rejection-reason and bank-catalog editing are outside these pages.

## Screens and workflow

### Player List

The list retains the original white card, refresh/export toolbar, search field, filter dialog, cyan Details action, verification chips, Other Details menu, and pagination. Details open in a separate tab. Dates display in `Asia/Manila`; date filters include the selected Philippine calendar days and query their corresponding UTC boundaries.

| Query parameter | Default | Accepted values / behavior |
| --- | --- | --- |
| `search` | Empty | Name, mobile number, email, or username; maximum 100 characters |
| `status` | `Active` | `All`, `Active`, `Inactive` |
| `account_verified` | `Files Submitted` | `All`, `Verified`, `Pending`, `Files Submitted`, `Rejected` |
| `type` | `Players` in the UI | `Players`, `Duplicate Players` |
| `date_from` | `2024-07-01` | Optional `YYYY-MM-DD` registration date |
| `date_to` | Today in Manila | Optional `YYYY-MM-DD`; must be on or after `date_from` when both are supplied |
| `sort` | `id` | `id`, `name`, `account_verified`, `verified_at`, `created_at` |
| `direction` | `desc` | `asc`, `desc` |
| `per_page` | `10` | `5`, `10`, `25`, `50`, `100` |
| `page` | `1` | Pagination page |
| `duplicate_of` | None | Target player ID used to find other matching players |

Search is debounced by 350 milliseconds. Filtering or sorting starts from the first page. Refresh reloads the current filters. Export streams **all matching rows**, with UTF-8 encoding and the filename `players-list-report-YYYY-MM-DD.csv`; it is not limited to the visible page. Cells beginning with spreadsheet formula prefixes are escaped.

Duplicate checks compare first name, last name, and birthdate. Names must be nonempty and birthdate must be present. `/player/{player}/duplicates` excludes the selected player and defaults to all account/verification statuses without a registration-date range. This is a review list; it does not merge or remove identities.

Route parameters and displayed player IDs refer to the shared target's `players.id`. Historical source IDs are resolved through `legacy_player_mappings`; source and target player IDs can differ.

### Player Details

`/player/{player}` displays PAGCOR NDRP results above a two-column layout. Personal information and settings are on the left; uploaded pictures, fund accounts, and activity tabs are on the right. Smaller screens stack the columns.

Personal information includes KYC/account status, identity and contact fields, age, nationality, venue, current/permanent addresses, income/work/business details, registration date, deactivation/rejection reasons, and wallet balance. The image viewer supports zoom, rotation, and switching between Valid ID and Selfie.

The activity tabs are scoped to the selected target player:

| Tab | Data source |
| --- | --- |
| Transactions | Existing `player_wallet_transactions` and transaction-type labels |
| Player Logs | Imported `admin_player_logs` |
| Admin Logs | Imported `admin_player_legacy_logs` combined with current `admin_player_audits` and administrator names |

Activity queries accept `tab` (`transactions`, `player_logs`, `admin_logs`), `activity_search` (maximum 100 characters), optional `activity_date_from` / `activity_date_to` in `YYYY-MM-DD`, and the same page sizes as the list. Results default to ten rows, newest first. The historical Admin Logs view excludes `verified_cashin` and `approved_cashin`, matching the legacy detail screen; those records remain preserved in the imported table.

### Verification and rejection

1. Review the personal information, uploaded pictures, NDRP results, and any matching players.
2. Choose **Verify** or **Reject** and confirm in the dialog. Both actions require permission **23 / Player Verify**, in addition to permission 1, and accept only `Pending` or `Files Submitted` KYC status.
3. Verification sets `player_profiles.kyc_status` to `Verified`, enables the shared `betting`, `cashin`, and `cashout` controls, and records the verifying administrator and UTC time. It preserves the player's active/inactive status and wallet.
4. Rejection requires an active imported rejection reason. It returns KYC status to **Pending**, retains the uploaded pictures and existing controls, and records the reason. This preserves the legacy resubmission flow; the action does not set KYC status to `Rejected`.
5. Each successful evaluation commits its audit record with the state change and queues an SMS after commit when the player has a mobile number.

The submitted `expected` KYC status must still match the database. A stale evaluation fails without changing the player or queuing a second notice. NDRP results support manual review; a match or lookup failure does not automatically approve or block verification.

### Account controls and fund accounts

Setting changes require permissions **1 + 24 / Player Settings** and a confirmation dialog. Betting, Cash In, and Cash Out can be changed only for verified players. Account activation/deactivation can be changed independently of KYC. Deactivation requires a reason of at most 50 characters. Account-status changes increment `players.session_version`, invalidating existing player sessions.

The player portal's `app/Services/Ending/EndingBetting.php` reads the live `players.betting` control. When that value is null, it retains the archived legacy restriction as a fallback. A live admin decision does not rewrite the encrypted legacy archive, and quarantined mappings remain blocked. The companion player-portal migration `2026_10_30_055912_add_player_account_controls.php` adds missing nullable controls when that application migrates independently.

Fund-account evaluation requires permissions **1 + 23**. Only active accounts in `Pending` status can be evaluated, and the account must belong to the selected player. Verification makes the account default only when the player has no existing active, verified default account. Rejection requires a reason of at most 500 characters and leaves the account non-default. Player-row locking serializes review decisions; both actions audit the change and queue an SMS after commit when a mobile number is available.

## Routes and request contracts

These are same-origin web routes using the administrator session. Mutations require CSRF protection and use Inertia forms with Wayfinder-generated route helpers. Successful mutations redirect to the detail page with HTTP **303** and an Inertia success message. Browser validation errors return to the form; JSON requests receive **422** with field errors. Stale-state errors use the `player` field.

| Method and path | Permission IDs | Input / response |
| --- | --- | --- |
| `GET /players/list` | 1 | List filters above; renders `players/List` |
| `GET /players/export` | 1 | Same filters; streamed CSV download |
| `GET /player/{player}` | 1 | Activity filters above; renders `players/Details` |
| `GET /player/{player}/duplicates` | 1 | Matching-player list with `duplicate_of` set to the selected ID |
| `GET /players/{player}/ndrp` | 1 | JSON lookup result; limited to 30 requests per minute |
| `GET /players/{player}/images/{kind}` | 1 | Private image; `kind` is `selfie` or `valid_id` |
| `POST /players/{player}/verify` | 1 + 23 | `expected`: current `Pending` or `Files Submitted` status |
| `POST /players/{player}/reject` | 1 + 23 | `expected`, `reject_reason_id`: active reason ID |
| `PUT /players/{player}/settings/{setting}` | 1 + 24 | `enabled`: boolean, `expected`: current stored setting; `reason` required for account deactivation |
| `POST /players/{player}/fund-accounts/{fund}/{action}` | 1 + 23 | `action`: `verify` or `reject`; `reason` required for rejection |

`setting` accepts `betting`, `cashin`, `cashout`, or `account`. For account changes, `expected` is `Active` or `Inactive`; for the other controls it is `Enabled` or `Disabled`. A null control is presented to the setting workflow as `Disabled`.

For example, reject a submitted player's KYC using the selected catalog reason:

```json
{ "expected": "Files Submitted", "reject_reason_id": 1 }
```

Disable an active account with a recorded reason:

```json
{ "enabled": false, "expected": "Active", "reason": "Requested account review" }
```

Missing permissions return **403**. Missing players, a fund account belonging to another player, and unavailable/private images return **404**. Mutations return **503** until `admin_player_imports` contains a verified report for the configured source. Passwords, private filesystem paths, encrypted archives, and migration checksums are excluded from page props.

## Configuration and operations

Configure the existing target/legacy connections as described in [migration.md](migration.md), then set the following in the private `.env`. `.env.example` contains empty integration placeholders; production credentials belong only in the environment.

| Variable | Purpose |
| --- | --- |
| `LEGACY_SOURCE` | Identity-mapping/import source name; defaults to `gamexsports-local` and must match the existing player mappings |
| `PLAYER_MEDIA_ROOT` | Absolute path to the player portal's private media root, usually its `storage/app/private` directory |
| `PAGCOR_BASE_URL` | HTTPS provider API base URL; the client appends `/GetAll` |
| `PAGCOR_AES_KEY` | Existing provider encryption key, exactly 16 characters |
| `PAGCOR_SIGN_KEY` | Existing provider member ID, exactly 16 characters |
| `ADMIN_SMS_DRIVER` | `m360` for delivery; `capture` for local/testing use |
| `M360_APP_KEY`, `M360_APP_SECRET`, `M360_MASK` | Existing SMS provider configuration |
| `QUEUE_CONNECTION` | Default queue connection; use `database` for admin workers. Player notices explicitly select `database` even if this default differs |
| `DB_QUEUE_TABLE` | Defaults to the admin-owned `admin_jobs`; keep separate from the player application's queue |

### Private images

The authenticated image route reads `player_profiles.selfie_path` or `valid_id_path`. Files must resolve inside `PLAYER_MEDIA_ROOT/players/{target_player_id}/`, be JPEG, PNG, or WebP, and be at most 10 MiB. The media root must be mounted/readable by the admin process; it is not a public URL. The route sends private, non-cacheable responses with MIME checks. Missing files display an unavailable-image state. Legacy image URLs are not fetched as a fallback.

### PAGCOR NDRP

The detail page loads NDRP separately with a loading state and retry feedback. The server uses the legacy salted AES-256-CBC request format and sends the configured member ID plus encrypted name to `GetAll`. Successful results are cached for five minutes. Provider failures are not cached, so retry can perform a new lookup.

The JSON body has `status: ready` with record counts and display fields under `data`, or `status: unavailable` / `error` with a message. These integration states use HTTP 200; authentication, authorization, and rate-limit failures retain their HTTP status codes. Missing/invalid configuration is reported as unavailable. Automated checks fake provider responses and connection failures; they do not establish live provider availability.

### SMS worker

Run a worker from the admin application:

```shell
php artisan queue:work database
```

`SendPlayerNotice` uses `AdminSms::sendMessage()` for KYC and fund-account decisions. Notices are dispatched after the transaction commits; settings changes do not queue notices. Jobs allow three attempts, have a 30-second timeout, and configure retry backoffs of 30, 120, and 300 seconds. The database connection defaults to a 90-second reservation timeout. Admin queue failures are stored in `admin_failed_jobs`.

For local/testing capture mode, the worker writes messages to `storage/app/private/notifications/`; browser fixtures use `browser-notifications/`. No SMS is sent in capture mode. OTP delivery remains synchronous and does not depend on this worker. After changing worker code or environment settings, restart the worker so it picks up the new configuration.

## Database migration

The admin schema changes are defined in:

- [2026_10_30_053732_create_admin_player_management_tables.php](../database/migrations/2026_10_30_053732_create_admin_player_management_tables.php): admin history, review metadata, audits, import reports, isolated queue tables, nullable shared account controls, and profile lookup indexes.
- [2026_10_30_055909_allow_missing_legacy_bank_types.php](../database/migrations/2026_10_30_055909_allow_missing_legacy_bank_types.php): preserves null legacy bank names/types.

The migration reuses existing `players`, `player_profiles`, location/income/venue lookups, `player_wallet`, and `player_wallet_transactions`. It adds nullable `players.betting`, `cashin`, and `cashout` columns when absent. Existing KYC and active status continue to come from `player_profiles.kyc_status` and `players.status`.

### Import procedure

The shared player schema, lookup data, and `legacy_player_mappings` must already exist. Import administrators and permissions before using review actions. Preserve the database and both applications' existing keys according to [the recovery guidance](migration.md#recovery-and-future-imports).

Run from `gamexsports-admin` using PHP 8.5:

```shell
php artisan migrate --no-interaction
php artisan legacy:player-management --dry-run --no-interaction
php artisan legacy:player-management --execute --no-interaction
php artisan legacy:player-management --verify --no-interaction
```

Use `php85` where PHP 8.5 is installed under that Windows command. The flags `--dry-run`, `--execute`, and `--verify` are mutually exclusive; omitting all three selects dry-run. `--batch` defaults to 2000 and accepts 1 through 5000. Progress reports source, matching, and missing counts for each table.

Dry-run does not write player/history records; it temporarily acquires an import lock. Execute copies missing rows, initializes only null shared account controls from legacy settings (`Enabled` stays enabled; other values become disabled), and compares every imported row before writing a verified report. Standalone verify also writes a report after successful comparison. The `players:import` cache lock prevents concurrent imports and expires after two hours.

Source reads use the protected legacy connection and, on MySQL, a consistent read-only snapshot. Target inserts commit in batches. An interrupted execute may leave completed batches, which can be checked and resumed with another dry-run/execute. Matching rows are skipped. Source checksum changes, conflicting immutable target data, missing identity mappings for source player accounts, or mismatched imported counts fail the operation; no force-overwrite mode exists.

Existing target fund review status/default/update time and verification-processing/verification fields are deliberately excluded from historical field comparison, so a rerun preserves later admin decisions. Existing non-null shared controls are retained. Quarantined player mappings retain a null target link with their original legacy ID; orphaned history is preserved without inventing an identity. Source timestamps are converted from Manila to UTC; zero-date strings are retained in `legacy_dates` while the corresponding target timestamp becomes null.

### Source mapping and verified local result — 2026-10-30

The execute and full comparison completed with the following counts:

| Legacy source | Admin target | Verified rows |
| --- | --- | ---: |
| `players` (selected status/control fields) | `admin_player_legacy_states` | 73,050 |
| `player_verification_details` | `admin_player_verifications` | 17,748 |
| `player_reject_reasons` | `admin_player_reject_reasons` | 39 |
| `banks` | `admin_player_banks` | 8 |
| `bank_accounts` | `admin_player_fund_accounts` | 1,411 |
| `admin_user_logs` where `player_id > 0` | `admin_player_legacy_logs` | 67,325 |
| `player_logs` | `admin_player_logs` | 1,564,395 |

`admin_player_audits` stores new review/control changes, including actor, action, before/after values, reason, IP, and UTC timestamp. `admin_player_imports` stores successful verification reports. These have no historical source-table import.

The existing **72,992** target player accounts and **1,177,008** wallet transactions were retained. The **58** quarantined legacy identities remained unlinked. On this snapshot, the list's default filters returned **287** players, matching the reference screenshot; that count is not a permanent application limit.

These migrations intentionally refuse destructive rollback because account controls are shared and audit history must be retained. Use a reviewed forward migration for schema corrections. Review source/target conflicts before changing imported data; do not delete history or reset the shared database to force a rerun.

## Implementation and verification

| Area | Entry point |
| --- | --- |
| Routes, permissions, validation, image/export responses | [PlayerController.php](../app/Http/Controllers/PlayerController.php) |
| Shared player/detail/activity queries | [PlayerQueries.php](../app/Services/Players/PlayerQueries.php) |
| Transactional review, account controls, audits | [PlayerWorkflow.php](../app/Services/Players/PlayerWorkflow.php) |
| Provider request and result cache | [PagcorLookup.php](../app/Services/Players/PagcorLookup.php) |
| History mapping and comparison | [PlayerManagementImport.php](../app/Services/Legacy/PlayerManagementImport.php) |
| Queue job | [SendPlayerNotice.php](../app/Jobs/SendPlayerNotice.php) |
| Vue pages and shared components | [List.vue](../resources/js/pages/players/List.vue), [Details.vue](../resources/js/pages/players/Details.vue), [players components](../resources/js/components/players) |

Focused checks:

```shell
php artisan test --compact tests/Feature/PlayerManagementTest.php tests/Feature/PlayerManagementImportTest.php
npm run check
npm run types:check
npm run build
npx playwright test tests/browser/players.spec.ts
```

On Windows, use `php85` and `scripts/with-node.ps1` as described in the [README](../README.md#verification). Backend fixtures use in-memory SQLite. Browser fixtures use `storage/framework/testing/admin-browser.sqlite`, synthetic players/admins, captured OTPs, and mocked NDRP results at 1440px, 820px, and 390px. Their mutations never use real player accounts. Screenshots are kept in ignored `storage/app/visual-baselines/`.

Implementation verification recorded 102 passing admin backend tests and 24 passing player-portal betting tests. Player browser workflows passed at all three widths; the existing desktop ending-game browser check passed on an isolated rerun after a timing failure in the combined suite. TypeScript, PHP static analysis, formatting/lint, and the frontend build also passed. These results describe the implementation run on 2026-10-30, not a new test run for this document.

## Troubleshooting

| Symptom | Check |
| --- | --- |
| Players navigation is missing or a route returns 403 | Current administrator status/session and permission 1; review actions also need 23 or 24 as listed above |
| Submitted player list is empty | Active/KYC/date filters; compare with `All` statuses and the intended registration range |
| Review returns 503 | A successful import verification report must exist for the configured `LEGACY_SOURCE` |
| A review reports a stale player or setting | Refresh the detail page and review its latest state before confirming again |
| Uploaded images are unavailable | `PLAYER_MEDIA_ROOT`, the target player's stored relative paths, file presence/type/size, and filesystem access |
| NDRP is unavailable or retry fails | HTTPS base URL, existing 16-character credentials, provider connectivity, and response shape |
| Account changed but SMS has not arrived | Admin database worker, queue configuration, provider settings, and `admin_failed_jobs`; an account update can commit before delivery |
| Import fails after copying some tables | Review the reported conflict, source availability, identity mappings, and schema compatibility; retain completed batches for a checked rerun |
