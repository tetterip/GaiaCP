<?php

// Spanish texts of Laravel's validation messages for the rules GaiaCP's forms use; any other rule falls back to English.
// :attribute is the field's label as the form shows it.

return [
    'accepted' => 'Debe aceptar el campo «:attribute».',
    'confirmed' => 'La confirmación del campo «:attribute» no coincide.',
    'current_password' => 'La contraseña no es correcta.',
    'date' => 'El campo «:attribute» debe ser una fecha válida.',
    'different' => 'Los campos «:attribute» y «:other» deben ser distintos.',
    'email' => 'El campo «:attribute» debe ser una dirección de email válida.',
    'exists' => 'La opción del campo «:attribute» no es válida.',
    'in' => 'La opción del campo «:attribute» no es válida.',
    'integer' => 'El campo «:attribute» debe ser un número entero.',
    'max' => [
        'numeric' => 'El campo «:attribute» no puede ser mayor que :max.',
        'string' => 'El campo «:attribute» no puede tener más de :max caracteres.',
        'array' => 'El campo «:attribute» no puede tener más de :max elementos.',
        'file' => 'El campo «:attribute» no puede ser mayor que :max kilobytes.',
    ],
    'min' => [
        'numeric' => 'El campo «:attribute» debe ser al menos :min.',
        'string' => 'El campo «:attribute» debe tener al menos :min caracteres.',
        'array' => 'El campo «:attribute» debe tener al menos :min elementos.',
        'file' => 'El campo «:attribute» debe tener al menos :min kilobytes.',
    ],
    'numeric' => 'El campo «:attribute» debe ser un número.',
    'password' => [
        'letters' => 'El campo «:attribute» debe contener al menos una letra.',
        'mixed' => 'El campo «:attribute» debe contener al menos una letra mayúscula y una minúscula.',
        'numbers' => 'El campo «:attribute» debe contener al menos un número.',
        'symbols' => 'El campo «:attribute» debe contener al menos un símbolo.',
        'uncompromised' => 'Esta contraseña ha aparecido en una filtración de datos. Elija otra.',
    ],
    'regex' => 'El formato del campo «:attribute» no es válido.',
    'required' => 'El campo «:attribute» es obligatorio.',
    'required_if' => 'El campo «:attribute» es obligatorio cuando «:other» es :value.',
    'same' => 'Los campos «:attribute» y «:other» deben coincidir.',
    'string' => 'El campo «:attribute» debe ser texto.',
    'unique' => 'Este valor del campo «:attribute» ya está en uso.',
    'url' => 'El campo «:attribute» debe ser una URL válida.',
];
