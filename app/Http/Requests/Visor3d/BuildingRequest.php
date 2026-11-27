<?php

namespace App\Http\Requests\Visor3d;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BuildingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la Policy se revisa en el controlador
    }

    public function rules(): array
    {
        $req = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name'    => [$req, 'string', 'max:150'],
            'address' => ['nullable', 'string', 'max:255'],
            'city'    => ['nullable', 'string', 'max:100'],

            'config'                            => [$req, 'array'],
            'config.floor_height'               => ['required_with:config', 'numeric', 'between:2.2,6'],
            'config.colors'                     => ['nullable', 'array'],
            'config.colors.*'                   => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'config.towers'                     => ['required_with:config', 'array', 'min:1', 'max:20'],
            'config.towers.*.code'              => ['required', 'string', 'max:20', 'distinct'],
            'config.towers.*.name'              => ['nullable', 'string', 'max:60'],
            'config.towers.*.floors'            => ['required', 'integer', 'between:1,80'],
            'config.towers.*.units_per_floor'   => ['required', 'integer', 'between:1,30'],
            'config.towers.*.layout'            => ['nullable', 'array'],
            'config.towers.*.layout.*'          => ['string', 'max:10'],
            'config.towers.*.offset.x'          => ['nullable', 'numeric'],
            'config.towers.*.offset.z'          => ['nullable', 'numeric'],
            'config.towers.*.rotation'          => ['nullable', 'numeric', 'between:-360,360'],
            'config.towers.*.rows'              => ['nullable', 'integer', 'between:1,30'],
            'config.towers.*.unit_width'        => ['nullable', 'numeric', 'between:2,40'],
            'config.towers.*.unit_depth'        => ['nullable', 'numeric', 'between:2,40'],
            'config.towers.*.locked'            => ['nullable', 'boolean'],
            'config.site'                       => ['nullable', 'array'],
            'config.site.width'                 => ['nullable', 'numeric', 'between:10,3000'], // ancho real del plano (m)
            'config.special_floors'             => ['nullable', 'array'],
            'config.special_floors.*.tower'     => ['nullable', 'string', 'max:20'],
            'config.special_floors.*.floor'     => ['required', 'integer', 'between:-10,80'],
            'config.special_floors.*.kind'      => ['required', 'in:lobby,parking,terrace,amenities'],

            'unit_types'             => ['sometimes', 'array', 'max:30'],
            'unit_types.*.code'      => ['required', 'string', 'max:10', 'distinct'],
            'unit_types.*.name'      => ['nullable', 'string', 'max:100'],
            'unit_types.*.area_m2'   => ['nullable', 'numeric', 'min:0'],
            'unit_types.*.bedrooms'  => ['nullable', 'integer', 'between:0,20'],
            'unit_types.*.bathrooms' => ['nullable', 'integer', 'between:0,20'],
            'unit_types.*.color'     => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    /** Cada layout debe tener units_per_floor posiciones y usar tipos declarados. */
    public function after(): array
    {
        return [function (Validator $v) {
            $towers = $this->input('config.towers', []);
            $codes  = collect($this->input('unit_types', []))->pluck('code');

            foreach ($towers as $i => $t) {
                $layout = $t['layout'] ?? null;
                if (! is_array($layout)) {
                    continue;
                }
                if (count($layout) !== (int) ($t['units_per_floor'] ?? 0)) {
                    $v->errors()->add("config.towers.$i.layout", 'El layout debe tener una posición por unidad del piso.');
                }
                if ($this->has('unit_types') && collect($layout)->diff($codes)->isNotEmpty()) {
                    $v->errors()->add("config.towers.$i.layout", 'El layout usa tipos de unidad que no existen.');
                }
            }
        }];
    }

    public function messages(): array
    {
        return [
            'config.towers.*.code.distinct' => 'Hay códigos de torre repetidos.',
            'config.colors.*.regex'         => 'Los colores van en formato #RRGGBB.',
        ];
    }
}
