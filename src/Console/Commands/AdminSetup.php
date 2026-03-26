<?php

namespace Elmekadem\ArchitectorAdmin\Console\Commands;

use Illuminate\Console\Command;

class AdminSetup extends Command
{
    protected $signature = 'admin:setup';

    protected $description = 'Interactive admin setup wizard (will be fully populated when package is completed)';

    public function handle(): int
    {
        $this->error('Command files are being prepared. Please copy command files from the Architector project.');
        $this->line('See ArchitectorPackage/INSTALLATION.md for setup instructions.');
        
        return self::FAILURE;
    }
}
