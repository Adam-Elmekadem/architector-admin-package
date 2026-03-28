# Architector Admin

Architector Admin is a Laravel package that provisions an admin-ready backend and scaffolds a React frontend through a single setup command.

The package is designed for teams that want a fast and repeatable admin bootstrap flow with Sanctum-based authentication and a generated frontend starter.

## Highlights

- Single setup entrypoint: `php artisan admin:setup`
- Interactive or non-interactive setup
- Admin user create/login verification flow
- Sanctum token issuance
- API auth endpoint provisioning (`/api/auth/login`, `/api/auth/logout`, `/api/auth/me`)
- Migration-driven CRUD generation (entities discovered from migrations)
- Auto-generated CRUD controller/routes plus React CRUD modals and forms
- React frontend scaffold (Vite + Tailwind v4 + Redux Toolkit + Router + Axios + React Icons)
- Safe regeneration using `--force`

## Release Docs

- Changelog: `CHANGELOG.md`
- Current release note: `docs/releases/V1.3.3.md`

## Requirements

- PHP 8.2+
- Laravel 12+
- Composer
- Node.js 18+

## Installation

```bash
composer require elmekadem/architector-admin
```

Laravel package discovery registers the provider automatically.

## The Only Setup Command

```bash
php artisan admin:setup
```

This is the only supported command for creating the admin dashboard setup flow.

## Command Options

```bash
php artisan admin:setup \
  [--frontend-path=frontend] \
  [--force] \
  [--skip-frontend]
```

- `--frontend-path`: output directory for generated React app (default: `frontend`)
- `--force`: overwrite generated files
- `--skip-frontend`: complete backend/auth setup without frontend generation

## What `admin:setup` Does

1. Ensures `routes/api.php` exists
2. Creates or validates an admin user
3. Issues a Sanctum token
4. Ensures API auth controller exists at `app/Http/Controllers/Api/AuthController.php`
5. Ensures auth routes exist in `routes/api.php`
6. Generates migration-driven CRUD backend components:

- `app/Http/Controllers/AdminDashboardCrudController.php`
- `app/Support/AdminDashboard/FieldResolver.php`
- `app/Support/AdminDashboard/EntityTableResolver.php`
- `app/Support/AdminDashboard/CrudPayloadBuilder.php`
- `/api/admin-dashboard/*` entity/schema/records routes

7. Writes/updates `config/admin_dashboard.php`
8. Generates React frontend files (unless `--skip-frontend`), including CRUD table views and create/edit modals based on detected entity schemas

## Generated Frontend Stack

- React 18
- Vite 7
- Tailwind CSS v4
- Redux Toolkit + React Redux
- React Router DOM
- Axios
- React Icons

## Generated Frontend Structure

Default output path: `frontend`

```text
frontend/
  package.json
  vite.config.js
  index.html
  .env.example
  src/
    main.jsx
    App.jsx
    api.js
    styles.css
    app/store.js
    components/EntitySidebar.jsx
    features/auth/authSlice.js
    pages/LoginPage.jsx
```

## Quick Start

```bash
# 1) Run setup
php artisan admin:setup

# 2) Install frontend deps
cd frontend
npm install

# 3) Start frontend dev server
npm run dev

# 4) Start Laravel backend (separate terminal)
php artisan serve
```

## Non-Interactive Setup (CI / Automation)

```bash
php artisan admin:setup --no-interaction --force --frontend-path=frontend
```

Optional environment variables used by non-interactive mode:

- `ADMIN_SETUP_EMAIL` (default: `admin@example.com`)
- `ADMIN_SETUP_NAME` (default: `Admin User`)
- `ADMIN_SETUP_PASSWORD` (default: `admin12345`)

## API Endpoints

Auth endpoints provisioned by setup:

- `POST /api/auth/login`
- `POST /api/auth/logout` (requires Sanctum auth)
- `GET /api/auth/me` (requires Sanctum auth)

Generated frontend also targets admin dashboard endpoints under:

- `/api/admin-dashboard/...`

## Publishing Assets (Optional)

```bash
php artisan vendor:publish --provider="Elmekadem\ArchitectorAdmin\Providers\ArchitectorServiceProvider"
```

Available tags:

- `architector-views`
- `architector-stubs`
- `architector-config`
- `architector-migrations`
- `architector`

## Troubleshooting

### Command not found: `admin:setup`

Run:

```bash
composer dump-autoload
php artisan package:discover --ansi
php artisan list | findstr admin:setup
```

### Frontend files were not regenerated

Use the force flag:

```bash
php artisan admin:setup --force
```

### Auth routes/controller not created as expected

Run setup again with force:

```bash
php artisan admin:setup --force
```

Then verify:

- `app/Http/Controllers/Api/AuthController.php`
- `routes/api.php`

## Versioning

The package is released with semantic version tags.

## License

MIT
