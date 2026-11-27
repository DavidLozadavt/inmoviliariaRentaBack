<?php

namespace App\Http\Requests\Visor3d;

use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Edificio que envía el ERP (NexiLiving Business): mismas reglas de estructura que
 * BuildingRequest, más el dueño, la referencia del proyecto y el estado de cada unidad.
 */
class ErpEdificioRequest extends BuildingRequest
{
    public function authorize(): bool
    {
        $token = $this->header('X-Bridge-Token');
        return ! empty($token) && hash_equals((string) config('services.bridge.token'), (string) $token);
    }

    protected function failedAuthorization()
    {
        throw new HttpResponseException(response()->json(['error' => 'No autorizado. Token de seguridad inválido o ausente.'], 401));
    }

    public function rules(): array
    {
        return parent::rules() + [
            'erp_ref'                 => ['required', 'string', 'max:80'],
            'propietario.email'       => ['required', 'email', 'max:255'],
            'propietario.nombre'      => ['required', 'string', 'max:255'],
            'propietario.id_documento' => ['nullable', 'string', 'max:45'],
            'unidades'                => ['present', 'array', 'max:5000'],
            'unidades.*.tower'        => ['required', 'string', 'max:20'],
            'unidades.*.unit_code'    => ['required', 'string', 'max:20'],
            'unidades.*.estado'       => ['required', 'in:disponible,reservado,vendido'],
            'unidades.*.precio'       => ['nullable', 'numeric', 'min:0'],
            'unidades.*.publicado'    => ['boolean'],
            // Foto de portada del proyecto en el ERP (base64, ~10 MB de imagen como máximo).
            'cover'                   => ['nullable', 'array'],
            'cover.nombre'            => ['required_with:cover', 'string', 'max:255'],
            'cover.contenido'         => ['required_with:cover', 'string', 'max:14000000'],
        ];
    }
}
