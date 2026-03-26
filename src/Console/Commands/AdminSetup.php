<?php

namespace Elmekadem\ArchitectorAdmin\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class AdminSetup extends Command
{
    protected $signature = 'admin:setup';

    protected $description = 'Interactive admin setup (login/create admin, issue token, generate dashboard, and scaffold backend files)';

    public function handle(): int
    {
        $this->ensureApiRoutesFile();

        if (! $this->input->isInteractive()) {
            return $this->runNonInteractiveSetup();
        }

        $this->info('Admin setup wizard');
        $this->line('This will login or create an admin account, issue a token, and optionally save dashboard API settings.');
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
            $this->info('Admin login verified.');
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

            $this->info('Admin account saved.');
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

        $plainTextToken = $user->createToken($tokenName)->plainTextToken;

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
            Artisan::call('config:clear');
            $this->info('Dashboard API configuration updated.');
        } else {
            $api = trim((string) $this->ask('API endpoint for generated dashboard', $defaultApi));
        }

        $generateDashboard = $this->confirm('Generate/update admin dashboard now?', true);
        if ($generateDashboard) {
            $title = trim((string) $this->ask('Dashboard title', 'CoachPro'));
            $welcome = trim((string) $this->ask('Welcome message', 'Welcome back, '.$name));
            $route = trim((string) $this->ask('Dashboard route', '/admin/dashboard'));
            $color = $this->choice('Theme color', ['basic', 'cyan', 'emerald', 'rose'], 1);
            $design = $this->choice('Base design', ['cards', 'tables', 'gridlayouts'], 2);
            $enableCrud = $this->confirm('Enable CRUD mode (inline add/edit/delete operations)?', $api === '');

            Artisan::call('make:admin', [
                '--title' => $title !== '' ? $title : 'CoachPro',
                '--welcome' => $welcome !== '' ? $welcome : 'Welcome back, '.$name,
                '--user' => $name,
                '--color' => $color,
                '--design' => $design,
                '--api' => $api,
                '--token' => $plainTextToken,
                '--crud' => $enableCrud ? '1' : '0',
                '--install-icons' => '1',
                '--route' => $route !== '' ? $route : '/admin/dashboard',
                '--force' => true,
                '--no-interaction' => true,
            ]);

            $this->info('Admin dashboard generated successfully via make:admin.');
            $this->line(Artisan::output());
        }

        $generateEntityChain = $this->confirm('Generate backend CRUD files per migration table (models/controllers/seeders/routes)?', true);
        if ($generateEntityChain) {
            Artisan::call('admin:generate-entity', [
                '--all' => true,
            ]);

            $this->info('Backend chain generated successfully.');
            $this->line(Artisan::output());
        }

        $this->newLine();
        $this->info('Setup complete. Admin token (copy now):');
        $this->line($plainTextToken);

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

        $configPath = config_path('admin_dashboard.php');
        $apiEndpoint = 'http://localhost:8000/api/admin-dashboard';
        $this->writeConfig($configPath, [
            'api_endpoint' => $apiEndpoint,
            'api_token' => $plainTextToken,
        ]);

        Artisan::call('config:clear');

        Artisan::call('make:admin', [
            '--title' => 'CoachPro',
            '--welcome' => 'Welcome back, '.$name,
            '--user' => $name,
            '--color' => 'cyan',
            '--design' => 'gridlayouts',
            '--api' => $apiEndpoint,
            '--token' => $plainTextToken,
            '--crud' => '0',
            '--install-icons' => '1',
            '--route' => '/admin/dashboard',
            '--force' => true,
            '--no-interaction' => true,
        ]);

        Artisan::call('admin:generate-entity', [
            '--all' => true,
        ]);

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
}
