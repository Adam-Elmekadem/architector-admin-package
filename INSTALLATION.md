# Installation & Development Guide

This guide covers how to install `elmekadem/architector-admin` in your Laravel projects and how to publish it to Packagist for distribution.

## Local Installation (During Development)

### Option 1: Local Path Repository (Recommended for Development)

If you're testing the package locally in another Laravel project:

**In your Laravel project's `composer.json`:**

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../ArchitectorPackage",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "elmekadem/architector-admin": "*@dev"
  }
}
```

Then run:

```bash
composer install
```

The package will be symlinked from `../ArchitectorPackage`, allowing you to edit package files directly and see changes immediately.

### Option 2: Git Repository (For Testing Before Publishing)

Push the package to a private or public GitHub repository:

```bash
cd ArchitectorPackage
git init
git add .
git commit -m "Initial commit"
git remote add origin https://github.com/yourusername/architector-admin.git
git branch -M main
git push -u origin main
```

Then in your Laravel project:

```bash
composer require yourusername/architector-admin:dev-main
```

## Setting Up the Package (First Time)

### 1. Complete the Repository

The package structure is created. Now you need to copy the command files from the main Architector project:

```bash
# Copy command files
cp Architector/app/Console/Commands/AdminSetup.php ArchitectorPackage/src/Console/Commands/
cp Architector/app/Console/Commands/MakeAdmin.php ArchitectorPackage/src/Console/Commands/
cp Architector/app/Console/Commands/AdminGenerateEntity.php ArchitectorPackage/src/Console/Commands/

# Update namespace in copied files from App\Console\Commands to Elmekadem\ArchitectorAdmin\Console\Commands
```

### 2. Copy Views and Resources

```bash
# Copy views
cp -r Architector/resources/views/admin/* ArchitectorPackage/resources/views/admin/

# Copy stubs (if used)
cp -r Architector/resources/stubs/* ArchitectorPackage/resources/stubs/
```

### 3. Update Namespaces

Find and replace namespaces in copied files:

**Before:**

```php
namespace App\Console\Commands;
namespace App\Http\Controllers;
namespace App\Models;
```

**After:**

```php
namespace Elmekadem\ArchitectorAdmin\Console\Commands;
namespace Elmekadem\ArchitectorAdmin\Http\Controllers;
namespace Elmekadem\ArchitectorAdmin\Models;
```

### 4. Test in a New Project

Create a test Laravel project:

```bash
laravel new test-architector
cd test-architector

# Add package as local path
# Edit composer.json with repository config from Option 1 above

composer install

# Publish package assets
php artisan vendor:publish --provider="Elmekadem\ArchitectorAdmin\Providers\ArchitectorServiceProvider"

# Test setup command
php artisan admin:setup
```

## Publishing to Packagist

### Step 1: Create GitHub Repository

```bash
cd ArchitectorPackage

git init
git add .
git commit -m "feat: initial release v1.0.0"
git branch -M main
git remote add origin https://github.com/yourusername/architector-admin.git
git push -u origin main

# Create a GitHub release
# Go to https://github.com/yourusername/architector-admin/releases
# Click "Create a new release"
# Tag: v1.0.0
# Title: Initial Release
# Publish release
```

### Step 2: Register on Packagist

1. Go to https://packagist.org
2. Sign in with GitHub or create an account
3. Click "Submit Package"
4. Enter your repository URL: `https://github.com/yourusername/architector-admin`
5. Submit

### Step 3: Enable Auto-Updates

To automatically update Packagist when you push to GitHub:

1. On Packagist, go to your package page
2. Click "Edit"
3. Copy the webhook URL
4. Go to GitHub repository Settings > Webhooks
5. Add new webhook with:
   - **Payload URL:** (paste from Packagist)
   - **Content type:** application/json
   - **Events:** Just push events
   - **Active:** ✓

Now every time you push to GitHub, Packagist will update automatically.

### Step 4: Create Distribution Tags

For each release, create a git tag:

```bash
git tag -a v1.0.0 -m "Version 1.0.0 - Initial release with dashboard generator"
git push origin v1.0.0

# For hotfixes
git tag -a v1.0.1 -m "Version 1.0.1 - Bug fixes"
git push origin v1.0.1
```

## Using the Package

Once published to Packagist, users can install it like any Laravel package:

```bash
# Install
composer require elmekadem/architector-admin

# Or specific version
composer require elmekadem/architector-admin:^1.0

# Publish assets
php artisan vendor:publish --provider="Elmekadem\ArchitectorAdmin\Providers\ArchitectorServiceProvider"

# Run setup
php artisan admin:setup
```

## Package Structure Reference

```
ArchitectorPackage/
├── src/
│   ├── Console/
│   │   └── Commands/
│   │       ├── AdminSetup.php           # Interactive setup
│   │       ├── MakeAdmin.php            # Dashboard generator
│   │       └── AdminGenerateEntity.php  # Backend scaffolder
│   ├── Http/
│   │   └── Controllers/
│   │       └── AdminDashboardCrudController.php
│   ├── Providers/
│   │   └── ArchitectorServiceProvider.php
│   └── config/
│       └── architector.php
├── resources/
│   ├── views/
│   │   └── admin/
│   │       ├── layout/
│   │       │   ├── app.blade.php
│   │       │   ├── sidebar.blade.php
│   │       │   └── topbar.blade.php
│   │       └── pages/
│   │           └── dashboard.blade.php
│   └── stubs/
│       ├── controller.stub
│       ├── model.stub
│       └── migration.stub
├── database/
│   └── migrations/
│       └── (empty, for users to publish)
├── routes/
│   └── (empty, for reference)
├── composer.json
├── README.md
├── INSTALLATION.md
└── LICENSE
```

## Troubleshooting Installation

### Issue: Package from local path not found

```bash
# Clear composer cache
composer clear-cache
composer dump-autoload

# Try again
composer remove elmekadem/architector-admin
composer install
```

### Issue: "Class not found" for ArchitectorServiceProvider

```bash
# Ensure PSR-4 autoloading is set up correctly in composer.json
composer dump-autoload

# Or force rebuild
rm -rf vendor/
composer install
```

### Issue: Views not loading

```bash
# Publish views
php artisan vendor:publish --provider="Elmekadem\ArchitectorAdmin\Providers\ArchitectorServiceProvider" --tag=architector-views

# Clear view cache
php artisan view:clear
```

## Development Workflow

### Making Changes to Commands

```bash
# Edit package file
nano ArchitectorPackage/src/Console/Commands/MakeAdmin.php

# Test in your Laravel project (with symlink)
php artisan make:admin

# Changes take effect immediately with symlink
```

### Testing Before Packagist Release

```bash
# Create test project
laravel new test-app
cd test-app

# Add package as symlinked local path
# Edit composer.json

composer install

# Test all commands
php artisan admin:setup
php artisan make:admin --help
php artisan admin:generate-entity --help

# Verify published files
ls resources/views/admin/
ls app/Http/Controllers/AdminDashboardCrudController.php
```

### Releasing New Versions

```bash
cd ArchitectorPackage

# Update version in src/Providers/ArchitectorServiceProvider.php (optional)
# Update CHANGELOG.md

git add .
git commit -m "chore: release v1.1.0

- Feature: Add new theme color
- Fix: Resolve dashboard rendering issue
- Docs: Update README with new examples"

git tag -a v1.1.0 -m "Version 1.1.0 - New features and bug fixes"
git push origin main
git push origin v1.1.0

# Packagist auto-updates (if webhook configured)
# Otherwise, go to packagist.org and update manually
```

## Key Files Explained

### `composer.json`

- Declares package metadata
- Specifies dependencies (Laravel, Sanctum)
- Configures PSR-4 autoloading
- Registers ServiceProvider

### `src/Providers/ArchitectorServiceProvider.php`

- Registers commands
- Publishes views, stubs, config
- Loads migrations and views from package

### `src/config/architector.php`

- Default configuration values
- Environment variable mapping
- User-customizable settings

### `resources/views/admin/`

- Master layout
- Dashboard page
- Reusable components
- Published to `resources/views/vendor/architector/` in user projects

## Next Steps

1. ✅ Package structure created
2. ⬜ Copy command files and update namespaces
3. ⬜ Copy views and stubs
4. ⬜ Test locally in a new Laravel project
5. ⬜ Push to GitHub
6. ⬜ Register on Packagist
7. ⬜ Create releases for version management

Once published, the package will be available to thousands of Laravel developers through Composer!
