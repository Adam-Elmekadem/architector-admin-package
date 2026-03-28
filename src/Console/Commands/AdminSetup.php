<?php

namespace Elmekadem\ArchitectorAdmin\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSetup extends Command
{
    protected $signature = 'admine:setup
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

            $frontendApi = trim((string) $this->ask('Frontend API base URL', $api));
            if ($frontendApi === '') {
                $frontendApi = $api;
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
        $this->writeConfig($configPath, [
            'api_endpoint' => $apiEndpoint,
            'api_token' => $plainTextToken,
        ]);

        if (! (bool) $this->option('skip-frontend')) {
            $this->generateReactFrontend(
                (string) $this->option('frontend-path'),
                [
                    'api_url' => $apiEndpoint,
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
import { FiEdit2, FiPlus, FiTrash2 } from 'react-icons/fi';
import {
    deleteRecord,
    fetchEntities,
    fetchRecords,
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

function DashboardPage() {
    return <div className="panel">Select an entity from the sidebar.</div>;
}

function EntityPage({ token }) {
    const [entities, setEntities] = useState([]);
    const [selected, setSelected] = useState('');
    const [rows, setRows] = useState([]);
    const [isLoading, setIsLoading] = useState(false);
    const [draft, setDraft] = useState(null);

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
        if (!selected) {
            setRows([]);
            return;
        }

        setIsLoading(true);
        fetchRecords(selected)
            .then(setRows)
            .finally(() => setIsLoading(false));
    }, [selected]);

    const onSave = async () => {
        await saveRecord(selected, draft.id, draft);
        setDraft(null);
        setRows(await fetchRecords(selected));
    };

    const onDelete = async (id) => {
        await deleteRecord(selected, id);
        setRows(await fetchRecords(selected));
    };

    return (
        <div className="layout">
            <EntitySidebar entities={entities} selected={selected} onSelect={setSelected} />
            <main className="content">
                <div className="panel row-between">
                    <h2>{selected || 'No entity selected'}</h2>
                    <button className="btn-primary" onClick={() => setDraft({})} type="button">
                        <FiPlus /> New
                    </button>
                </div>
                <div className="panel table-wrap">
                    {isLoading ? (
                        <p>Loading records...</p>
                    ) : (
                        <table>
                            <thead>
                                <tr>
                                    {(rows[0] ? Object.keys(rows[0]) : []).map((key) => (
                                        <th key={key}>{key}</th>
                                    ))}
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((row) => (
                                    <tr key={row.id ?? JSON.stringify(row)}>
                                        {Object.entries(row).map(([key, value]) => (
                                            <td key={key}>{String(value ?? '')}</td>
                                        ))}
                                        <td>
                                            <div className="action-row">
                                                <button type="button" onClick={() => setDraft(row)} aria-label="Edit">
                                                    <FiEdit2 />
                                                </button>
                                                <button type="button" onClick={() => onDelete(row.id)} aria-label="Delete">
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
            </main>
            {draft && (
                <div className="modal-backdrop">
                    <div className="modal">
                        <h3>{draft.id ? 'Edit record' : 'Create record'}</h3>
                        {Object.keys(draft).map((key) => (
                            key !== 'id' && (
                                <label key={key}>
                                    <span>{key}</span>
                                    <input
                                        value={draft[key] ?? ''}
                                        onChange={(e) => setDraft((current) => ({ ...current, [key]: e.target.value }))}
                                    />
                                </label>
                            )
                        ))}
                        {Object.keys(draft).length === 0 && (
                            <p className="muted">Record has no editable fields yet. Add columns and refresh.</p>
                        )}
                        <div className="row-end">
                            <button className="btn-ghost" type="button" onClick={() => setDraft(null)}>Cancel</button>
                            <button className="btn-primary" type="button" onClick={onSave}>Save</button>
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
                element={auth.token ? <EntityPage token={auth.token} /> : <Navigate to="/login" replace />}
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

        return str_replace('__API_URL__', $apiUrl, $template);
        }

        private function frontendStylesCss(string $themeMode): string
        {
                $background = $themeMode === 'light' ? '#f8fafc' : '#0d141b';
                $text = $themeMode === 'light' ? '#0f172a' : '#e8ecf1';
                $panel = $themeMode === 'light' ? '#ffffff' : '#111a24';
        $colorScheme = $themeMode === 'both' ? 'dark light' : $themeMode;

                return <<<CSS
@import "tailwindcss";

:root {
    color-scheme: {$colorScheme};
    --bg: {$background};
    --text: {$text};
    --panel: {$panel};
    --brand: #e11d48;
    --muted: #94a3b8;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    background: radial-gradient(circle at 0% 0%, #1f2a37 0%, var(--bg) 45%);
    color: var(--text);
    font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
}

.layout {
    display: grid;
    grid-template-columns: 280px 1fr;
    min-height: 100vh;
}

.content {
    padding: 24px;
}

.panel {
    background: var(--panel);
    border: 1px solid rgba(148, 163, 184, 0.2);
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 16px;
}

.row-between {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.row-end {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 16px;
}

.btn-primary,
.btn-ghost,
button {
    border: 0;
    border-radius: 8px;
    padding: 8px 12px;
    cursor: pointer;
}

.btn-primary {
    background: var(--brand);
    color: #fff;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-ghost {
    background: transparent;
    border: 1px solid rgba(148, 163, 184, 0.3);
    color: var(--text);
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 760px;
}

th,
td {
    text-align: left;
    padding: 10px;
    border-bottom: 1px solid rgba(148, 163, 184, 0.2);
}

.action-row {
    display: flex;
    gap: 8px;
}

.modal-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.5);
    display: grid;
    place-items: center;
    padding: 20px;
}

.modal {
    width: min(560px, 100%);
    background: var(--panel);
    border-radius: 14px;
    border: 1px solid rgba(148, 163, 184, 0.25);
    padding: 20px;
}

label {
    display: block;
    margin-top: 12px;
}

label span {
    display: block;
    font-size: 0.82rem;
    margin-bottom: 6px;
    color: var(--muted);
}

input {
    width: 100%;
    border-radius: 8px;
    border: 1px solid rgba(148, 163, 184, 0.35);
    background: transparent;
    color: var(--text);
    padding: 10px;
}

.muted {
    color: var(--muted);
}

.login-shell {
    min-height: 100vh;
    display: grid;
    place-items: center;
    padding: 24px;
}

.login-card {
    width: min(420px, 100%);
}

@media (max-width: 960px) {
    .layout {
        grid-template-columns: 1fr;
    }
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
        <main className="login-shell">
            <form className="panel login-card" onSubmit={submit}>
                <h1>Architector Admin</h1>
                <p className="muted">Sign in with your admin account</p>
                <label>
                    <span>Email</span>
                    <input value={email} onChange={(e) => setEmail(e.target.value)} type="email" required />
                </label>
                <label>
                    <span>Password</span>
                    <input value={password} onChange={(e) => setPassword(e.target.value)} type="password" required />
                </label>
                {error && <p className="muted">{error}</p>}
                <button className="btn-primary" type="submit" disabled={loading}>
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

export default function EntitySidebar({ entities, selected, onSelect }) {
    return (
        <aside className="panel" style={{ margin: 16 }}>
            <h3 style={{ marginTop: 0 }}>Entities</h3>
            <div style={{ display: 'grid', gap: 8 }}>
                {entities.map((entity) => (
                    <button
                        key={entity}
                        type="button"
                        className={entity === selected ? 'btn-primary' : 'btn-ghost'}
                        onClick={() => onSelect(entity)}
                    >
                        {entity}
                    </button>
                ))}
            </div>
        </aside>
    );
}
JSX;
        }
}
