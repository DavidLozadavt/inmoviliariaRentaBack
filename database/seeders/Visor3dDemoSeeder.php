<?php

namespace Database\Seeders;

use App\Models\Building;
use App\Models\Contract;
use App\Models\Maintenance;
use App\Models\Panorama;
use App\Models\Payment;
use App\Models\Property;
use App\Models\UnitType;
use App\Models\User;
use App\Services\Visor3d\PanoramaService;
use App\Services\Visor3d\UnitGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Datos de demostración del Visor 3D de edificios.
 *
 *   php artisan db:seed --class=Visor3dDemoSeeder
 *
 * - Arrendador demo (visor3d.demo@test.local) e inquilinos demo.
 * - Dos edificios: una torre de venta (22 pisos, áreas sociales arriba) y un conjunto de arriendo (3 torres).
 * - Unidades con estados variados, contratos con mora, PQRS abiertas.
 * - Fotos 360° por tipo de unidad (database/seeders/visor3d) con puntos de paso entre espacios.
 * - Enlace público de cada edificio (se imprime al final).
 *
 * Idempotente: borra y recrea SOLO los edificios demo de ese arrendador (por nombre), sus unidades,
 * contratos, pagos, PQRS y fotos. No toca nada más. No corre en producción.
 */
class Visor3dDemoSeeder extends Seeder
{
    private const OWNER_EMAIL = 'visor3d.demo@test.local';
    private const PASSWORD    = 'Visor3d.2026';
    private const ASSETS      = __DIR__ . '/visor3d';

    /** Espacios por tipo de unidad (claves de database/seeders/visor3d/manifest.json). */
    private const SPACES = [
        'A' => ['living', 'comedor', 'cocina', 'habitacion', 'bano'],
        'B' => ['living', 'cocina', 'habitacion', 'bano'],
        'C' => ['living', 'cocina', 'bano'],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Visor3dDemoSeeder no corre en producción.');
            return;
        }

        $owner   = $this->user(self::OWNER_EMAIL, 'Demo Visor 3D', 'landlord', '900000001');
        $tenants = collect(range(1, 8))->map(fn ($i) => $this->user(
            "inquilino{$i}.visor3d@test.local", "Inquilino Demo {$i}", 'tenant', (string) (900000100 + $i)
        ));

        foreach ($this->buildings() as $def) {
            $this->cleanup($owner, $def['name']);
            $building = $this->createBuilding($owner, $def, $tenants);
            $this->report($building);
        }

        $this->command?->info('Clave de las cuentas demo: ' . self::PASSWORD);
    }

    // ── Definición de los edificios ──────────────────────────────────────

    private function buildings(): array
    {
        $types = [
            ['code' => 'A', 'name' => 'Esquinero 3 alcobas', 'area_m2' => 86, 'bedrooms' => 3, 'bathrooms' => 2, 'color' => '#60a5fa'],
            ['code' => 'B', 'name' => 'Central 2 alcobas', 'area_m2' => 63, 'bedrooms' => 2, 'bathrooms' => 2, 'color' => '#f472b6'],
            ['code' => 'C', 'name' => 'Estudio', 'area_m2' => 42, 'bedrooms' => 1, 'bathrooms' => 1, 'color' => '#34d399'],
        ];

        return [
            [
                'name'    => 'Torre Mirador del Río',
                'slug'    => 'mirador',
                'address' => 'Av. del Río # 45-10',
                'city'    => 'Medellín',
                'mode'    => 'sale',            // venta: vendidos en rojo, sin contratos
                'prices'  => ['A' => 980000000, 'B' => 720000000, 'C' => 455000000],
                'config'  => [
                    'floor_height'   => 3.1,
                    'colors'         => ['facade' => '#e7e1d6'],
                    'towers'         => [
                        ['code' => 'T1', 'name' => 'Torre Mirador', 'floors' => 22, 'units_per_floor' => 6, 'layout' => ['A', 'B', 'C', 'C', 'B', 'A']],
                    ],
                    'special_floors' => [
                        ['tower' => null, 'floor' => -1, 'kind' => 'parking'],
                        ['tower' => null, 'floor' => 0, 'kind' => 'parking'],
                        ['tower' => null, 'floor' => 1, 'kind' => 'lobby'],
                        ['tower' => null, 'floor' => 21, 'kind' => 'amenities'],
                        ['tower' => null, 'floor' => 22, 'kind' => 'amenities'],
                        ['tower' => null, 'floor' => 23, 'kind' => 'terrace'],
                    ],
                ],
                'types'   => $types,
                'split'   => ['sold' => 0.45, 'reserved' => 0.18],
            ],
            [
                'name'    => 'Conjunto Bosques de la Sabana',
                'slug'    => 'bosques',
                'address' => 'Calle 170 # 54-30',
                'city'    => 'Bogotá',
                'mode'    => 'rent',            // arriendo: contratos, cartera y PQRS
                'prices'  => ['A' => 3400000, 'B' => 2600000, 'C' => 1750000],
                'config'  => [
                    'floor_height'   => 2.9,
                    'colors'         => ['facade' => '#d9d4cb'],
                    'towers'         => [
                        ['code' => 'T1', 'name' => 'Torre 1', 'floors' => 9, 'units_per_floor' => 4, 'layout' => ['A', 'B', 'B', 'A']],
                        ['code' => 'T2', 'name' => 'Torre 2', 'floors' => 9, 'units_per_floor' => 4, 'layout' => ['A', 'C', 'C', 'A']],
                        ['code' => 'T3', 'name' => 'Torre 3', 'floors' => 7, 'units_per_floor' => 6, 'layout' => ['B', 'C', 'C', 'C', 'C', 'B']],
                    ],
                    'special_floors' => [
                        ['tower' => null, 'floor' => 0, 'kind' => 'parking'],
                        ['tower' => null, 'floor' => 1, 'kind' => 'lobby'],
                        ['tower' => 'T3', 'floor' => 8, 'kind' => 'terrace'],
                    ],
                ],
                'types'   => $types,
                'split'   => ['rented' => 0.55, 'reserved' => 0.12],
            ],
        ];
    }

    // ── Construcción ─────────────────────────────────────────────────────

    private function createBuilding(User $owner, array $def, $tenants): Building
    {
        mt_srand(crc32($def['name'])); // mismos estados en cada corrida

        $building = Building::create([
            'user_id' => $owner->id,
            'name'    => $def['name'],
            'address' => $def['address'],
            'city'    => $def['city'],
            'config'  => $def['config'],
        ]);
        foreach ($def['types'] as $t) {
            $building->unitTypes()->create($t);
        }

        app(UnitGenerator::class)->generate($building, $def['prices']);

        // Las unidades demo sí se publican (aparecen en el listado de NexiLiving)
        $building->units()->update(['visibility' => 'published', 'approval_status' => 'approved']);

        DB::transaction(function () use ($building, $def, $tenants) {
            $tenantIdx = 0;
            foreach ($building->units()->orderBy('tower')->orderBy('floor')->orderBy('unit_code')->get() as $unit) {
                $r = mt_rand() / mt_getrandmax();
                // Pisos altos se venden/arriendan primero (más realista en el visor)
                $r -= $unit->floor * 0.006;

                if ($def['mode'] === 'sale') {
                    $status = $r < $def['split']['sold'] ? 'sold' : ($r < $def['split']['sold'] + $def['split']['reserved'] ? 'reserved' : 'available');
                    $unit->update(['commercial_status' => $status, 'status' => $status]);
                    continue;
                }

                $status = $r < $def['split']['rented'] ? 'rented' : ($r < $def['split']['rented'] + $def['split']['reserved'] ? 'reserved' : 'available');
                $unit->update(['commercial_status' => $status, 'status' => $status]);

                if ($status === 'rented') {
                    $tenant = $tenants[$tenantIdx++ % $tenants->count()];
                    $this->contractWithArrears($unit, $tenant, $building->user_id);
                }

                // PQRS abiertas en ~10 % de las unidades
                if (mt_rand(1, 100) <= 10) {
                    Maintenance::create([
                        'property_id'  => $unit->id,
                        'user_id'      => $tenants[mt_rand(0, $tenants->count() - 1)]->id,
                        'title'        => collect(['Fuga en el baño', 'Humedad en la alcoba', 'Citófono dañado', 'Puerta del balcón'])->random(),
                        'description'  => 'Solicitud de demostración del visor 3D.',
                        'priority'     => collect(['low', 'medium', 'high'])->random(),
                        'status'       => mt_rand(0, 1) ? 'pending' : 'in_progress',
                        'request_date' => now()->subDays(mt_rand(1, 20))->toDateString(),
                    ]);
                }
            }
        });

        $this->panoramas($building);
        $this->photos($building, $def['slug']);

        // Previsualización de la tarjeta: render del modelo 3D
        $preview = PanoramaService::DIR . "/{$building->id}/preview-demo.webp";
        Storage::disk(PanoramaService::DISK)->put($preview, file_get_contents(self::ASSETS . "/photos/{$def['slug']}-exterior.webp"));
        $building->forceFill(['preview_path' => $preview])->save();

        $building->forceFill(['public_token' => Str::random(40), 'public_expires_at' => null])->save();

        return $building->fresh();
    }

    /** 70 % al día, 20 % mora < 60 días, 10 % mora > 60 días. */
    private function contractWithArrears(Property $unit, User $tenant, int $landlordId): void
    {
        $contract = Contract::create([
            'property_id'          => $unit->id,
            'landlord_id'          => $landlordId,
            'tenant_id'            => $tenant->id,
            'start_date'           => now()->subMonths(mt_rand(2, 18))->toDateString(),
            'end_date'             => now()->addMonths(mt_rand(3, 12))->toDateString(),
            'status'               => 'active',
            'deposit'              => $unit->monthly_price,
            'validated_by_support' => 'yes',
            'accepted_by_tenant'   => 'yes',
        ]);

        $r = mt_rand(1, 100);
        [$status, $days] = $r <= 70 ? ['paid', mt_rand(3, 25)] : ($r <= 90 ? ['pending', mt_rand(5, 55)] : ['pending', mt_rand(65, 120)]);

        Payment::create([
            'contract_id'    => $contract->id,
            'payment_date'   => now()->subDays($days)->toDateString(),
            'amount'         => (string) $unit->monthly_price,
            'status'         => $status,
            'payment_method' => 'transfer',
            'receipt_path'   => '',
        ]);
    }

    /** Copia las fotos 360° de cada tipo y crea los puntos de paso del manifest. */
    private function panoramas(Building $building): void
    {
        $manifest = json_decode(file_get_contents(self::ASSETS . '/manifest.json'), true);
        $disk     = Storage::disk(PanoramaService::DISK);
        $dir      = PanoramaService::DIR . '/' . $building->id;

        foreach ($building->unitTypes as $type) {
            $created = [];
            foreach (self::SPACES[$type->code] ?? [] as $order => $key) {
                $name = Str::random(32);
                $disk->put("$dir/$name.webp", file_get_contents(self::ASSETS . "/$key.webp"));
                $disk->put("$dir/$name-t.webp", file_get_contents(self::ASSETS . "/$key-t.webp"));
                [$w] = getimagesize(self::ASSETS . "/$key.webp");

                $created[$key] = $type->panoramas()->create([
                    'space'      => $manifest[$key]['space'],
                    'kind'       => $key === 'living' ? 'sala' : $key,
                    'projection' => 'equirect',
                    'path'       => "$dir/$name.webp",
                    'thumb_path' => "$dir/$name-t.webp",
                    'width'      => $w,
                    'size_kb'    => (int) ceil(filesize(self::ASSETS . "/$key.webp") / 1024),
                    'is_start'   => $order === 0,
                    'order'      => $order,
                ]);
            }

            // Solo enlaces a espacios que este tipo tiene
            foreach ($created as $key => $pano) {
                foreach ($manifest[$key]['links'] as $link) {
                    if (isset($created[$link['target']])) {
                        $pano->hotspots()->create([
                            'target_panorama_id' => $created[$link['target']]->id,
                            'yaw'   => $link['yaw'],
                            'pitch' => $link['pitch'],
                            'label' => $link['label'],
                        ]);
                    }
                }
            }
        }
    }

    /**
     * Fotos del listado y del detalle de cada unidad: fachada y planta del edificio (renders del visor 3D)
     * y vistas del interior según el tipo. Se copian una vez por edificio y las unidades las comparten.
     */
    private function photos(Building $building, string $slug): void
    {
        $disk = Storage::disk('public');
        $dir  = "properties/visor3d-demo/{$building->id}";
        $src  = self::ASSETS . '/photos';

        $files = ["{$slug}-exterior", "{$slug}-planta", 'living', 'comedor', 'cocina', 'habitacion', 'bano'];
        foreach ($files as $f) {
            $disk->put("$dir/$f.webp", file_get_contents("$src/$f.webp"));
        }

        $types = $building->unitTypes->keyBy('id');
        $rows  = [];
        $now   = now();
        foreach ($building->units()->get(['id', 'unit_type_id']) as $unit) {
            $code   = $types[$unit->unit_type_id]->code ?? 'C';
            $spaces = array_intersect(['living', 'comedor', 'cocina', 'habitacion', 'bano'], self::SPACES[$code] ?? []);
            $list   = array_merge(["{$slug}-exterior"], array_values($spaces), ["{$slug}-planta"]);
            foreach ($list as $order => $f) {
                $rows[] = ['property_id' => $unit->id, 'path' => "$dir/$f.webp", 'is_main' => $order === 0, 'order' => $order,
                    'created_at' => $now, 'updated_at' => $now];
            }
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('property_images')->insert($chunk);
        }
    }

    // ── Utilidades ───────────────────────────────────────────────────────

    private function user(string $email, string $name, string $role, string $document): User
    {
        $u = User::firstOrNew(['email' => $email]);
        $u->forceFill([
            'name'              => $name,
            'role'              => $role,
            'status'            => 'active',
            'password'          => Hash::make(self::PASSWORD),
            'phone'             => '+57300' . substr($document, -7),
            'id_documento'      => $document,
            'address'           => $u->address ?: 'Dirección demo',
            'email_verified_at' => now(),
        ])->save();

        return $u;
    }

    /** Borra el edificio demo previo (mismo nombre y dueño) con todo lo que el seeder creó. */
    private function cleanup(User $owner, string $name): void
    {
        $old = Building::withTrashed()->where('user_id', $owner->id)->where('name', $name)->get();

        foreach ($old as $b) {
            $unitIds = Property::withTrashed()->where('building_id', $b->id)->pluck('id');
            $typeIds = UnitType::where('building_id', $b->id)->pluck('id');

            DB::transaction(function () use ($b, $unitIds, $typeIds) {
                $contractIds = Contract::whereIn('property_id', $unitIds)->pluck('id');
                Payment::whereIn('contract_id', $contractIds)->delete();
                Contract::whereIn('id', $contractIds)->delete();
                Maintenance::whereIn('property_id', $unitIds)->delete();
                Panorama::where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('owner_type', UnitType::class)->whereIn('owner_id', $typeIds))
                    ->orWhere(fn ($q) => $q->where('owner_type', Property::class)->whereIn('owner_id', $unitIds)))
                    ->delete();
                DB::table('property_images')->whereIn('property_id', $unitIds)->delete();
                Property::withTrashed()->whereIn('id', $unitIds)->forceDelete();
                $b->forceDelete();
            });

            Storage::disk(PanoramaService::DISK)->deleteDirectory(PanoramaService::DIR . '/' . $b->id);
            Storage::disk('public')->deleteDirectory("properties/visor3d-demo/{$b->id}");
        }
    }

    private function report(Building $b): void
    {
        $counts = $b->units()->selectRaw('commercial_status, count(*) c')->groupBy('commercial_status')->pluck('c', 'commercial_status');
        $query  = parse_url(URL::signedRoute('visor3d.public', ['token' => $b->public_token], null, false), PHP_URL_QUERY);
        $front  = rtrim(config('app.frontend_url'), '/');

        $this->command?->info("{$b->name} (id {$b->id}): " . $counts->map(fn ($c, $s) => "$s $c")->join(', '));
        $this->command?->line("  Privado: {$front}/edificios/{$b->id}");
        $this->command?->line("  Público: {$front}/visor/{$b->public_token}?{$query}");
    }
}
