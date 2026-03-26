# Architector Admin - Laravel Admin Dashboard Generator Package

A production-ready Laravel package that automatically scaffolds beautiful, customizable admin dashboards with zero boilerplate. Generate full-featured admin UIs, API-driven CRUD interfaces, and backend scaffolding with a single command.

## Features

✨ **Instant Admin Generation**

- One-command admin dashboard scaffolding
- Automatic layout, routes, and API integration
- Beautiful Tailwind CSS UI with dark/light theme support
- Responsive design (mobile, tablet, desktop)

🎨 **Customizable Themes**

- 4 built-in themes: Basic, Cyan, Emerald, Rose
- Multiple design layouts: Cards, Tables, Grid
- Configurable colors, fonts, and spacing
- Easy to extend with custom themes

⚡ **API-Driven Architecture**

- Connect to external APIs (REST, GraphQL-ready)
- Bearer token authentication
- Dynamic entity management
- Schema introspection for table generation
- User admin actions (ban/unban/reset password)

🛠️ **CRUD Operations**

- Local in-memory CRUD mode for prototyping
- API-backed CRUD for production
- Inline add/edit/delete UI
- Automatic validation

🔐 **Security**

- Laravel Sanctum token authentication
- Admin middleware guards
- CSRF protection
- Input validation and sanitization

📱 **Backend Generation**

- Auto-generate Models, Controllers, Migrations
- Create API routes for each entity
- Generate seeders for testing data
- Database table introspection

## Requirements

- PHP 8.2+
- Laravel 12.0+
- Laravel Sanctum 4.0+
- Composer
- Node.js (for Tailwind compilation)

## Installation

### Step 1: Install via Composer

```bash
composer require elmekadem/architector-admin
```

The package will auto-register via Laravel's package discovery.

### Step 2: Publish Package Assets

Publish views, stubs, and configuration:

```bash
php artisan vendor:publish --provider="Elmekadem\ArchitectorAdmin\Providers\ArchitectorServiceProvider"
```

Or publish selectively:

```bash
# Publish only views
php artisan vendor:publish --provider="Elmekadem\ArchitectorAdmin\Providers\ArchitectorServiceProvider" --tag=architector-views

# Publish only configuration
php artisan vendor:publish --provider="Elmekadem\ArchitectorAdmin\Providers\ArchitectorServiceProvider" --tag=architector-config

# Publish only stubs
php artisan vendor:publish --provider="Elmekadem\ArchitectorAdmin\Providers\ArchitectorServiceProvider" --tag=architector-stubs

# Publish only migrations
php artisan vendor:publish --provider="Elmekadem\ArchitectorAdmin\Providers\ArchitectorServiceProvider" --tag=architector-migrations
```

### Step 3: Run Setup Wizard

Start the interactive setup wizard:

```bash
php artisan admin:setup
```

This wizard will:

1. Create or select an admin user
2. Generate an API token (Sanctum)
3. Configure dashboard settings
4. Generate the admin dashboard UI
5. Scaffold backend CRUD files per table

## Usage

### Quick Start

```bash
# Interactive setup (recommended for first time)
php artisan admin:setup

# Non-interactive setup (for CI/CD)
php artisan admin:setup --no-interaction
```

### Generate Dashboard Only

```bash
php artisan make:admin \
  --title="My Admin" \
  --welcome="Welcome Admin" \
  --user="John Doe" \
  --color=emerald \
  --design=gridlayouts \
  --route=/admin \
  --api.endpoint="https://api.example.com" \
  --api-token="your-bearer-token" \
  --install-icons=1 \
  --force
```

**Options:**

| Option            | Description                            | Default             |
| ----------------- | -------------------------------------- | ------------------- |
| `--title`         | Brand title in sidebar                 | CoachPro            |
| `--welcome`       | Welcome message in topbar              | Welcome back, Admin |
| `--user`          | User name in profile chip              | Admin User          |
| `--color`         | Theme color (basic/cyan/emerald/rose)  | basic               |
| `--design`        | Layout type (cards/tables/gridlayouts) | gridlayouts         |
| `--route`         | Dashboard URL path                     | /admin/dashboard    |
| `--api`           | External API endpoint (optional)       | empty               |
| `--token`         | Bearer token for API auth              | empty               |
| `--crud`          | Enable local CRUD mode (0/1)           | 1                   |
| `--install-icons` | Auto-install Blade Heroicons (0/1)     | 1                   |
| `--force`         | Overwrite existing files               | false               |

### Generate Backend Scaffold

Generate Models, Controllers, Migrations, and Routes for all tables:

```bash
php artisan admin:generate-entity --all
```

Generate for specific table:

```bash
php artisan admin:generate-entity --table=users
```

## Architecture

### Directory Structure After Installation

```
app/
├── Console/Commands/
│   ├── AdminSetup.php              # Interactive setup wizard
│   ├── MakeAdmin.php               # Dashboard generator
│   └── AdminGenerateEntity.php     # Backend scaffolder
├── Http/
│   ├── Controllers/
│   │   ├── Admin/
│   │   │   ├── UsersController.php
│   │   │   └── ProductsController.php
│   │   └── AdminDashboardCrudController.php
│   └── Middleware/
│       └── AdminGuard.php           # Admin authorization
├── Models/
│   ├── User.php
│   ├── Product.php
│   └── ...
resources/
├── views/
│   └── admin/
│       ├── layout/
│       │   ├── app.blade.php        # Master layout
│       │   ├── sidebar.blade.php    # Navigation
│       │   └── topbar.blade.php     # Header
│       └── pages/
│           └── dashboard.blade.php  # Dashboard page
routes/
├── web.php                           # Dashboard route registration
└── api.php                           # API endpoints (auto-created)
database/
├── migrations/
│   ├── users_table.php
│   └── products_table.php
└── seeders/
    └── ProductSeeder.php
```

### API Endpoints

When CRUD mode is enabled, the following endpoints are available:

#### Authentication

```
POST   /api/admin-dashboard/login
POST   /api/admin-dashboard/logout
GET    /api/admin-dashboard/me
```

#### Entity CRUD Operations

```
GET    /api/admin-dashboard/{entity}/schema     # Get table structure
GET    /api/admin-dashboard/{entity}/records    # List all records
POST   /api/admin-dashboard/{entity}/records    # Create record
PUT    /api/admin-dashboard/{entity}/records/{id} # Update record
DELETE /api/admin-dashboard/{entity}/records/{id} # Delete record
```

#### User Admin Actions

```
POST   /api/admin-dashboard/users/{id}/ban           # Ban user (set is_active=false)
POST   /api/admin-dashboard/users/{id}/unban         # Unban user (set is_active=true)
POST   /api/admin-dashboard/users/{id}/reset-password # Send password reset
```

**Example Request:**

```bash
curl -X GET "http://localhost:8000/api/admin-dashboard/users/schema" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"

# Response:
{
  "columns": [
    {"name": "id", "type": "bigint", "nullable": false},
    {"name": "email", "type": "string", "nullable": false},
    {"name": "name", "type": "string", "nullable": false}
  ]
}
```

```bash
curl -X GET "http://localhost:8000/api/admin-dashboard/users/records?page=1&per_page=10" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Accept: application/json"

# Response:
{
  "data": [
    {
      "id": 1,
      "name": "John",
      "email": "john@example.com",
      "created_at": "2024-01-15T10:30:00Z"
    }
  ],
  "pagination": {...}
}
```

## Before & After Examples

### Before (Without Architector)

```
Starting a new admin dashboard project:

1. Create layouts manually
2. Write sidebar/topbar HTML/CSS
3. Setup table components
4. Build API controllers
5. Generate routes
6. Write authentication
7. Create CRUD operations
8. Style with Tailwind
9. Test everything

Time: 6-8 hours
```

### After (With Architector)

```
# 1. Install package
composer require elmekadem/architector-admin

# 2. Run setup (interactive prompts)
php artisan admin:setup

# 3. Generate backend (optional)
php artisan admin:generate-entity --all

# Done! Visit http://localhost:8000/admin/dashboard
```

Time: **2-5 minutes**  
Generated: Dashboard UI, API routes, CRUD controllers, middleware, authentication

## Configuration

### Environment Variables

Add to your `.env` file:

```env
# API Configuration
ARCHITECTOR_API_ENDPOINT=https://api.example.com
ARCHITECTOR_API_TOKEN=your_bearer_token

# Dashboard Appearance
ARCHITECTOR_DEFAULT_COLOR=emerald
ARCHITECTOR_DEFAULT_DESIGN=gridlayouts
ARCHITECTOR_DEFAULT_ROUTE=/admin/dashboard

# Features
ARCHITECTOR_ENABLE_API_MODE=true
ARCHITECTOR_ENABLE_CRUD_MODE=true
ARCHITECTOR_USE_ICONS=true
```

### Configuration File

Edit `config/architector.php` after publishing:

```php
return [
    'api_endpoint' => env('ARCHITECTOR_API_ENDPOINT', ''),
    'api_token' => env('ARCHITECTOR_API_TOKEN', ''),
    'default_color' => env('ARCHITECTOR_DEFAULT_COLOR', 'basic'),
    'default_design' => env('ARCHITECTOR_DEFAULT_DESIGN', 'gridlayouts'),
    'default_route' => env('ARCHITECTOR_DEFAULT_ROUTE', '/admin/dashboard'),
    'use_icons' => env('ARCHITECTOR_USE_ICONS', true),
    'enable_api_mode' => env('ARCHITECTOR_ENABLE_API_MODE', true),
    'enable_crud_mode' => env('ARCHITECTOR_ENABLE_CRUD_MODE', true),
];
```

## Workflow

### Typical Development Workflow

```bash
# Create new Laravel project
laravel new myapp
cd myapp

# Install Architector
composer require elmekadem/architector-admin

# Run setup wizard
php artisan admin:setup
# Follow prompts:
# - Create admin user (email: admin@example.com)
# - Token name: admin-dashboard
# - Save config: yes
# - Generate dashboard: yes
# - Choose theme: emerald
# - Generate backend: yes

# Migrate database
php artisan migrate

# Start dev server
php artisan serve

# Visit dashboard
# http://localhost:8000/admin/dashboard
```

### Adding to Existing Project

```bash
# In existing Laravel project
composer require elmekadem/architector-admin

# Setup admin user and dashboard
php artisan admin:setup

# Generate backend for existing tables
php artisan admin:generate-entity --all

# Done! Dashboard is now integrated
```

### Customizing Generated Code

After generation, all files are editable:

```bash
# Edit dashboard layout
nano resources/views/admin/layout/app.blade.php

# Edit dashboard page
nano resources/views/admin/pages/dashboard.blade.php

# Edit generated controllers
nano app/Http/Controllers/Admin/UsersController.php

# Edit API routes
nano routes/api.php
```

## Troubleshooting

### Issue: "routes/api.php not found" on boot

**Solution:** The package auto-creates missing API route files. No action needed.

```bash
# Verify file exists
test -f routes/api.php && echo "API routes file exists"

# Or manually create:
php artisan make:admin --force
```

### Issue: "Cannot redeclare block-scoped variable"

**Solution:** This is fixed in latest version. Dashboard now uses data-attributes instead of inline Blade json_encode.

**Verification:**

```blade
<!-- ✅ Good - Uses data-attributes -->
<div id="config" data-api="{{ $api }}" class="hidden"></div>

<!-- ❌ Bad - Inline Blade in JS -->
<script>const api = {!! json_encode($api) !!};</script>
```

### Issue: "Undefined class BladeUI\Heroicons\HeroiconsServiceProvider"

**Solution:** Package uses string-based class detection to safely check for icon library.

```bash
# Auto-install icons:
php artisan make:admin --install-icons=1

# Or manually:
composer require blade-ui-kit/blade-heroicons
```

### Issue: "Class not found" when routes reference non-existent controller

**Solution:** Generated routes use `class_exists()` guards to prevent missing controller crashes.

**Prevention:**

```bash
# Generate backend files first
php artisan admin:generate-entity --all

# Then generate dashboard
php artisan make:admin
```

### Issue: Admin middleware not found

**Solution:** Create the middleware manually or use the generated one from `admin:generate-entity`.

```bash
# Generate admin middleware
php artisan make:middleware AdminGuard

# Update app/Http/Middleware/AdminGuard.php
# Add to: app/Http/Kernel.php protected $routeMiddleware
```

## Security

### Admin Middleware

Always protect admin routes with authentication:

```php
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::view('/admin/dashboard', 'admin.pages.dashboard');
});
```

Implement `AdminGuard` middleware:

```php
// app/Http/Middleware/AdminGuard.php
public function handle(Request $request, Closure $next)
{
    if (!auth()->check() || !(auth()->user()->is_admin ?? false)) {
        abort(403, 'Unauthorized');
    }

    return $next($request);
}
```

### Token Management

```php
// Generate admin token
$user = User::where('email', 'admin@example.com')->first();
$token = $user->createToken('admin-dashboard')->plainTextToken;

// Revoke tokens
$user->tokens()->delete();

// Revoke specific token
$user->tokens()->where('name', 'admin-dashboard')->delete();
```

### CORS Configuration

For external API connections, configure CORS in `config/cors.php`:

```php
'allowed_origins' => ['https://api.example.com'],
'allowed_methods' => ['*'],
'allowed_headers' => ['*'],
'exposed_headers' => [],
'max_age' => 0,
'supports_credentials' => true,
```

## Advanced Options

### Custom Themes

Edit generated `resources/views/admin/layout/app.blade.php` to customize:

```blade
<!-- Change gradient -->
<body class="bg-linear-to-br from-custom-100 via-custom-100 to-custom-200">
```

### Custom API Schema

Override `AdminDashboardCrudController@schema()`:

```php
public function schema($entity): JsonResponse
{
    // Custom schema logic
    return response()->json([
        'columns' => $this->getEntityColumns($entity),
        'displayField' => 'name',
        'searchable' => ['name', 'email'],
    ]);
}
```

### Custom Models

The package doesn't restrict Model customization. Extend generated models:

```php
// app/Models/User.php
class User extends Model
{
    // Add custom scopes, methods, relationships
    public function scopeAdmins($query)
    {
        return $query->where('is_admin', true);
    }
}
```

## Publishing & Distribution

### Initial Release

```bash
# Create GitHub repository
git init
git add .
git commit -m "Initial commit"
git remote add origin https://github.com/yourusername/architector-admin.git
git push -u origin main
```

### Register on Packagist

1. Go to https://packagist.org/packages/submit
2. Enter repository URL: `https://github.com/yourusername/architector-admin`
3. Submit

Users can now install via:

```bash
composer require yourusername/architector-admin
```

## Support & Contributing

- **Issues:** GitHub Issues
- **Discussions:** GitHub Discussions
- **Contributing:** Submit Pull Requests

## License

MIT License - see LICENSE file

## Changelog

### v1.0.0 (Current)

- Initial release
- Admin setup wizard
- Dashboard generator (4 themes)
- CRUD operations (local & API)
- Backend scaffolding
- Icon library integration (Blade Heroicons)
- Sanctum authentication
- Self-healing for missing files

---

**Created by Adam Elmekadem**  
**Package Name:** `elmekadem/architector-admin`  
**Repository:** https://github.com/Adam-Elmekadem/ArchitectorPackage
