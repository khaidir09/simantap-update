# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

SIMANTAP (Jurnal Mengajar Digital) is a school management app from BTIKP Disdikbud Prov. Kalimantan Selatan. It covers teaching journals, student attendance, violations and parent calls, lesson plans (RPP), extracurriculars, grades, and a Tata Usaha (administration) module.

This directory is a **production distribution bundle** issued to one school (see `SERTIFIKAT-LISENSI.txt`), not the development repo. It isn't a Git repository either.

- **Frontend:** a prebuilt bundle (React 19, Tailwind 4) in `app.html` + `assets/index-*.js/css`. There's no frontend source here. Local frontend changes are string-replacement patch scripts in `docs/patches/apply-*-bundle.js`, each writing a new `assets/index-CGWL69P7-<name>.js` from the previous one and pointing `app.html` at it. The Tailwind CSS is precompiled, so a patch can only use classes that already exist in `assets/index-CC1PBzaO.css`. The bundle calls `api/index.php?action=<name>` and `api/update.php?action=<name>`.
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
| `api/index.php` | auth/session, users, journals (`save_journal`/`delete_journal` recompute daily attendance, ALFA incidents, violation points, and auto parent calls via `presensi_harian.php`; see `docs/patches/presensi-harian-jurnal.md`), forum, incidents, general activities, extracurriculars, RPP, notifications, subjects, `system_config`, grades, profile, uploads |
| `api/endpoints_v2.php` | legacy student CRUD, violation types, `get_config`/`save_config`, attendance records, parent calls |
| `api/endpoints_v3.php` | homeroom dashboards, extracurricular members/activities, `save_user_v2`, classes |
| `api/endpoints_tu.php` | staff (GTK), students (TU version), file upload/delete, letters, legalization, inventory, rooms/bookings, archive, staff attendance |
| `api/dispensasi.php` | student dispensations (`dispensasi_*` actions, dispatched by explicit `case`s in `index.php`); table `student_dispensations` is created on first use. See `docs/patches/dispensasi-siswa.md` |
| `api/presensi_jam_sebelumnya.php` | `presensi_jam_sebelumnya` (explicit `case` in `index.php`): SAKIT/IZIN from the latest earlier non-draft journal of the same class/date (or MANUAL homeroom attendance) that the journal form pre-fills for new journals; ALFA isn't carried over. See `docs/patches/presensi-jam-sebelumnya.md` |
| `api/presensi_kosong.php` | helpers for school days and the Dashboard Kelas (no actions of its own): `system_config.school_days_per_week` (`5` = Mon–Fri, `6` = Mon–Sat; missing/invalid → 6; ADMIN-only via `save_system_config`, refused by `save_config`; `pkHariSekolah`). `presensiTanggalKosong` adds `presensi_kosong` to `get_homeroom_dashboard`: school days of the semester (from the school's first attendance date to today) with no `attendance_records` for the class; dates with no attendance in any class go to `kemungkinan_libur`. `cetak_rekap_bulanan`'s missing-date list uses the same school days. See `docs/patches/tanggal-belum-presensi.md` |
| `api/label_media.php` | journal-form "Media / Alat Pembelajaran" labels (`media_label_list`/`media_label_simpan`/`media_label_hapus`, explicit `case`s in `index.php`): built-in labels + each teacher's typed labels and past `alat_media` values; table `journal_media_labels` is created on first use; the journal still stores comma-separated text. See `docs/patches/label-media-jurnal.md` |
| `api/jadwal_kbm.php` | teacher KBM schedules in `gtk_pegawai.jadwal_mengajar_json` (`jadwal_kbm_get`/`jadwal_kbm_save`, explicit `case`s in `index.php`): teachers edit their own from Profil, TU/ADMIN edit anyone's; saves require the `versi` hash (409 on conflict); old free-text `jamKe` entries are parsed on read. The journal form auto-fills Jam Ke-/Jam Mulai/Jam Selesai from it. See `docs/specs/jadwal-kbm-mandiri-guru.md` |
| `api/kelola_guru.php` | helpers for the Kelola Guru page (no actions of its own): `kgCatatLogin` writes `users.last_login_at` on login (column created on first use; the old `lastLogin` has `ON UPDATE` and isn't a login time); `kgStatusGuru` adds `mapel_count`/`jadwal_count`/`last_login_at`/`last_login_hari` to `get_users`; `kgValidasiEmail` guards `save_user`/`save_user_v2` (email change ADMIN-only, 409 on duplicate, 400 on bad format). `gtk_update` can still change `users.email` without this guard. See `docs/specs/kelola-guru-tabel-modal.md` |
| `api/bimbingan_pkl.php` | Jurnal Bimbingan PKL (16 `pkl_*` actions, explicit `case`s in `index.php`): DU/DI master, student placements (teacher ↔ class XII student ↔ DU/DI ↔ period, no overlapping periods per student, guarded by `GET_LOCK`), guidance journals with optional photos (`pkl_jurnal_simpan` `salin_ke[]` copies one entry to the teacher's other students at the same DU/DI as separate rows, all-or-nothing; duplicate placement+date+start time → 409), HTML print pages (monthly recap per teacher, student card). Mentors must have a `teacher_subjects` assignment for class XII; tables `pkl_dudi`/`pkl_penempatan`/`pkl_jurnal` are created on first use. Doesn't touch attendance, incidents, points, or `journal_entries`. Only available when the school level is SMK (see `api/jenjang_sekolah.php`). See `docs/specs/jurnal-bimbingan-pkl.md` |
| `api/jenjang_sekolah.php` | helpers for the school level (no actions of its own): `system_config.school_level` (`SD`/`SMP`/`SMA`/`SMK`, set by ADMIN via `save_system_config`; detected from `kop_sekolah` while unset). `jenjangAktif` gives the levels (SD I–VI, SMP VII–IX, SMA/SMK X–XII) and `pkl_aktif`; `jenjangParseLevel` reads a level from class IDs/names (Roman or 1–12) and must stay identical to `__jjParseLevel` in the bundle (shared cases in `docs/patches/uji-jenjang-sekolah-kasus.json`); `save_system_config` returns 409 with `peringatan_jenjang` when existing data is outside the new level until resent with `konfirmasi_jenjang: true`; `jenjangWajibPkl` blocks all `pkl_*` actions outside SMK. `get_config` adds `school_level`/`school_level_sumber`. See `docs/specs/jenjang-sekolah-dinamis.md` |
| `api/profil_murid.php` | Profil Murid 360° (8 actions: `profil_murid_daftar`/`_get`/`_asesmen_simpan`/`_asesmen_hapus`/`_asesmen_template`/`_asesmen_impor`/`_dapodik_impor`, `cetak_profil_murid`; explicit `case`s in `index.php`): one page per student combining Dapodik biodata, non-cognitive assessment (RIASEC, learning style, difficulties, easiest/hardest subjects) and career plan per academic year + semester, plus grades (current semester and per-semester trend via `pantauNilaiCollect(..., $studentsOverride)`), attendance, teacher notes, incidents, parent calls, dispensations, extracurriculars. Access = `pantauNilaiScope` (homeroom: active students of own class; BK/KS/ADMIN: all; TU denied); NIK/KK masked for homeroom teachers. Dapodik import (TU/ADMIN) matches NISN then NIK, overwrites biodata and some `students` columns (never name/class), drops bank/coordinate columns; assessment import uses the SIMANTAP template. Excel is parsed in the browser (bundled SheetJS), mapping/validation in PHP. Tables `student_biodata`/`student_asesmen`/`student_import_log` are created on first use. See `docs/specs/profil-murid-360.md` |
| `api/verifikasi_jurnal.php` | helpers for the journal verification mode (no actions of its own): `system_config.journal_verification_mode` (`OTOMATIS`/`MANUAL`, ADMIN-only via `save_system_config`; missing/invalid → `OTOMATIS`). In `OTOMATIS`, `save_journal` marks non-draft journals SAH with `verifiedBy = 'SISTEM'` and owners may edit/delete SAH journals; `MANUAL` is the old Kepsek verification + lock. `vjPastikanMigrasi` (called from journal/config/dashboard actions) once SAHs old pending journals, guarded by `journal_verification_migrated` + `GET_LOCK`. Switching `MANUAL`→`OTOMATIS` with pending journals returns 409 `peringatan_verifikasi` until resent with `konfirmasi_verifikasi: true`; `save_config` refuses these keys. `get_journals` adds `verifiedByName` (SISTEM → `kop_kepsek_nama`, never shown as "SISTEM"). See `docs/specs/verifikasi-jurnal-otomatis.md` |

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
- **Weak authorization:** most TU actions only call `requireAuth()` (`gtk_update_atribut` is now limited to `TATA_USAHA`/`ADMIN` and POST). Record ownership checks are mostly missing. Journals are the exception: `save_journal`/`delete_journal` limit roles other than `ADMIN`/`KEPALA_SEKOLAH` to their own journals (and, in `MANUAL` verification mode, only unverified ones; see `api/verifikasi_jurnal.php`), and `save_journal` keeps the verification status from the database. `save_lesson_rpp` still accepts `status` from the body. `save_user`/`save_user_v2` let `TATA_USAHA` and `KEPALA_SEKOLAH` assign the `ADMIN` role.
- **Unauthenticated PII reads:** `gtk_list`, `student_list`, `get_teacher_registry`, `get_student_registry`, `get_homeroom_dashboard`, and `get_journal_stats` return personal data without login.
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
- DDL inside request handlers. `student_list`/`student_create`/`student_update` convert `students.status` from the schema's enum (`AKTIF`, …) to VARCHAR (`Aktif`, `Pindah`, …). `get_classes` runs `CREATE TABLE IF NOT EXISTS` and seeds `classes` from `students`. `approve_journals_month` creates `journal_approval_batches`. Expect mixed-case status values; for example, v2's `get_students` filters on `status = 'AKTIF'`.

Known data bugs and hardcoded values:
- `tu_file_delete` with `module=siswa` queries a nonexistent `siswa` table.
- `'SMAN 1 BANJARBARU'` and `'2025/2026'` are hardcoded as defaults in several places.
- `verify_ekskul_journal` is a no-op.
- `users.lastLogin` changes on every row update, not just on login. Use `users.last_login_at` (see `api/kelola_guru.php`).

## Uploads

User files go under `uploads/{journals,logos,tu}/<module>/<sanitized-id>/`. Responses return paths relative to the deploy root, and the frontend resolves them. Each top-level folder has an empty `index.html` to block directory listing.
