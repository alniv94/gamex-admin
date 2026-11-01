# Otso Panalo

Otso Panalo is available in the Games, Bets, and Game Configurations navigation entries. The module follows the legacy CodeIgniter controllers/models in `admins-api/app/{Controllers,Models}/OtsoPanalo` and the Vue screens in `admin-portal/src/{views,components}/otso_panalo`.

**Otso Panalo has no jackpot rollover.** Each new game snapshots the active configuration's jackpot. Previous games, unclaimed prizes, unused pool funds, cancellations, and pending approvals never change that value. The pool percentage is retained as a configuration and historical field; it does not increase a later game's jackpot. Historical games retain their original recorded jackpot, including amounts previously produced by the old implementation.

## Database and field mappings

The additive migration `2026_11_01_033138_create_otso_panalo_tables.php` creates the following tables in the existing admin/player target database. It does not change the source database or the player portal's migration history.

| Legacy source                                         | Destination                     | Relationships and mapping                                                                                                                                               |
| ----------------------------------------------------- | ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `otso_panalo_statuses`                                | Same name                       | Original `id`, `name`, `status`                                                                                                                                         |
| `otso_panalo_bet_types`                               | Same name                       | Original `id`, `bet_type`, `status`                                                                                                                                     |
| `otso_panalo_winner_types`                            | Same name                       | Original `id`, `winner_type`, `status`; see catalog repair below                                                                                                        |
| `otso_panalo_game_configs`                            | Same name                       | Name, logo, bet amount, all four prizes, pool percentage, status, creation/update dates                                                                                 |
| `otso_panalo_games`                                   | Same name                       | Ending game, configuration and status IDs; prize snapshot; betting state/deadline; enable/disable/cancellation actors and dates; original prize-release flag            |
| `otso_panalo_bets`                                    | Same name                       | Original game/type IDs, ticket name, four combinations, amount, refund/added-to-prize flags, geolocation, IP and creation date; player references are mapped separately |
| `otso_panalo_game_results`                            | Same name                       | Original game ID, four quarter results, approval status and creation date                                                                                               |
| `otso_panalo_bet_quarter_results`                     | Same name                       | Original bet/game IDs, quarter number, bet/result combinations, Win/Lose outcome and creation date                                                                      |
| `otso_panalo_winners`                                 | Same name                       | Original bet/tier IDs, Verified/Approved status, amount, release flag and creation date                                                                                 |
| `admin_user_logs` where action contains `otso_panalo` | `admin_otso_panalo_legacy_logs` | Original log ID, action, reference, details/date; linked game and mapped administrator when identifiable; complete original row is archived                             |

Every copied row has `legacy_id = source.id`, a unique legacy index, SHA-256 `legacy_checksum`, complete `legacy_payload`, and `legacy_dates` for invalid zero dates. Existing primary IDs are preserved so game, bet, result, and winner references stay intact. The importer reserves source auto-increment high-water marks before new native records are allowed.

Source dates use Asia/Manila; operational destination dates are converted to UTC. The UI/PDF displays Manila dates. Zero dates become null with their original text retained in `legacy_dates` and the source archive. Existing enabled/disabled/cancelled administrator references use `admin_profiles.legacy_admin_id → user_id`; unmapped actors remain null, with their original IDs preserved in the archive. Result approval activity remains available in legacy activity logs even where the source result has no approver/date columns.

Bet identity mapping is `source.player_id → legacy_player_mappings(source, legacy_player_id) → players.id`. Bets retain `legacy_player_id` and `legacy_player_mapping_id`. Quarantined identities remain unlinked; a missing mapping fails the import. Unlinked players cannot receive refunds or secondary-prize credits. Payment batches roll back if any required identity is unresolved.

The source's progressive-source, previous/default jackpot, rollover, remaining-pool and payout-summary columns are preserved only in `legacy_payload`. There are no operational rollover columns in the new game table.

Foreign keys protect the module's game/configuration/status, game/bet/type, result/game, quarter/bet/game, and winner/bet/tier relationships. Uniqueness prevents multiple games for an ending game, multiple results for a game, multiple winners for a ticket, duplicate quarter outcomes and duplicate settlement references. Player references are validated through the existing identity map because player tables are owned by the separate player application.

The module also creates:

- `admin_otso_panalo_audits`: administrator, game, action, before/after data, IP and timestamp for native changes.
- `admin_otso_panalo_imports`: verified source and per-table import reports. Mutations require a completed import.
- `admin_otso_panalo_settlements`: unique `(kind, reference_id)` payment references and links to new wallet transactions.

The local source has an empty `otso_panalo_winner_types` table despite existing winners using tiers 1–4. The importer restores only the exact definitions in the legacy `OtsoPanaloWinnerTypesSeeder`: 1 Jackpot, 2 Second Prize, 3 Third Prize, 4 Fourth Prize. These target-only defaults have null `legacy_id`, are excluded from copied-source counts, and are verified against those definitions. Unknown tiers or conflicting labels fail the import. No source catalog is written.

## Migration and verification

Import administrators, player identity mappings and ending history first using the existing migration workflows. `config/otso.php` defaults its source identifier to `LEGACY_SOURCE`; `LEGACY_OTSO_SOURCE` can select another already-established mapping source.

```shell
php artisan migrate --no-interaction
php artisan legacy:otso-panalo --dry-run --no-interaction
php artisan legacy:otso-panalo --execute --no-interaction
php artisan legacy:otso-panalo --verify --no-interaction
```

The default command mode is a dry run. `--batch=1..2000` bounds memory use. The protected `legacy` connection permits reads and consistent read-only snapshots. The importer checks relationships and duplicate processing keys, copies missing rows in transactions, compares every source archive/checksum and all untouched mapped fields, and verifies copied counts before recording readiness. Existing rows are never overwritten. Source drift, local identity collisions, missing mappings and unreviewed target changes fail explicitly. A failed initial run can be retried after resolving its reported conflict.

Native changes set `managed_at` and write audit records. Later verification continues to compare their original archives/checksums without treating authorized local changes as source data. Importing history never debits wagers, issues refunds, credits prizes or replays source wallet transactions. Historical `refunded` and `prize_released` flags remain authoritative.

On November 1, 2026, the local execution imported and verified 4 statuses, 3 bet types, 1 configuration, 17 games, 79 bets, 13 results, 284 quarter outcomes, 19 winners and 249 activity logs. Four target-only winner-tier defaults were restored from the legacy seeder. There were no unlinked imported bets.

The game details action **Import Legacy Bets** can copy additional source bets for an unfinished imported game before result processing. It requires Create Otso Panalo, checks existing records for conflicts, and never creates or charges bets on behalf of a player. The reference implementation has PDF generation, rather than a PDF-to-bet upload parser; no speculative parser was added.

## Workflow and permissions

Existing imported Spatie permission names and grants are reused. No administrator receives new permissions automatically.

| Action                                      | Permission                                                            |
| ------------------------------------------- | --------------------------------------------------------------------- |
| Games, details and bets                     | `Otso Panalo`                                                         |
| Create game / import additional legacy bets | `Create Otso Panalo`                                                  |
| Edit an unstarted game                      | `Update Otso Panalo`                                                  |
| Enable/disable betting / cancel and refund  | `Otso Panalo Game Settings`                                           |
| View result                                 | `Otso Panalo Game Result` or a corresponding result action permission |
| Create and submit result                    | `Otso Panalo Create Result`                                           |
| Edit pending result                         | `Otso Panalo Update Result`                                           |
| Approve result                              | `Otso Panalo Approve Result`                                          |
| View/generate winners                       | `Otso Panalo Game Winners`                                            |
| Approve one/all winners                     | `Otso Panalo Game Winner Approval`                                    |
| View configurations                         | `Otso Panalo Game Types`                                              |
| Create/update/activate configuration        | `Otso Panalo Update Game Types`                                       |
| Download bets PDF                           | `Ending Game PDF Generation` and `Otso Panalo`                        |

The legacy Winner Verification permission remains in the existing catalog. As in the old generation flow, computed winners start Verified; there is no invented extra verification transition. Permissions are enforced on the server in addition to visible controls. The legacy system permits a user who has both creation and approval permissions to perform both; no new separate-person requirement is imposed.

Only one configuration is active at a time. Games use an available unstarted NBA ending game and initially close betting 30 minutes before its schedule. New games start Not yet started with betting Disabled. Editing is blocked while betting is enabled or after the game starts, and an existing ticket prevents changing the matchup. Editing preserves the game's saved jackpot while refreshing the other configured values, matching legacy behavior. Betting cannot reopen past its deadline or after results/cancellation.

Results contain one or more `digit-digit` quarter values, in team A–team B order. Saved ending scores are shown as reference and can supply their last digits. Creation finishes the Otso game, closes betting, and submits For Approval. Pending edits increment a version; stale or repeated approvals fail. Approved results are immutable. Generation and winner approval require an approved result. Empty generations are recorded so a zero-bet/zero-winner game cannot be processed repeatedly.

Unrefunded tickets matching four quarters share the game jackpot equally, rounded to cents per ticket as in the legacy implementation. Three, two and one matched quarters receive the configured second, third and fourth prizes respectively. Quarter outcomes and winner records are saved transactionally in bounded batches. No funds are carried into another game.

Winner approval credits second–fourth prizes once and marks them released, using the target player's actual wallet and a unique settlement reference. Jackpot approval retains `prize_released = No`, matching the legacy manual jackpot-release boundary. Whole batches roll back on missing mappings or invalid balances. Cancellation is limited to games without results/outcomes, requires one of the four legacy reasons, and refunds outstanding wagers once. Database transactions, row locks, shared import/write locks and settlement uniqueness protect these operations.

## Interface, assets and PDFs

The pages retain the legacy games/configuration tables, game detail prize and betting panels, result and winner tabs, confirmation dialogs, ticket details and bet-date/game-schedule filters. Pagination and search run on the server; bet pages support up to 500 rows. The implementation reuses the new app's AdminLayout, Vuetify, entity logos, date/money helpers, pager and Wayfinder routes. Existing configuration logos are copied to `public/uploads/otso_panalo/game_configs`; new validated uploads use the public storage disk. Run `php artisan storage:link` if the installation does not already expose that disk.

The legal-portrait PDF reuses `admins-api/app/Views/otso_panalo_bets.php`, adapted to an escaped Laravel view. It includes all bets for the selected game, including older and refunded tickets, independently of listing filters, with usernames, ticket names, quarter combinations, amounts, Manila dates, totals and page numbers. Dompdf 3 is an approved dependency; remote resources and PHP execution are disabled in the renderer.

CSV export streams every matching bet independently of pagination, using the applied search, date and game filters. Quarter combinations remain text in spreadsheets, and formula-like cell values are escaped.

## Validation

Feature tests cover fixed jackpots, configuration activation, game locks/cutoffs, result validation/versioned approval, all prize tiers, jackpot sharing, duplicate and empty generation, wallet/refund idempotency and rollback, route permissions and game scoping, source protection, repeatable imports, drift/collision handling, the missing catalog, timezone filters, pagination and escaped PDF generation. Browser tests exercise configuration creation, fixed-jackpot game creation, result submission/approval, winner declaration/payment and PDF download in the isolated browser database at desktop, tablet and mobile widths.
