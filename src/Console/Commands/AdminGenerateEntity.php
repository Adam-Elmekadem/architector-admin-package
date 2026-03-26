<?php

namespace Elmekadem\ArchitectorAdmin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminGenerateEntity extends Command
{
    protected $signature = 'admin:generate-entity
                            {table? : Table name (example: stagiaires)}
                            {--all : Generate files for all detected migration tables}
                            {--controllers-only : Generate/update only CRUD controllers}
                            {--include-system : Include system/sensitive tables (cache, jobs, personal_access_tokens...)}
                            {--force : Overwrite generated files}';

    protected $description = 'Generate Model, Seeder, CRUD Controller and API route per table';

    public function handle(): int
    {
        $tables = $this->migrationTables();
        if ($tables === []) {
            $this->error('No tables detected from migrations.');

            return self::FAILURE;
        }

        $selectedTables = $this->resolveSelectedTables($tables);
        if ($selectedTables === []) {
            $this->warn('No table selected.');

            return self::SUCCESS;
        }

        foreach ($selectedTables as $table) {
            $modelClass = $this->modelClass($table);
            $controllerClass = $modelClass.'Controller';
            $seederClass = $modelClass.'Seeder';

            if (! (bool) $this->option('controllers-only')) {
                $this->generateModel($table, $modelClass);
            }
            $this->generateController($table, $modelClass, $controllerClass);
            if (! (bool) $this->option('controllers-only')) {
                $this->generateSeeder($modelClass, $seederClass);
                $this->registerSeeder($seederClass);
            }
            $this->registerApiResourceRoute($table, $controllerClass);

            $this->info("Generated backend chain for table: {$table}");
        }

        $this->newLine();
        $this->info('Entity generation complete.');

        return self::SUCCESS;
    }

    private function resolveSelectedTables(array $tables): array
    {
        $tableArg = strtolower(trim((string) ($this->argument('table') ?? '')));

        if ((bool) $this->option('all')) {
            return $tables;
        }

        if ($tableArg !== '') {
            if (! in_array($tableArg, $tables, true)) {
                $this->error("Unknown table: {$tableArg}");

                return [];
            }

            return [$tableArg];
        }

        if (! $this->input->isInteractive()) {
            $this->error('Provide a table name or use --all in non-interactive mode.');

            return [];
        }

        $mode = $this->choice('Generation mode', ['single table', 'all tables'], 0);

        if ($mode === 'all tables') {
            return $tables;
        }

        $selected = $this->choice('Select table', $tables, 0);

        return [$selected];
    }

    private function migrationTables(): array
    {
        $tables = [];
        $migrationFiles = File::glob(database_path('migrations/*.php')) ?: [];

        foreach ($migrationFiles as $file) {
            $filename = strtolower((string) basename($file));

            if (preg_match('/create_(.+?)_table/', $filename, $match)) {
                $tables[] = strtolower(trim((string) $match[1]));
                continue;
            }

            $contents = File::get($file);
            if (preg_match('/Schema::create\(\s*[\'\"]([^\'\"]+)[\'\"]/', $contents, $match)) {
                $tables[] = strtolower(trim((string) $match[1]));
            }
        }

        $tables = array_values(array_unique(array_filter($tables)));
        $tables = $this->filterSystemTables($tables);
        sort($tables);

        return $tables;
    }

    private function filterSystemTables(array $tables): array
    {
        if ((bool) $this->option('include-system')) {
            return $tables;
        }

        return array_values(array_filter($tables, function ($table) {
            return ! in_array($table, $this->systemTables(), true);
        }));
    }

    private function systemTables(): array
    {
        return [
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
    }

    private function modelClass(string $table): string
    {
        return Str::studly(Str::singular($table));
    }

    private function generateModel(string $table, string $modelClass): void
    {
        $path = app_path("Models/{$modelClass}.php");
        if (File::exists($path) && ! (bool) $this->option('force')) {
            $this->warn("Skipped existing model: {$path}");

            return;
        }

        $fillable = $this->fillableColumns($table);
        $fillableCode = $fillable === []
            ? ''
            : "\n    protected \$fillable = [\n".
                implode("\n", array_map(fn ($col) => "        '{$col}',", $fillable)).
                "\n    ];\n";

        $content = "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass {$modelClass} extends Model\n{\n    use HasFactory;\n\n    protected \$table = '{$table}';\n{$fillableCode}}\n";

        File::put($path, $content);
        $this->line("Written model: {$path}");
    }

    private function generateController(string $table, string $modelClass, string $controllerClass): void
    {
        $dir = app_path('Http/Controllers/Admin');
        File::ensureDirectoryExists($dir);

        $path = $dir."/{$controllerClass}.php";
        if (File::exists($path) && ! (bool) $this->option('force')) {
            $this->warn("Skipped existing controller: {$path}");

            return;
        }

        $hasPassword = in_array('password', $this->fillableColumns($table), true);
        $storePassword = $hasPassword
            ? "\n        if (array_key_exists('password', \$data) && \$data['password'] !== '') {\n            \$data['password'] = Hash::make((string) \$data['password']);\n        }\n"
            : '';
        $updatePassword = $hasPassword
            ? "\n            if (array_key_exists('password', \$data)) {\n                if ((string) \$data['password'] === '') {\n                    unset(\$data['password']);\n                } else {\n                    \$data['password'] = Hash::make((string) \$data['password']);\n                }\n            }\n"
            : '';
        $hashImport = $hasPassword ? "use Illuminate\\Support\\Facades\\Hash;\n" : '';

        $content = "<?php\n\nnamespace App\\Http\\Controllers\\Admin;\n\nuse App\\Http\\Controllers\\Controller;\nuse App\\Models\\{$modelClass};\nuse Illuminate\\Http\\Request;\n{$hashImport}class {$controllerClass} extends Controller\n{\n    public function index()\n    {\n        \$paginator = {$modelClass}::query()->latest('id')->paginate(25);\n        \$paginator->setCollection(\n            \$paginator->getCollection()->map(function (\$item) {\n                return \$this->sanitizeRecord(\$item);\n            })\n        );\n\n        return response()->json(\$paginator);\n    }\n\n    public function store(Request \$request)\n    {\n        try {\n            \$data = \$request->only((new {$modelClass}())->getFillable());{$storePassword}\n            if (\$data === []) {\n                return response()->json(['message' => 'No fillable fields provided'], 422);\n            }\n\n            \$record = {$modelClass}::create(\$data);\n\n            return response()->json(\$this->sanitizeRecord(\$record), 201);\n        } catch (\\Throwable \$exception) {\n            return response()->json(['message' => 'Store failed: '.\$exception->getMessage()], 422);\n        }\n    }\n\n    public function show(int \$id)\n    {\n        \$record = {$modelClass}::find(\$id);\n\n        if (! \$record) {\n            return response()->json(['message' => 'Record not found'], 404);\n        }\n\n        return response()->json(\$this->sanitizeRecord(\$record));\n    }\n\n    public function update(Request \$request, int \$id)\n    {\n        \$record = {$modelClass}::find(\$id);\n\n        if (! \$record) {\n            return response()->json(['message' => 'Record not found'], 404);\n        }\n\n        try {\n            \$data = \$request->only((new {$modelClass}())->getFillable());{$updatePassword}\n            if (\$data === []) {\n                return response()->json(['message' => 'No fillable fields provided'], 422);\n            }\n\n            \$record->update(\$data);\n\n            return response()->json(\$this->sanitizeRecord(\$record->fresh()));\n        } catch (\\Throwable \$exception) {\n            return response()->json(['message' => 'Update failed: '.\$exception->getMessage()], 422);\n        }\n    }\n\n    public function destroy(int \$id)\n    {\n        \$record = {$modelClass}::find(\$id);\n\n        if (! \$record) {\n            return response()->json(['message' => 'Record not found'], 404);\n        }\n\n        try {\n            \$record->delete();\n\n            return response()->json(['success' => true]);\n        } catch (\\Throwable \$exception) {\n            return response()->json(['message' => 'Delete failed: '.\$exception->getMessage()], 422);\n        }\n    }\n\n    private function sanitizeRecord({$modelClass} \$record): array\n    {\n        \$data = \$record->toArray();\n\n        foreach (\$this->sensitiveColumns() as \$column) {\n            unset(\$data[\$column]);\n        }\n\n        foreach (array_keys(\$data) as \$column) {\n            if (\$this->isSensitiveColumn((string) \$column)) {\n                unset(\$data[\$column]);\n            }\n        }\n\n        return \$data;\n    }\n\n    private function sensitiveColumns(): array\n    {\n        return [\n            'password',\n            'remember_token',\n            'token',\n            'tokenable_id',\n            'tokenable_type',\n            'two_factor_secret',\n            'two_factor_recovery_codes',\n        ];\n    }\n\n    private function isSensitiveColumn(string \$column): bool\n    {\n        \$column = strtolower(\$column);\n\n        return str_contains(\$column, 'password')\n            || str_contains(\$column, 'secret')\n            || str_contains(\$column, 'token');\n    }\n}\n";

        File::put($path, $content);
        $this->line("Written controller: {$path}");
    }

    private function generateSeeder(string $modelClass, string $seederClass): void
    {
        $path = database_path("seeders/{$seederClass}.php");
        if (File::exists($path) && ! (bool) $this->option('force')) {
            $this->warn("Skipped existing seeder: {$path}");

            return;
        }

        $content = "<?php\n\nnamespace Database\\Seeders;\n\nuse Illuminate\\Database\\Seeder;\n\nclass {$seederClass} extends Seeder\n{\n    public function run(): void\n    {\n        // Add seed data for {$modelClass} here.\n    }\n}\n";

        File::put($path, $content);
        $this->line("Written seeder: {$path}");
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
            $this->line("Updated seeder registry: {$path}");
        }
    }

    private function registerApiResourceRoute(string $table, string $controllerClass): void
    {
        $path = base_path('routes/api.php');
        if (! File::exists($path)) {
            return;
        }

        $content = File::get($path);
        if (str_contains($content, "Admin\\{$controllerClass}::class")) {
            return;
        }

        $routeLine = "    Route::apiResource('/admin-resources/{$table}', \\App\\Http\\Controllers\\Admin\\{$controllerClass}::class)->only(['index', 'store', 'show', 'update', 'destroy']);\n";
        $block = "\nRoute::middleware(['auth:sanctum', 'admin'])->group(function () {\n{$routeLine}});\n";

        File::append($path, $block);
        $this->line("Updated API routes for {$table}");
    }

    private function fillableColumns(string $table): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        $columns = Schema::getColumnListing($table);

        return array_values(array_filter($columns, function ($column) {
            if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                return false;
            }

            if (str_contains($column, 'token') || str_contains($column, 'secret')) {
                return false;
            }

            if (str_contains($column, 'password')) {
                return false;
            }

            return ! in_array($column, ['remember_token'], true);
        }));
    }
}
