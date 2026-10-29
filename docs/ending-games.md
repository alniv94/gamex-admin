# Ending games

Open `/ending/games` using an active administrator account with permission **17**. The module supports scheduled game creation and editing, preset/custom cards, betting controls, per-quarter score entry and correction, winning bet discovery, verification, and approval/declaration. Game details include cards, bets, prize snapshots, results, winners, current activity and original historical logs. `/ending/history` searches original ending logs, including rows whose source reference no longer exists.

## Workflow

1. Create a game with two different active teams from one active league and a future Philippine schedule. Presets load current active quantities; a quantity of zero excludes that preset. Amounts and quarter prizes are snapshotted when the cards are created. The legacy config values named “Prize Percent” are multipliers: an amount of 15 and a value of 10 yields 150 tokens, matching the old application.
2. Open betting before the configured cutoff (the source currently specifies 30 minutes). Close betting before posting results. Cards can be added before the game starts. Cards with bets cannot be disabled; teams cannot change after bets exist. Started games cannot be rescheduled.
3. Enter quarter results in order. Zero scores are valid. The last digit of Team A's score followed by Team B's determines the combination, for example **21–30 → 1-0**. The server derives the slot. Quarter four marks the game Finished. Score corrections require a reason and are blocked while that quarter has a verified or approved winner.
4. Winning bets are derived from results and prize snapshots, excluding refunded bets/cards. Preserve the source's matching rule: type **2 / Per Quarter** matches the slot across quarters; type **1 / Traditional** also requires the bet's quarter to match. Earlier historical type-1 bets with quarter 0 remain preserved. Already recorded historical winners remain visible even when their old data no longer matches the current rule; the UI marks those for review.
5. Verify each matching player/bet/quarter, then approve to declare the winner. Verification and approval retries do not create duplicate winners or audit actions. Unverification is allowed only before approval and prize release. Unresolved player identities or duplicate legacy result/prize/winner rows prevent approval until reviewed.

**Declaration does not release prizes or credit wallets.** This module ends at winner declaration. Historical `prize_released` flags and existing wallet records are preserved, and new declarations retain `No`. Prize settlement, investor payout routing, prize delivery, SMS winner announcements, PDF generation/email delivery, and the separate Lucky Four, Power Ball, Otso Panalo and Happy Ending Max workflows are outside this module. No messages are sent by game actions.

Every successful state change and its audit record commit together. Audit entries include the acting administrator, game, action, record, before/after values, IP, UTC timestamp, and correction reason where applicable. Scheduler actions use a system actor. HTTP clients cannot edit or delete logs. The UI displays dates in Asia/Manila. Read responses exclude migration checksums, private archives, bet geolocation and IP fields.

## Access and endpoints

All routes use the current active administrator session. Ending-game actions use Inertia forms on named web routes, with CSRF protection for POST and PUT requests. Successful actions redirect to the game and deliver an Inertia flash message; invalid inputs and blocked transitions return validation errors to the submitting form. No default role bypass or extra permissions are granted.

| Route                                           | Permission | Input                                                                                                                              |
| ----------------------------------------------- | ---------- | ---------------------------------------------------------------------------------------------------------------------------------- |
| `GET /ending/games`                             | 17         | `search`, `league_id`, `status` (`scheduled`, `ongoing`, `finished`), `date_from`, `date_to`, `page`                               |
| `GET /ending/games/{game}`                      | 17         | `cards_page`, `winners_page`, `logs_page`, `legacy_page`; winners require 34                                                       |
| `GET /ending/history`                           | 17         | `action`, `game_id`, `page`                                                                                                        |
| `GET /ending/games/{game}?card_id={card}`       | 17         | Optional `cardBets` prop requested through an Inertia partial reload; scoped to the game                                           |
| `POST /ending/games`                            | 32         | `team_a`, `team_b`, `schedule` (`YYYY-MM-DDTHH:mm`, Asia/Manila), optional `presets[]` with `bet_type_id`, `config_id`, `quantity` |
| `PUT /ending/games/{game}`                      | 10         | `team_a`, `team_b`, `schedule`                                                                                                     |
| `PUT /ending/games/{game}/betting`              | 10         | Boolean `enabled`                                                                                                                  |
| `POST /ending/games/{game}/cards`               | 12         | `quantity` (1–1000), existing `bet_amount_id`, or new `bet_type_id`, `amount`, and four `prizes[]`                                 |
| `PUT /ending/games/{game}/cards/{card}/betting` | 12         | Boolean `enabled`                                                                                                                  |
| `POST /ending/games/{game}/results`             | 11         | `quarter_id` (1–4), nonnegative integer `team_a_score`, `team_b_score`                                                             |
| `PUT /ending/games/{game}/results/{result}`     | 11         | Same fields and required correction `reason`                                                                                       |
| `POST /ending/games/{game}/winners/verify`      | 8          | `bet_id`, `quarter_id`                                                                                                             |
| `POST /ending/games/{game}/winners/unverify`    | 8          | `bet_id`, `quarter_id`                                                                                                             |
| `POST /ending/games/{game}/winners/approve`     | 9          | `bet_id`, `quarter_id`                                                                                                             |

New prize entries contain `quarter_id`, `is_cash` (`Cash` or `Not Cash`), and `cash_prize` or `other_prize`. Money uses two decimal places. Expired browser sessions redirect to sign-in. Missing permissions return 403 and missing or cross-game records return 404. Validation failures, an ongoing import/write, and an unverified initial migration are returned as form errors.

Eloquent models define game, team, league, card, bet, result, winner, prize and audit relationships, with decimal/date casts and eager loading. Scoped model binding protects card/result updates. The workflow retains its transaction and row locks and records snapshots before mutating models. Query Builder remains in the historical copy/reconciliation tools and the set-based winner projection, which must account for legacy duplicates and winners independently of ordinary model updates. Wayfinder generates the frontend action URLs during builds and before frontend checks.

## Scheduler

Run Laravel's scheduler continuously to enforce the cutoff:

```powershell
php85 artisan schedule:work
```

Production can run `php artisan schedule:run` once per minute instead. The scheduled `ending:close-betting` command closes **new, natively created games** at their configured cutoff and marks scheduled games Started when their schedule arrives. It records each transition once and leaves imported historical betting/status flags intact. Each mutation also checks the cutoff before reopening betting. The player betting service must independently enforce the same schedule cutoff when accepting bets; that service is not changed here.

## Historical copy

The target reuses the shared `gamexsports_portal` database and the admin application's independent `admin_migrations` history. Existing players, administrator identities, wallet snapshots and wallet transactions are not updated. Source reads use the protected SELECT-only `legacy` connection and a consistent read-only MySQL snapshot per scan.

```powershell
php85 artisan migrate --force
php85 artisan legacy:ending --dry-run
php85 artisan legacy:ending --execute
php85 artisan legacy:ending --verify
php85 scripts/ending-check.php
```

`legacy:ending` defaults to preview and accepts `--batch=1..2000` (default 1000). Modes are mutually exclusive. Execute inserts missing rows in committed batches and then compares every source/mapped target row before recording successful verification. If interrupted, rerun execute. Matching rows are skipped; changed source/target rows, native ID collisions, missing player mappings, and unexpected copied row counts fail without overwriting existing rows. Completed batches remain available for a retry. New game mutations stay disabled until the first complete verification. A shared lock prevents imports and game writes from overlapping.

Use the same player mapping source label as the player portal. This installation uses **`gamexsports-local`**, from `LEGACY_SOURCE` (or the optional `LEGACY_ENDING_SOURCE` override). Import player mappings first; source player IDs must never be assumed equal to target `players.id`.

After a quarantined identity is reviewed and resolved using the player portal's existing resolution process, run `php85 artisan ending:link-players --dry-run`, then `--execute` to attach those approved mapping IDs to their historical bets. This command never resolves or guesses an identity itself, never replaces an existing player link, and records each attachment in the game's activity. It preserves all financial values and release flags, and skips orphan records without a recoverable game link.

| Source                                                                                                                                              | Target and mapping                                                                                                                                                                                                                    |
| --------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ending_leagues`, `ending_teams`, `ending_game_statuses`, `ending_game_quarters`, `ending_game_winner_statuses`, `ending_bet_types`, `ending_slots` | Same IDs and fields                                                                                                                                                                                                                   |
| `ending_config`                                                                                                                                     | Allowlisted betting cutoff/card settings and eight quarter prize multipliers; unrelated OTP, contact and messaging configuration excluded                                                                                             |
| `ending_traditional_bet_amount_config`, `ending_per_quarter_bet_amount_config`, `ending_bet_amount_config`                                          | IDs, amounts, status, quantities/added amounts retained; preset floating amounts become decimal(10,2)                                                                                                                                 |
| `ending_games`                                                                                                                                      | Same IDs, teams, schedule, statuses, betting, variant and release flags                                                                                                                                                               |
| `ending_bet_amounts`, `ending_bet_amount_prizes`, `ending_cards`                                                                                    | Same IDs, relationships, exact decimal money, non-cash descriptions, generated-file/refund/release flags                                                                                                                              |
| `ending_bets`                                                                                                                                       | Same IDs, card/slot/quarter/type, ticket name, location/IP, result/refund state; `player_id` remapped through `legacy_player_mappings`; original retained as `legacy_player_id` and mapping retained as `legacy_player_mapping_id`    |
| Quarantined player bets                                                                                                                             | Null `player_id`; original player and mapping IDs retained; winner approval blocked                                                                                                                                                   |
| `ending_game_results`                                                                                                                               | Same scores, combinations, Lucky Four result field, IDs and quarters                                                                                                                                                                  |
| `ending_game_winners`                                                                                                                               | Same IDs, bet/quarter/status, timestamps and prize-release flags; no fabricated verifier/approver or approval date                                                                                                                    |
| `ending_agent_bet_commissions`, `ending_sub_agent_bet_commissions`                                                                                  | Same bet IDs, percentages, exact amounts, paid state, source agent/administrator references and process dates; no commissions replayed                                                                                                |
| Ending-related `admin_user_logs`                                                                                                                    | Original ID, administrator/player/reference IDs, details, remarks and timestamp in `admin_ending_legacy_logs`; resolved game link when its referenced source record exists; Lucky Four/Power Ball actions filtered by recorded detail |

Dates convert from Asia/Manila to UTC. Zero dates become null with their original strings in `legacy_dates`. A SHA-256 checksum of every complete original row distinguishes imported records from native records and detects source changes. MySQL auto-increment counters advance to at least the source counters, preventing reuse of deleted historical IDs; existing higher target counters remain intact. Original historical references may point to missing parents, so source relationships use indexed identifiers rather than foreign keys that would discard or fabricate records. Native writes enforce their relationships under a game row lock and database transaction. Source agent/processor IDs remain historical identifiers, without pretending those unrelated modules have been migrated.

Historical flags and transaction references are copied as facts; no result processing, payout, refund, balance update, SMS, email, or notification is replayed. Generated-file flags are retained, but old PDF binaries are not copied or advertised as downloadable. Automatic rollback of historical tables is disabled; recovery uses a reviewed database backup. Verify immediately after migration, before native changes to imported games; subsequent deliberate local changes are reported as drift.

## Verification

The backend tests use only in-memory SQLite, and the browser suite uses its isolated synthetic SQLite fixture. They cover permission checks, transactional rollback, cutoff closure, ordered quarters, duplicate submissions, winning/losing and cross-game bets, quarantined player identities, correction locks/reasons, non-cash prizes, complete migration/reruns, drift/collision detection, protected source access, and desktop/tablet/mobile creation through declaration. Run the checks documented in the main README.

## Verified local result — 2026-10-29

The copy and full row comparison completed successfully, including a second execute that skipped matching history and preserved the additional ending variant-setting logs. Source access remained read-only. Exact decimal totals for amounts, prizes and commissions matched.

| Record                                          |    Verified count |
| ----------------------------------------------- | ----------------: |
| Games                                           |            12,870 |
| Leagues / teams                                 |          58 / 885 |
| Bet amounts / quarter prizes                    | 119,016 / 432,273 |
| Cards                                           |           956,600 |
| Bets                                            |         1,022,794 |
| Quarter results                                 |            50,208 |
| Winner records                                  |            22,457 |
| Agent bet commissions                           |           466,255 |
| Sub-agent bet commissions                       |                 0 |
| Original ending administrator logs              |           258,695 |
| Bets retained under quarantined player mappings |             2,402 |

Administrator count remained **85**, player count **72,992**, wallets **73,050**, and wallet transactions **1,177,008**. Wallet balance and transaction monetary totals remained unchanged. Quarantined bets retain both original and mapping IDs and remain blocked from new winner approval until reviewed; no player identities were fabricated. Actual MySQL game/winner queries were also checked against the copied records.
