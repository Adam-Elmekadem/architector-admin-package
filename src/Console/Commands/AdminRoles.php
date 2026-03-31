<?php

namespace Elmekadem\ArchitectorAdmin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AdminRoles extends Command
{
    protected $signature = 'admin:roles
                            {--force : Overwrite generated files when possible}';

    protected $description = 'Generate roles, permissions, and middleware protection scaffold';

    public function handle(): int
    {
        $migrationPath = $this->ensureRolesMigration();
        $seederPath = $this->ensureRolesSeeder();
        $middlewarePath = $this->ensureMiddleware();

        $this->registerSeeder('AdminRolesSeeder');
        $this->registerMiddlewareAlias();

        $this->newLine();
        $this->info('Admin roles scaffold generated.');
        $this->line('Migration: '.$migrationPath);
        $this->line('Seeder: '.$seederPath);
        $this->line('Middleware: '.$middlewarePath);
        $this->newLine();
        $this->line('Next steps:');
        $this->line('  php artisan migrate');
        $this->line('  php artisan db:seed --class=AdminRolesSeeder');
        $this->line("  Protect routes with middleware('admin.role:role:admin') or middleware('admin.role:permission:create')");

        return self::SUCCESS;
    }

    private function ensureRolesMigration(): string
    {
        $existing = File::glob(database_path('migrations/*_create_admin_roles_permissions_tables.php')) ?: [];
        if ($existing !== [] && ! (bool) $this->option('force')) {
            $path = (string) $existing[0];
            $this->warn('Roles migration already exists: '.$path);

            return $path;
        }

        $filename = date('Y_m_d_His').'_create_admin_roles_permissions_tables.php';
        $path = database_path('migrations/'.$filename);

        $content = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label')->nullable();
            $table->timestamps();
        });

        Schema::create('permission_role', function (Blueprint $table) {
            $table->id();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['permission_id', 'role_id']);
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('permission_role');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
PHP;

        File::put($path, $content);
        $this->line('Written migration: '.$path);

        return $path;
    }

    private function ensureRolesSeeder(): string
    {
        $path = database_path('seeders/AdminRolesSeeder.php');

        if (File::exists($path) && ! (bool) $this->option('force')) {
            $this->warn('Roles seeder already exists: '.$path);

            return $path;
        }

        $content = <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AdminRolesSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();

        $roles = [
            ['name' => 'admin', 'label' => 'Administrator'],
            ['name' => 'editor', 'label' => 'Editor'],
            ['name' => 'user', 'label' => 'User'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                ['label' => $role['label'], 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $permissions = [
            ['name' => 'create', 'label' => 'Create records'],
            ['name' => 'edit', 'label' => 'Edit records'],
            ['name' => 'delete', 'label' => 'Delete records'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                ['label' => $permission['label'], 'updated_at' => $now, 'created_at' => $now]
            );
        }

        $roleIds = DB::table('roles')->pluck('id', 'name');
        $permissionIds = DB::table('permissions')->pluck('id', 'name');

        $rolePermissions = [
            'admin' => ['create', 'edit', 'delete'],
            'editor' => ['create', 'edit'],
            'user' => [],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $roleId = $roleIds[$roleName] ?? null;
            if (! $roleId) {
                continue;
            }

            foreach ($permissionNames as $permissionName) {
                $permissionId = $permissionIds[$permissionName] ?? null;
                if (! $permissionId) {
                    continue;
                }

                DB::table('permission_role')->updateOrInsert(
                    ['permission_id' => $permissionId, 'role_id' => $roleId],
                    ['updated_at' => $now, 'created_at' => $now]
                );
            }
        }
    }
}
PHP;

        File::put($path, $content);
        $this->line('Written seeder: '.$path);

        return $path;
    }

    private function ensureMiddleware(): string
    {
        $path = app_path('Http/Middleware/AdminRolePermissionMiddleware.php');

        if (File::exists($path) && ! (bool) $this->option('force')) {
            $this->warn('Middleware already exists: '.$path);

            return $path;
        }

        $content = <<<'PHP'
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class AdminRolePermissionMiddleware
{
    public function handle(Request $request, Closure $next, string $ability = ''): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if ($ability === '') {
            return $next($request);
        }

        $roleNames = DB::table('roles')
            ->join('role_user', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->pluck('roles.name')
            ->map(fn ($name) => strtolower((string) $name))
            ->values()
            ->all();

        $permissionNames = DB::table('permissions')
            ->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('role_user', 'permission_role.role_id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->pluck('permissions.name')
            ->map(fn ($name) => strtolower((string) $name))
            ->unique()
            ->values()
            ->all();

        $checks = array_values(array_filter(array_map('trim', explode(',', $ability)), fn ($v) => $v !== ''));

        foreach ($checks as $check) {
            $normalized = strtolower($check);

            if (str_starts_with($normalized, 'role:')) {
                $requiredRole = trim(substr($normalized, 5));
                if ($requiredRole !== '' && in_array($requiredRole, $roleNames, true)) {
                    return $next($request);
                }
                continue;
            }

            if (str_starts_with($normalized, 'permission:')) {
                $requiredPermission = trim(substr($normalized, 11));
                if ($requiredPermission !== '' && in_array($requiredPermission, $permissionNames, true)) {
                    return $next($request);
                }
                continue;
            }

            if (in_array($normalized, $roleNames, true) || in_array($normalized, $permissionNames, true)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Forbidden. Missing required role or permission.'], 403);
    }
}
PHP;

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
        $this->line('Written middleware: '.$path);

        return $path;
    }

    private function registerSeeder(string $seederClass): void
    {
        $path = database_path('seeders/DatabaseSeeder.php');
        if (! File::exists($path)) {
            return;
        }

        $content = File::get($path);
        $needle = $seederClass.'::class';
        if (str_contains($content, $needle)) {
            return;
        }

        $insert = "\n        \$this->call([\n            {$seederClass}::class,\n        ]);\n";
        $updated = preg_replace('/public function run\(\): void\s*\{/', "public function run(): void\n    {{$insert}", $content, 1);

        if (is_string($updated)) {
            File::put($path, $updated);
            $this->line('Updated seeder registry: '.$path);
        }
    }

    private function registerMiddlewareAlias(): void
    {
        $path = base_path('bootstrap/app.php');
        if (! File::exists($path)) {
            return;
        }

        $content = File::get($path);
        if (str_contains($content, "'admin.role' => \\App\\Http\\Middleware\\AdminRolePermissionMiddleware::class")) {
            return;
        }

        if (str_contains($content, 'withMiddleware(function (Middleware $middleware)')) {
            $aliasLine = "\n        \$middleware->alias(['admin.role' => \\App\\Http\\Middleware\\AdminRolePermissionMiddleware::class]);";
            $updated = preg_replace(
                '/withMiddleware\(function \(Middleware \$middleware\)(?:: void)? \{/',
                'withMiddleware(function (Middleware $middleware): void {'.$aliasLine,
                $content,
                1
            );

            if (is_string($updated)) {
                File::put($path, $updated);
                $this->line('Registered middleware alias in bootstrap/app.php');
            }

            return;
        }

        $this->warn('Could not auto-register middleware alias. Add manually in bootstrap/app.php: admin.role => App\\Http\\Middleware\\AdminRolePermissionMiddleware::class');
    }
}
