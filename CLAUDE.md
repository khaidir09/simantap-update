# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

SIMANTAP (Jurnal Mengajar Digital) is a school management app from BTIKP Disdikbud Prov. Kalimantan Selatan. It covers teaching journals, student attendance, violations and parent calls, lesson plans (RPP), extracurriculars, grades, and a Tata Usaha (administration) module.

This directory is a **production distribution bundle** issued to one school (see `SERTIFIKAT-LISENSI.txt`), not the development repo. It isn't a Git repository either.

- **Frontend:** a prebuilt bundle (React 19, Tailwind 4) in `app.html` + `assets/index-*.js/css`. There's no frontend source here, so frontend changes can't be made in this directory. The bundle calls `api/index.php?action=<name>` and `api/update.php?action=<name>`.
- **Backend:** plain PHP 8 + MySQLi with no framework and no Composer.
- **Tooling:** there are no tests, linter, type checker, build step, or migration CLI here. Don't assume any framework commands exist. The only available check is `php -l <file>` (local PHP 8.2).
- **Generated files:** files marked "DIGENERATE" (`api/version.php`, `api/license_pubkey.php`, `install/installer_config.php`, `install/schema.sql`) come from upstream scripts or patches that aren't in this directory. Don't edit them by hand.
- **Updates can overwrite local edits:** the portal's auto-updater replaces files in place, so a patch may overwrite changes made directly in this bundle.
- **Language:** code comments and user-facing messages are in Indonesian. Keep them that way.

## Request flow and gates

`index.php` (root) is the gate for the SPA. It checks these in order:
1. If `api/install.lock` is missing and `install/` exists, it redirects to the install wizard.
2. If `api/.updating` exists, it returns a 503 maintenance page.
3. It checks the license through `api/license_guard.php`. If the license is invalid, it redirects to `install/` for reactivation, or returns a 403 if that folder is gone.
4. It serves `app.html`.

`api/index.php` is the single JSON API router. It applies the same install, maintenance, and license gates, then defines `SIMANTAP_API`, starts the session (`$_SESSION['user']` holds the whole user row), and dispatches on `?action=`. `api/update.php` is a separate entry point (ADMIN only) and doesn't check `install.lock` or `.updating`.

**Action dispatch is a fallback chain.** The `switch` in `api/index.php` falls through `default:` to `endpoints_v2.php`. v2's `default:` requires `endpoints_v3.php`, and then `endpoints_tu.php` is tried; v3 ends up being required twice. Each endpoint file:
- starts with `if (!defined('SIMANTAP_API')) exit`;
- reuses `$db` and the helpers from the router's scope;
- ends its `switch` with `default: return false;` and then `return true;`.

**The first match wins, so duplicate actions are shadowed.** Before adding or changing an action, grep its name across all four files. These actions are currently shadowed, and the listed copy is dead code:
- `get_subjects`: the `endpoints_v2.php` copy.
- `delete_general_activity`: the `endpoints_v3.php` copy, which has auth. The live copy in `index.php` doesn't.
- `get_parent_calls`, `update_parent_call`, `save_parent_call_manual`: the `endpoints_v3.php` copies.

The files are split by development phase, not by domain:

| File | Domains |
|---|---|
| `api/index.php` | auth/session, users, journals (`save_journal` also writes attendance, ALFA incidents, violation points, and auto parent calls), forum, incidents, general activities, extracurriculars, RPP, notifications, subjects, `system_config`, grades, profile, uploads |
| `api/endpoints_v2.php` | legacy student CRUD, violation types, `get_config`/`save_config`, attendance records, parent calls |
| `api/endpoints_v3.php` | homeroom dashboards, extracurricular members/activities, `save_user_v2`, classes |
| `api/endpoints_tu.php` | staff (GTK), students (TU version), file upload/delete, letters, legalization, inventory, rooms/bookings, archive, staff attendance |

Several parallel legacy/current endpoint pairs exist and the bundle uses both sides:
- users: `save_user` vs `save_user_v2`;
- config: `get_config`/`save_config` vs `get_system_config`/`save_system_config`;
- students: `get_students`/`save_student`/`delete_student` in v2 (English columns) vs `student_list`/`student_create`/`student_update`/`student_delete` in TU (Indonesian aliases).

## Helpers and conventions (`api/db.php`)

- `getDB()` returns a static mysqli connection (utf8mb4).
- `jsonResponse()` and `errorResponse()` both call `exit`.
- `getRequestBody()` returns the decoded JSON body.
- `requireAuth()` and `requireRole([...])` read only the session. They never re-check the database, so a deleted user or a changed role takes effect only after the user logs in again.

Typical handler pattern:
- Call `requireAuth()`/`requireRole()` first.
- Take the actor from `$user['uid']`/`$user['displayName']`, never from the body.
- Use prepared statements.
- Generate IDs with `uniqid('prefix_')`.
- Store JSON columns with `json_encode`; on read, decode them, cast types, and add alias fields for the frontend (it sends both camelCase and snake_case).

Good references:
- `save_violation_type` in `endpoints_v2.php`: simple CRUD with a role check.
- `update_parent_call` in `endpoints_v2.php`: audit log built from the session.
- `get_class_attendance_summary` in `endpoints_v3.php`: dynamic prepared parameters.

**Error handling gotcha:**
- `error_reporting(0)` is set globally.
- On PHP 8.1+, mysqli throws exceptions by default, so `if ($stmt->execute()) … else errorResponse()` branches usually never run.
- A DB error surfaces as an HTTP 500 with an empty body. Debugging means temporarily enabling errors or logging.

**Roles actually used:** `ADMIN`, `KEPALA_SEKOLAH`, `TATA_USAHA`, `GURU`. The `users.role` enum also has `OPERATOR`, `PENGAWAS`, and `SISWA`, but no code uses them. `users.class_homeroom` and `users.is_bk` mark homeroom and counseling teachers.

**Staff/user link:** `gtk_pegawai.id` equals `users.uid`. Several actions keep them in sync both ways (`gtk_create`/`gtk_update`/`gtk_delete`, `save_user_v2`, `delete_user`, `upload_profile_photo`), so changes to either module must keep both tables consistent.

## Security state (don't copy these patterns)

The existing code has serious gaps. Don't use them as templates, and flag them when touching nearby code:
- **Missing auth on mutations:** `register_guru` is a public signup. Several mutations have no auth at all: `delete_incident`, `delete_general_activity`, `delete_lesson_rpp`, `save_extracurricular`, `delete_extracurricular`, `save_ekskul_journal`, `save_student_registry`.
- **Weak authorization:** most TU actions only call `requireAuth()`. There are no record ownership checks: any user can delete any journal, and `save_journal`/`save_lesson_rpp` accept `isVerified`/`status` from the body. `save_user`/`save_user_v2` let `TATA_USAHA` and `KEPALA_SEKOLAH` assign the `ADMIN` role.
- **Unauthenticated PII reads:** `gtk_list`, `student_list`, `get_teacher_registry`, `get_student_registry`, and `get_journal_stats` return personal data without login.
- **Unsafe uploads:**
  - Uploads keep the client-supplied file extension.
  - `tu_file_upload` doesn't validate file type (except `pas_foto`) or the `module` path segment.
  - `tu_file_delete` unlinks a path taken straight from the body.
  - There's no `.htaccess`, so nothing blocks PHP execution in `uploads/` or public access to files in `api/`.
- **Other issues:**
  - The HTTP method is never checked, so GET deletes are CSRF-able.
  - Login doesn't call `session_regenerate_id`.
  - The default password `simantap123` is hardcoded.
  - `save_journal` returns stack traces to the client.

## Configuration and state files (in `api/`)

- `config.local.php` is written by the installer and defines `DB_*` and `APP_TIMEZONE` (default `Asia/Makassar`). Without it, `db.php` uses dev fallbacks.
- `license.json` holds the Ed25519-signed payload `{npsn, installed_url, issued_at}`. It's verified with ext `sodium` against `LICENSE_PUBLIC_KEY`, and **the request host must match `installed_url`**. The installer's requirement check doesn't test for `sodium`.
- `install.lock`, `.updating`, `.heartbeat`, `.license_revoked`, and `.update_available` are runtime flags. The heartbeat (at most once a day) sets the revoked and update flags. The updater also uses `patch_tmp/` and `backups/`.

**Local dev without a portal token** needs both of these, and neither file exists in this checkout yet:
- `api/config.local.php` with `define('SIMANTAP_DEV_MODE', true);`, which skips license checks;
- `api/install.lock`, because otherwise every request is sent to `install/` while that folder exists.

Also check that ext `sodium` is enabled in the web server's php.ini if you're not using dev mode; the local PHP CLI doesn't load it.

## Database schema

`install/schema.sql` is the base schema. The installer imports it in chunks. Later schema changes come from:
- signed patches applied by `api/update.php`, tracked in `schema_migrations`. Rollback doesn't revert migrations, and it picks the "latest" backup by string sort, so `1.0.9` sorts after `1.0.10`;
- DDL inside request handlers. `student_list`/`student_create`/`student_update` convert `students.status` from the schema's enum (`AKTIF`, …) to VARCHAR (`Aktif`, `Pindah`, …). `get_classes` runs `CREATE TABLE IF NOT EXISTS` and seeds `classes` from `students`. Expect mixed-case status values; for example, v2's `get_students` filters on `status = 'AKTIF'`.

Known data bugs and hardcoded values:
- `tu_file_delete` with `module=siswa` queries a nonexistent `siswa` table.
- `'SMAN 1 BANJARBARU'` and `'2025/2026'` are hardcoded as defaults in several places.
- `verify_ekskul_journal` is a no-op.
- `users.lastLogin` changes on every row update, not just on login.

## Uploads

User files go under `uploads/{journals,logos,tu}/<module>/<sanitized-id>/`. Responses return paths relative to the deploy root, and the frontend resolves them. Each top-level folder has an empty `index.html` to block directory listing.
