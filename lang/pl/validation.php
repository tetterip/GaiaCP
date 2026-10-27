<?php

// Polish texts of Laravel's validation messages for the rules GaiaCP's forms use; any other rule falls back to English.
// :attribute is the field's label as the form shows it.

return [
    'accepted' => 'Pole „:attribute” musi zostać zaakceptowane.',
    'confirmed' => 'Potwierdzenie pola „:attribute” nie zgadza się.',
    'current_password' => 'Hasło jest nieprawidłowe.',
    'date' => 'Pole „:attribute” musi być prawidłową datą.',
    'different' => 'Pola „:attribute” i „:other” muszą się różnić.',
    'email' => 'Pole „:attribute” musi być prawidłowym adresem e-mail.',
    'exists' => 'Wybór w polu „:attribute” jest nieprawidłowy.',
    'in' => 'Wybór w polu „:attribute” jest nieprawidłowy.',
    'integer' => 'Pole „:attribute” musi być liczbą całkowitą.',
    'max' => [
        'numeric' => 'Pole „:attribute” nie może być większe niż :max.',
        'string' => 'Pole „:attribute” nie może mieć więcej niż :max znaków.',
        'array' => 'Pole „:attribute” nie może mieć więcej niż :max elementów.',
        'file' => 'Pole „:attribute” nie może być większe niż :max kilobajtów.',
    ],
    'min' => [
        'numeric' => 'Pole „:attribute” musi wynosić co najmniej :min.',
        'string' => 'Pole „:attribute” musi mieć co najmniej :min znaków.',
        'array' => 'Pole „:attribute” musi mieć co najmniej :min elementów.',
        'file' => 'Pole „:attribute” musi mieć co najmniej :min kilobajtów.',
    ],
    'numeric' => 'Pole „:attribute” musi być liczbą.',
    'password' => [
        'letters' => 'Pole „:attribute” musi zawierać co najmniej jedną literę.',
        'mixed' => 'Pole „:attribute” musi zawierać co najmniej jedną wielką i jedną małą literę.',
        'numbers' => 'Pole „:attribute” musi zawierać co najmniej jedną cyfrę.',
        'symbols' => 'Pole „:attribute” musi zawierać co najmniej jeden symbol.',
        'uncompromised' => 'To hasło pojawiło się w wycieku danych. Prosimy wybrać inne.',
    ],
    'regex' => 'Format pola „:attribute” jest nieprawidłowy.',
    'required' => 'Pole „:attribute” jest wymagane.',
    'required_if' => 'Pole „:attribute” jest wymagane, gdy „:other” ma wartość :value.',
    'same' => 'Pola „:attribute” i „:other” muszą być zgodne.',
    'string' => 'Pole „:attribute” musi być tekstem.',
    'unique' => 'Ta wartość pola „:attribute” jest już używana.',
    'url' => 'Pole „:attribute” musi być prawidłowym adresem URL.',
];
