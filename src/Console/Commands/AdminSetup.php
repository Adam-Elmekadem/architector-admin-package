<?php

namespace Elmekadem\ArchitectorAdmin\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
            $this->line('  npm install');
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

                $this->line('Theme mode: '.$settings['theme_mode']);
                $this->line('CRUD mode: '.(((bool) $settings['crud_enabled']) ? 'enabled' : 'disabled'));
                $this->line('Frontend API: '.$settings['api_url']);
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
        "react-router-dom": "^6.26.2"
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
import React, { useEffect, useState } from 'react';
import { Navigate, Route, Routes } from 'react-router-dom';
import { useDispatch, useSelector } from 'react-redux';
import {
    FiActivity,
    FiClock,
    FiEdit2,
    FiGrid,
    FiLogOut,
    FiMapPin,
    FiPlus,
    FiSearch,
    FiTrash2,
    FiUsers,
} from 'react-icons/fi';
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
    hydrateAuth,
    selectAuth,
    setAuth,
} from './features/auth/authSlice';
import LoginPage from './pages/LoginPage';
import EntitySidebar from './components/EntitySidebar';

function EntityPage({ token, onLogout }) {
    const [entities, setEntities] = useState([]);
    const [stats, setStats] = useState([]);
    const [activeView, setActiveView] = useState('dashboard');
    const [selected, setSelected] = useState('');
    const [columns, setColumns] = useState([]);
    const [rows, setRows] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [isStatsLoading, setIsStatsLoading] = useState(false);
    const [draft, setDraft] = useState(null);
    const [saving, setSaving] = useState(false);
    const [query, setQuery] = useState('');

    const fieldColumns = columns.filter((column) => {
        const name = String(column?.name || '');
        return !['id', 'created_at', 'updated_at'].includes(name);
    });

    const filteredRows = rows.filter((row) => {
        const term = query.trim().toLowerCase();
        if (!term) {
            return true;
        }

        return Object.values(row).some((value) => String(value ?? '').toLowerCase().includes(term));
    });

    useEffect(() => {
        setApiToken(token);
    }, [token]);

    useEffect(() => {
        fetchEntities()
            .then((data) => {
                setEntities(data);
                if (data.length > 0) {
                    setSelected((current) => current || data[0]);
                }
            })
            .catch(() => {
                setEntities([]);
            });
    }, []);

    useEffect(() => {
        if (entities.length === 0) {
            setStats([]);
            return;
        }

        setIsStatsLoading(true);
        Promise.all(
            entities.slice(0, 6).map(async (entity) => {
                try {
                    const list = await fetchRecords(entity);
                    return {
                        entity,
                        count: Array.isArray(list) ? list.length : 0,
                    };
                } catch {
                    return {
                        entity,
                        count: 0,
                    };
                }
            })
        )
            .then(setStats)
            .finally(() => setIsStatsLoading(false));
    }, [entities]);

    useEffect(() => {
        if (!selected || activeView !== 'entity') {
            setColumns([]);
            setRows([]);
            return;
        }

        setIsLoading(true);
        Promise.all([fetchSchema(selected), fetchRecords(selected)])
            .then(([schema, list]) => {
                setColumns(Array.isArray(schema) ? schema : []);
                setRows(Array.isArray(list) ? list : []);
            })
            .finally(() => setIsLoading(false));
    }, [selected, activeView]);

    const onSave = async () => {
        if (!draft || !selected) {
            return;
        }

        setSaving(true);
        try {
            await saveRecord(selected, draft.id, draft);
            setDraft(null);
            setRows(await fetchRecords(selected));
        } finally {
            setSaving(false);
        }
    };

    const onDelete = async (id) => {
        await deleteRecord(selected, id);
        setRows(await fetchRecords(selected));
    };

    const openCreateModal = () => {
        const nextDraft = {};
        fieldColumns.forEach((column) => {
            nextDraft[column.name] = '';
        });
        setDraft(nextDraft);
    };

    const handleLogout = async () => {
        try {
            await logout();
        } catch {
            // Local logout still proceeds if token is expired.
        }

        onLogout();
    };

    const selectDashboard = () => {
        setActiveView('dashboard');
    };

    const selectEntity = (entity) => {
        setSelected(entity);
        setActiveView('entity');
    };

    const iconByEntity = (name) => {
        const normalized = String(name || '').toLowerCase();
        if (normalized.includes('user')) return FiUsers;
        if (normalized.includes('city')) return FiMapPin;
        if (normalized.includes('activ')) return FiActivity;
        return FiGrid;
    };

    return (
        <div className="min-h-screen bg-[radial-gradient(1200px_500px_at_-10%_-5%,rgba(62,131,255,0.22),transparent_55%),radial-gradient(900px_380px_at_110%_-20%,rgba(225,29,72,0.2),transparent_52%),linear-gradient(180deg,#071322_0%,#050d18_65%)] p-3 text-slate-100 md:p-4">
            <div className="grid min-h-[calc(100vh-24px)] grid-cols-1 gap-4 lg:grid-cols-[260px_minmax(0,1fr)]">
                <EntitySidebar
                    entities={entities}
                    selectedEntity={selected}
                    activeView={activeView}
                    onSelectEntity={selectEntity}
                    onSelectDashboard={selectDashboard}
                />
                <main className="min-w-0">
                    <header className="mb-4 flex flex-col gap-3 rounded-2xl border border-slate-700/60 bg-slate-900/60 p-4 shadow-xl shadow-black/20 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p className="m-0 text-[11px] uppercase tracking-[0.14em] text-slate-400">Welcome, Admin</p>
                            <h2 className="mt-1 text-2xl font-semibold capitalize">
                                {activeView === 'dashboard' ? 'Dashboard' : selected || 'No entity selected'}
                            </h2>
                        </div>
                        <div className="flex items-center gap-2 self-end md:self-auto">
                            <button
                                className="inline-flex items-center gap-2 rounded-lg border border-slate-600 px-3 py-2 text-sm font-medium text-slate-200 transition hover:bg-slate-800"
                                type="button"
                                onClick={handleLogout}
                            >
                                <FiLogOut /> Logout
                            </button>
                            <button
                                className="inline-flex items-center gap-2 rounded-lg bg-rose-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-50"
                                onClick={openCreateModal}
                                type="button"
                                disabled={!selected || activeView !== 'entity'}
                            >
                                <FiPlus /> New
                            </button>
                        </div>
                    </header>

                    {activeView === 'dashboard' ? (
                        <>
                            <section className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-6">
                                {(isStatsLoading ? Array.from({ length: 6 }).map((_, idx) => ({ entity: `item-${idx}`, count: 0 })) : stats).map((item) => {
                                    const Icon = iconByEntity(item.entity);
                                    return (
                                        <article key={item.entity} className="rounded-xl border border-slate-700/60 bg-slate-900/60 p-4 shadow-lg shadow-black/20">
                                            <div className="mb-3 inline-flex rounded-lg bg-rose-500/15 p-2 text-rose-400">
                                                <Icon />
                                            </div>
                                            <p className="text-2xl font-semibold">{isStatsLoading ? '-' : item.count}</p>
                                            <p className="mt-1 text-xs capitalize text-slate-400">{item.entity}</p>
                                        </article>
                                    );
                                })}
                            </section>

                            <section className="grid grid-cols-1 gap-4 xl:grid-cols-[2fr_1fr]">
                                <article className="rounded-2xl border border-slate-700/60 bg-slate-900/60 p-4 shadow-xl shadow-black/20">
                                    <h3 className="mb-4 text-sm font-semibold">Items per Entity</h3>
                                    <div className="flex h-52 items-end gap-2">
                                        {stats.slice(0, 12).map((item, idx) => (
                                            <div key={item.entity} className="flex flex-1 flex-col items-center gap-2">
                                                <div
                                                    className="w-full rounded-t bg-rose-500/90"
                                                    style={{ height: `${Math.max(18, Math.min(170, item.count * 10 + 18))}px` }}
                                                />
                                                <span className="w-full truncate text-center text-[10px] text-slate-400">
                                                    {idx + 1}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                </article>
                                <article className="rounded-2xl border border-slate-700/60 bg-slate-900/60 p-4 shadow-xl shadow-black/20">
                                    <h3 className="mb-4 text-sm font-semibold">Distribution</h3>
                                    <div className="mx-auto mt-3 h-44 w-44 rounded-full border-[16px] border-rose-400/90 border-r-rose-600 border-b-rose-500" />
                                    <p className="mt-4 text-center text-xs text-slate-400">Live entity split preview</p>
                                </article>
                            </section>
                        </>
                    ) : (
                        <section className="rounded-2xl border border-slate-700/60 bg-slate-900/60 p-4 shadow-xl shadow-black/20">
                            <div className="mb-3 flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                <h3 className="text-xl font-semibold capitalize">{selected}</h3>
                                <label className="relative block w-full md:w-72">
                                    <FiSearch className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" />
                                    <input
                                        value={query}
                                        onChange={(event) => setQuery(event.target.value)}
                                        placeholder="Search..."
                                        className="w-full rounded-lg border border-slate-700 bg-slate-950 pl-10 pr-3 py-2 text-sm text-slate-100 outline-none transition focus:border-rose-500"
                                    />
                                </label>
                            </div>

                            <div className="overflow-x-auto">
                                {!selected ? (
                                    <p className="text-slate-400">Choose an entity from the sidebar to start.</p>
                                ) : isLoading ? (
                                    <div className="space-y-2">
                                        <div className="h-8 animate-pulse rounded bg-slate-800" />
                                        <div className="h-8 animate-pulse rounded bg-slate-800" />
                                        <div className="h-8 animate-pulse rounded bg-slate-800" />
                                        <div className="h-8 animate-pulse rounded bg-slate-800" />
                                    </div>
                                ) : (
                                    <table className="w-full min-w-[860px] border-collapse text-left text-sm">
                                        <thead>
                                            <tr>
                                                {(rows[0] ? Object.keys(rows[0]) : fieldColumns.map((column) => column.name)).map((key) => (
                                                    <th key={key} className="border-b border-slate-700 px-2 py-3 font-semibold text-slate-100">{key}</th>
                                                ))}
                                                <th className="border-b border-slate-700 px-2 py-3 font-semibold text-slate-100">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {filteredRows.map((row) => (
                                                <tr key={row.id ?? JSON.stringify(row)} className="transition hover:bg-slate-800/50">
                                                    {Object.entries(row).map(([key, value]) => (
                                                        <td key={key} className="border-b border-slate-800 px-2 py-3 text-slate-200">{String(value ?? '')}</td>
                                                    ))}
                                                    <td className="border-b border-slate-800 px-2 py-3">
                                                        <div className="flex gap-2">
                                                            <button
                                                                type="button"
                                                                className="rounded-md border border-slate-700 p-2 text-slate-200 transition hover:bg-slate-800"
                                                                onClick={() => setDraft(row)}
                                                                aria-label="Edit"
                                                            >
                                                                <FiEdit2 />
                                                            </button>
                                                            <button
                                                                type="button"
                                                                className="rounded-md border border-slate-700 p-2 text-slate-200 transition hover:bg-slate-800"
                                                                onClick={() => onDelete(row.id)}
                                                                aria-label="Delete"
                                                            >
                                                                <FiTrash2 />
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </div>
                        </section>
                    )}
                </main>
            </div>
            {draft && (
                <div className="fixed inset-0 z-20 grid place-items-center bg-slate-950/70 p-4 backdrop-blur-[1px]">
                    <div className="w-full max-w-xl rounded-2xl border border-slate-700 bg-slate-900 p-5 shadow-2xl shadow-black/30">
                        <h3 className="mb-2 text-xl font-semibold">{draft.id ? 'Edit record' : 'Create record'}</h3>
                        {(fieldColumns.length > 0 ? fieldColumns : Object.keys(draft).map((name) => ({ name, type: 'string' }))).map((column) => (
                            column.name !== 'id' && (
                                <label key={column.name} className="mt-3 block">
                                    <span className="mb-1 block text-xs uppercase tracking-wide text-slate-400">{column.name}</span>
                                    <input
                                        type={String(column.type || '').includes('int') ? 'number' : 'text'}
                                        placeholder={`Enter ${column.name}`}
                                        value={draft[column.name] ?? ''}
                                        className="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-100 outline-none transition focus:border-rose-500"
                                        onChange={(e) => setDraft((current) => ({ ...current, [column.name]: e.target.value }))}
                                    />
                                </label>
                            )
                        ))}
                        {fieldColumns.length === 0 && (
                            <p className="text-slate-400">Record has no editable fields yet. Add columns and refresh.</p>
                        )}
                        <div className="mt-5 flex justify-end gap-2">
                            <button
                                className="rounded-lg border border-slate-600 px-3 py-2 text-sm font-medium text-slate-200 transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-50"
                                type="button"
                                onClick={() => setDraft(null)}
                                disabled={saving}
                            >
                                Cancel
                            </button>
                            <button
                                className="rounded-lg bg-rose-600 px-3 py-2 text-sm font-medium text-white transition hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-50"
                                type="button"
                                onClick={onSave}
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
        dispatch(hydrateAuth());
    }, [dispatch]);

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
                element={auth.token ? <EntityPage token={auth.token} onLogout={() => dispatch(clearAuth())} /> : <Navigate to="/login" replace />}
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

const STORAGE_KEY = 'architector_auth';

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
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ token: state.token, user: state.user }));
        },
        clearAuth(state) {
            state.token = null;
            state.user = null;
            localStorage.removeItem(STORAGE_KEY);
        },
        hydrateAuth(state) {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return;
            try {
                const parsed = JSON.parse(raw);
                state.token = parsed?.token ?? null;
                state.user = parsed?.user ?? null;
            } catch {
                state.token = null;
                state.user = null;
            }
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
                    {loading ? 'Signing in...' : 'Login'}
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
import { FiGrid, FiLayers } from 'react-icons/fi';

export default function EntitySidebar({ entities, selectedEntity, activeView, onSelectEntity, onSelectDashboard }) {
    return (
        <aside className="flex min-h-[calc(100vh-24px)] flex-col rounded-2xl border border-slate-700/60 bg-slate-900/60 p-4 shadow-xl shadow-black/20 lg:min-h-[calc(100vh-32px)]">
            <div className="mb-4 border-b border-slate-700 pb-3">
                <div className="flex items-center gap-2">
                    <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-rose-500 text-sm font-bold text-white">O</span>
                    <p className="m-0 text-lg font-semibold text-slate-100">OhMyGuide</p>
                </div>
                <p className="mt-1 text-[11px] uppercase tracking-[0.12em] text-rose-400">Admin</p>
            </div>

            <div className="mb-2 grid gap-2">
                <button
                    type="button"
                    onClick={onSelectDashboard}
                    className={
                        activeView === 'dashboard'
                            ? 'inline-flex items-center gap-2 rounded-lg bg-rose-500 px-3 py-2 text-left text-sm font-semibold text-white'
                            : 'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-left text-sm font-medium text-slate-300 transition hover:bg-slate-800'
                    }
                >
                    <FiGrid /> Dashboard
                </button>
            </div>

            <p className="mb-2 mt-1 text-xs uppercase tracking-[0.1em] text-slate-500">Entities</p>
            <div className="grid gap-2">
                {entities.map((entity) => (
                    <button
                        key={entity}
                        type="button"
                        className={
                            activeView === 'entity' && entity === selectedEntity
                                ? 'inline-flex items-center gap-2 rounded-lg bg-rose-500 px-3 py-2 text-left text-sm font-semibold text-white'
                                : 'inline-flex items-center gap-2 rounded-lg border border-slate-800 bg-slate-950/40 px-3 py-2 text-left text-sm font-semibold text-slate-200 transition hover:bg-slate-800'
                        }
                        onClick={() => onSelectEntity(entity)}
                    >
                        <FiLayers className="text-xs" />
                        {entity}
                    </button>
                ))}
            </div>

            <div className="mt-auto border-t border-slate-700 pt-3 text-xs text-slate-400">
                Dynamic CRUD from migration tables
            </div>
        </aside>
    );
}
JSX;
        }
}
