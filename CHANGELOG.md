# Changelog

All notable changes to this package are documented in this file.

## V1.3.3 - 2026-03-28

### Added

- `admin:setup` now generates a clean CRUD backend scaffold by default:
  - `app/Http/Controllers/AdminDashboardCrudController.php`
  - `app/Support/AdminDashboard/FieldResolver.php`
  - `app/Support/AdminDashboard/EntityTableResolver.php`
  - `app/Support/AdminDashboard/CrudPayloadBuilder.php`

### Changed

- `admin:setup` now ensures CRUD API routes for:
  - `/api/admin-dashboard/entities`
  - `/api/admin-dashboard/{entity}/schema`
  - `/api/admin-dashboard/{entity}/records` (GET/POST/PUT/DELETE)

### Notes

- Tag `V1.3.2` was created earlier in the flow.
- `V1.3.3` is the authoritative release for the published rebased commit on `main`.

## V1.3.0 - 2026-03-28

### Added

- Migration-comment driven dynamic CRUD field rules for generated frontend forms.

### Changed

- Login-first generated admin flow and improved generated dashboard parity behavior.
