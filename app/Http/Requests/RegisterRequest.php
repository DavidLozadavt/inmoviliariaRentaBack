<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => 'required|string|max:100|min:2',
            'phone'        => 'required|string|regex:/^[0-9]{10,20}$/|unique:users,phone',
            'email'        => 'required|email|max:255|unique:users,email',
            'password'     => 'required|string|min:8',
            'address'      => 'required|string|max:255|min:5',
            'id_documento' => 'required|string|max:50|unique:users,id_documento',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'         => 'El nombre es obligatorio',
            'name.min'              => 'El nombre debe tener al menos 2 caracteres',
            'phone.required'        => 'El teléfono es obligatorio',
            'phone.regex'           => 'El teléfono debe contener entre 10 y 20 dígitos',
            'phone.unique'          => 'Este teléfono ya está registrado',
            'email.required'        => 'El correo electrónico es obligatorio',
            'email.email'           => 'Debe ingresar un correo válido',
            'email.unique'          => 'Este correo ya está registrado',
            'password.required'     => 'La contraseña es obligatoria',
            'password.min'          => 'La contraseña debe tener al menos 8 caracteres',
            'address.required'      => 'La dirección es obligatoria',
            'address.min'           => 'La dirección debe tener al menos 5 caracteres',
            'id_documento.required' => 'El documento de identidad es obligatorio',
            'id_documento.unique'   => 'Este documento ya está registrado',
        ];
    }
}
