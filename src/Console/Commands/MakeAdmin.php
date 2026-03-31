<?php

namespace Elmekadem\ArchitectorAdmin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class MakeAdmin extends Command
{
    private const HEROICONS_PROVIDER = 'BladeUI\\Heroicons\\HeroiconsServiceProvider';

    protected $signature = 'make:admin
                            {--title=CoachPro : Brand title in the sidebar}
                            {--welcome=Welcome back, Admin : Welcome message in the topbar}
                            {--user=Admin User : User name shown in profile chip}
                            {--color=basic : Theme color (basic, cyan, emerald, rose)}
                            {--design=gridlayouts : Base design (cards, tables, gridlayouts)}
                            {--api= : External API URL for dynamic dashboard data}
                            {--token= : Bearer token for API authentication}
                            {--crud=0 : Enable basic local CRUD mode when API is disabled (1/0)}
                            {--install-icons=1 : Install Blade Heroicons library automatically (1/0)}
                            {--route=/admin/dashboard : Dashboard URL}
                            {--force : Overwrite existing generated view files}';

    protected $description = 'Generate a customizable Tailwind admin dashboard UI';

    public function handle()
    {
        $defaults = [
            'title' => $this->cleanText((string) $this->option('title')),
            'user' => $this->cleanText((string) $this->option('user')),
            'welcome' => $this->cleanText((string) $this->option('welcome')),
            'route' => $this->normalizeRoute((string) $this->option('route')),
            'color' => $this->normalizeColor((string) $this->option('color')),
            'design' => $this->normalizeDesign((string) $this->option('design')),
            'api' => $this->normalizeApiUrl((string) $this->option('api')),
            'token' => trim((string) $this->option('token')),
            'crud' => $this->toBoolOption((string) $this->option('crud')),
        ];

        $settings = $this->resolveSettings($defaults);
        $installIcons = $this->toBoolOption((string) $this->option('install-icons'));
        $useIconLibrary = $installIcons
            ? $this->ensureHeroiconsInstalled()
            : class_exists(self::HEROICONS_PROVIDER);

        $migrationTables = $this->migrationTables();
        $settings['entities'] = $migrationTables;
        $settings['sidebar_items'] = $this->sidebarLabelsFromTables($migrationTables);
        $settings['entity_slugs'] = $migrationTables;
        $theme = $this->getTheme($settings['color']);

        $this->info('Generating Tailwind admin dashboard...');

        $this->createDirectories();
        $this->createLayoutFiles(
            $settings['title'],
            $settings['welcome'],
            $settings['user'],
            $settings['route'],
            $theme,
            $settings['sidebar_items'],
            $settings['entity_slugs'],
            $useIconLibrary
        );
        $this->createPages($theme, $settings);
        $this->createCrudBackend($settings);
        $this->addRoute($settings['route']);

        $this->newLine();
        $this->info('Admin dashboard created successfully.');
        $this->line('Theme: '.$settings['color']);
        $this->line('Base design: '.$settings['design']);
        $this->line('API mode: '.($settings['api'] !== '' ? 'enabled' : 'disabled'));
        $this->line('CRUD mode: '.($settings['crud'] ? 'enabled' : 'disabled'));
        $this->line('Detected entities: '.count($migrationTables));
        $this->line('Open: '.url($settings['route']));

        return self::SUCCESS;
    }

    private function createDirectories(): void
    {
        $paths = [
            resource_path('views/admin/layout'),
            resource_path('views/admin/pages'),
            resource_path('views/admin/components'),
        ];

        foreach ($paths as $path) {
            File::ensureDirectoryExists($path);
        }
    }

    private function createPages(array $theme, array $settings): void
    {
        $this->writeFile(
            resource_path('views/admin/pages/dashboard.blade.php'),
            $this->getDashboard($theme, $settings)
        );
    }

    private function createCrudBackend(array $settings): void
    {
        if (! ($settings['crud'] ?? false)) {
            return;
        }

        $this->writeFile(
            app_path('Http/Controllers/AdminDashboardCrudController.php'),
            $this->getCrudController()
        );

        $this->addCrudApiRoutes();
    }

    private function createLayoutFiles(string $title, string $welcome, string $user, string $routePath, array $theme, array $sidebarItems, array $entitySlugs, bool $useIconLibrary): void
    {
        $this->writeFile(
            resource_path('views/admin/layout/app.blade.php'),
            $this->getAppLayout($theme)
        );

        $this->writeFile(
            resource_path('views/admin/layout/sidebar.blade.php'),
            $this->getSidebar($title, $routePath, $theme, $sidebarItems, $entitySlugs, $useIconLibrary)
        );

        $this->writeFile(
            resource_path('views/admin/layout/topbar.blade.php'),
            $this->getTopbar($welcome, $user, $theme, $useIconLibrary)
        );
    }

    private function ensureHeroiconsInstalled(): bool
    {
        $this->animateSpinner('Checking Blade Heroicons', 6, 70000);

        if (class_exists(self::HEROICONS_PROVIDER)) {
            $this->info('Blade Heroicons already installed. Skipping download.');

            return true;
        }

        $this->warn('Blade Heroicons not found. Installing blade-ui-kit/blade-heroicons...');
        $process = Process::path(base_path())
            ->timeout(600)
            ->start('composer require blade-ui-kit/blade-heroicons --no-interaction');

        $this->spinProcess('Installing heroicons', $process, 8, 100000);

        $this->output->writeln("\rInstalling heroicons done.   ");
        $result = $process->wait();

        if ($result->failed()) {
            $this->warn('Icon library install failed. Falling back to built-in glyph icons.');

            return false;
        }

        $this->info('Blade Heroicons installed successfully.');

        return true;
    }

    private function animateSpinner(string $label, int $cycles = 6, int $sleepMicroseconds = 80000): void
    {
        $frames = ['|', '/', '-', '\\'];
        $frameIndex = 0;

        for ($i = 0; $i < $cycles; $i++) {
            $this->output->write("\r{$label} {$frames[$frameIndex]} ");
            usleep($sleepMicroseconds);
            $frameIndex = ($frameIndex + 1) % count($frames);
        }

        $this->output->writeln("\r{$label} done.   ");
    }

    private function spinProcess(string $label, $process, int $minFrames = 8, int $sleepMicroseconds = 100000): void
    {
        $frames = ['|', '/', '-', '\\'];
        $frameIndex = 0;
        $shown = 0;

        while ($process->running() || $shown < $minFrames) {
            $this->output->write("\r{$label} {$frames[$frameIndex]} ");
            usleep($sleepMicroseconds);
            $frameIndex = ($frameIndex + 1) % count($frames);
            $shown++;
        }
    }

    private function resolveSettings(array $defaults): array
    {
        if (! $this->input->isInteractive()) {
            return $defaults;
        }

        $edit = $this->choice('Edit settings? (yes/no)', ['yes', 'no'], 0);

        if ($edit === 'no') {
            $this->line('Using default settings with basic color.');
            $defaults['color'] = 'basic';
            $defaults['design'] = 'gridlayouts';
            $defaults['api'] = '';
            $defaults['token'] = '';
            $defaults['crud'] = true;

            return $defaults;
        }

        $title = $this->cleanText((string) $this->ask('Title', $defaults['title']));
        $user = $this->cleanText((string) $this->ask('User', $defaults['user']));
        $welcome = $this->cleanText((string) $this->ask('Welcome message', "Welcome back, {$user}"));
        $color = $this->choice('Color', array_keys($this->themes()), array_search($defaults['color'], array_keys($this->themes()), true) ?: 0);
        $design = $this->choice('Base design', $this->designs(), array_search($defaults['design'], $this->designs(), true) ?: 2);
        $route = $this->normalizeRoute((string) $this->ask('Route', $defaults['route']));
        $useApi = $this->choice('Connect API data source? (yes/no)', ['yes', 'no'], 1);
        $api = '';
        $token = '';
        $crud = $defaults['crud'];

        if ($useApi === 'yes') {
            $api = $this->normalizeApiUrl((string) $this->ask('API URL', $defaults['api'] !== '' ? $defaults['api'] : 'https://jsonplaceholder.typicode.com/users'));
            $token = trim((string) $this->ask('Bearer token (optional)', $defaults['token']));
            $crud = false;
        } else {
            $useCrud = $this->choice('Enable local basic CRUD mode? (yes/no)', ['yes', 'no'], $defaults['crud'] ? 0 : 1);
            $crud = $useCrud === 'yes';
        }

        return [
            'title' => $title,
            'user' => $user,
            'welcome' => $welcome,
            'route' => $route,
            'color' => $this->normalizeColor($color),
            'design' => $this->normalizeDesign($design),
            'api' => $api,
            'token' => $token,
            'crud' => $crud,
        ];
    }

    private function toBoolOption(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    private function addRoute(string $routePath): void
    {
        $routesFile = base_path('routes/web.php');
        $routesContent = File::exists($routesFile) ? File::get($routesFile) : '';
        $quotedPath = preg_quote($routePath, '/');

        if (preg_match("/Route::(get|view)\(\s*'{$quotedPath}'\s*,/", $routesContent)) {
            $this->warn("Route already exists: {$routePath}");
        } else {
            $route = "\nRoute::view('{$routePath}', 'admin.pages.dashboard');\n";
            File::append($routesFile, $route);
            $this->info("Route added: {$routePath}");
        }

        $entityRoute = rtrim($routePath, '/')."/{entity}";
        $quotedEntityRoute = preg_quote($entityRoute, '/');

        if (! preg_match("/Route::(get|view)\(\s*'{$quotedEntityRoute}'\s*,/", $routesContent)) {
            $route = "Route::view('{$entityRoute}', 'admin.pages.dashboard')->where('entity', '[A-Za-z0-9_-]+');\n";
            File::append($routesFile, $route);
            $this->info("Entity route added: {$entityRoute}");
        } else {
            $this->warn("Entity route already exists: {$entityRoute}");
        }
    }

    private function addCrudApiRoutes(): void
    {
        $routesFile = base_path('routes/api.php');

        if (! File::exists($routesFile)) {
            $baseApiContent = <<<'PHP'
<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
PHP;

            File::ensureDirectoryExists(dirname($routesFile));
            File::put($routesFile, $baseApiContent.PHP_EOL);
            $this->warn('routes/api.php was missing and has been recreated automatically.');
        }

        $routesContent = File::exists($routesFile) ? File::get($routesFile) : '';

        $hasEntityCrudRoutes = str_contains($routesContent, "AdminDashboardCrudController::class, 'schema'")
            && str_contains($routesContent, "AdminDashboardCrudController::class, 'index'")
            && str_contains($routesContent, "AdminDashboardCrudController::class, 'store'")
            && str_contains($routesContent, "AdminDashboardCrudController::class, 'update'")
            && str_contains($routesContent, "AdminDashboardCrudController::class, 'destroy'");

        $hasUserAdminActions = str_contains($routesContent, "AdminDashboardCrudController::class, 'banUser'")
            && str_contains($routesContent, "AdminDashboardCrudController::class, 'unbanUser'")
            && str_contains($routesContent, "AdminDashboardCrudController::class, 'resetUserPassword'");

        if ($hasEntityCrudRoutes && $hasUserAdminActions) {
            $this->warn('CRUD API routes already exist: /api/admin-dashboard/{entity}/records + schema + user actions');

            return;
        }

        if (str_contains($routesContent, '/admin-dashboard') || str_contains($routesContent, 'AdminDashboardCrudController::class')) {
            $this->warn('Legacy CRUD API routes detected; adding entity-aware routes.');
        }

        $routeBlock = <<<'PHP'

Route::middleware(['auth:sanctum', 'admin'])->group(function () {
    Route::prefix('/admin-dashboard')->group(function () {
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

        File::append($routesFile, $routeBlock."\n");
        $this->info('CRUD API routes added: /api/admin-dashboard/{entity}/schema + records + user admin actions');
    }

    private function writeFile(string $path, string $content): void
    {
        $force = (bool) $this->option('force');

        if (File::exists($path) && ! $force) {
            $this->warn("Skipped existing file: {$path} (use --force to overwrite)");

            return;
        }

        File::put($path, $content);
        $this->line("Written: {$path}");
    }

    private function normalizeRoute(string $routePath): string
    {
        $trimmed = '/'.ltrim(trim($routePath), '/');

        return $trimmed === '/' ? '/admin/dashboard' : $trimmed;
    }

    private function cleanText(string $text): string
    {
        $clean = trim($text);

        return $clean === '' ? 'Admin' : e($clean);
    }

    private function normalizeColor(string $color): string
    {
        $normalized = strtolower(trim($color));

        return array_key_exists($normalized, $this->themes()) ? $normalized : 'basic';
    }

    private function normalizeDesign(string $design): string
    {
        $normalized = strtolower(trim($design));

        return in_array($normalized, $this->designs(), true) ? $normalized : 'gridlayouts';
    }

    private function normalizeApiUrl(string $apiUrl): string
    {
        $trimmed = trim($apiUrl);

        if ($trimmed === '') {
            return '';
        }

        return filter_var($trimmed, FILTER_VALIDATE_URL) ? $trimmed : '';
    }

    private function migrationTables(): array
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
        $tables = array_values(array_filter($tables, function ($table) {
            return ! in_array($table, [
                'cache',
                'cache_locks',
                'jobs',
                'job_batches',
                'failed_jobs',
                'sessions',
                'password_reset_tokens',
                'personal_access_tokens',
                'migrations',
            ], true);
        }));
        sort($tables);

        return $tables;
    }

    private function sidebarLabelsFromTables(array $tables): array
    {
        if (count($tables) === 0) {
            return ['Squad', 'Messenger', 'Statistic', 'Calendar', 'Finance', 'Transfers', 'Youth academy'];
        }

        $labels = [];
        foreach (array_slice($tables, 0, 10) as $table) {
            $labels[] = $this->humanizeTableName($table);
        }

        return $labels;
    }

    private function humanizeTableName(string $table): string
    {
        $label = str_replace(['_', '-'], ' ', trim($table));
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return ucwords($label);
    }

    private function designs(): array
    {
        return ['cards', 'tables', 'gridlayouts'];
    }

    private function themes(): array
    {
        return [
            'basic' => [
                'bodyGradient' => 'from-slate-100 via-slate-100 to-slate-200',
                'activeItem' => 'bg-slate-800 text-white shadow-lg shadow-slate-900/30',
                'activeDot' => 'bg-slate-100',
                'inactiveDot' => 'bg-slate-400/70',
                'linkColor' => 'text-slate-700',
                'welcomeColor' => 'text-slate-700',
                'notifyDot' => 'bg-slate-500',
                'avatarGradient' => 'from-slate-700 to-slate-500',
                'progressTrack' => 'bg-slate-200',
                'progressFill' => 'from-slate-500 to-slate-700',
                'promoGradient' => 'from-slate-700 to-slate-900',
                'promoShadow' => 'shadow-slate-900/25',
                'promoButtonBg' => 'bg-slate-100',
                'promoButtonText' => 'text-slate-800',
            ],
            'cyan' => [
                'bodyGradient' => 'from-cyan-100 via-teal-100 to-sky-200',
                'activeItem' => 'bg-cyan-600 text-white shadow-lg shadow-cyan-700/30',
                'activeDot' => 'bg-cyan-100',
                'inactiveDot' => 'bg-cyan-400/70',
                'linkColor' => 'text-cyan-700',
                'welcomeColor' => 'text-cyan-700',
                'notifyDot' => 'bg-cyan-500',
                'avatarGradient' => 'from-cyan-700 to-cyan-400',
                'progressTrack' => 'bg-cyan-100',
                'progressFill' => 'from-cyan-500 to-rose-400',
                'promoGradient' => 'from-cyan-600 to-sky-700',
                'promoShadow' => 'shadow-cyan-900/25',
                'promoButtonBg' => 'bg-cyan-100',
                'promoButtonText' => 'text-cyan-800',
            ],
            'emerald' => [
                'bodyGradient' => 'from-emerald-100 via-teal-100 to-lime-200',
                'activeItem' => 'bg-emerald-600 text-white shadow-lg shadow-emerald-700/30',
                'activeDot' => 'bg-emerald-100',
                'inactiveDot' => 'bg-emerald-400/70',
                'linkColor' => 'text-emerald-700',
                'welcomeColor' => 'text-emerald-700',
                'notifyDot' => 'bg-emerald-500',
                'avatarGradient' => 'from-emerald-700 to-emerald-400',
                'progressTrack' => 'bg-emerald-100',
                'progressFill' => 'from-emerald-500 to-teal-400',
                'promoGradient' => 'from-emerald-600 to-teal-700',
                'promoShadow' => 'shadow-emerald-900/25',
                'promoButtonBg' => 'bg-emerald-100',
                'promoButtonText' => 'text-emerald-800',
            ],
            'rose' => [
                'bodyGradient' => 'from-rose-100 via-pink-100 to-orange-200',
                'activeItem' => 'bg-rose-600 text-white shadow-lg shadow-rose-700/30',
                'activeDot' => 'bg-rose-100',
                'inactiveDot' => 'bg-rose-400/70',
                'linkColor' => 'text-rose-700',
                'welcomeColor' => 'text-rose-700',
                'notifyDot' => 'bg-rose-500',
                'avatarGradient' => 'from-rose-700 to-rose-400',
                'progressTrack' => 'bg-rose-100',
                'progressFill' => 'from-rose-500 to-orange-400',
                'promoGradient' => 'from-rose-600 to-orange-700',
                'promoShadow' => 'shadow-rose-900/25',
                'promoButtonBg' => 'bg-rose-100',
                'promoButtonText' => 'text-rose-800',
            ],
        ];
    }

    private function getTheme(string $color): array
    {
        $themes = $this->themes();

        return $themes[$color] ?? $themes['basic'];
    }

    private function getAppLayout(array $theme): string
    {
        $bodyGradient = $theme['bodyGradient'];

        return <<<BLADE
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard</title>
    @vite('resources/css/app.css')
</head>
<body class="h-screen w-screen bg-linear-to-br {$bodyGradient} text-slate-800">
    <div class="h-screen w-screen p-0">
        <div class="grid h-full w-full rounded-3xl border border-white/70 bg-white/45 shadow-2xl shadow-slate-900/10 backdrop-blur-xl lg:grid-cols-[230px_minmax(0,1fr)]">
            <aside class="hidden h-full border-b border-white/70 bg-white/60 p-5 lg:block lg:border-b-0 lg:border-r lg:p-6">
                @include('admin.layout.sidebar')
            </aside>

            <section class="min-w-0 h-full overflow-y-auto p-4 md:p-6 lg:p-7">
                @include('admin.layout.topbar')

                <main class="mt-4 min-w-0 pb-8">
                    @yield('content')
                </main>
            </section>
        </div>

        <div id="mobile-sidebar-modal" class="fixed inset-0 z-40 hidden lg:hidden" aria-hidden="true">
            <div id="mobile-sidebar-backdrop" class="absolute inset-0 bg-slate-900/50"></div>
            <aside class="absolute left-0 top-0 h-full w-[86vw] max-w-[320px] border-r border-white/70 bg-white/95 p-5 shadow-2xl shadow-slate-900/25 backdrop-blur-xl">
                <div class="mb-4 flex items-center justify-end">
                    <button id="mobile-sidebar-close" type="button" class="rounded-lg bg-slate-100 px-3 py-1.5 text-xs font-bold text-slate-700 hover:bg-slate-200">Close</button>
                </div>
                @include('admin.layout.sidebar')
            </aside>
        </div>
    </div>

    <script>
        (function () {
            const openBtn = document.getElementById('mobile-menu-toggle');
            const modal = document.getElementById('mobile-sidebar-modal');
            const closeBtn = document.getElementById('mobile-sidebar-close');
            const backdrop = document.getElementById('mobile-sidebar-backdrop');

            if (!openBtn || !modal) {
                return;
            }

            function openSidebar() {
                modal.classList.remove('hidden');
                modal.classList.add('flex');
                document.body.classList.add('overflow-hidden');
            }

            function closeSidebar() {
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                document.body.classList.remove('overflow-hidden');
            }

            function syncMenuButtonVisibility() {
                const isMobile = window.matchMedia('(max-width: 1023px)').matches;
                openBtn.style.display = isMobile ? 'inline-flex' : 'none';

                if (!isMobile) {
                    closeSidebar();
                }
            }

            syncMenuButtonVisibility();
            window.addEventListener('resize', syncMenuButtonVisibility);

            openBtn.addEventListener('click', openSidebar);

            if (closeBtn) {
                closeBtn.addEventListener('click', closeSidebar);
            }

            if (backdrop) {
                backdrop.addEventListener('click', closeSidebar);
            }
        })();
    </script>
</body>
</html>
BLADE;
    }

    private function getSidebar(string $title, string $routePath, array $theme, array $sidebarItems, array $entitySlugs, bool $useIconLibrary): string
    {
        $matchPattern = ltrim($routePath, '/');
        $activeItem = $theme['activeItem'];
        $itemsMarkup = '';
        $logoutIcon = $useIconLibrary
            ? '<x-heroicon-o-arrow-right-on-rectangle class="h-4 w-4" />'
            : '⇥';
        $dashboardIcon = $useIconLibrary
            ? '<x-heroicon-o-home class="h-5 w-5" />'
            : '⌂';

        foreach ($sidebarItems as $index => $item) {
            $safe = e((string) $item);
            $rawSlug = (string) ($entitySlugs[$index] ?? str_replace(' ', '_', strtolower($item)));
            $slug = e($rawSlug);
            $icon = $useIconLibrary
                ? '<x-heroicon-o-'.$this->sidebarIconNameForSlug($rawSlug).' class="h-5 w-5" />'
                : e($this->sidebarIconForSlug($rawSlug));
            $itemPath = rtrim($routePath, '/').'/'.$slug;
            $itemMatch = $matchPattern.'/'.$slug;
            $itemsMarkup .= <<<HTML
        <a href="{{ url('{$itemPath}') }}" class="flex items-center gap-3 rounded-xl px-4 py-2.5 text-sm font-medium transition {{ request()->is('{$itemMatch}') ? '{$activeItem}' : 'text-slate-500 hover:bg-white/70 hover:text-slate-800' }}">
            <span class="inline-grid h-5 w-5 place-items-center {{ request()->is('{$itemMatch}') ? 'text-white' : 'text-slate-500' }}">{$icon}</span>
            {$safe}
        </a>

HTML;
        }

        return <<<BLADE
<div class="flex h-full flex-col gap-6">
    <div>
        <p class="text-3xl font-black tracking-tight text-slate-800">{$title}</p>
        <p class="text-xs text-slate-500">Admin workspace</p>
    </div>

    <nav class="space-y-1.5">
        <a
            href="{{ url('{$routePath}') }}"
            class="flex items-center gap-3 rounded-xl px-4 py-3 text-sm font-semibold transition {{ request()->is('{$matchPattern}') ? '{$activeItem}' : 'text-slate-600 hover:bg-white/70 hover:text-slate-800' }}"
        >
            <span class="inline-grid h-5 w-5 place-items-center {{ request()->is('{$matchPattern}') ? 'text-white' : 'text-slate-500' }}">{$dashboardIcon}</span>
            Dashboard
        </a>

{$itemsMarkup}
    </nav>

    <div class="mt-auto pt-3 border-t border-slate-200/70">
        @if (Route::has('logout'))
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-white/85 px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm shadow-slate-900/5 transition hover:bg-white">
                    <span class="inline-grid h-4 w-4 place-items-center text-sm leading-none">{$logoutIcon}</span>
                    <span>Logout</span>
                </button>
            </form>
        @else
            <a href="{{ url('/logout') }}" class="w-full inline-flex items-center justify-center gap-2 rounded-xl bg-white/85 px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-sm shadow-slate-900/5 transition hover:bg-white">
                <span class="inline-grid h-4 w-4 place-items-center text-sm leading-none">{$logoutIcon}</span>
                <span>Logout</span>
            </a>
        @endif
    </div>
</div>
BLADE;
    }

    private function getTopbar(string $welcome, string $user, array $theme, bool $useIconLibrary): string
    {
        $welcomeColor = $theme['welcomeColor'];
        $avatarGradient = $theme['avatarGradient'];
        $menuIcon = $useIconLibrary
            ? '<x-heroicon-o-bars-3 class="h-5 w-5" />'
            : '≡';
        $searchIcon = $useIconLibrary
            ? '<x-heroicon-o-magnifying-glass class="h-4 w-4" />'
            : '⌕';
        $bellIcon = $useIconLibrary
            ? '<x-heroicon-o-bell class="h-4 w-4" />'
            : '⌁';

        return <<<BLADE
<header class="w-full flex items-center justify-between gap-3">
    <p class="text-sm font-semibold {$welcomeColor}">{$welcome}</p>

    <div class="ml-auto flex items-center gap-2">
        <button class="rounded-xl bg-white/80 p-2 text-slate-600 transition hover:bg-white" type="button" aria-label="Search">
            <span class="inline-grid h-4 w-4 place-items-center text-sm leading-none">{$searchIcon}</span>
        </button>

        <button class="relative rounded-xl bg-white/80 p-2 text-slate-600 transition hover:bg-white" type="button" aria-label="Notifications">
            <span class="inline-grid h-4 w-4 place-items-center text-sm leading-none">{$bellIcon}</span>
            <span class="absolute right-1 top-1 rounded-full {$theme['notifyDot']} px-1 text-[9px] font-bold text-white">1</span>
        </button>

        <div class="flex items-center gap-2 rounded-full bg-white/85 px-2 py-1.5 shadow-sm shadow-slate-900/5">
            <span class="grid h-7 w-7 place-items-center rounded-full bg-gradient-to-br {$avatarGradient} text-xs font-bold text-white">A</span>
            <span class="pr-2 text-xs font-semibold text-slate-700">{$user}</span>
        </div>

        <button id="mobile-menu-toggle" class="hidden rounded-xl bg-white/80 p-2 text-slate-600 transition hover:bg-white" type="button" aria-label="Open menu">
            <span class="inline-grid h-5 w-5 place-items-center text-sm leading-none">{$menuIcon}</span>
        </button>
    </div>
</header>
BLADE;
    }

    private function sidebarIconForSlug(string $slug): string
    {
        return match (strtolower(trim($slug))) {
            'categories' => '◫',
            'customers' => '◉',
            'order_items' => '≡',
            'orders' => '▣',
            'products' => '◈',
            'users' => '◍',
            default => '◇',
        };
    }

    private function sidebarIconNameForSlug(string $slug): string
    {
        return match (strtolower(trim($slug))) {
            'categories' => 'squares-2x2',
            'customers' => 'user-group',
            'order_items' => 'list-bullet',
            'orders' => 'clipboard-document-list',
            'products' => 'cube',
            'users' => 'users',
            default => 'rectangle-group',
        };
    }

    private function getDashboard(array $theme, array $settings): string
    {
        $linkColor = $theme['linkColor'];
        $promoGradient = $theme['promoGradient'];
        $promoShadow = $theme['promoShadow'];
        $promoButtonBg = $theme['promoButtonBg'];
        $promoButtonText = $theme['promoButtonText'];
        $design = $settings['design'];
        $entitySlugs = $settings['entity_slugs'] ?? [];
        $entitySlugsJs = json_encode(array_values($entitySlugs), JSON_UNESCAPED_SLASHES);
        $effectiveApi = $settings['api'];
        if ($effectiveApi === '' && (($settings['crud'] ?? false) === true)) {
            $effectiveApi = '/api/admin-dashboard';
        }

        $apiUrl = json_encode($effectiveApi, JSON_UNESCAPED_SLASHES);
        $token = json_encode($settings['token'], JSON_UNESCAPED_SLASHES);
        $crudEnabled = (bool) ($settings['crud'] ?? false);
        $crudEnabledJs = $crudEnabled ? 'true' : 'false';
        $crudMarkup = $crudEnabled ? $this->crudPanelMarkup($linkColor) : '';
        $entityNames = $settings['sidebar_items'] ?? [];
        $entityMarkup = $this->entityStripMarkup($entityNames, $linkColor);
        $layoutMarkup = $this->getDesignMarkup($design, $linkColor, $promoGradient, $promoShadow, $promoButtonBg, $promoButtonText, $crudMarkup);

        return <<<BLADE
@extends('admin.layout.app')

@section('content')
    <div
        id="dashboard-config"
        data-api-endpoint="{{ config('admin_dashboard.api_endpoint', '') }}"
        data-api-token="{{ config('admin_dashboard.api_token', '') }}"
        data-selected-entity="{{ request()->route('entity') ?? '' }}"
        class="hidden"
    ></div>

    <h1 class="mb-2 text-4xl font-black tracking-tight text-slate-800 md:text-5xl">Dashboard</h1>
    <p id="api-status" class="mb-4 text-xs font-semibold text-slate-500">API: waiting</p>
{$entityMarkup}

{$layoutMarkup}

    <script>
        (function () {
            const defaultEndpoint = {$apiUrl};
            const defaultToken = '';
            const configEl = document.getElementById('dashboard-config');
            const configuredEndpoint = configEl ? (configEl.dataset.apiEndpoint || '') : '';
            const configuredToken = configEl ? (configEl.dataset.apiToken || '') : '';
            const normalizedConfiguredEndpoint = configuredEndpoint.trim();
            const hasValidConfiguredEndpoint = normalizedConfiguredEndpoint !== '' &&
                (/^https?:\/\//i.test(normalizedConfiguredEndpoint) || normalizedConfiguredEndpoint.startsWith('/'));
            const endpoint = hasValidConfiguredEndpoint ? normalizedConfiguredEndpoint : defaultEndpoint;
            const token = configuredToken || defaultToken;
            const crudEnabled = {$crudEnabledJs};
            const runtimeCrudEnabled = crudEnabled;
            const entitySlugs = {$entitySlugsJs};
            const selectedEntityFromRoute = configEl ? (configEl.dataset.selectedEntity || '') : '';
            const statusEl = document.getElementById('api-status');
            let editId = null;
            let crudSchema = null;
            let crudRecordsCache = [];

            const selectedEntity = (selectedEntityFromRoute && entitySlugs.includes(selectedEntityFromRoute))
                ? selectedEntityFromRoute
                : (entitySlugs[0] || 'records');

            function crudRecordsEndpoint() {
                return endpoint + '/' + selectedEntity + '/records';
            }

            function crudSchemaEndpoint() {
                return endpoint + '/' + selectedEntity + '/schema';
            }

            function apiRecordsEndpoint() {
                if (!endpoint) {
                    return '';
                }

                if (endpoint.includes('{entity}')) {
                    return endpoint.replace('{entity}', selectedEntity);
                }

                if (/\/records\/?$/i.test(endpoint)) {
                    return endpoint;
                }

                return endpoint.replace(/\/+$/, '') + '/' + selectedEntity + '/records';
            }

            function apiHeaders(jsonBody = false) {
                const headers = { 'Accept': 'application/json' };
                if (jsonBody) {
                    headers['Content-Type'] = 'application/json';
                }
                if (token) {
                    headers['Authorization'] = 'Bearer ' + token;
                }

                return headers;
            }

            function setCrudBusy(isBusy, text) {
                const loader = document.getElementById('crud-loading-indicator');
                const loaderText = document.getElementById('crud-loading-text');
                const modalLoader = document.getElementById('crud-modal-loading');
                const modalLoaderText = document.getElementById('crud-modal-loading-text');
                const openBtn = document.getElementById('crud-open-modal');
                const submitBtn = document.querySelector('#crud-form button[type="submit"]');
                const closeBtn = document.getElementById('crud-close-modal');
                const closeBtnSecondary = document.getElementById('crud-close-modal-secondary');
                const resetBtn = document.getElementById('crud-reset');
                const submitLabel = document.getElementById('crud-submit-label');

                if (loader) {
                    loader.classList.toggle('hidden', !isBusy);
                    loader.classList.toggle('inline-flex', isBusy);
                }

                if (loaderText && text) {
                    loaderText.textContent = text;
                }

                if (modalLoader) {
                    modalLoader.classList.add('hidden');
                    modalLoader.classList.remove('flex');
                }

                if (modalLoaderText && text) {
                    modalLoaderText.textContent = text;
                }

                if (openBtn) {
                    openBtn.disabled = isBusy;
                    openBtn.classList.toggle('opacity-60', isBusy);
                    openBtn.classList.toggle('cursor-not-allowed', isBusy);
                }

                [submitBtn, closeBtn, closeBtnSecondary, resetBtn].forEach(function (btn) {
                    if (!btn) {
                        return;
                    }
                    btn.disabled = isBusy;
                    btn.classList.toggle('opacity-60', isBusy);
                    btn.classList.toggle('cursor-not-allowed', isBusy);
                });

                if (submitLabel) {
                    const defaultLabel = submitLabel.dataset.defaultLabel || submitLabel.textContent || 'Create record';
                    submitLabel.dataset.defaultLabel = defaultLabel;

                    if (isBusy) {
                        const busyText = text || 'Saving...';
                        submitLabel.innerHTML = '<span class="inline-flex items-center gap-2"><svg class="h-4 w-4 shrink-0 text-white" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="42 18" opacity="0.9"><animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.8s" repeatCount="indefinite" /></circle></svg><span>' + busyText + '</span></span>';
                    } else {
                        submitLabel.textContent = defaultLabel;
                    }
                }
            }

            function setText(id, value) {
                const node = document.getElementById(id);
                if (!node) {
                    return;
                }
                node.textContent = value;
            }

            function asText(value) {
                if (value === null || value === undefined) {
                    return '-';
                }

                if (typeof value === 'object') {
                    try {
                        const stringified = JSON.stringify(value);
                        return stringified.length > 48 ? stringified.slice(0, 45) + '...' : stringified;
                    } catch (error) {
                        return '[object]';
                    }
                }

                return String(value);
            }

            function normalizePayload(payload) {
                if (Array.isArray(payload)) {
                    return { records: payload, root: {} };
                }

                if (payload && typeof payload === 'object') {
                    if (Array.isArray(payload.data)) {
                        return { records: payload.data, root: payload };
                    }

                    const candidateKeys = ['items', 'results', 'rows', 'users', 'records'];
                    for (const key of candidateKeys) {
                        if (Array.isArray(payload[key])) {
                            return { records: payload[key], root: payload };
                        }
                    }

                    return { records: [payload], root: payload };
                }

                return { records: [], root: {} };
            }

            function renderKeyValues(record) {
                const grid = document.getElementById('kv-grid');
                if (!grid) {
                    return;
                }

                const entries = Object.entries(record || {}).filter(function (pair) {
                    return typeof pair[1] !== 'object' || pair[1] === null;
                }).slice(0, 6);

                if (entries.length === 0) {
                    grid.innerHTML = '<div class="rounded-lg border border-slate-200 bg-white/70 p-2 text-xs text-slate-500">No data found.</div>';
                    return;
                }

                grid.innerHTML = entries.map(function (pair) {
                    const key = pair[0];
                    const value = pair[1];

                    return '<div class="rounded-lg border border-slate-200 bg-white/70 p-2">' +
                        '<p class="text-[9px] uppercase text-slate-500">' + key + '</p>' +
                        '<p class="mt-0.5 text-xs font-semibold text-slate-700 truncate">' + asText(value) + '</p>' +
                    '</div>';
                }).join('');

            }

            function renderNestedTables(record) {
                const container = document.getElementById('nested-tables-container');
                if (!container) return;

                const nestedObjects = Object.entries(record || {}).filter(function (pair) {
                    return pair[1] !== null && typeof pair[1] === 'object' && !Array.isArray(pair[1]);
                });

                container.innerHTML = nestedObjects.map(function (pair) {
                    const key = pair[0];
                    const obj = pair[1];
                    const entries = Object.entries(obj).slice(0, 10);

                    return '<article class="rounded-lg border border-slate-200 bg-white/80 p-3 shadow-sm">' +
                        '<h3 class="mb-2 text-sm font-bold text-slate-800">' + key + ' (Nested)</h3>' +
                        '<div class="grid gap-1.5 sm:grid-cols-2">' +
                        entries.map(function (entry) {
                            return '<div class="rounded border border-slate-100 bg-slate-50 p-2">' +
                                '<p class="text-[8px] uppercase text-slate-500">' + entry[0] + '</p>' +
                                '<p class="mt-0.5 text-xs font-semibold text-slate-700 truncate">' + asText(entry[1]) + '</p>' +
                            '</div>';
                        }).join('') +
                        '</div>' +
                    '</article>';
                }).join('');
            }


            function flattenObject(obj, prefix = '') {
                const result = {};
                Object.entries(obj || {}).forEach(function (pair) {
                    const key = pair[0];
                    const value = pair[1];
                    const fullKey = prefix ? prefix + '_' + key : key;

                    if (value !== null && typeof value === 'object' && !Array.isArray(value)) {
                        Object.assign(result, flattenObject(value, fullKey));
                    } else {
                        result[fullKey] = value;
                    }
                });
                return result;
            }

            function renderTable(records) {
                const thead = document.getElementById('data-columns');
                const tbody = document.getElementById('data-rows');
                const recordCountEl = document.getElementById('record-count');

                if (!thead || !tbody) {
                    return;
                }

                if (!Array.isArray(records) || records.length === 0) {
                    thead.innerHTML = '<th class="px-2 py-1.5">Data</th>';
                    tbody.innerHTML = '<tr><td class="px-2 py-2 text-xs text-slate-500">No records found in API response.</td></tr>';
                    if (recordCountEl) recordCountEl.textContent = 'No data';
                    return;
                }

                if (recordCountEl) recordCountEl.textContent = records.length + ' rows';

                const first = records[0] && typeof records[0] === 'object' ? records[0] : { value: records[0] };
                const flattened = flattenObject(first);
                const columns = Object.keys(flattened);
                const safeColumns = columns.length > 0 ? columns : ['value'];

                thead.innerHTML = safeColumns.map(function (col) {
                    return '<th class="px-2 py-1.5 font-semibold whitespace-nowrap">' + col + '</th>';
                }).join('');

                tbody.innerHTML = records.slice(0, 10).map(function (row) {
                    const item = (row && typeof row === 'object') ? row : { value: row };
                    const flatItem = flattenObject(item);
                    const tds = safeColumns.map(function (col) {
                        const cellValue = flatItem[col];
                        return '<td class="px-2 py-1.5 whitespace-nowrap text-sm">' + asText(cellValue) + '</td>';
                    }).join('');

                    return '<tr class="border-t border-slate-100 hover:bg-slate-50/50">' + tds + '</tr>';
                }).join('');
            }

            function renderCrudTable(records) {
                const thead = document.getElementById('data-columns');
                const tbody = document.getElementById('data-rows');
                const recordCountEl = document.getElementById('record-count');

                if (!thead || !tbody) {
                    return;
                }

                const safeRecords = Array.isArray(records) ? records : [];

                if (safeRecords.length === 0) {
                    thead.innerHTML = '<th class="px-2 py-1.5">Data</th>';
                    tbody.innerHTML = '<tr><td class="px-2 py-2 text-xs text-slate-500">No records yet. Click "New record" to create one.</td></tr>';
                    if (recordCountEl) {
                        recordCountEl.textContent = '0 rows';
                    }
                    return;
                }

                const first = safeRecords[0] && typeof safeRecords[0] === 'object' ? safeRecords[0] : { value: safeRecords[0] };
                const columns = Object.keys(flattenObject(first));
                const safeColumns = columns.length > 0 ? columns : ['value'];

                thead.innerHTML = safeColumns.map(function (col) {
                    return '<th class="px-2 py-1.5 font-semibold whitespace-nowrap">' + col + '</th>';
                }).join('') + '<th class="px-2 py-1.5 font-semibold whitespace-nowrap">Actions</th>';

                tbody.innerHTML = safeRecords.map(function (row) {
                    const item = (row && typeof row === 'object') ? row : { value: row };
                    const flatItem = flattenObject(item);
                    const cells = safeColumns.map(function (col) {
                        return '<td class="px-2 py-1.5 whitespace-nowrap text-sm">' + asText(flatItem[col]) + '</td>';
                    }).join('');

                    const rowId = Number(row && row.id);

                    const actions = '<td class="px-2 py-1.5 whitespace-nowrap text-xs">' +
                        '<button type="button" class="mr-2 rounded-md bg-slate-100 px-2 py-1 font-semibold text-slate-700 hover:bg-slate-200" data-crud-edit="' + rowId + '">Edit</button>' +
                        '<button type="button" class="rounded-md bg-rose-100 px-2 py-1 font-semibold text-rose-700 hover:bg-rose-200" data-crud-delete="' + rowId + '">Delete</button>' +
                    '</td>';

                    return '<tr class="border-t border-slate-100 hover:bg-slate-50/50">' + cells + actions + '</tr>';
                }).join('');

                if (recordCountEl) {
                    recordCountEl.textContent = safeRecords.length + ' rows';
                }
            }

            function bindCrudActions(records) {
                const tbody = document.getElementById('data-rows');
                if (!tbody) {
                    return;
                }

                tbody.querySelectorAll('[data-crud-edit]').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        const id = Number(btn.getAttribute('data-crud-edit'));
                        const row = records.find(function (item) {
                            return Number(item.id) === id;
                        }) || {};

                        openCrudModal('edit', row);
                    });
                });

                tbody.querySelectorAll('[data-crud-delete]').forEach(function (btn) {
                    btn.addEventListener('click', async function () {
                        const id = Number(btn.getAttribute('data-crud-delete'));

                        try {
                            setCrudBusy(true, 'Deleting record...');
                            const response = await fetch(crudRecordsEndpoint() + '/' + id, {
                                method: 'DELETE',
                                headers: apiHeaders(),
                            });

                            if (!response.ok) {
                                throw new Error('HTTP ' + response.status);
                            }

                            await refreshCrud();
                            if (statusEl) {
                                statusEl.textContent = 'CRUD: record deleted';
                            }
                        } catch (error) {
                            if (statusEl) {
                                statusEl.textContent = 'CRUD error: ' + error.message;
                            }
                        } finally {
                            setCrudBusy(false, 'Loading entity schema...');
                        }
                    });
                });
            }

            function normalizeCrudPayload(payload) {
                if (Array.isArray(payload)) {
                    return payload;
                }

                if (payload && typeof payload === 'object') {
                    if (Array.isArray(payload.data)) {
                        return payload.data;
                    }
                    if (Array.isArray(payload.records)) {
                        return payload.records;
                    }
                }

                return [];
            }

            async function refreshCrud() {
                const tableHead = document.getElementById('data-columns');
                const tableBody = document.getElementById('data-rows');
                if (tableHead) {
                    tableHead.innerHTML = '<tr><th class="px-2 py-1.5">Data</th></tr>';
                }
                if (tableBody) {
                    tableBody.innerHTML = '<tr><td class="px-2 py-2 text-xs text-slate-500">Loading entity schema...</td></tr>';
                }

                const response = await fetch(crudRecordsEndpoint(), { headers: apiHeaders() });
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const payload = await response.json();
                const records = normalizeCrudPayload(payload);
                renderSummary(records);
                renderCrudTable(records);
                bindCrudActions(records);

                return records;
            }

            async function fetchCrudSchema() {
                const response = await fetch(crudSchemaEndpoint(), { headers: apiHeaders() });
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }

                const payload = await response.json();
                const columns = Array.isArray(payload.columns) ? payload.columns : [];

                return {
                    columns: columns,
                };
            }

            function buildCrudDynamicFields(schema) {
                const container = document.getElementById('crud-dynamic-fields');
                if (!container) {
                    return;
                }

                const columns = Array.isArray(schema && schema.columns) ? schema.columns : [];
                const editable = columns.filter(function (column) {
                    return !['id', 'created_at', 'updated_at', 'deleted_at'].includes(column.name);
                });

                if (editable.length === 0) {
                    container.innerHTML = '<p class="text-xs text-slate-600 sm:col-span-2">No editable columns found for this entity.</p>';
                    return;
                }

                function escapeAttr(value) {
                    return String(value)
                        .replace(/&/g, '&amp;')
                        .replace(/"/g, '&quot;');
                }

                function placeholderFromColumn(name, label, type) {
                    const key = String(name || '').toLowerCase();
                    const readable = String(label || 'Value');

                    if (type === 'date') {
                        return 'YYYY-MM-DD';
                    }
                    if (type === 'datetime') {
                        return 'YYYY-MM-DDTHH:MM';
                    }
                    if (/email/.test(key)) {
                        return 'name@example.com';
                    }
                    if (/phone|tel|mobile/.test(key)) {
                        return '+212600000000';
                    }
                    if (/code/.test(key)) {
                        return 'Ex: ' + readable;
                    }
                    if (/description|content|note|notes|summary|details|address|bio/.test(key)) {
                        return 'Write ' + readable.toLowerCase();
                    }

                    return 'Enter ' + readable.toLowerCase();
                }

                container.innerHTML = editable.map(function (column) {
                    const col = String(column.name || '');
                    const label = col.replace(/_/g, ' ').replace(/\b\w/g, function (char) { return char.toUpperCase(); });
                    const requiredAttr = column.required ? ' required' : '';
                    const fullWidth = /description|content|note|notes|address|bio|summary|details/i.test(col);
                    const wrapperClass = fullWidth ? 'sm:col-span-2' : '';
                    const baseClass = 'rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm outline-none ring-cyan-200 focus:ring';
                    const inputType = String(column.input_type || '').toLowerCase();
                    const placeholder = String(column.placeholder || '').trim() || placeholderFromColumn(col, label, column.type);
                    const placeholderAttr = placeholder ? ' placeholder="' + escapeAttr(placeholder) + '"' : '';
                    const validationHint = column.validation
                        ? '<p class="mt-1 text-[10px] font-medium text-slate-500">Validation: ' + escapeAttr(String(column.validation)) + '</p>'
                        : '';
                    const relationshipHint = column.relationship && column.relationship.related_table
                        ? '<p class="mt-1 text-[10px] font-medium text-cyan-700">Relation: belongsTo ' + escapeAttr(String(column.relationship.related_table)) + '</p>'
                        : '';

                    const hintMarkup = validationHint + relationshipHint;

                    if ((inputType === 'select' || column.is_foreign_key) && Array.isArray(column.options) && column.options.length > 0) {
                        const optionsHtml = ['<option value="">Select ' + label + '</option>'].concat(
                            column.options.map(function (option) {
                                return '<option value="' + option.value + '">' + option.label + '</option>';
                            })
                        ).join('');

                        return '<div class="' + wrapperClass + '">' +
                            '<label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-700">' + label + '</label>' +
                            '<select data-crud-field="' + col + '" data-crud-type="select" class="' + baseClass + '"' + requiredAttr + '>' + optionsHtml + '</select>' +
                            hintMarkup +
                        '</div>';
                    }

                    if (inputType === 'textarea') {
                        return '<div class="' + wrapperClass + '">' +
                            '<label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-700">' + label + '</label>' +
                            '<textarea data-crud-field="' + col + '" data-crud-type="textarea" rows="4" class="' + baseClass + '"' + requiredAttr + placeholderAttr + '></textarea>' +
                            hintMarkup +
                        '</div>';
                    }

                    if (inputType === 'checkbox') {
                        return '<div class="' + wrapperClass + '">' +
                            '<label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-700">' + label + '</label>' +
                            '<label class="inline-flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700">' +
                                '<input data-crud-field="' + col + '" data-crud-type="checkbox" type="checkbox" class="h-4 w-4 rounded border-slate-300 text-cyan-600 focus:ring-cyan-500">' +
                                '<span>Enabled</span>' +
                            '</label>' +
                            hintMarkup +
                        '</div>';
                    }

                    const htmlInputType = (function () {
                        if (inputType === 'email' || inputType === 'password' || inputType === 'tel' || inputType === 'date' || inputType === 'datetime-local' || inputType === 'number') {
                            return inputType;
                        }

                        if (column.type === 'boolean') {
                            return 'checkbox';
                        }

                        if (column.type === 'integer' || column.type === 'bigint' || column.type === 'decimal' || column.type === 'float') {
                            return 'number';
                        }

                        if (column.type === 'date') {
                            return 'date';
                        }

                        if (column.type === 'datetime') {
                            return 'datetime-local';
                        }

                        return 'text';
                    })();
                    const stepAttr = htmlInputType === 'number' ? ' step="any"' : '';

                    return '<div class="' + wrapperClass + '">' +
                        '<label class="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-700">' + label + '</label>' +
                        '<input data-crud-field="' + col + '" data-crud-type="' + htmlInputType + '" type="' + htmlInputType + '" class="' + baseClass + '"' + stepAttr + requiredAttr + placeholderAttr + '>' +
                        hintMarkup +
                    '</div>';
                }).join('');
            }

            function collectCrudPayload() {
                const payload = {};

                document.querySelectorAll('[data-crud-field]').forEach(function (input) {
                    const column = input.getAttribute('data-crud-field');
                    if (!column) {
                        return;
                    }

                    const fieldType = String(input.getAttribute('data-crud-type') || '').toLowerCase();
                    if (fieldType === 'checkbox') {
                        payload[column] = input.checked ? 1 : 0;
                        return;
                    }

                    const raw = (input.value || '').trim();
                    if (raw === '') {
                        payload[column] = null;
                        return;
                    }

                    if (fieldType === 'number') {
                        const num = Number(raw);
                        payload[column] = Number.isFinite(num) ? num : raw;
                        return;
                    }

                    payload[column] = raw;
                });

                return payload;
            }

            function openCrudModal(mode, row) {
                const modal = document.getElementById('crud-modal');
                const title = document.getElementById('crud-modal-title');
                const submitLabel = document.getElementById('crud-submit-label');

                if (!modal) {
                    return;
                }

                if (mode === 'edit') {
                    editId = Number(row && row.id);
                    if (title) {
                        title.textContent = 'Update Record';
                    }
                    if (submitLabel) {
                        submitLabel.textContent = 'Save changes';
                        submitLabel.dataset.defaultLabel = 'Save changes';
                    }

                    document.querySelectorAll('[data-crud-field]').forEach(function (input) {
                        const column = input.getAttribute('data-crud-field');
                        if (!column) {
                            return;
                        }

                        const value = row[column];
                        input.value = value === null || value === undefined ? '' : String(value);
                    });
                } else {
                    editId = null;
                    if (title) {
                        title.textContent = 'Create Record';
                    }
                    if (submitLabel) {
                        submitLabel.textContent = 'Create record';
                        submitLabel.dataset.defaultLabel = 'Create record';
                    }
                    const form = document.getElementById('crud-form');
                    if (form) {
                        form.reset();
                    }
                }

                modal.classList.remove('hidden');
                modal.style.display = 'flex';
                document.body.classList.add('overflow-hidden');
            }

            function closeCrudModal() {
                const modal = document.getElementById('crud-modal');
                if (!modal) {
                    return;
                }

                modal.classList.add('hidden');
                modal.style.display = 'none';
                document.body.classList.remove('overflow-hidden');
            }

            function initCrudMode() {
                const panel = document.getElementById('crud-panel');
                if (!panel) {
                    renderSummary([]);
                    renderTable([]);
                    return;
                }

                const openBtn = document.getElementById('crud-open-modal');
                const closeBtn = document.getElementById('crud-close-modal');
                const closeBtnSecondary = document.getElementById('crud-close-modal-secondary');
                const modal = document.getElementById('crud-modal');
                const form = document.getElementById('crud-form');
                const resetBtn = document.getElementById('crud-reset');
                const submitLabel = document.getElementById('crud-submit-label');

                function clearForm() {
                    if (!form) {
                        return;
                    }
                    form.reset();
                    editId = null;
                    if (submitLabel) {
                        submitLabel.textContent = 'Create record';
                        submitLabel.dataset.defaultLabel = 'Create record';
                    }
                }

                if (openBtn) {
                    openBtn.addEventListener('click', function () {
                        openCrudModal('create', {});
                    });
                }

                if (closeBtn) {
                    closeBtn.addEventListener('click', closeCrudModal);
                }

                if (closeBtnSecondary) {
                    closeBtnSecondary.addEventListener('click', closeCrudModal);
                }

                if (modal) {
                    modal.addEventListener('click', function (event) {
                        if (event.target === modal) {
                            closeCrudModal();
                        }
                    });
                }

                if (form) {
                    form.addEventListener('submit', async function (event) {
                        event.preventDefault();
                        const payload = collectCrudPayload();

                        try {
                            const savingText = editId === null ? 'Creating...' : 'Saving...';
                            setCrudBusy(true, savingText);
                            const method = editId === null ? 'POST' : 'PUT';
                            const target = editId === null ? crudRecordsEndpoint() : (crudRecordsEndpoint() + '/' + editId);
                            const response = await fetch(target, {
                                method: method,
                                headers: apiHeaders(true),
                                body: JSON.stringify(payload),
                            });

                            if (!response.ok) {
                                throw new Error('HTTP ' + response.status);
                            }

                            const records = await refreshCrud();
                            clearForm();
                            closeCrudModal();

                            if (statusEl) {
                                statusEl.textContent = 'CRUD: record saved (' + records.length + ' records)';
                            }
                        } catch (error) {
                            if (statusEl) {
                                statusEl.textContent = 'CRUD error: ' + error.message;
                            }
                        } finally {
                            setCrudBusy(false, 'Loading entity schema...');
                        }
                    });
                }

                if (resetBtn) {
                    resetBtn.addEventListener('click', function () {
                        clearForm();
                    });
                }

                setCrudBusy(true, 'Loading entity schema...');

                fetchCrudSchema()
                    .then(function (schema) {
                        crudSchema = schema;
                        buildCrudDynamicFields(crudSchema);

                        return refreshCrud();
                    })
                    .then(function (records) {
                        crudRecordsCache = records;
                        setCrudBusy(false, 'Loading entity schema...');
                        if (statusEl) {
                            statusEl.textContent = 'CRUD: ' + selectedEntity + ' mode enabled (' + records.length + ' records)';
                        }
                    })
                    .catch(function (error) {
                        setCrudBusy(false, 'Loading entity schema...');
                        if (statusEl) {
                            statusEl.textContent = 'CRUD error: ' + error.message;
                        }
                    });
            }

            function renderSummary(records) {
                const first = records[0] && typeof records[0] === 'object' ? records[0] : {};
                const fields = Object.keys(first);
                const numericFields = fields.filter(function (key) {
                    return Number.isFinite(Number(first[key]));
                });

                setText('stat-records', String(records.length));
                setText('stat-fields', String(fields.length));
                setText('stat-numeric', String(numericFields.length));

                try {
                    setText('stat-endpoint', endpoint ? new URL(endpoint).host : 'N/A');
                } catch (error) {
                    setText('stat-endpoint', endpoint || 'N/A');
                }
            }

            async function loadDashboardData() {
                if (runtimeCrudEnabled) {
                    initCrudMode();
                    return;
                }

                const apiEndpoint = apiRecordsEndpoint();

                if (!apiEndpoint) {

                    if (statusEl) {
                        statusEl.textContent = 'API: disabled (no URL provided)';
                    }

                    renderSummary([]);
                    renderKeyValues({});
                    renderTable([]);
                    return;
                }

                if (statusEl) {
                    statusEl.textContent = 'API: loading data...';
                }

                try {
                    const headers = { 'Accept': 'application/json' };
                    if (token) {
                        headers['Authorization'] = 'Bearer ' + token;
                    }

                    const response = await fetch(apiEndpoint, { headers: headers });

                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    const payload = await response.json();
                    const normalized = normalizePayload(payload);
                    const records = Array.isArray(normalized.records) ? normalized.records : [];
                    const firstRecord = records[0] && typeof records[0] === 'object' ? records[0] : {};

                    renderSummary(records);
                    renderKeyValues(firstRecord);
                    renderTable(records);

                    if (statusEl) {
                        statusEl.textContent = 'API: data loaded (' + records.length + ' records)';
                    }
                } catch (error) {
                    if (statusEl) {
                        statusEl.textContent = 'API error: ' + error.message;
                    }

                    renderSummary([]);
                    renderKeyValues({});
                    renderTable([]);
                }
            }

            loadDashboardData();
        })();
    </script>
@endsection
BLADE;
    }

    private function getDesignMarkup(
        string $design,
        string $linkColor,
        string $promoGradient,
        string $promoShadow,
        string $promoButtonBg,
        string $promoButtonText,
        string $crudMarkup
    ): string {
        if ($design === 'cards') {
            return <<<HTML
    <div class="grid min-w-0 gap-3">
        {$this->summaryCardsMarkup()}
        {$crudMarkup}

        <section class="min-w-0 space-y-4">
            {$this->dataTableMarkup($linkColor)}
            {$this->promoMarkup($promoGradient, $promoShadow, $promoButtonBg, $promoButtonText)}
        </section>
    </div>
HTML;
        }

        if ($design === 'tables') {
            return <<<HTML
    <div class="grid min-w-0 gap-3">
        {$this->summaryCardsMarkup()}
        {$crudMarkup}

        <section class="min-w-0 space-y-4">
            {$this->dataTableMarkup($linkColor)}
            {$this->promoMarkup($promoGradient, $promoShadow, $promoButtonBg, $promoButtonText)}
        </section>
    </div>
HTML;
        }

        return <<<HTML
    <div class="grid min-w-0 gap-4">
        {$this->summaryCardsMarkup()}
        {$crudMarkup}

        <section class="min-w-0 space-y-4">
            {$this->dataTableMarkup($linkColor)}
            {$this->promoMarkup($promoGradient, $promoShadow, $promoButtonBg, $promoButtonText)}
        </section>
    </div>
HTML;
    }

    private function crudPanelMarkup(string $linkColor): string
    {
        return <<<HTML
<article id="crud-panel" class="rounded-2xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
    <div class="mb-3 flex items-center justify-between gap-3">
        <div>
            <h2 class="text-sm font-bold">Basic CRUD</h2>
            <p class="text-xs text-slate-500">Create, update and delete from a focused modal form.</p>
        </div>
        <span class="text-xs font-semibold {$linkColor}">Entity aware</span>
    </div>

    <div class="flex items-center gap-2">
        <button id="crud-open-modal" type="button" class="rounded-lg bg-cyan-600 px-4 py-2 text-xs font-bold text-white hover:bg-cyan-700 transition">New record</button>
    </div>
</article>

<div id="crud-modal" class="fixed inset-0 z-50 hidden p-4" style="background-color: rgba(15, 23, 42, 0.64);">
    <div class="flex h-full w-full items-center justify-center">
        <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white p-5 shadow-2xl shadow-slate-900/30 sm:p-6 flex flex-col" style="width:min(92vw,32rem);height:min(92vw,32rem);max-height:86vh;background-color:rgba(255,255,255,0.98);color:#0f172a;">
        <div class="mb-3 flex items-center justify-between">
            <h3 id="crud-modal-title" class="text-base font-black text-slate-800">Create</h3>
            <button id="crud-close-modal" type="button" class="rounded-lg bg-slate-200 px-2.5 py-1 text-xs font-bold text-slate-700 hover:bg-slate-300 transition">✕</button>
        </div>

        <form id="crud-form" class="flex flex-col flex-1 space-y-3">
            <div id="crud-modal-loading" class="hidden items-center gap-2 rounded-lg bg-cyan-50 px-3 py-2 text-xs font-semibold text-cyan-700">
                <svg class="h-4 w-4 shrink-0 text-cyan-600" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-dasharray="42 18" opacity="0.9">
                        <animateTransform attributeName="transform" type="rotate" from="0 12 12" to="360 12 12" dur="0.8s" repeatCount="indefinite" />
                    </circle>
                </svg>
                <span id="crud-modal-loading-text">Loading...</span>
            </div>
            <div class="flex-1 overflow-y-auto pr-1.5">
                <div id="crud-dynamic-fields" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <p class="text-xs text-slate-700">Loading fields...</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2 pt-2 border-t border-slate-200">
                <button type="submit" class="h-10 flex-1 rounded-lg bg-cyan-600 px-4 text-sm font-bold text-white hover:bg-cyan-700 transition">
                    <span id="crud-submit-label">Create</span>
                </button>
                <button id="crud-close-modal-secondary" type="button" class="h-10 rounded-lg bg-slate-200 px-4 text-sm font-bold text-slate-700 hover:bg-slate-300 transition">Cancel</button>
            </div>
        </form>
        </div>
    </div>
</div>
HTML;
    }

    private function entityStripMarkup(array $entityNames, string $linkColor): string
    {
        if (count($entityNames) === 0) {
            return '';
        }

        $pills = '';
        foreach ($entityNames as $name) {
            $safe = e((string) $name);
            $pills .= <<<HTML
        <span class="rounded-full border border-slate-200 bg-white/80 px-3 py-1 text-[11px] font-semibold text-slate-700">{$safe}</span>

HTML;
        }

        return <<<HTML
    <section class="mb-4 rounded-2xl border border-white/70 bg-white/85 p-3 shadow-sm shadow-slate-900/5">
        <div class="mb-2 flex items-center justify-between">
            <h2 class="text-xs font-bold uppercase tracking-wide text-slate-500">Detected Entities</h2>
            <span class="text-xs font-semibold {$linkColor}">From migrations</span>
        </div>
        <div class="flex flex-wrap gap-2">
{$pills}        </div>
    </section>
HTML;
    }

    private function summaryCardsMarkup(): string
    {
        return <<<HTML
<section class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
    <article class="h-36 rounded-xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
        <p class="text-[10px] uppercase text-slate-500">Records</p>
        <p id="stat-records" class="text-3xl font-black">0</p>
    </article>
    <article class="h-36 rounded-xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
        <p class="text-[10px] uppercase text-slate-500">Fields</p>
        <p id="stat-fields" class="text-3xl font-black">0</p>
    </article>
    <article class="h-36 rounded-xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
        <p class="text-[10px] uppercase text-slate-500">Numeric Fields</p>
        <p id="stat-numeric" class="text-3xl font-black">0</p>
    </article>
    <article class="h-36 rounded-xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
        <p class="text-[10px] uppercase text-slate-500">Endpoint</p>
        <p id="stat-endpoint" class="truncate text-sm font-bold text-slate-700">N/A</p>
    </article>
</section>
HTML;
    }

    private function keyValuesMarkup(string $linkColor): string
    {
        return <<<HTML
<article class="min-w-0 rounded-2xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="text-sm font-bold">Record Preview</h2>
        <a href="#" class="text-xs font-semibold {$linkColor}">First record</a>
    </div>
    <div id="kv-grid" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3"></div>
</article>
HTML;
    }

    private function dataTableMarkup(string $linkColor): string
    {
        return <<<HTML
<article class="rounded-2xl border border-white/70 bg-white/85 p-4 shadow-sm shadow-slate-900/5">
    <div class="mb-2 flex items-center justify-between">
        <h2 class="text-sm font-bold">All Records</h2>
        <a href="#" id="record-count" class="text-xs font-semibold {$linkColor}"></a>
    </div>

    <div class="max-w-full overflow-x-auto">
        <table class="w-max min-w-max text-left text-sm">
            <thead id="data-columns" class="text-[9px] uppercase tracking-wide text-slate-500"></thead>
            <tbody id="data-rows" class="text-sm font-medium text-slate-700"></tbody>
        </table>
    </div>
</article>
HTML;
    }

    private function promoMarkup(string $promoGradient, string $promoShadow, string $promoButtonBg, string $promoButtonText): string
    {
        return <<<HTML
<article class="rounded-2xl bg-gradient-to-br {$promoGradient} p-5 text-white shadow-2xl {$promoShadow}">
    <p class="text-[11px] font-semibold tracking-widest text-cyan-100">SMART NOTE</p>
    <h3 class="mt-2 max-w-[18ch] text-3xl font-black leading-tight">Dashboard adapts automatically to your API schema.</h3>
    <a href="#" class="mt-4 inline-flex rounded-full {$promoButtonBg} px-4 py-2 text-xs font-bold {$promoButtonText}">Refresh source</a>
</article>
HTML;
    }

    private function getCrudController(): string
    {
        return <<<'PHP'
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminDashboardCrudController extends Controller
{
    public function schema(string $entity)
    {
        $table = $this->resolveTable($entity);

        if ($table === null) {
            return response()->json(['message' => 'Entity table not found'], 404);
        }

        $metadata = $this->columnMetadata($table);

        $columns = array_map(function ($column) use ($table, $metadata) {
            $relatedTable = $this->relatedTableFromForeignKey($column);
            $isForeignKey = $relatedTable !== null;
            $columnMeta = $metadata[$column] ?? [];
            $required = array_key_exists('nullable', $columnMeta)
                ? ! (bool) $columnMeta['nullable']
                : ! in_array($column, ['created_at', 'updated_at', 'deleted_at'], true);
            $type = $this->columnType($table, $column);

            return [
                'name' => $column,
                'type' => $type,
                'required' => $required,
                'is_foreign_key' => $isForeignKey,
                'related_table' => $relatedTable,
                'options' => $isForeignKey ? $this->foreignKeyOptions($relatedTable) : [],
                'input_type' => $isForeignKey ? 'select' : $this->inferInputType($column, $type),
                'placeholder' => $this->placeholderFor($column, $type, $isForeignKey ? 'select' : $this->inferInputType($column, $type)),
                'validation' => $this->fieldValidationRule($table, $column, $columnMeta, $isForeignKey),
                'relationship' => $isForeignKey ? $this->fieldRelationship($column) : null,
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
        $table = $this->resolveTable($entity);

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
        $table = $this->resolveTable($entity);

        if ($table === null) {
            return response()->json(['message' => 'Entity table not found'], 404);
        }

        $request->validate($this->validationRules($table));

        $payload = $this->payloadForTable($request, $table);
        if ($payload === []) {
            return response()->json([
                'message' => 'No valid columns in payload for this entity',
                'allowed' => $this->editableColumns($table),
            ], 422);
        }

        $foreignKeyError = $this->validateForeignKeys($table, $payload);
        if ($foreignKeyError !== null) {
            return response()->json(['message' => $foreignKeyError], 422);
        }

        $id = DB::table($table)->insertGetId($payload);
        $record = DB::table($table)->where('id', $id)->first();

        return response()->json($record, 201);
    }

    public function update(Request $request, string $entity, int $id)
    {
        $table = $this->resolveTable($entity);

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

        $payload = $this->payloadForTable($request, $table);
        if ($payload === []) {
            return response()->json([
                'message' => 'No valid columns in payload for this entity',
                'allowed' => $this->editableColumns($table),
            ], 422);
        }

        $foreignKeyError = $this->validateForeignKeys($table, $payload);
        if ($foreignKeyError !== null) {
            return response()->json(['message' => $foreignKeyError], 422);
        }

        DB::table($table)->where('id', $id)->update($payload);

        return response()->json(DB::table($table)->where('id', $id)->first());
    }

    public function destroy(string $entity, int $id)
    {
        $table = $this->resolveTable($entity);

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

    private function resolveTable(string $entity): ?string
    {
        $table = preg_replace('/[^a-z0-9_]+/i', '', strtolower(trim($entity)));

        if ($table === null || $table === '') {
            return null;
        }

        if (! Schema::hasTable($table)) {
            return null;
        }

        return $table;
    }

    private function editableColumns(string $table): array
    {
        $columns = Schema::getColumnListing($table);

        return array_values(array_filter($columns, function ($column) {
            return ! in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'], true);
        }));
    }

    private function payloadForTable(Request $request, string $table): array
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

    private function validateForeignKeys(string $table, array $payload): ?string
    {
        foreach ($payload as $column => $value) {
            $relatedTable = $this->relatedTableFromForeignKey((string) $column);
            if ($relatedTable === null || $value === null || $value === '') {
                continue;
            }

            if (! is_numeric($value)) {
                return "Invalid foreign key value for {$column}.";
            }

            $exists = DB::table($relatedTable)->where('id', (int) $value)->exists();
            if (! $exists) {
                return "Foreign key {$column} references missing record in {$relatedTable}.";
            }
        }

        return null;
    }

    private function relatedTableFromForeignKey(string $column): ?string
    {
        if (! Str::endsWith($column, '_id')) {
            return null;
        }

        $base = Str::beforeLast($column, '_id');
        $table = Str::plural($base);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return null;
        }

        return $table;
    }

    private function foreignKeyOptions(string $table): array
    {
        $labelColumn = collect(['name', 'title', 'full_name', 'code', 'email'])
            ->first(function ($candidate) use ($table) {
                return Schema::hasColumn($table, $candidate);
            }) ?? 'id';

        return DB::table($table)
            ->select(['id', $labelColumn])
            ->limit(200)
            ->get()
            ->map(function ($row) use ($labelColumn) {
                return [
                    'value' => (string) $row->id,
                    'label' => (string) ($row->{$labelColumn} ?? $row->id),
                ];
            })
            ->values()
            ->all();
    }

    private function columnType(string $table, string $column): string
    {
        try {
            return (string) Schema::getColumnType($table, $column);
        } catch (\Throwable $e) {
            return 'string';
        }
    }

    private function columnMetadata(string $table): array
    {
        try {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'mysql') {
                return [];
            }

            $database = $connection->getDatabaseName();
            $rows = DB::select(
                'SELECT COLUMN_NAME, IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
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
                ];
            }

            return $result;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** @return array<string, string> */
    private function validationRules(string $table, bool $forUpdate = false, ?int $ignoreId = null): array
    {
        $metadata = $this->columnMetadata($table);
        $rules = [];

        foreach (Schema::getColumnListing($table) as $column) {
            $isForeignKey = $this->relatedTableFromForeignKey($column) !== null;
            $rule = $this->fieldValidationRule($table, $column, $metadata[$column] ?? [], $isForeignKey, $forUpdate, $ignoreId);
            if ($rule !== '') {
                $rules[$column] = $rule;
            }
        }

        return $rules;
    }

    private function fieldValidationRule(string $table, string $column, array $columnMeta, bool $isForeignKey, bool $forUpdate = false, ?int $ignoreId = null): string
    {
        if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            return '';
        }

        $rules = [];
        if ($forUpdate) {
            $rules[] = 'sometimes';
        }

        $nullable = (bool) ($columnMeta['nullable'] ?? false);
        $rules[] = $nullable ? 'nullable' : 'required';

        $type = strtolower($this->columnType($table, $column));
        if ($isForeignKey) {
            $related = $this->relatedTableFromForeignKey($column);
            $rules[] = 'integer';
            if ($related !== null) {
                $rules[] = "exists:{$related},id";
            }
        } elseif (in_array($type, ['integer', 'bigint', 'smallint', 'tinyint'], true)) {
            $rules[] = 'integer';
        } elseif (in_array($type, ['decimal', 'float', 'double'], true)) {
            $rules[] = 'numeric';
        } elseif (in_array($type, ['boolean', 'bool'], true)) {
            $rules[] = 'boolean';
        } elseif (in_array($type, ['date', 'datetime', 'timestamp'], true)) {
            $rules[] = 'date';
        } else {
            $rules[] = 'string';
            if (in_array($type, ['string', 'char', 'varchar'], true)) {
                $rules[] = 'max:255';
            }
        }

        if (str_contains(strtolower($column), 'email')) {
            $rules[] = 'email';
            $rules[] = $forUpdate && $ignoreId !== null
                ? "unique:{$table},{$column},{$ignoreId},id"
                : "unique:{$table},{$column}";
        }

        return implode('|', array_values(array_unique($rules)));
    }

    /** @return array<string, string> */
    private function fieldRelationship(string $column): array
    {
        $base = Str::beforeLast($column, '_id');
        $relatedTable = Str::plural($base);
        $modelClass = Str::studly(Str::singular($relatedTable));

        return [
            'type' => 'belongsTo',
            'method' => Str::camel($base),
            'foreign_key' => $column,
            'related_table' => $relatedTable,
            'related_model' => "App\\Models\\{$modelClass}",
            'eloquent' => "public function ".Str::camel($base)."() { return \$this->belongsTo(\\App\\Models\\{$modelClass}::class, '{$column}'); }",
        ];
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

        if (str_contains($name, 'description') || str_contains($name, 'content') || str_contains($name, 'notes')) {
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

        if ($inputType === 'date') {
            return 'Pick '.$label;
        }

        if ($inputType === 'datetime-local') {
            return 'Pick '.$label.' date and time';
        }

        if ($inputType === 'email') {
            return 'example@domain.com';
        }

        if (str_contains(strtolower($columnType), 'text') || $inputType === 'textarea') {
            return 'Enter '.$label;
        }

        return 'Enter '.$label;
    }
}
PHP;
    }
}

