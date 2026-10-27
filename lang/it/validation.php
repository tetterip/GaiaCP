<?php

// Italian texts of Laravel's validation messages for the rules GaiaCP's forms use; any other rule falls back to English.
// :attribute is the field's label as the form shows it.

return [
    'accepted' => 'Il campo «:attribute» deve essere accettato.',
    'confirmed' => 'La conferma del campo «:attribute» non corrisponde.',
    'current_password' => 'La password non è corretta.',
    'date' => 'Il campo «:attribute» deve essere una data valida.',
    'different' => 'I campi «:attribute» e «:other» devono essere diversi.',
    'email' => 'Il campo «:attribute» deve essere un indirizzo email valido.',
    'exists' => 'La scelta nel campo «:attribute» non è valida.',
    'in' => 'La scelta nel campo «:attribute» non è valida.',
    'integer' => 'Il campo «:attribute» deve essere un numero intero.',
    'max' => [
        'numeric' => 'Il campo «:attribute» non può essere maggiore di :max.',
        'string' => 'Il campo «:attribute» non può superare :max caratteri.',
        'array' => 'Il campo «:attribute» non può avere più di :max elementi.',
        'file' => 'Il campo «:attribute» non può superare :max kilobyte.',
    ],
    'min' => [
        'numeric' => 'Il campo «:attribute» deve essere almeno :min.',
        'string' => 'Il campo «:attribute» deve avere almeno :min caratteri.',
        'array' => 'Il campo «:attribute» deve avere almeno :min elementi.',
        'file' => 'Il campo «:attribute» deve essere almeno :min kilobyte.',
    ],
    'numeric' => 'Il campo «:attribute» deve essere un numero.',
    'password' => [
        'letters' => 'Il campo «:attribute» deve contenere almeno una lettera.',
        'mixed' => 'Il campo «:attribute» deve contenere almeno una lettera maiuscola e una minuscola.',
        'numbers' => 'Il campo «:attribute» deve contenere almeno un numero.',
        'symbols' => 'Il campo «:attribute» deve contenere almeno un simbolo.',
        'uncompromised' => 'Questa password è comparsa in una fuga di dati. Ne scelga un\'altra.',
    ],
    'regex' => 'Il formato del campo «:attribute» non è valido.',
    'required' => 'Il campo «:attribute» è obbligatorio.',
    'required_if' => 'Il campo «:attribute» è obbligatorio quando «:other» è :value.',
    'same' => 'I campi «:attribute» e «:other» devono corrispondere.',
    'string' => 'Il campo «:attribute» deve essere un testo.',
    'unique' => 'Questo valore del campo «:attribute» è già in uso.',
    'url' => 'Il campo «:attribute» deve essere un URL valido.',
];
