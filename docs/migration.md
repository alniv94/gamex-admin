# Administrator migration

## Source and target

The legacy source is `gamexsports`. The configured shared target is `gamexsports_portal` (underscore), rather than a newly created schema named `gamexsports-portal`. Player records remain in `players`; the reference portal reserves `users` for administrators.

The admin application's `admin_migrations` history is independent of the player application's `migrations`. Its migrations create admin-specific tables and reuse an existing compatible `users` table. The admin baseline refuses a pre-separation player `users` table. Rolling back the admin baseline does not drop `users`; dropping authentication tables still requires a reviewed backup and a deliberate maintenance plan.

The legacy connection uses a SELECT-only principal and rejects write SQL before execution. MySQL source reads use a consistent, read-only snapshot. Target identities are preflighted before a transaction imports accounts and verifies the result. An import lock prevents overlapping CLI imports.

## Commands

Run from the admin application with PHP 8.5:

```shell
php artisan migrate
php artisan legacy:import-admins --dry-run
php artisan legacy:import-admins --execute
php artisan legacy:verify-admins
```

Omitting both import flags defaults to dry-run. Dry-run writes no account records; the cache lock is temporary. Execute reruns verify and skip existing mapped accounts. A source or target change fails verification instead of overwriting local identities or granting new permissions implicitly.

Identity conflicts abort the import. Keep administrator IDs available in the target; the importer preserves source primary keys. There is no automatic identity merging or conflict override.

## Field mapping

| Source                                                | Target / transformation                                                                 |
| ----------------------------------------------------- | --------------------------------------------------------------------------------------- |
| admin_users.id                                        | users.id and admin_profiles.legacy_admin_id                                             |
| admin_users.mobile_number                             | Canonical +639xxxxxxxxx in users.mobile_number                                          |
| Empty mobile on an inactive admin                     | Null mobile; account remains inactive                                                   |
| Empty/invalid mobile on an active admin               | Import refusal                                                                          |
| admin_users.password                                  | Exact legacy string, inserted without a password mutator                                |
| admin_users.is_active                                 | users.status, preserving Active / Inactive                                              |
| admin_users.created_at                                | Converted from Asia/Manila to UTC                                                       |
| Profile name fields                                   | Exact fields in admin_profiles and composed users.name                                  |
| Profile email                                         | Exact admin_profiles.email_address plus normalized users.email when valid and unique    |
| Invalid/duplicate email                               | users.email null; original profile and encrypted archive preserved                      |
| Profile birthdate                                     | Exact date, or null for a zero date; raw value stays archived                           |
| Profile role_id                                       | admin_profiles.legacy_role_id                                                           |
| admin_access                                          | admin_permissions with original IDs, labels, order, and legacy module names             |
| Valid admin_user_access assignments                   | Unique admin_user_permissions rows                                                      |
| All source assignments belonging to an existing admin | Exact rows, including duplicates and invalid IDs, inside that admin's encrypted archive |
| Assignments referencing nonexistent admins            | Encrypted admin_legacy_exceptions records                                               |
| login_key, login_verified, OTP rows                   | Excluded from import                                                                    |

The raw user/profile/permission snapshot is encrypted in `admin_profiles.legacy_payload`; a SHA-256 source checksum allows repeat verification. The archive preserves raw email, phone, date, and password values. Do not expose archives through API resources or logs.

The original role ID is retained as evidence; a new role management system is not part of this phase. Existing legacy module names are data, not automatically exposed admin routes.

## Verified local result — 2026-10-29

- 85 administrator identities: 45 active and 40 inactive.
- 122 permission definitions and 1,273 valid unique assignments.
- 3 inactive accounts had no mobile; these remain unable to sign in.
- 2 invalid/duplicate email values remain available in profiles and archives.
- 2 legacy password strings were "-" and were preserved; password login is disabled.
- All 1,338 source permission rows were accounted for: 1,273 valid unique grants, 62 orphaned rows, 2 invalid ID-0 rows, and 1 duplicate row.
- Verification checks IDs, normalized identities, hashes, status, profile fields, timestamps, encrypted snapshots, permission definitions, assignments, and orphan archives.
- Execute, verify, and a second execute completed successfully; the second run created no duplicate users.
- Existing player count remained 72,992.

Invalid or orphaned grants never create privileges. The original rows remain recoverable from the encrypted evidence.

## Recovery and future imports

Back up the shared target database and this admin application's APP_KEY together before moving environments or changing mappings. The player portal has its own key; preserve both keys for their respective archives. Never regenerate the admin APP_KEY after an import.

A corrected source record changes its checksum. Review the source/target differences before applying any intentional synchronization. This phase deliberately has no force-update or destructive rollback command. Do not delete mapped users to make a rerun pass, and do not run migrate:fresh, migrate:refresh, migrate:reset, or db:wipe against the shared database.
