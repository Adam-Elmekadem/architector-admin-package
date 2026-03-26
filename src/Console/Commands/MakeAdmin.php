<?php

namespace Elmekadem\ArchitectorAdmin\Console\Commands;

use Illuminate\Console\Command;

class MakeAdmin extends Command
{
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

    protected $description = 'Generate a customizable Tailwind admin dashboard UI (will be fully populated when package is completed)';

    public function handle(): int
    {
        $this->error('Command files are being prepared. Please copy command files from the Architector project.');
        $this->line('See ArchitectorPackage/INSTALLATION.md for setup instructions.');
        
        return self::FAILURE;
    }
}
