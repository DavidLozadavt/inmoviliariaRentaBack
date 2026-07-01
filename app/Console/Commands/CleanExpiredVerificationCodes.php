<?php

namespace App\Console\Commands;

use App\Services\VerificationCodeService;
use Illuminate\Console\Command;

class CleanExpiredVerificationCodes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'verification:cleanup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Limpiar códigos de verificación expirados o usados';

    protected $verificationService;

    /**
     * Create a new command instance.
     */
    public function __construct(VerificationCodeService $verificationService)
    {
        parent::__construct();
        $this->verificationService = $verificationService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Iniciando limpieza de códigos de verificación...');

        $deletedCount = $this->verificationService->cleanupExpiredCodes();

        $this->info("✓ Limpieza completada: {$deletedCount} códigos eliminados.");

        return Command::SUCCESS;
    }
}
