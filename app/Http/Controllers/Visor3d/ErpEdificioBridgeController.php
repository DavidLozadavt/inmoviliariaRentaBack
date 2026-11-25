<?php

namespace App\Http\Controllers\Visor3d;

use App\Http\Controllers\Controller;
use App\Http\Requests\Visor3d\ErpEdificioRequest;
use App\Models\Building;
use App\Models\Property;
use App\Models\User;
use App\Services\Visor3d\UnitGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Puente ERP → NexiLiving: el proyecto vertical configurado en NexiLiving Business
 * (ERP virtualt) crea o actualiza su edificio 3D aquí. Idempotente por `erp_ref`.
 * El ERP manda la estructura (torres, pisos, tipos) y el estado de cada unidad;
 * fotos, fachadas y recorridos se siguen editando en NexiLiving.
 */
class ErpEdificioBridgeController extends Controller
{
    private const ESTADOS = ['disponible' => 'available', 'reservado' => 'reserved', 'vendido' => 'sold'];

    public function sincronizar(ErpEdificioRequest $request, UnitGenerator $generador): JsonResponse
    {
        $dueno = $this->propietario($request->input('propietario'));

        [$building, $creado] = DB::transaction(function () use ($request, $dueno) {
            $building = Building::where('erp_ref', $request->input('erp_ref'))->first();
            $creado = ! $building;
            $building ??= new Building();
            $building->forceFill([
                'erp_ref' => $request->input('erp_ref'),
                'user_id' => $dueno->id,
                'name'    => $request->input('name'),
                'address' => $request->input('address'),
                'city'    => $request->input('city'),
            ]);
            // Lo que se editó en NexiLiving (fotos por torre, posición en el plano, planos de unidad) se conserva.
            $anterior = $building->config ?? [];
            $config = $request->input('config');
            foreach ($config['towers'] as $i => $t) {
                $previa = collect($anterior['towers'] ?? [])->firstWhere('code', $t['code']) ?? [];
                $config['towers'][$i] = $t + $previa;
            }
            foreach (['site', 'unit_plans', 'colors'] as $k) {
                if (! isset($config[$k]) && isset($anterior[$k])) {
                    $config[$k] = $anterior[$k];
                }
            }
            $building->config = $config;
            $building->config_version = ($building->config_version ?? 0) + 1;
            $building->public_token ??= Str::random(40);
            $building->save();

            $codigos = [];
            foreach ($request->input('unit_types', []) as $t) {
                $building->unitTypes()->updateOrCreate(['code' => $t['code']], $t);
                $codigos[] = $t['code'];
            }
            if ($request->has('unit_types')) {
                $building->unitTypes()->whereNotIn('code', $codigos)->delete();
            }

            return [$building, $creado];
        });

        $generado = $generador->generate($building);

        $sinPosicion = [];
        $unidades = $building->units()->get()->keyBy(fn ($u) => "{$u->tower}|{$u->unit_code}");
        foreach ($request->input('unidades') as $u) {
            $prop = $unidades->get("{$u['tower']}|{$u['unit_code']}");
            if (! $prop) {
                $sinPosicion[] = "{$u['tower']}-{$u['unit_code']}";
                continue;
            }
            $estado = self::ESTADOS[$u['estado']];
            $prop->forceFill([
                'commercial_status' => $estado,
                'status'            => $estado,
                'monthly_price'     => $u['precio'] ?? $prop->monthly_price,
                'visibility'        => ($u['publicado'] ?? false) ? 'published' : 'hidden',
                'approval_status'   => ($u['publicado'] ?? false) ? 'approved' : $prop->approval_status,
            ])->save();
        }
        $building->increment('config_version');

        $enlace = app(PublicLinkController::class)->linkData($building->fresh(), null);

        return response()->json([
            'building_id'  => $building->id,
            'creado'       => $creado,
            'unidades'     => $generado['total_slots'],
            'nuevas'       => $generado['created'],
            'sin_posicion' => $sinPosicion,
            'visor_url'    => $enlace['url'] ?? null,
            'embed_url'    => $enlace['embed_url'] ?? null,
        ], $creado ? 201 : 200);
    }

    /** El dueño es la inmobiliaria: se busca por correo o documento; si no existe se crea sin clave usable. */
    private function propietario(array $p): User
    {
        $usuario = User::where('email', $p['email'])->first()
            ?? (! empty($p['id_documento']) ? User::where('id_documento', $p['id_documento'])->first() : null);
        if ($usuario) {
            if ($usuario->role === 'tenant') {
                $usuario->forceFill(['role' => 'landlord'])->save();
            }
            return $usuario;
        }

        $id = DB::table('users')->insertGetId([
            'name'                => $p['nombre'],
            'email'               => $p['email'],
            'password'            => Hash::make(Str::random(40)), // entra con "recuperar clave" o al sincronizar la de NexiService
            'phone'               => 'sin-telefono-' . uniqid(),
            'id_documento'        => $p['id_documento'] ?? ('erp-' . uniqid()),
            'address'             => 'No especificada',
            'role'                => 'landlord',
            'status'              => 'active',
            'verification_status' => 'verified',
            'email_verified_at'   => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        return User::findOrFail($id);
    }
}
