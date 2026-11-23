<?php

namespace App\Console\Commands;

use App\Services\Visor3d\PanoramaService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class Visor3dCleanOrphans extends Command
{
    protected $signature = 'visor3d:limpiar-huerfanos {--dry-run : Solo lista, no borra}';

    protected $description = 'Borra fotos 360° de storage/app/public/visor3d que ninguna fila usa (ahorra disco e inodos)';

    public function handle(PanoramaService $service): int
    {
        $files = $service->orphanFiles();

        if ($files->isEmpty()) {
            $this->info('No hay archivos huérfanos.');
            return self::SUCCESS;
        }

        $files->each(fn ($f) => $this->line($f));

        if ($this->option('dry-run')) {
            $this->warn("{$files->count()} archivo(s) se borrarían (dry-run).");
            return self::SUCCESS;
        }

        Storage::disk(PanoramaService::DISK)->delete($files->all());
        $this->info("{$files->count()} archivo(s) borrados.");

        return self::SUCCESS;
    }
}
