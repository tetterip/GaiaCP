<?php

// Dutch texts of Laravel's validation messages for the rules GaiaCP's forms use; any other rule falls back to English.
// :attribute is the field's label as the form shows it.

return [
    'accepted' => 'U moet het veld ":attribute" accepteren.',
    'confirmed' => 'De bevestiging van het veld ":attribute" komt niet overeen.',
    'current_password' => 'Het wachtwoord is niet juist.',
    'date' => 'Het veld ":attribute" moet een geldige datum zijn.',
    'different' => 'De velden ":attribute" en ":other" moeten verschillen.',
    'email' => 'Het veld ":attribute" moet een geldig e-mailadres zijn.',
    'exists' => 'De keuze in het veld ":attribute" is ongeldig.',
    'in' => 'De keuze in het veld ":attribute" is ongeldig.',
    'integer' => 'Het veld ":attribute" moet een geheel getal zijn.',
    'max' => [
        'numeric' => 'Het veld ":attribute" mag niet groter zijn dan :max.',
        'string' => 'Het veld ":attribute" mag niet meer dan :max tekens bevatten.',
        'array' => 'Het veld ":attribute" mag niet meer dan :max items bevatten.',
        'file' => 'Het veld ":attribute" mag niet groter zijn dan :max kilobytes.',
    ],
    'min' => [
        'numeric' => 'Het veld ":attribute" moet minstens :min zijn.',
        'string' => 'Het veld ":attribute" moet minstens :min tekens bevatten.',
        'array' => 'Het veld ":attribute" moet minstens :min items bevatten.',
        'file' => 'Het veld ":attribute" moet minstens :min kilobytes zijn.',
    ],
    'numeric' => 'Het veld ":attribute" moet een getal zijn.',
    'password' => [
        'letters' => 'Het veld ":attribute" moet minstens één letter bevatten.',
        'mixed' => 'Het veld ":attribute" moet minstens één hoofdletter en één kleine letter bevatten.',
        'numbers' => 'Het veld ":attribute" moet minstens één cijfer bevatten.',
        'symbols' => 'Het veld ":attribute" moet minstens één symbool bevatten.',
        'uncompromised' => 'Dit wachtwoord is voorgekomen in een datalek. Kies een ander wachtwoord.',
    ],
    'regex' => 'De opmaak van het veld ":attribute" is ongeldig.',
    'required' => 'Het veld ":attribute" is verplicht.',
    'required_if' => 'Het veld ":attribute" is verplicht als ":other" :value is.',
    'same' => 'De velden ":attribute" en ":other" moeten overeenkomen.',
    'string' => 'Het veld ":attribute" moet tekst zijn.',
    'unique' => 'Deze waarde in het veld ":attribute" is al in gebruik.',
    'url' => 'Het veld ":attribute" moet een geldige URL zijn.',
];
