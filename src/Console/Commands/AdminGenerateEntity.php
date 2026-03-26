<?php

namespace Elmekadem\ArchitectorAdmin\Console\Commands;

use Illuminate\Console\Command;

class AdminGenerateEntity extends Command
{
    protected $signature = 'admin:generate-entity
                            {--table= : Specific table to generate for}
                            {--all : Generate for all tables}';

    protected $description = 'Generate Models, Controllers, and API routes for database tables (will be fully populated when package is completed)';

    public function handle(): int
    {
        $this->error('Command files are being prepared. Please copy command files from the Architector project.');
        $this->line('See ArchitectorPackage/INSTALLATION.md for setup instructions.');
        
        return self::FAILURE;
    }
}
