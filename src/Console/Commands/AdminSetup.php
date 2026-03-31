<?php

namespace Elmekadem\ArchitectorAdmin\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class AdminSetup extends Command
{
    protected $signature = 'admin:setup
                            {--frontend-path=frontend : Path where React frontend will be generated}
                            {--force : Overwrite generated frontend files if they already exist}
                            {--skip-frontend : Skip frontend generation}';

    protected $description = 'Interactive Architector setup (admin account, token, API helpers, and React frontend scaffold)';

    public function handle(): int
    {
        $this->ensureApiRoutesFile();
        $this->showArchitectorBanner();

        if (! $this->input->isInteractive()) {
            return $this->runNonInteractiveSetup();
        }

        $this->info('Architector setup wizard');
        $this->line('This wizard can create or verify your admin account, issue a Sanctum token, and scaffold a React admin frontend.');
        $this->newLine();

        $mode = $this->choice(
            'Admin account mode',
            ['create or update admin', 'login existing admin'],
            1
        );

        if ($mode === 'login existing admin') {
            $email = $this->askValidEmail('Existing admin email');
            $user = User::where('email', $email)->first();

            if (! $user) {
                $this->error('No user found with this email. Run setup again and choose "create or update admin".');

                return self::FAILURE;
            }

            if (! (bool) ($user->is_admin ?? false)) {
                $this->error('This user is not an admin. Run setup again and choose "create or update admin".');

                return self::FAILURE;
            }

            $authenticated = false;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $password = (string) $this->secret('Admin password');
                if (Hash::check($password, (string) $user->password)) {
                    $authenticated = true;
                    break;
                }

                $this->error('Invalid password.');
            }

            if (! $authenticated) {
                $this->error('Too many failed attempts.');

                return self::FAILURE;
            }

            $name = (string) $user->name;
            $this->info('Admin login verified');
            $this->line('Email: '.$user->email);
        } else {
            $email = $this->askValidEmail();
            $existing = User::where('email', $email)->first();

            $defaultName = $existing?->name ?: 'Admin User';
            $name = trim((string) $this->ask('Admin name', $defaultName));
            if ($name === '') {
                $name = $defaultName;
            }

            $password = '';
            if ($existing) {
                $changePassword = $this->confirm('User exists. Change password?', false);
                if ($changePassword) {
                    $password = $this->askPasswordWithConfirmation();
                }
            } else {
                $password = $this->askPasswordWithConfirmation();
            }

            $user = $existing ?: new User();
            $user->email = $email;
            $user->name = $name;
            $user->is_admin = true;

            if ($password !== '') {
                $user->password = Hash::make($password);
            }

            $user->save();

            $this->info('Admin account saved');
            $this->line('Email: '.$user->email);
        }

        $revokeExisting = $this->confirm('Revoke existing tokens first?', true);
        if ($revokeExisting) {
            $user->tokens()->delete();
        }

        $tokenName = trim((string) $this->ask('Token name', 'admin-dashboard'));
        if ($tokenName === '') {
            $tokenName = 'admin-dashboard';
        }

        $this->animateSpinner('Issuing Sanctum token');
        $plainTextToken = $user->createToken($tokenName)->plainTextToken;
        $this->ensureAuthApiController();
        $this->ensureAuthApiRoutes();
        $this->ensureCrudBackendScaffold();

        $configPath = config_path('admin_dashboard.php');
        $currentConfig = $this->loadConfig($configPath);

        $defaultApi = $currentConfig['api_endpoint'] !== ''
            ? $currentConfig['api_endpoint']
            : 'http://localhost:8000/api/admin-dashboard';
        $defaultFrontendApi = $this->frontendApiBaseFromDashboardEndpoint($defaultApi);

        $api = $defaultApi;
        $saveConfig = $this->confirm('Save API endpoint and token to dashboard config?', true);
        if ($saveConfig) {
            $api = trim((string) $this->ask('API endpoint', $defaultApi));
            $this->writeConfig($configPath, [
                'api_endpoint' => $api,
                'api_token' => $plainTextToken,
            ]);
            $this->info('Dashboard API configuration updated.');
        } else {
            $api = trim((string) $this->ask('API endpoint for generated dashboard', $defaultApi));
        }

        $generateFrontend = ! (bool) $this->option('skip-frontend');
        if ($generateFrontend) {
            $generateFrontend = $this->confirm('Generate React frontend now?', true);
        }

        $frontendPath = trim((string) $this->option('frontend-path'));
        if ($frontendPath === '') {
            $frontendPath = 'frontend';
        }

        if ($generateFrontend) {
            $crudEnabled = $this->confirm('Enable dynamic CRUD mode in generated React app?', true);
            $themeMode = $this->choice('Theme mode', ['dark', 'light', 'both'], 0);

            $frontendPath = trim((string) $this->ask('Frontend output path', $frontendPath));
            if ($frontendPath === '') {
                $frontendPath = 'frontend';
            }

            $frontendApi = trim((string) $this->ask('Frontend API base URL', $defaultFrontendApi));
            if ($frontendApi === '') {
                $frontendApi = $defaultFrontendApi;
            }

            $this->generateReactFrontend(
                $frontendPath,
                [
                    'api_url' => $frontendApi,
                    'theme_mode' => $themeMode,
                    'crud_enabled' => $crudEnabled,
                    'admin_name' => $name,
                    'admin_email' => $user->email,
                    'token' => $plainTextToken,
                ],
                (bool) $this->option('force')
            );
        }

        $this->newLine();
        $this->info('Setup complete');
        $this->line('Admin token (copy now):');
        $this->line($plainTextToken);

        if ($generateFrontend) {
            $this->line('Frontend path: '.base_path($frontendPath));
            $this->line('Run next:');
            $this->line('  cd '.str_replace('\\', '/', $frontendPath));
            $this->line('  npm run dev');
        }

        return self::SUCCESS;
    }

    private function runNonInteractiveSetup(): int
    {
        $email = strtolower(trim((string) env('ADMIN_SETUP_EMAIL', 'admin@example.com')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = 'admin@example.com';
        }

        $name = trim((string) env('ADMIN_SETUP_NAME', 'Admin User'));
        if ($name === '') {
            $name = 'Admin User';
        }

        $password = (string) env('ADMIN_SETUP_PASSWORD', 'admin12345');
        if (mb_strlen($password) < 8) {
            $password = 'admin12345';
        }

        $user = User::where('email', $email)->first() ?: new User();
        $user->email = $email;
        $user->name = $name;
        $user->is_admin = true;
        $user->password = Hash::make($password);
        $user->save();

        $user->tokens()->delete();
        $plainTextToken = $user->createToken('admin-dashboard')->plainTextToken;
        $this->ensureAuthApiController();
        $this->ensureAuthApiRoutes();
        $this->ensureCrudBackendScaffold();

        $configPath = config_path('admin_dashboard.php');
        $apiEndpoint = 'http://localhost:8000/api/admin-dashboard';
        $frontendApiBase = $this->frontendApiBaseFromDashboardEndpoint($apiEndpoint);
        $this->writeConfig($configPath, [
            'api_endpoint' => $apiEndpoint,
            'api_token' => $plainTextToken,
        ]);

        if (! (bool) $this->option('skip-frontend')) {
            $this->generateReactFrontend(
                (string) $this->option('frontend-path'),
                [
                    'api_url' => $frontendApiBase,
                    'theme_mode' => 'dark',
                    'crud_enabled' => true,
                    'admin_name' => $name,
                    'admin_email' => $email,
                    'token' => $plainTextToken,
                ],
                (bool) $this->option('force')
            );
        }

        $this->info('Non-interactive setup completed.');
        $this->line('Admin email: '.$email);
        $this->line('Admin password: '.$password);
        $this->line('Admin token: '.$plainTextToken);

        return self::SUCCESS;
    }

    private function askValidEmail(string $label = 'Admin email'): string
    {
        do {
            $email = strtolower(trim((string) $this->ask($label)));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }

            $this->error('Please enter a valid email address.');
        } while (true);
    }

    private function askPasswordWithConfirmation(): string
    {
        do {
            $password = (string) $this->secret('Password (min 8 chars)');
            if (mb_strlen($password) < 8) {
                $this->error('Password must be at least 8 characters.');
                continue;
            }

            $confirmation = (string) $this->secret('Confirm password');
            if ($password !== $confirmation) {
                $this->error('Password confirmation does not match.');
                continue;
            }

            return $password;
        } while (true);
    }

    private function loadConfig(string $path): array
    {
        if (! File::exists($path)) {
            return ['api_endpoint' => '', 'api_token' => ''];
        }

        $loaded = include $path;

        if (! is_array($loaded)) {
            return ['api_endpoint' => '', 'api_token' => ''];
        }

        return [
            'api_endpoint' => (string) ($loaded['api_endpoint'] ?? ''),
            'api_token' => (string) ($loaded['api_token'] ?? ''),
        ];
    }

    private function writeConfig(string $path, array $values): void
    {
        $content = "<?php\n\nreturn [\n"
            ."    'api_endpoint' => ".var_export((string) ($values['api_endpoint'] ?? ''), true).",\n"
            ."    'api_token' => ".var_export((string) ($values['api_token'] ?? ''), true).",\n"
            ."];\n";

        File::put($path, $content);
    }

    private function frontendApiBaseFromDashboardEndpoint(string $dashboardApiEndpoint): string
    {
        $normalized = rtrim(trim($dashboardApiEndpoint), '/');

        if ($normalized === '') {
            return 'http://localhost:8000/api';
        }

        $withoutDashboardPrefix = preg_replace('#/admin-dashboard$#', '', $normalized);

        if (is_string($withoutDashboardPrefix) && $withoutDashboardPrefix !== '') {
            return $withoutDashboardPrefix;
        }

        return 'http://localhost:8000/api';
    }

    private function ensureApiRoutesFile(): void
    {
        $path = base_path('routes/api.php');

        if (File::exists($path)) {
            return;
        }

        $content = <<<'PHP'
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
PHP;

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content.PHP_EOL);
        $this->warn('routes/api.php was missing and has been recreated automatically.');
    }

        private function showArchitectorBanner(): void
        {
                $lines = [
                        '    _             _     _ _            _             ',
                        '   / \\   _ __ ___| |__ (_) |_ ___  ___| |_ ___  _ __ ',
                        '  / _ \\ | "__/ __| "_ \\| | __/ _ \\/ __| __/ _ \\| "__|',
                        ' / ___ \\| | | (__| | | | | ||  __/ (__| || (_) | |   ',
                        '/_/   \\_\\_|  \\___|_| |_|_|\\__\\___|\\___|\\__\\___/|_|   ',
                ];

                foreach ($lines as $line) {
                        $this->line('<fg=magenta>'.$line.'</>');
                }

                $this->line('<fg=magenta>Architector setup initialized</>');
                $this->newLine();
        }

        private function animateSpinner(string $label, int $cycles = 8, int $sleepMicroseconds = 70000): void
        {
                $frames = ['|', '/', '-', '\\'];
                $index = 0;

                for ($i = 0; $i < $cycles; $i++) {
                        $this->output->write("\r{$label} {$frames[$index]} ");
                        usleep($sleepMicroseconds);
                        $index = ($index + 1) % count($frames);
                }

                $this->output->writeln("\r{$label} done.   ");
        }

        private function ensureAuthApiController(): void
        {
                $path = app_path('Http/Controllers/Api/AuthController.php');

                if (File::exists($path) && ! (bool) $this->option('force')) {
                        return;
                }

                File::ensureDirectoryExists(dirname($path));

                $content = <<<'PHP'
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
        public function login(Request $request): JsonResponse
        {
                $data = $request->validate([
                        'email' => ['required', 'email'],
                        'password' => ['required', 'string'],
                ]);

                $user = User::query()->where('email', $data['email'])->first();

                if (! $user || ! Hash::check($data['password'], (string) $user->password)) {
                        return response()->json(['message' => 'Invalid credentials'], 422);
                }

                if (! (bool) ($user->is_admin ?? false)) {
                        return response()->json(['message' => 'This account is not admin-enabled'], 403);
                }

                $token = $user->createToken('architector-admin')->plainTextToken;

                return response()->json([
                        'token' => $token,
                        'user' => [
                                'id' => $user->id,
                                'name' => $user->name,
                                'email' => $user->email,
                                'is_admin' => (bool) ($user->is_admin ?? false),
                        ],
                ]);
        }

        public function logout(Request $request): JsonResponse
        {
                $request->user()?->currentAccessToken()?->delete();

                return response()->json(['success' => true]);
        }

        public function me(Request $request): JsonResponse
        {
                return response()->json(['user' => $request->user()]);
        }
}
PHP;

                File::put($path, $content.PHP_EOL);
        }

        private function ensureAuthApiRoutes(): void
        {
                $path = base_path('routes/api.php');
                if (! File::exists($path)) {
                        return;
                }

                $content = File::get($path);

                $useLine = 'use App\\Http\\Controllers\\Api\\AuthController;';
                if (! Str::contains($content, $useLine)) {
                        $content = preg_replace('/^<\?php\s*/', "<?php\n\n{$useLine}\n", $content, 1) ?? $content;
                }

                $authBlock = <<<'PHP'
Route::post('/auth/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);
});
PHP;

                if (! Str::contains($content, "Route::post('/auth/login'")) {
                        $content = rtrim($content)."\n\n{$authBlock}\n";
                }

                File::put($path, $content);
        }

        private function ensureCrudBackendScaffold(): void
        {
                $this->ensureCrudApiController();
                $this->ensureCrudSupportFiles();
                $this->ensureCrudApiRoutes();
        }

        private function ensureCrudApiController(): void
        {
                $path = app_path('Http/Controllers/AdminDashboardCrudController.php');

                $this->writeBackendFile($path, $this->crudControllerTemplate());
        }

        private function ensureCrudSupportFiles(): void
        {
                $basePath = app_path('Support/AdminDashboard');
                File::ensureDirectoryExists($basePath);

                $this->writeBackendFile($basePath.'/FieldResolver.php', $this->fieldResolverTemplate());
                $this->writeBackendFile($basePath.'/EntityTableResolver.php', $this->entityTableResolverTemplate());
                $this->writeBackendFile($basePath.'/CrudPayloadBuilder.php', $this->crudPayloadBuilderTemplate());
        }

        private function ensureCrudApiRoutes(): void
        {
                $path = base_path('routes/api.php');
                if (! File::exists($path)) {
                        return;
                }

                $content = File::get($path);
                if (Str::contains($content, "Route::get('/admin-dashboard/entities'")) {
                        return;
                }

                $crudBlock = <<<'PHP'
Route::middleware(['auth:sanctum', 'admin'])->group(function () {
        Route::prefix('/admin-dashboard')->group(function () {
                Route::get('/entities', [\App\Http\Controllers\AdminDashboardCrudController::class, 'entities']);
                Route::get('/{entity}/schema', [\App\Http\Controllers\AdminDashboardCrudController::class, 'schema']);
                Route::get('/{entity}/records', [\App\Http\Controllers\AdminDashboardCrudController::class, 'index']);
                Route::post('/{entity}/records', [\App\Http\Controllers\AdminDashboardCrudController::class, 'store']);
                Route::put('/{entity}/records/{id}', [\App\Http\Controllers\AdminDashboardCrudController::class, 'update']);
                Route::delete('/{entity}/records/{id}', [\App\Http\Controllers\AdminDashboardCrudController::class, 'destroy']);

                Route::post('/users/{id}/ban', [\App\Http\Controllers\AdminDashboardCrudController::class, 'banUser']);
                Route::post('/users/{id}/unban', [\App\Http\Controllers\AdminDashboardCrudController::class, 'unbanUser']);
                Route::post('/users/{id}/reset-password', [\App\Http\Controllers\AdminDashboardCrudController::class, 'resetUserPassword']);
        });
});
PHP;

                File::put($path, rtrim($content)."\n\n{$crudBlock}\n");
        }

        private function writeBackendFile(string $path, string $content): void
        {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, rtrim($content).PHP_EOL);
        }

        private function crudControllerTemplate(): string
        {
                return <<<'PHP'
<?php

namespace App\Http\Controllers;

use App\Support\AdminDashboard\CrudPayloadBuilder;
use App\Support\AdminDashboard\EntityTableResolver;
use App\Support\AdminDashboard\FieldResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class AdminDashboardCrudController extends Controller
{
    public function __construct(
        private FieldResolver $fieldResolver,
        private EntityTableResolver $tableResolver,
        private CrudPayloadBuilder $payloadBuilder,
    ) {
    }

    public function entities()
    {
        return response()->json([
            'entities' => $this->tableResolver->migrationTables(),
        ]);
    }

    public function schema(string $entity)
    {
        $table = $this->tableResolver->resolveTable($entity);

        if ($table === null) {
            return response()->json(['message' => 'Entity table not found'], 404);
        }

        $metadata = $this->tableResolver->columnMetadata($table);

        $columns = array_map(function ($column) use ($table, $metadata) {
            $relatedTable = $this->tableResolver->relatedTableFromForeignKeyInTable($table, $column);
            $isForeignKey = $relatedTable !== null;
            $columnMeta = $metadata[$column] ?? [];
            $required = array_key_exists('nullable', $columnMeta)
                ? ! (bool) $columnMeta['nullable']
                : ! in_array($column, ['created_at', 'updated_at', 'deleted_at'], true);
            $columnType = $this->tableResolver->columnType($table, $column);
            $fieldConfig = $this->fieldResolver->resolve(
                $column,
                $columnType,
                $columnMeta,
                $isForeignKey,
                $isForeignKey ? $this->tableResolver->foreignKeyOptions($relatedTable) : []
            );

            return [
                'name' => $column,
                'type' => $columnType,
                'required' => $required,
                'is_foreign_key' => $isForeignKey,
                'related_table' => $relatedTable,
                'options' => $fieldConfig['options'],
                'input_type' => $fieldConfig['input_type'],
                'placeholder' => $fieldConfig['placeholder'],
                'validation' => $this->tableResolver->validationRuleForColumn($table, $column),
                'relationship' => $isForeignKey ? $this->tableResolver->relationshipForColumn($table, $column) : null,
            ];
        }, Schema::getColumnListing($table));

        return response()->json([
            'entity' => $entity,
            'table' => $table,
            'columns' => $columns,
        ]);
    }

    public function index(string $entity)
    {
        $table = $this->tableResolver->resolveTable($entity);

        if ($table === null) {
            return response()->json(['message' => 'Entity table not found'], 404);
        }

        $query = DB::table($table);
        $columns = Schema::getColumnListing($table);

        if (in_array('id', $columns, true)) {
            $query->orderByDesc('id');
        }

        return response()->json($query->limit(100)->get());
    }

    public function store(Request $request, string $entity)
    {
        $table = $this->tableResolver->resolveTable($entity);

        if ($table === null) {
            return response()->json(['message' => 'Entity table not found'], 404);
        }

        $request->validate($this->validationRules($table));

        $payload = $this->payloadBuilder->payloadForTable($request, $table);
        if ($payload === []) {
            return response()->json([
                'message' => 'No valid columns in payload for this entity',
                'allowed' => $this->payloadBuilder->editableColumns($table),
            ], 422);
        }

        $foreignKeyError = $this->payloadBuilder->validateForeignKeys($payload);
        if ($foreignKeyError !== null) {
            return response()->json(['message' => $foreignKeyError], 422);
        }

        $id = DB::table($table)->insertGetId($payload);
        $record = DB::table($table)->where('id', $id)->first();

        return response()->json($record, 201);
    }

    public function update(Request $request, string $entity, int $id)
    {
        $table = $this->tableResolver->resolveTable($entity);

        if ($table === null) {
            return response()->json(['message' => 'Entity table not found'], 404);
        }

        if (! Schema::hasColumn($table, 'id')) {
            return response()->json(['message' => 'Entity does not support ID-based updates'], 422);
        }

        $exists = DB::table($table)->where('id', $id)->exists();
        if (! $exists) {
            return response()->json(['message' => 'Record not found'], 404);
        }

        $request->validate($this->validationRules($table, true, $id));

        $payload = $this->payloadBuilder->payloadForTable($request, $table);
        if ($payload === []) {
            return response()->json([
                'message' => 'No valid columns in payload for this entity',
                'allowed' => $this->payloadBuilder->editableColumns($table),
            ], 422);
        }

        $foreignKeyError = $this->payloadBuilder->validateForeignKeys($payload);
        if ($foreignKeyError !== null) {
            return response()->json(['message' => $foreignKeyError], 422);
        }

        DB::table($table)->where('id', $id)->update($payload);

        return response()->json(DB::table($table)->where('id', $id)->first());
    }

    public function destroy(string $entity, int $id)
    {
        $table = $this->tableResolver->resolveTable($entity);

        if ($table === null) {
            return response()->json(['message' => 'Entity table not found'], 404);
        }

        if (! Schema::hasColumn($table, 'id')) {
            return response()->json(['message' => 'Entity does not support ID-based deletes'], 422);
        }

        $deleted = DB::table($table)->where('id', $id)->delete();
        if ($deleted === 0) {
            return response()->json(['message' => 'Record not found'], 404);
        }

        return response()->json(['success' => true]);
    }

    public function banUser(int $id)
    {
        $user = DB::table('users')->where('id', $id)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        DB::table('users')->where('id', $id)->update(['banned_at' => now()]);

        return response()->json(['success' => true, 'message' => 'User banned']);
    }

    public function unbanUser(int $id)
    {
        $user = DB::table('users')->where('id', $id)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        DB::table('users')->where('id', $id)->update(['banned_at' => null]);

        return response()->json(['success' => true, 'message' => 'User unbanned']);
    }

    public function resetUserPassword(int $id)
    {
        $user = DB::table('users')->where('id', $id)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        $tempPassword = 'TempPass!' . str_pad((string) rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        DB::table('users')->where('id', $id)->update(['password' => Hash::make($tempPassword)]);

        return response()->json(['success' => true, 'message' => 'Password reset', 'temp_password' => $tempPassword]);
    }

    /** @return array<string, string> */
    private function validationRules(string $table, bool $forUpdate = false, ?int $ignoreId = null): array
    {
        $rules = [];

        foreach (Schema::getColumnListing($table) as $column) {
            $rule = $this->tableResolver->validationRuleForColumn($table, $column, $forUpdate, $ignoreId);
            if ($rule !== '') {
                $rules[$column] = $rule;
            }
        }

        return $rules;
    }
}
PHP;
        }

        private function entityTableResolverTemplate(): string
        {
                return <<<'PHP'
<?php

namespace App\Support\AdminDashboard;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EntityTableResolver
{
    /** @var string[] */
    private array $systemTables = [
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'password_reset_tokens',
        'personal_access_tokens',
        'migrations',
    ];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $migrationDefinitionCache = [];

    public function resolveTable(string $entity): ?string
    {
        $table = preg_replace('/[^a-z0-9_]+/i', '', strtolower(trim($entity)));

        if ($table === null || $table === '') {
            return null;
        }

        if (! Schema::hasTable($table)) {
            return null;
        }

        if (in_array($table, $this->systemTables, true)) {
            return null;
        }

        return $table;
    }

    public function relatedTableFromForeignKey(string $column): ?string
    {
        return $this->relatedTableFromForeignKeyInTable('', $column);
    }

    public function relatedTableFromForeignKeyInTable(string $table, string $column): ?string
    {
        if (! Str::endsWith($column, '_id')) {
            return null;
        }

        if ($table !== '') {
            $definition = $this->migrationColumnDefinitions($table)[$column] ?? null;
            if (is_array($definition) && isset($definition['related_table']) && is_string($definition['related_table'])) {
                $relatedTable = strtolower(trim($definition['related_table']));
                if ($relatedTable !== '' && Schema::hasTable($relatedTable) && Schema::hasColumn($relatedTable, 'id')) {
                    return $relatedTable;
                }
            }
        }

        $base = Str::beforeLast($column, '_id');
        $table = Str::plural($base);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return null;
        }

        return $table;
    }

    public function foreignKeyOptions(string $table): array
    {
        $labelColumn = collect(['name', 'title', 'full_name', 'code', 'email'])
            ->first(function ($candidate) use ($table) {
                return Schema::hasColumn($table, $candidate);
            });

        if (! $labelColumn) {
            $labelColumn = collect(Schema::getColumnListing($table))
                ->first(function ($candidate) {
                    $normalized = strtolower((string) $candidate);

                    return ! in_array($normalized, ['id', 'created_at', 'updated_at', 'deleted_at'], true)
                        && ! str_ends_with($normalized, '_id');
                });
        }

        if (! $labelColumn) {
            $labelColumn = 'id';
        }

        return DB::table($table)
            ->select(['id', $labelColumn])
            ->limit(200)
            ->get()
            ->map(function ($row) use ($labelColumn) {
                $rawLabel = $row->{$labelColumn} ?? null;
                $label = trim((string) ($rawLabel ?? ''));
                if ($label === '' || strtolower($label) === 'null') {
                    $label = 'Unnamed option';
                }

                return [
                    'value' => (string) $row->id,
                    'label' => $label,
                ];
            })
            ->values()
            ->all();
    }

    public function columnType(string $table, string $column): string
    {
        try {
            return (string) Schema::getColumnType($table, $column);
        } catch (\Throwable $e) {
            return 'string';
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function migrationColumnDefinition(string $table, string $column): array
    {
        return $this->migrationColumnDefinitions($table)[$column] ?? [];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function migrationColumnDefinitions(string $table): array
    {
        $table = strtolower(trim($table));
        if ($table === '') {
            return [];
        }

        if (isset($this->migrationDefinitionCache[$table])) {
            return $this->migrationDefinitionCache[$table];
        }

        $definitions = [];
        $migrationFiles = File::glob(database_path('migrations/*.php')) ?: [];

        foreach ($migrationFiles as $file) {
            $contents = File::get($file);
            $parsed = $this->parseMigrationColumnsFromContents($contents, $table);

            foreach ($parsed as $column => $definition) {
                $existing = $definitions[$column] ?? [];
                $definitions[$column] = $this->mergeDefinition($existing, $definition);
            }
        }

        $this->migrationDefinitionCache[$table] = $definitions;

        return $definitions;
    }

    /**
     * @return array<string, string>|null
     */
    public function relationshipForColumn(string $table, string $column): ?array
    {
        $related = $this->relatedTableFromForeignKeyInTable($table, $column);
        if ($related === null) {
            return null;
        }

        $modelClass = Str::studly(Str::singular($related));

        return [
            'type' => 'belongsTo',
            'method' => Str::camel(Str::beforeLast($column, '_id')),
            'foreign_key' => $column,
            'related_table' => $related,
            'related_model' => "App\\Models\\{$modelClass}",
            'eloquent' => "public function ".Str::camel(Str::beforeLast($column, '_id'))."() { return \$this->belongsTo(\\App\\Models\\{$modelClass}::class, '{$column}'); }",
        ];
    }

    public function validationRuleForColumn(string $table, string $column, bool $forUpdate = false, ?int $ignoreId = null): string
    {
        if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            return '';
        }

        if (! Schema::hasColumn($table, $column)) {
            return '';
        }

        $columnType = strtolower($this->columnType($table, $column));
        $metadata = $this->columnMetadata($table)[$column] ?? [];
        $definition = $this->migrationColumnDefinition($table, $column);
        $relatedTable = $this->relatedTableFromForeignKeyInTable($table, $column);

        $rules = [];
        if ($forUpdate) {
            $rules[] = 'sometimes';
        }

        $isNullable = (bool) ($metadata['nullable'] ?? false) || (bool) ($definition['nullable'] ?? false);
        $rules[] = $isNullable ? 'nullable' : 'required';

        if ($relatedTable !== null) {
            $rules[] = 'integer';
            $rules[] = "exists:{$relatedTable},id";
        } else {
            if (in_array($columnType, ['string', 'char', 'varchar'], true)) {
                $rules[] = 'string';
                $max = (int) ($definition['max'] ?? 255);
                $rules[] = 'max:'.($max > 0 ? $max : 255);
            } elseif (in_array($columnType, ['text', 'mediumtext', 'longtext'], true)) {
                $rules[] = 'string';
            } elseif (in_array($columnType, ['integer', 'bigint', 'smallint', 'tinyint'], true)) {
                $rules[] = 'integer';
            } elseif (in_array($columnType, ['decimal', 'float', 'double'], true)) {
                $rules[] = 'numeric';
            } elseif (in_array($columnType, ['boolean', 'bool'], true)) {
                $rules[] = 'boolean';
            } elseif (in_array($columnType, ['date'], true)) {
                $rules[] = 'date';
            } elseif (in_array($columnType, ['datetime', 'timestamp'], true)) {
                $rules[] = 'date';
            }

            if (str_contains(strtolower($column), 'email')) {
                $rules[] = 'email';
            }

            $enumOptions = $definition['enum'] ?? [];
            if (is_array($enumOptions) && count($enumOptions) > 0) {
                $rules[] = 'in:'.implode(',', array_map(fn ($value) => (string) $value, $enumOptions));
            }
        }

        $isUnique = (bool) ($definition['unique'] ?? false);
        if ($isUnique) {
            if ($forUpdate && $ignoreId !== null) {
                $rules[] = "unique:{$table},{$column},{$ignoreId},id";
            } else {
                $rules[] = "unique:{$table},{$column}";
            }
        }

        return implode('|', array_values(array_unique($rules)));
    }

    public function columnMetadata(string $table): array
    {
        try {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'mysql') {
                return [];
            }

            $database = $connection->getDatabaseName();
            $rows = DB::select(
                'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_COMMENT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                [$database, $table]
            );

            $result = [];
            foreach ($rows as $row) {
                $name = (string) ($row->COLUMN_NAME ?? '');
                if ($name === '') {
                    continue;
                }

                $result[$name] = [
                    'nullable' => strtoupper((string) ($row->IS_NULLABLE ?? 'NO')) === 'YES',
                    'comment' => (string) ($row->COLUMN_COMMENT ?? ''),
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public function migrationTables(): array
    {
        $tables = [];
        $migrationFiles = File::glob(database_path('migrations/*.php')) ?: [];

        foreach ($migrationFiles as $file) {
            $filename = strtolower((string) basename($file));

            if (preg_match('/create_(.+?)_table/', $filename, $match)) {
                $tables[] = $match[1];
                continue;
            }

            $contents = File::get($file);
            if (preg_match('/Schema::create\(\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $match)) {
                $tables[] = strtolower(trim((string) $match[1]));
            }
        }

        $tables = array_values(array_unique(array_filter($tables)));
        $tables = array_values(array_filter($tables, fn ($table) => ! in_array($table, $this->systemTables, true)));
        sort($tables);

        return $tables;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function parseMigrationColumnsFromContents(string $contents, string $table): array
    {
        $result = [];
        $tablePattern = preg_quote($table, '/');
        $blockPattern = "/Schema::(?:create|table)\\(\\s*['\"]{$tablePattern}['\"]\\s*,\\s*function\\s*\\([^)]*\\)\\s*\\{([\\s\\S]*?)\\}\\s*\\);/i";

        if (! preg_match_all($blockPattern, $contents, $blocks)) {
            return $result;
        }

        foreach ($blocks[1] as $block) {
            if (! is_string($block)) {
                continue;
            }

            if (! preg_match_all('/\\$table->([a-zA-Z_][a-zA-Z0-9_]*)\\((.*?)\\)([^;]*);/s', $block, $statements, PREG_SET_ORDER)) {
                continue;
            }

            foreach ($statements as $statement) {
                $method = strtolower((string) ($statement[1] ?? ''));
                $args = (string) ($statement[2] ?? '');
                $chain = (string) ($statement[3] ?? '');

                if ($method === '' || str_starts_with($method, 'drop')) {
                    continue;
                }

                if ($method === 'unique') {
                    if (preg_match('/[\'\"]([a-zA-Z0-9_]+)[\'\"]/', $args, $columnMatch)) {
                        $column = strtolower((string) $columnMatch[1]);
                        $result[$column] = $this->mergeDefinition($result[$column] ?? [], ['unique' => true]);
                    }
                    continue;
                }

                $column = $this->extractColumnNameFromMigrationCall($method, $args);
                if ($column === null || $column === '') {
                    continue;
                }

                $definition = [
                    'type' => $method,
                    'nullable' => str_contains(strtolower($chain), '->nullable(') || str_contains(strtolower($chain), '->nullable()'),
                    'unique' => str_contains(strtolower($chain), '->unique(') || str_contains(strtolower($chain), '->unique()'),
                    'max' => $this->extractStringLength($method, $args),
                    'enum' => $this->extractEnumOptions($method, $args),
                ];

                if (str_ends_with($column, '_id') || str_contains(strtolower($chain), '->constrained(') || str_contains(strtolower($chain), '->constrained()')) {
                    $definition['is_foreign_key'] = true;
                    $definition['related_table'] = $this->extractRelatedTableFromChain($chain)
                        ?? Str::plural(Str::beforeLast($column, '_id'));
                }

                $result[$column] = $this->mergeDefinition($result[$column] ?? [], $definition);
            }
        }

        return $result;
    }

    private function extractColumnNameFromMigrationCall(string $method, string $args): ?string
    {
        if ($method === 'foreignidfor') {
            if (preg_match('/([A-Z][A-Za-z0-9_\\\\]+)::class/', $args, $classMatch)) {
                $class = class_basename((string) $classMatch[1]);

                return strtolower(Str::snake($class)).'_id';
            }

            return null;
        }

        if (! preg_match('/[\'\"]([a-zA-Z0-9_]+)[\'\"]/', $args, $match)) {
            return null;
        }

        return strtolower((string) $match[1]);
    }

    private function extractStringLength(string $method, string $args): ?int
    {
        if (! in_array($method, ['string', 'char'], true)) {
            return null;
        }

        if (preg_match('/[\'\"][a-zA-Z0-9_]+[\'\"]\\s*,\\s*(\\d+)/', $args, $match)) {
            return (int) $match[1];
        }

        return 255;
    }

    /**
     * @return string[]
     */
    private function extractEnumOptions(string $method, string $args): array
    {
        if ($method !== 'enum') {
            return [];
        }

        if (! preg_match_all('/[\'\"]([^\'\"]+)[\'\"]/', $args, $matches)) {
            return [];
        }

        $values = array_map('strval', $matches[1] ?? []);

        return array_values(array_unique(array_slice($values, 1)));
    }

    private function extractRelatedTableFromChain(string $chain): ?string
    {
        if (preg_match('/->constrained\\(\\s*[\'\"]([a-zA-Z0-9_]+)[\'\"]\\s*\\)/i', $chain, $match)) {
            return strtolower((string) $match[1]);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     * @return array<string, mixed>
     */
    private function mergeDefinition(array $left, array $right): array
    {
        $merged = $left;

        foreach ($right as $key => $value) {
            if ($key === 'enum') {
                $existing = is_array($merged[$key] ?? null) ? $merged[$key] : [];
                $incoming = is_array($value) ? $value : [];
                $merged[$key] = array_values(array_unique(array_merge($existing, $incoming)));
                continue;
            }

            if (is_bool($value)) {
                $merged[$key] = (bool) ($merged[$key] ?? false) || $value;
                continue;
            }

            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
PHP;
        }

        private function fieldResolverTemplate(): string
        {
                return <<<'PHP'
<?php

namespace App\Support\AdminDashboard;

class FieldResolver
{
    public function resolve(
        string $column,
        string $columnType,
        array $columnMeta,
        bool $isForeignKey,
        array $foreignKeyOptions = []
    ): array {
        $hint = $this->parseInputHint((string) ($columnMeta['comment'] ?? ''));
        $inputType = $isForeignKey
            ? 'select'
            : ($hint['type'] ?? $this->inferInputType($column, $columnType));

        $options = $isForeignKey
            ? $foreignKeyOptions
            : ($hint['options'] ?? []);

        return [
            'input_type' => $inputType,
            'options' => $options,
            'placeholder' => $this->placeholderFor($column, $columnType, $inputType),
        ];
    }

    private function parseInputHint(string $comment): array
    {
        $raw = trim($comment);
        if ($raw === '') {
            return [];
        }

        $normalized = strtolower($raw);

        if (str_starts_with($normalized, 'radio:')) {
            return [
                'type' => 'radio',
                'options' => $this->normalizeSimpleOptions(substr($raw, 6)),
            ];
        }

        if (str_starts_with($normalized, 'select:')) {
            return [
                'type' => 'select',
                'options' => $this->normalizeSimpleOptions(substr($raw, 7)),
            ];
        }

        if (str_starts_with($normalized, 'checkbox:')) {
            return [
                'type' => 'checkboxes',
                'options' => $this->normalizeSimpleOptions(substr($raw, 9)),
            ];
        }

        if (str_starts_with($normalized, 'checkboxes:')) {
            return [
                'type' => 'checkboxes',
                'options' => $this->normalizeSimpleOptions(substr($raw, 11)),
            ];
        }

        if (in_array($normalized, ['date', 'datetime', 'datetime-local', 'textarea', 'text'], true)) {
            return ['type' => $normalized];
        }

        return [];
    }

    private function normalizeSimpleOptions(string $csv): array
    {
        $parts = array_values(array_filter(array_map('trim', explode(',', $csv)), function ($value) {
            return $value !== '';
        }));

        return array_map(function ($value) {
            return [
                'value' => $value,
                'label' => ucwords(str_replace('_', ' ', strtolower($value))),
            ];
        }, $parts);
    }

    private function inferInputType(string $column, string $columnType): string
    {
        $name = strtolower(trim($column));
        $type = strtolower(trim($columnType));

        if (str_contains($type, 'date') && ! str_contains($type, 'time')) {
            return 'date';
        }

        if (str_contains($type, 'time') || str_contains($type, 'timestamp') || str_contains($type, 'datetime')) {
            return 'datetime-local';
        }

        if (in_array($type, ['integer', 'bigint', 'smallint', 'decimal', 'float', 'double'], true)) {
            return 'number';
        }

        if (in_array($type, ['boolean', 'bool'], true) || str_starts_with($name, 'is_') || str_starts_with($name, 'has_')) {
            return 'checkbox';
        }

        if ($name === 'gender') {
            return 'radio';
        }

        if (str_contains($name, 'skills') || str_contains($name, 'tags')) {
            return 'checkboxes';
        }

        if (str_contains($name, 'description') || str_contains($name, 'bio') || str_contains($name, 'notes')) {
            return 'textarea';
        }

        if (str_contains($name, 'email')) {
            return 'email';
        }

        if (str_contains($name, 'phone') || str_contains($name, 'mobile')) {
            return 'tel';
        }

        if (str_contains($name, 'password')) {
            return 'password';
        }

        return 'text';
    }

    private function placeholderFor(string $column, string $columnType, string $inputType): string
    {
        $label = ucwords(str_replace('_', ' ', strtolower(trim($column))));

        if ($inputType === 'select') {
            return 'Select '.$label;
        }

        if ($inputType === 'radio' || $inputType === 'checkboxes') {
            return 'Choose '.$label;
        }

        if ($inputType === 'date') {
            return 'Pick '.$label;
        }

        if ($inputType === 'datetime-local') {
            return 'Pick '.$label.' date and time';
        }

        if ($inputType === 'textarea') {
            return 'Enter '.$label;
        }

        if ($inputType === 'number') {
            return 'Enter '.$label;
        }

        if ($inputType === 'email') {
            return 'example@domain.com';
        }

        if ($inputType === 'tel') {
            return 'Enter '.$label;
        }

        if ($inputType === 'password') {
            return 'Enter '.$label;
        }

        if (str_contains(strtolower($columnType), 'text')) {
            return 'Enter '.$label;
        }

        return 'Enter '.$label;
    }
}
PHP;
        }

        private function crudPayloadBuilderTemplate(): string
        {
                return <<<'PHP'
<?php

namespace App\Support\AdminDashboard;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class CrudPayloadBuilder
{
    public function __construct(private EntityTableResolver $tableResolver)
    {
    }

    /** @return string[] */
    public function editableColumns(string $table): array
    {
        $columns = Schema::getColumnListing($table);

        return array_values(array_filter($columns, function ($column) {
            return ! in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'], true);
        }));
    }

    public function payloadForTable(Request $request, string $table): array
    {
        $allowed = $this->editableColumns($table);
        $data = [];
        $input = $request->all();

        foreach ($allowed as $column) {
            if (array_key_exists($column, $input)) {
                $data[$column] = $input[$column];
            }
        }

        $now = now();
        if (Schema::hasColumn($table, 'created_at') && ! array_key_exists('created_at', $data)) {
            $data['created_at'] = $now;
        }
        if (Schema::hasColumn($table, 'updated_at')) {
            $data['updated_at'] = $now;
        }

        return $data;
    }

    public function validateForeignKeys(array $payload): ?string
    {
        foreach ($payload as $column => $value) {
            $relatedTable = $this->tableResolver->relatedTableFromForeignKey((string) $column);
            if ($relatedTable === null || $value === null || $value === '') {
                continue;
            }

            if (! is_numeric($value)) {
                return "Invalid foreign key value for {$column}.";
            }

            $exists = \Illuminate\Support\Facades\DB::table($relatedTable)->where('id', (int) $value)->exists();
            if (! $exists) {
                return "Foreign key {$column} references missing record in {$relatedTable}.";
            }
        }

        return null;
    }
}
PHP;
        }

        private function generateReactFrontend(string $relativePath, array $settings, bool $force): void
        {
                $relativePath = trim(str_replace('\\\\', '/', $relativePath));
                if ($relativePath === '') {
                        $relativePath = 'frontend';
                }

                $frontendPath = base_path($relativePath);
                File::ensureDirectoryExists($frontendPath);
                File::ensureDirectoryExists($frontendPath.'/src/app');
                File::ensureDirectoryExists($frontendPath.'/src/components');
                File::ensureDirectoryExists($frontendPath.'/src/features/auth');
                File::ensureDirectoryExists($frontendPath.'/src/pages');

                $this->animateSpinner('Generating React frontend');

                $files = [
                        $frontendPath.'/package.json' => $this->frontendPackageJson(),
                        $frontendPath.'/vite.config.js' => $this->frontendViteConfig(),
                        $frontendPath.'/index.html' => $this->frontendIndexHtml(),
                        $frontendPath.'/.env.example' => $this->frontendEnvExample((string) $settings['api_url']),
                        $frontendPath.'/src/main.jsx' => $this->frontendMainJsx(),
                        $frontendPath.'/src/App.jsx' => $this->frontendAppJsx(),
                        $frontendPath.'/src/api.js' => $this->frontendApiJs((string) $settings['api_url']),
                        $frontendPath.'/src/styles.css' => $this->frontendStylesCss((string) $settings['theme_mode']),
                        $frontendPath.'/src/app/store.js' => $this->frontendStoreJs(),
                        $frontendPath.'/src/features/auth/authSlice.js' => $this->frontendAuthSlice(),
                        $frontendPath.'/src/pages/LoginPage.jsx' => $this->frontendLoginPage(),
                        $frontendPath.'/src/components/EntitySidebar.jsx' => $this->frontendSidebar(),
                ];

                foreach ($files as $path => $content) {
                        $this->writeGeneratedFile($path, $content, $force);
                }

                $this->installFrontendDependencies($frontendPath);

                $this->line('Theme mode: '.$settings['theme_mode']);
                $this->line('CRUD mode: '.(((bool) $settings['crud_enabled']) ? 'enabled' : 'disabled'));
                $this->line('Frontend API: '.$settings['api_url']);
        }

            private function installFrontendDependencies(string $frontendPath): void
            {
                if (! File::exists($frontendPath.'/package.json')) {
                    return;
                }

                $npmExecutable = PHP_OS_FAMILY === 'Windows' ? 'npm.cmd' : 'npm';
                $this->line('Installing frontend dependencies (npm install)...');

                try {
                    $process = new Process([$npmExecutable, 'install'], $frontendPath);
                    $process->setTimeout(600);
                    $process->run();

                    if ($process->isSuccessful()) {
                        $this->info('Frontend dependencies installed.');
                        return;
                    }

                    $this->warn('Automatic npm install failed. Run manually in your frontend directory.');
                } catch (\Throwable $e) {
                    $this->warn('Could not run npm install automatically: '.$e->getMessage());
                }
            }

        private function writeGeneratedFile(string $path, string $content, bool $force): void
        {
                if (File::exists($path) && ! $force) {
                        $this->warn('Skipped existing file: '.$path);

                        return;
                }

                File::put($path, rtrim($content).PHP_EOL);
                $this->line('Written: '.$path);
        }

        private function frontendPackageJson(): string
        {
                return <<<'JSON'
{
    "name": "architector-frontend",
    "private": true,
    "version": "1.0.0",
    "type": "module",
    "scripts": {
        "dev": "vite",
        "build": "vite build",
        "preview": "vite preview"
    },
    "dependencies": {
        "@reduxjs/toolkit": "^2.2.7",
        "axios": "^1.7.7",
        "react": "^18.3.1",
        "react-dom": "^18.3.1",
        "react-icons": "^5.3.0",
        "react-redux": "^9.1.2",
        "react-router-dom": "^6.26.2",
        "recharts": "^2.12.7"
    },
    "devDependencies": {
        "@tailwindcss/vite": "^4.1.11",
        "@vitejs/plugin-react": "^5.0.0",
        "tailwindcss": "^4.1.11",
        "vite": "^7.0.0"
    }
}
JSON;
        }

        private function frontendViteConfig(): string
        {
                return <<<'JS'
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [react(), tailwindcss()],
    server: {
        port: 5173,
    },
});
JS;
        }

        private function frontendIndexHtml(): string
        {
                return <<<'HTML'
<!doctype html>
<html lang="en">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <title>Architector Admin</title>
    </head>
    <body>
        <div id="root"></div>
        <script type="module" src="/src/main.jsx"></script>
    </body>
</html>
HTML;
        }

        private function frontendEnvExample(string $apiUrl): string
        {
                return "VITE_API_BASE_URL={$apiUrl}";
        }

        private function frontendMainJsx(): string
        {
                return <<<'JS'
import React from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import { Provider } from 'react-redux';
import App from './App';
import { store } from './app/store';
import './styles.css';

createRoot(document.getElementById('root')).render(
    <React.StrictMode>
        <Provider store={store}>
            <BrowserRouter>
                <App />
            </BrowserRouter>
        </Provider>
    </React.StrictMode>
);
JS;
        }

        private function frontendAppJsx(): string
        {
                return <<<'JSX'
import React, { useEffect, useMemo, useState } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { useDispatch, useSelector } from 'react-redux';
import {
    FiActivity,
    FiCalendar,
    FiEdit2,
    FiFileText,
    FiGrid,
    FiMapPin,
    FiPlus,
    FiSearch,
    FiTrash2,
    FiUsers,
} from 'react-icons/fi';
import {
    Bar,
    BarChart,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';
import {
    deleteRecord,
    fetchEntities,
    fetchRecords,
    fetchSchema,
    logout,
    saveRecord,
    setApiToken,
} from './api';
import {
    clearAuth,
    selectAuth,
    setAuth,
} from './features/auth/authSlice';
import LoginPage from './pages/LoginPage';
import EntitySidebar from './components/EntitySidebar';

function StatCard({ title, value, icon: Icon }) {
    return (
        <article className="rounded-xl border border-slate-800 bg-slate-900/70 px-4 py-3">
            <span className="inline-flex h-7 w-7 items-center justify-center rounded-md bg-rose-500/15 text-rose-400">
                <Icon size={14} />
            </span>
            <p className="mt-3 text-3xl font-semibold leading-none text-slate-100">{value}</p>
            <p className="mt-1 text-xs text-slate-400">{title}</p>
        </article>
    );
}

function SmallUser({ user }) {
    const name = user?.name || 'Unknown User';
    const email = user?.email || '-';
    return (
        <div className="flex items-center gap-3">
            <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-rose-500/15 text-[11px] font-semibold text-rose-300">
                {String(name).charAt(0).toUpperCase()}
            </span>
            <div className="min-w-0">
                <p className="truncate text-sm font-semibold text-slate-100">{name}</p>
                <p className="truncate text-xs text-slate-500">{email}</p>
            </div>
        </div>
    );
}

function EntityPage({ token, currentUser, onLogout }) {
    const [dashboardTitle, setDashboardTitle] = useState(() => localStorage.getItem('architector_dashboard_title') || 'Architector Admin');
    const [entities, setEntities] = useState([]);
    const [activeView, setActiveView] = useState('dashboard');
    const [selectedEntity, setSelectedEntity] = useState('');
    const [columns, setColumns] = useState([]);
    const [rows, setRows] = useState([]);
    const [stats, setStats] = useState([]);
    const [recentUsers, setRecentUsers] = useState([]);
    const [recentReservations, setRecentReservations] = useState([]);
    const [search, setSearch] = useState('');
    const [isLoading, setIsLoading] = useState(false);
    const [isStatsLoading, setIsStatsLoading] = useState(false);
    const [isLoggingOut, setIsLoggingOut] = useState(false);
    const [editingRow, setEditingRow] = useState(null);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        setApiToken(token);
    }, [token]);

    useEffect(() => {
        document.title = dashboardTitle || 'Architector Admin';
    }, [dashboardTitle]);

    useEffect(() => {
        fetchEntities()
            .then((nextEntities) => {
                setEntities(nextEntities);
                if (nextEntities.length > 0) {
                    setSelectedEntity(nextEntities[0]);
                }
            })
            .catch(() => setEntities([]));
    }, []);

    useEffect(() => {
        if (!selectedEntity || activeView !== 'entity') {
            setColumns([]);
            setRows([]);
            return;
        }

        setIsLoading(true);
        Promise.all([fetchSchema(selectedEntity), fetchRecords(selectedEntity)])
            .then(([schema, list]) => {
                setColumns(Array.isArray(schema) ? schema : []);
                setRows(Array.isArray(list) ? list : []);
            })
            .finally(() => setIsLoading(false));
    }, [selectedEntity, activeView]);

    useEffect(() => {
        if (entities.length === 0) {
            setStats([]);
            setRecentUsers([]);
            setRecentReservations([]);
            return;
        }

        setIsStatsLoading(true);

        Promise.all([
            Promise.all(
                entities.slice(0, 8).map(async (entity) => {
                    try {
                        const list = await fetchRecords(entity);
                        return { entity, count: Array.isArray(list) ? list.length : 0 };
                    } catch {
                        return { entity, count: 0 };
                    }
                })
            ),
            (async () => {
                const usersEntity = entities.find((entity) => String(entity).toLowerCase().includes('user'));
                if (!usersEntity) return [];
                try {
                    const list = await fetchRecords(usersEntity);
                    return Array.isArray(list) ? list.slice(0, 5) : [];
                } catch {
                    return [];
                }
            })(),
            (async () => {
                const reservationsEntity = entities.find((entity) => {
                    const normalized = String(entity).toLowerCase();
                    return normalized.includes('reservation') || normalized.includes('booking');
                });
                if (!reservationsEntity) return [];
                try {
                    const list = await fetchRecords(reservationsEntity);
                    return Array.isArray(list) ? list.slice(0, 5) : [];
                } catch {
                    return [];
                }
            })(),
        ])
            .then(([nextStats, users, reservations]) => {
                setStats(nextStats);
                setRecentUsers(users);
                setRecentReservations(reservations);
            })
            .finally(() => setIsStatsLoading(false));
    }, [entities]);

    const dashboardStats = useMemo(() => {
        const iconFor = (name) => {
            const lowered = String(name).toLowerCase();
            if (lowered.includes('hotel')) return FiGrid;
            if (lowered.includes('restaurant')) return FiActivity;
            if (lowered.includes('activ')) return FiActivity;
            if (lowered.includes('city')) return FiMapPin;
            if (lowered.includes('user')) return FiUsers;
            if (lowered.includes('reservation') || lowered.includes('booking')) return FiCalendar;
            if (lowered.includes('blog')) return FiFileText;
            return FiGrid;
        };

        return stats.map((item) => ({
            title: String(item.entity).replace(/_/g, ' '),
            value: item.count,
            icon: iconFor(item.entity),
        }));
    }, [stats]);

    const filteredRows = useMemo(() => {
        const term = search.trim().toLowerCase();
        if (!term) return rows;
        return rows.filter((row) =>
            Object.values(row).some((value) => String(value ?? '').toLowerCase().includes(term))
        );
    }, [rows, search]);

    const chartBars = useMemo(() => {
        return stats.slice(0, 16).map((item) => ({
            name: String(item.entity).replace(/_/g, ' '),
            value: Number(item.count || 0),
        }));
    }, [stats]);

    const pieData = useMemo(() => {
        return stats.slice(0, 3).map((item) => ({
            name: String(item.entity),
            value: Number(item.count || 0),
        }));
    }, [stats]);

    const tableColumns = useMemo(() => {
        if (rows[0]) {
            return Object.keys(rows[0]);
        }
        return columns.map((column) => column.name);
    }, [rows, columns]);

    const editableColumns = useMemo(() => {
        return columns.filter((column) => {
            const name = String(column?.name || '');
            return !['id', 'created_at', 'updated_at'].includes(name);
        });
    }, [columns]);

    const isUsersView = String(selectedEntity).toLowerCase().includes('user');

    const refreshSelectedEntity = async () => {
        if (!selectedEntity) return;
        const nextRows = await fetchRecords(selectedEntity);
        setRows(Array.isArray(nextRows) ? nextRows : []);
    };

    const handleDelete = async (id) => {
        await deleteRecord(selectedEntity, id);
        await refreshSelectedEntity();
    };

    const handleSaveEdit = async () => {
        if (!editingRow) return;
        setSaving(true);
        try {
            await saveRecord(selectedEntity, editingRow.id, editingRow);
            setEditingRow(null);
            await refreshSelectedEntity();
        } finally {
            setSaving(false);
        }
    };

    const handleOpenCreate = () => {
        const nextRow = {};
        editableColumns.forEach((column) => {
            if (column.name !== 'id') {
                nextRow[column.name] = '';
            }
        });
        setEditingRow(nextRow);
    };

    const handleLogout = async () => {
        setIsLoggingOut(true);
        try {
            await logout();
        } catch {
            // Keep local logout reliable even if token already expired.
        } finally {
            setIsLoggingOut(false);
        }
        onLogout();
    };

    const handleRoleToggle = async (row) => {
        if (!row?.id) return;

        const roleKey = Object.keys(row).find((key) => String(key).toLowerCase() === 'role');
        const adminKey = Object.keys(row).find((key) => String(key).toLowerCase() === 'is_admin');

        const nextPayload = { ...row };
        if (roleKey) {
            const role = String(row[roleKey] || '').toLowerCase();
            nextPayload[roleKey] = role === 'admin' ? 'user' : 'admin';
        } else if (adminKey) {
            nextPayload[adminKey] = Number(row[adminKey]) === 1 ? 0 : 1;
        } else {
            return;
        }

        await saveRecord(selectedEntity, row.id, nextPayload);
        await refreshSelectedEntity();
    };

    const handleDashboardTitleEdit = () => {
        const nextTitle = window.prompt('Enter dashboard title', dashboardTitle || 'Architector Admin');
        if (nextTitle === null) return;
        const sanitized = nextTitle.trim() || 'Architector Admin';
        setDashboardTitle(sanitized);
        localStorage.setItem('architector_dashboard_title', sanitized);
    };

    const placeholderForField = (name, type) => {
        const normalizedName = String(name || '').toLowerCase();
        const normalizedType = String(type || '').toLowerCase();
        const label = normalizedName.replace(/_/g, ' ');

        if (normalizedType.includes('date')) return 'YYYY-MM-DD';
        if (normalizedType.includes('time')) return 'YYYY-MM-DD HH:MM';
        if (normalizedName.includes('email')) return 'name@example.com';
        if (normalizedName.includes('phone') || normalizedName.includes('tel') || normalizedName.includes('mobile')) return '+212600000000';
        if (normalizedName.includes('status')) return 'active / pending / archived';
        if (normalizedName.includes('name')) return `Enter ${label}`;

        return `Enter ${label}`;
    };

    return (
        <div className="min-h-screen bg-[#050d1c] p-0 text-slate-100">
            <div className="grid min-h-screen grid-cols-1 lg:grid-cols-[178px_minmax(0,1fr)]">
                <EntitySidebar
                    entities={entities}
                    selectedEntity={selectedEntity}
                    activeView={activeView}
                    onSelectDashboard={() => setActiveView('dashboard')}
                    onSelectEntity={(entity) => {
                        setSelectedEntity(entity);
                        setActiveView('entity');
                    }}
                    currentUser={{
                        name: currentUser?.name || 'Admin',
                        email: currentUser?.email || 'admin@gmail.com',
                    }}
                    onLogout={handleLogout}
                    isLoggingOut={isLoggingOut}
                    siteUrl="http://localhost:8000"
                />

                <main className="min-w-0 border-l border-slate-800">
                    <header className="flex h-[62px] items-center justify-end border-b border-slate-800 px-5">
                        <p className="text-sm text-slate-400">
                            Welcome, <span className="font-semibold text-rose-500">{currentUser?.name || 'Admin'}</span>
                        </p>
                    </header>

                    <div className="px-4 py-4 sm:px-6">
                        {activeView === 'dashboard' ? (
                            <>
                                <div className="mb-3">
                                    <h1
                                        className="cursor-text text-3xl font-semibold"
                                        onDoubleClick={handleDashboardTitleEdit}
                                        title="Double click to edit title"
                                    >
                                        {dashboardTitle}
                                    </h1>
                                    <p className="mt-1 text-xs text-slate-500">Overview of your platform data</p>
                                </div>

                                <section className="grid grid-cols-2 gap-3 xl:grid-cols-6">
                                    {(isStatsLoading ? Array.from({ length: 6 }).map((_, index) => ({ title: `- ${index}`, value: '-', icon: FiGrid })) : dashboardStats.slice(0, 8)).map((card, index) => (
                                        <StatCard key={`${card.title}-${index}`} title={card.title} value={card.value} icon={card.icon} />
                                    ))}
                                </section>

                                <section className="mt-4 grid grid-cols-1 gap-3 xl:grid-cols-[2fr_1fr]">
                                    <article className="rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                                        <p className="mb-2 text-sm font-semibold">Items per Entity</p>
                                        <div className="h-52">
                                            <ResponsiveContainer width="100%" height="100%">
                                                <BarChart data={chartBars} margin={{ top: 8, right: 12, left: -18, bottom: 0 }}>
                                                    <XAxis dataKey="name" tick={{ fill: '#64748b', fontSize: 10 }} axisLine={false} tickLine={false} />
                                                    <YAxis tick={{ fill: '#64748b', fontSize: 10 }} axisLine={false} tickLine={false} />
                                                    <Tooltip
                                                        cursor={{ fill: 'rgba(100, 116, 139, 0.1)' }}
                                                        contentStyle={{
                                                            background: '#0f172a',
                                                            border: '1px solid #1e293b',
                                                            borderRadius: '10px',
                                                            color: '#e2e8f0',
                                                        }}
                                                    />
                                                    <Bar dataKey="value" fill="#ff4b55" radius={[3, 3, 0, 0]} maxBarSize={16} />
                                                </BarChart>
                                            </ResponsiveContainer>
                                        </div>
                                    </article>

                                    <article className="rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                                        <p className="mb-2 text-sm font-semibold">Distribution</p>
                                        <div className="h-52">
                                            <ResponsiveContainer width="100%" height="100%">
                                                <PieChart>
                                                    <Pie data={pieData} dataKey="value" nameKey="name" innerRadius={45} outerRadius={75} stroke="#020617" strokeWidth={2}>
                                                        {pieData.map((_, index) => {
                                                            const palette = ['#ff4b55', '#ff7a85', '#e03b44'];
                                                            return <Cell key={`pie-${index}`} fill={palette[index % palette.length]} />;
                                                        })}
                                                    </Pie>
                                                    <Tooltip
                                                        contentStyle={{
                                                            background: '#0f172a',
                                                            border: '1px solid #1e293b',
                                                            borderRadius: '10px',
                                                            color: '#e2e8f0',
                                                        }}
                                                    />
                                                </PieChart>
                                            </ResponsiveContainer>
                                        </div>
                                        <div className="mt-1 flex items-center justify-center gap-3 text-[11px] text-slate-400">
                                            {pieData.map((item, index) => (
                                                <span key={item.name} className="inline-flex items-center gap-1">
                                                    <span className={`h-2 w-2 rounded-sm ${index === 0 ? 'bg-[#ff4b55]' : index === 1 ? 'bg-[#ff7a85]' : 'bg-[#e03b44]'}`} />
                                                    {item.name}
                                                </span>
                                            ))}
                                        </div>
                                    </article>
                                </section>

                                <section className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
                                    <article className="rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                                        <p className="mb-3 text-sm font-semibold">Recent Users</p>
                                        {recentUsers.length === 0 ? (
                                            <p className="text-sm text-slate-500">No users yet</p>
                                        ) : (
                                            <div className="space-y-3">
                                                {recentUsers.map((user, index) => (
                                                    <SmallUser key={user.id || `${user.email}-${index}`} user={user} />
                                                ))}
                                            </div>
                                        )}
                                    </article>

                                    <article className="rounded-xl border border-slate-800 bg-slate-900/70 p-4">
                                        <p className="mb-3 text-sm font-semibold">Recent Reservations</p>
                                        {recentReservations.length === 0 ? (
                                            <p className="text-sm text-slate-500">No reservations yet</p>
                                        ) : (
                                            <div className="space-y-2">
                                                {recentReservations.map((reservation, index) => (
                                                    <div key={reservation.id || index} className="rounded-lg border border-slate-800 bg-slate-950/60 px-3 py-2">
                                                        <p className="truncate text-sm text-slate-200">{reservation.name || reservation.title || `Reservation #${reservation.id}`}</p>
                                                        <p className="mt-1 text-xs text-slate-500">{reservation.created_at || reservation.date || '-'}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        )}
                                    </article>
                                </section>

                                <button className="fixed bottom-4 right-4 inline-flex h-9 w-9 items-center justify-center rounded-full bg-emerald-500 text-white shadow-lg shadow-emerald-900/50" type="button">
                                    ✣
                                </button>
                            </>
                        ) : (
                            <section className="rounded-xl border border-slate-800 bg-slate-950/20">
                                <div className="border-b border-slate-800 px-4 py-3">
                                    <h1 className="text-3xl font-semibold capitalize">{selectedEntity}</h1>
                                </div>
                                <div className="border-b border-slate-800 px-4 py-2">
                                    <div className="flex items-center justify-between gap-3">
                                        <label className="relative block w-full max-w-sm">
                                            <FiSearch className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-500" />
                                            <input
                                                value={search}
                                                onChange={(event) => setSearch(event.target.value)}
                                                placeholder="Search..."
                                                className="h-9 w-full rounded-md border border-slate-700 bg-slate-900 px-9 text-sm text-slate-200 outline-none placeholder:text-slate-500 focus:border-slate-500"
                                            />
                                        </label>
                                        <button
                                            type="button"
                                            onClick={handleOpenCreate}
                                            className="inline-flex h-9 shrink-0 items-center gap-2 rounded-md bg-rose-500 px-3 text-xs font-semibold text-white transition hover:bg-rose-400"
                                        >
                                            <FiPlus size={14} /> Add
                                        </button>
                                    </div>
                                </div>

                                <div className="overflow-x-auto">
                                    {isLoading ? (
                                        <div className="space-y-2 p-4">
                                            <div className="h-8 animate-pulse rounded bg-slate-800" />
                                            <div className="h-8 animate-pulse rounded bg-slate-800" />
                                            <div className="h-8 animate-pulse rounded bg-slate-800" />
                                        </div>
                                    ) : (
                                        <table className="w-full min-w-[920px] border-collapse text-left text-sm">
                                            <thead>
                                                <tr className="bg-slate-900/80">
                                                    {tableColumns.map((column) => (
                                                        <th key={column} className="border-b border-slate-800 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">
                                                            {column}
                                                        </th>
                                                    ))}
                                                    <th className="border-b border-slate-800 px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400">actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {filteredRows.length === 0 && (
                                                    <tr>
                                                        <td colSpan={tableColumns.length + 1} className="px-3 py-8 text-center text-sm text-slate-500">
                                                            Not found
                                                        </td>
                                                    </tr>
                                                )}
                                                {filteredRows.map((row, rowIndex) => {
                                                    const roleValue = row.role || (Number(row.is_admin) === 1 ? 'admin' : 'user');
                                                    const isAdmin = String(roleValue).toLowerCase() === 'admin';
                                                    return (
                                                        <tr key={row.id || rowIndex} className="border-b border-slate-900/80 hover:bg-slate-800/40">
                                                            {tableColumns.map((column) => (
                                                                <td key={column} className="px-3 py-3 text-slate-200">
                                                                    {column === 'role' ? (
                                                                        <span className={`inline-flex rounded-full px-2 py-0.5 text-[10px] ${isAdmin ? 'bg-rose-500/20 text-rose-300' : 'bg-slate-700/70 text-slate-300'}`}>
                                                                            {String(row[column] || '').trim() || '-'}
                                                                        </span>
                                                                    ) : (
                                                                        String(row[column] ?? '')
                                                                    )}
                                                                </td>
                                                            ))}
                                                            <td className="px-3 py-3">
                                                                <div className="flex items-center gap-3">
                                                                    {isUsersView ? (
                                                                        <button
                                                                            type="button"
                                                                            onClick={() => handleRoleToggle(row)}
                                                                            className="text-xs font-semibold text-rose-400 hover:text-rose-300"
                                                                        >
                                                                            {isAdmin ? 'Revoke' : 'Make Admin'}
                                                                        </button>
                                                                    ) : (
                                                                        <>
                                                                            <button
                                                                                type="button"
                                                                                className="text-slate-300 hover:text-slate-100"
                                                                                onClick={() => setEditingRow(row)}
                                                                                aria-label="Edit"
                                                                            >
                                                                                <FiEdit2 size={14} />
                                                                            </button>
                                                                            <button
                                                                                type="button"
                                                                                className="text-rose-400 hover:text-rose-300"
                                                                                onClick={() => handleDelete(row.id)}
                                                                                aria-label="Delete"
                                                                            >
                                                                                <FiTrash2 size={14} />
                                                                            </button>
                                                                        </>
                                                                    )}
                                                                </div>
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    )}
                                </div>

                                <button className="fixed bottom-4 right-4 inline-flex h-9 w-9 items-center justify-center rounded-full bg-emerald-500 text-white shadow-lg shadow-emerald-900/50" type="button">
                                    ✣
                                </button>
                            </section>
                        )}
                    </div>
                </main>
            </div>

            {editingRow && (
                <div className="fixed inset-0 z-20 grid place-items-center bg-slate-950/70 px-4">
                    <div className="max-h-[85vh] w-full max-w-xl overflow-y-auto rounded-xl border border-slate-800 bg-slate-900 p-5">
                        <h3 className="text-lg font-semibold">{editingRow?.id ? 'Edit record' : 'Create record'}</h3>
                        <div className="mt-3 space-y-3">
                            {(editableColumns.length > 0
                                ? editableColumns
                                : Object.keys(editingRow).map((name) => ({ name, type: 'string' }))
                            ).map((column) => (
                                column.name !== 'id' && (
                                    <label key={column.name} className="block">
                                        <span className="mb-1 block text-xs uppercase tracking-wide text-slate-500">{column.name}</span>
                                        {(column.input_type === 'select' || column.is_foreign_key) && Array.isArray(column.options) && column.options.length > 0 ? (
                                            <select
                                                value={String(editingRow[column.name] ?? '')}
                                                onChange={(event) =>
                                                    setEditingRow((current) => ({
                                                        ...current,
                                                        [column.name]: event.target.value,
                                                    }))
                                                }
                                                className="h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-slate-100 outline-none focus:border-slate-500"
                                            >
                                                <option value="">Select {column.name.replace(/_id$/i, '').replace(/_/g, ' ')}</option>
                                                {column.options.map((option) => (
                                                    <option key={`${column.name}-${option.value}`} value={String(option.value)}>
                                                        {option.label}
                                                    </option>
                                                ))}
                                            </select>
                                        ) : column.input_type === 'radio' && Array.isArray(column.options) && column.options.length > 0 ? (
                                            <div className="flex flex-wrap gap-3 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
                                                {column.options.map((option) => (
                                                    <label key={`${column.name}-${option.value}`} className="inline-flex items-center gap-2">
                                                        <input
                                                            type="radio"
                                                            name={column.name}
                                                            value={String(option.value)}
                                                            checked={String(editingRow[column.name] ?? '') === String(option.value)}
                                                            onChange={(event) =>
                                                                setEditingRow((current) => ({
                                                                    ...current,
                                                                    [column.name]: event.target.value,
                                                                }))
                                                            }
                                                        />
                                                        <span>{option.label}</span>
                                                    </label>
                                                ))}
                                            </div>
                                        ) : column.input_type === 'checkboxes' && Array.isArray(column.options) && column.options.length > 0 ? (
                                            <div className="flex flex-wrap gap-3 rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100">
                                                {column.options.map((option) => {
                                                    const currentValues = String(editingRow[column.name] ?? '')
                                                        .split(',')
                                                        .map((item) => item.trim())
                                                        .filter(Boolean);
                                                    const checked = currentValues.includes(String(option.value));

                                                    return (
                                                        <label key={`${column.name}-${option.value}`} className="inline-flex items-center gap-2">
                                                            <input
                                                                type="checkbox"
                                                                value={String(option.value)}
                                                                checked={checked}
                                                                onChange={(event) => {
                                                                    const nextValues = event.target.checked
                                                                        ? [...currentValues, String(option.value)]
                                                                        : currentValues.filter((item) => item !== String(option.value));

                                                                    setEditingRow((current) => ({
                                                                        ...current,
                                                                        [column.name]: nextValues.join(', '),
                                                                    }));
                                                                }}
                                                            />
                                                            <span>{option.label}</span>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                        ) : column.input_type === 'textarea' ? (
                                            <textarea
                                                value={editingRow[column.name] ?? ''}
                                                placeholder={column.placeholder || placeholderForField(column.name, column.type)}
                                                onChange={(event) =>
                                                    setEditingRow((current) => ({
                                                        ...current,
                                                        [column.name]: event.target.value,
                                                    }))
                                                }
                                                className="min-h-24 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none focus:border-slate-500"
                                            />
                                        ) : (
                                            <input
                                                value={editingRow[column.name] ?? ''}
                                                type={
                                                    column.input_type === 'date'
                                                        ? 'date'
                                                        : column.input_type === 'datetime-local'
                                                            ? 'datetime-local'
                                                            : (column.input_type === 'number' || String(column.type || '').includes('int'))
                                                                ? 'number'
                                                                : 'text'
                                                }
                                                placeholder={column.placeholder || placeholderForField(column.name, column.type)}
                                                onChange={(event) =>
                                                    setEditingRow((current) => ({
                                                        ...current,
                                                        [column.name]: event.target.value,
                                                    }))
                                                }
                                                className="h-10 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 text-sm text-slate-100 outline-none focus:border-slate-500"
                                            />
                                        )}
                                    </label>
                                )
                            ))}
                        </div>
                        <div className="mt-4 flex justify-end gap-2">
                            <button
                                type="button"
                                onClick={() => setEditingRow(null)}
                                className="rounded-md border border-slate-700 px-3 py-2 text-sm text-slate-300 hover:bg-slate-800"
                                disabled={saving}
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                onClick={handleSaveEdit}
                                className="rounded-md bg-rose-500 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-400"
                                disabled={saving}
                            >
                                {saving ? 'Saving...' : 'Save'}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function App() {
    const dispatch = useDispatch();
    const auth = useSelector(selectAuth);

    useEffect(() => {
        setApiToken(auth.token);
    }, [auth.token]);

    return (
        <Routes>
            <Route
                path="/login"
                element={auth.token ? <Navigate to="/" replace /> : <LoginPage onSuccess={(payload) => dispatch(setAuth(payload))} />}
            />
            <Route
                path="/"
                element={
                    auth.token ? (
                        <EntityPage
                            token={auth.token}
                            currentUser={auth.user}
                            onLogout={() => dispatch(clearAuth())}
                        />
                    ) : (
                        <Navigate to="/login" replace />
                    )
                }
            />
            <Route path="*" element={<Navigate to={auth.token ? '/' : '/login'} replace />} />
        </Routes>
    );
}
JSX;
        }

        private function frontendApiJs(string $apiUrl): string
        {
            $template = <<<'JS'
import axios from 'axios';

const api = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL || '__API_URL__',
    headers: {
        Accept: 'application/json',
    },
});

export const setApiToken = (token) => {
    if (token) {
        api.defaults.headers.common.Authorization = `Bearer ${token}`;
    } else {
        delete api.defaults.headers.common.Authorization;
    }
};

export const login = async (payload) => {
    const { data } = await api.post('/auth/login', payload);
    return data;
};

export const logout = async () => {
    const { data } = await api.post('/auth/logout');
    return data;
};

export const fetchEntities = async () => {
    const { data } = await api.get('/admin-dashboard/entities');
    return Array.isArray(data?.entities) ? data.entities : [];
};

export const fetchSchema = async (entity) => {
    const { data } = await api.get(`/admin-dashboard/${entity}/schema`);
    if (Array.isArray(data?.columns)) {
        return data.columns;
    }

    return [];
};

export const fetchRecords = async (entity) => {
    const { data } = await api.get(`/admin-dashboard/${entity}/records`);
    if (Array.isArray(data)) return data;
    if (Array.isArray(data?.data)) return data.data;
    return [];
};

export const saveRecord = async (entity, id, payload) => {
    if (id) {
        const { data } = await api.put(`/admin-dashboard/${entity}/records/${id}`, payload);
        return data;
    }

    const { data } = await api.post(`/admin-dashboard/${entity}/records`, payload);
    return data;
};

export const deleteRecord = async (entity, id) => {
    const { data } = await api.delete(`/admin-dashboard/${entity}/records/${id}`);
    return data;
};
JS;

    $resolvedApiUrl = trim($apiUrl) !== '' ? $apiUrl : 'http://localhost:8000/api';

    return str_replace('__API_URL__', $resolvedApiUrl, $template);
        }

        private function frontendStylesCss(string $themeMode): string
        {
                return <<<'CSS'
@import "tailwindcss";

/* Global scrollbar theme aligned with the dashboard palette */
* {
    scrollbar-width: thin;
    scrollbar-color: #ff4b55 #0b1630;
}

*::-webkit-scrollbar {
    width: 10px;
    height: 10px;
}

*::-webkit-scrollbar-track {
    background: #0b1630;
}

*::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #ff6b75 0%, #ff4b55 100%);
    border: 2px solid #0b1630;
    border-radius: 999px;
}

*::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(180deg, #ff7b84 0%, #ff5a64 100%);
}
CSS;
        }

        private function frontendStoreJs(): string
        {
                return <<<'JS'
import { configureStore } from '@reduxjs/toolkit';
import authReducer from '../features/auth/authSlice';

export const store = configureStore({
    reducer: {
        auth: authReducer,
    },
});
JS;
        }

        private function frontendAuthSlice(): string
        {
                return <<<'JS'
import { createSlice } from '@reduxjs/toolkit';

const initialState = {
    token: null,
    user: null,
};

const authSlice = createSlice({
    name: 'auth',
    initialState,
    reducers: {
        setAuth(state, action) {
            state.token = action.payload.token;
            state.user = action.payload.user;
        },
        clearAuth(state) {
            state.token = null;
            state.user = null;
        },
        hydrateAuth(state) {
            state.token = null;
            state.user = null;
        },
    },
});

export const { setAuth, clearAuth, hydrateAuth } = authSlice.actions;
export const selectAuth = (state) => state.auth;
export default authSlice.reducer;
JS;
        }

        private function frontendLoginPage(): string
        {
                return <<<'JSX'
import React, { useState } from 'react';
import { FiLoader } from 'react-icons/fi';
import { login } from '../api';

export default function LoginPage({ onSuccess }) {
    const [email, setEmail] = useState('admin@example.com');
    const [password, setPassword] = useState('admin12345');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    const submit = async (event) => {
        event.preventDefault();
        setError('');
        setLoading(true);

        try {
            const payload = await login({ email, password });
            onSuccess(payload);
        } catch (requestError) {
            setError(requestError?.response?.data?.message || 'Login failed');
        } finally {
            setLoading(false);
        }
    };

    return (
        <main className="grid min-h-screen place-items-center bg-[radial-gradient(1200px_500px_at_-10%_-5%,rgba(62,131,255,0.22),transparent_55%),radial-gradient(900px_380px_at_110%_-20%,rgba(225,29,72,0.2),transparent_52%),linear-gradient(180deg,#071322_0%,#050d18_65%)] p-4 text-slate-100">
            <form className="w-full max-w-md rounded-2xl border border-slate-700/60 bg-slate-900/70 p-6 shadow-2xl shadow-black/30" onSubmit={submit}>
                <h1 className="text-2xl font-semibold">Architector Admin</h1>
                <p className="mt-1 text-sm text-slate-400">Sign in with your admin account</p>
                <label className="mt-4 block">
                    <span className="mb-1 block text-xs uppercase tracking-wide text-slate-400">Email</span>
                    <input
                        className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-rose-500"
                        value={email}
                        onChange={(e) => setEmail(e.target.value)}
                        type="email"
                        required
                    />
                </label>
                <label className="mt-3 block">
                    <span className="mb-1 block text-xs uppercase tracking-wide text-slate-400">Password</span>
                    <input
                        className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-rose-500"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        type="password"
                        required
                    />
                </label>
                {error && <p className="mt-3 text-sm text-rose-300">{error}</p>}
                <button
                    className="mt-5 inline-flex w-full items-center justify-center rounded-lg bg-rose-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-50"
                    type="submit"
                    disabled={loading}
                >
                    {loading ? (
                        <span className="inline-flex items-center gap-2">
                            <FiLoader className="animate-spin" />
                            Signing in...
                        </span>
                    ) : (
                        'Login'
                    )}
                </button>
            </form>
        </main>
    );
}
JSX;
        }

        private function frontendSidebar(): string
        {
                return <<<'JSX'
import React from 'react';
import { FiArrowLeftCircle, FiGrid, FiLayers, FiLoader, FiLogOut } from 'react-icons/fi';

export default function EntitySidebar({
    entities,
    selectedEntity,
    activeView,
    onSelectEntity,
    onSelectDashboard,
    currentUser,
    onLogout,
    isLoggingOut,
    siteUrl,
}) {
    return (
        <aside className="flex min-h-screen flex-col bg-[#0a162c]">
            <div className="border-b border-slate-800 px-3 py-4">
                <div className="flex items-center gap-2">
                    <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-rose-500 text-sm font-bold text-white">O</span>
                    <p className="m-0 text-base font-semibold text-slate-100">OhMyGuide</p>
                </div>
                <p className="mt-1 pl-0.5 text-[10px] uppercase tracking-[0.14em] text-rose-500">Admin</p>
            </div>

            <div className="px-2 py-3">
                <button
                    type="button"
                    onClick={onSelectDashboard}
                    className={
                        activeView === 'dashboard'
                            ? 'inline-flex w-full items-center gap-2 rounded-md bg-rose-500 px-3 py-2 text-left text-xs font-semibold text-white'
                            : 'inline-flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-xs font-medium text-slate-300 transition hover:bg-slate-800/60'
                    }
                >
                    <FiGrid /> Dashboard
                </button>
                <div className="mt-2 grid gap-0.5">
                    {entities.map((entity) => (
                        <button
                            key={entity}
                            type="button"
                            className={
                                activeView === 'entity' && entity === selectedEntity
                                    ? 'inline-flex w-full items-center gap-2 rounded-md bg-rose-500 px-3 py-2 text-left text-xs font-semibold text-white'
                                    : 'inline-flex w-full items-center gap-2 rounded-md px-3 py-2 text-left text-xs font-medium text-slate-300 transition hover:bg-slate-800/60'
                            }
                            onClick={() => onSelectEntity(entity)}
                        >
                            <FiLayers className="text-[11px]" />
                            {entity}
                        </button>
                    ))}
                </div>
            </div>

            <div className="mt-auto border-t border-slate-800 px-3 py-3">
                <div className="mb-2 flex items-center gap-3">
                    <span className="inline-flex h-9 w-9 items-center justify-center rounded-full bg-rose-500/20 text-sm font-bold text-rose-300">
                        {String(currentUser?.name || currentUser?.email || 'A').charAt(0).toUpperCase()}
                    </span>
                    <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-100">{currentUser?.name || 'Admin'}</p>
                        <p className="truncate text-[11px] text-slate-500">{currentUser?.email || 'admin@gmail.com'}</p>
                    </div>
                </div>
                <div className="grid gap-2">
                    <button
                        type="button"
                        onClick={onLogout}
                        className="inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs font-medium text-slate-300 transition hover:bg-slate-800/60 disabled:cursor-not-allowed disabled:opacity-60"
                        disabled={isLoggingOut}
                    >
                        {isLoggingOut ? <FiLoader className="animate-spin" /> : <FiLogOut />} {isLoggingOut ? 'Logging out...' : 'Logout'}
                    </button>
                    <a
                        href={siteUrl || '/'}
                        className="inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-left text-xs font-medium text-slate-300 transition hover:bg-slate-800/60"
                    >
                        <FiArrowLeftCircle /> Back to site
                    </a>
                </div>
            </div>
        </aside>
    );
}
JSX;
        }
}
