<?php

// French texts of Laravel's validation messages for the rules GaiaCP's forms use; any other rule falls back to English.
// :attribute is the field's label as the form shows it.

return [
    'accepted' => 'Vous devez accepter le champ « :attribute ».',
    'confirmed' => 'La confirmation du champ « :attribute » ne correspond pas.',
    'current_password' => 'Le mot de passe n\'est pas correct.',
    'date' => 'Le champ « :attribute » doit être une date valide.',
    'different' => 'Les champs « :attribute » et « :other » doivent être différents.',
    'email' => 'Le champ « :attribute » doit être une adresse email valide.',
    'exists' => 'Le choix du champ « :attribute » n\'est pas valide.',
    'in' => 'Le choix du champ « :attribute » n\'est pas valide.',
    'integer' => 'Le champ « :attribute » doit être un nombre entier.',
    'max' => [
        'numeric' => 'Le champ « :attribute » ne peut pas être supérieur à :max.',
        'string' => 'Le champ « :attribute » ne peut pas dépasser :max caractères.',
        'array' => 'Le champ « :attribute » ne peut pas avoir plus de :max éléments.',
        'file' => 'Le champ « :attribute » ne peut pas dépasser :max kilo-octets.',
    ],
    'min' => [
        'numeric' => 'Le champ « :attribute » doit être au moins :min.',
        'string' => 'Le champ « :attribute » doit contenir au moins :min caractères.',
        'array' => 'Le champ « :attribute » doit avoir au moins :min éléments.',
        'file' => 'Le champ « :attribute » doit faire au moins :min kilo-octets.',
    ],
    'numeric' => 'Le champ « :attribute » doit être un nombre.',
    'password' => [
        'letters' => 'Le champ « :attribute » doit contenir au moins une lettre.',
        'mixed' => 'Le champ « :attribute » doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ « :attribute » doit contenir au moins un chiffre.',
        'symbols' => 'Le champ « :attribute » doit contenir au moins un symbole.',
        'uncompromised' => 'Ce mot de passe est apparu dans une fuite de données. Choisissez-en un autre.',
    ],
    'regex' => 'Le format du champ « :attribute » n\'est pas valide.',
    'required' => 'Le champ « :attribute » est obligatoire.',
    'required_if' => 'Le champ « :attribute » est obligatoire quand « :other » vaut :value.',
    'same' => 'Les champs « :attribute » et « :other » doivent correspondre.',
    'string' => 'Le champ « :attribute » doit être du texte.',
    'unique' => 'Cette valeur du champ « :attribute » est déjà utilisée.',
    'url' => 'Le champ « :attribute » doit être une adresse URL valide.',
];
