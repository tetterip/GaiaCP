<?php

// German texts of Laravel's validation messages for the rules GaiaCP's forms use; any other rule falls back to English.
// :attribute is the field's label as the form shows it.

return [
    'accepted' => 'Das Feld „:attribute“ muss akzeptiert werden.',
    'confirmed' => 'Die Bestätigung des Feldes „:attribute“ stimmt nicht überein.',
    'current_password' => 'Das Passwort ist nicht korrekt.',
    'date' => 'Das Feld „:attribute“ muss ein gültiges Datum sein.',
    'different' => 'Die Felder „:attribute“ und „:other“ müssen sich unterscheiden.',
    'email' => 'Das Feld „:attribute“ muss eine gültige E-Mail-Adresse sein.',
    'exists' => 'Die Auswahl im Feld „:attribute“ ist ungültig.',
    'in' => 'Die Auswahl im Feld „:attribute“ ist ungültig.',
    'integer' => 'Das Feld „:attribute“ muss eine ganze Zahl sein.',
    'max' => [
        'numeric' => 'Das Feld „:attribute“ darf nicht größer als :max sein.',
        'string' => 'Das Feld „:attribute“ darf höchstens :max Zeichen haben.',
        'array' => 'Das Feld „:attribute“ darf höchstens :max Elemente haben.',
        'file' => 'Das Feld „:attribute“ darf nicht größer als :max Kilobyte sein.',
    ],
    'min' => [
        'numeric' => 'Das Feld „:attribute“ muss mindestens :min sein.',
        'string' => 'Das Feld „:attribute“ muss mindestens :min Zeichen haben.',
        'array' => 'Das Feld „:attribute“ muss mindestens :min Elemente haben.',
        'file' => 'Das Feld „:attribute“ muss mindestens :min Kilobyte groß sein.',
    ],
    'numeric' => 'Das Feld „:attribute“ muss eine Zahl sein.',
    'password' => [
        'letters' => 'Das Feld „:attribute“ muss mindestens einen Buchstaben enthalten.',
        'mixed' => 'Das Feld „:attribute“ muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => 'Das Feld „:attribute“ muss mindestens eine Zahl enthalten.',
        'symbols' => 'Das Feld „:attribute“ muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => 'Dieses Passwort ist in einem Datenleck aufgetaucht. Bitte wählen Sie ein anderes.',
    ],
    'regex' => 'Das Format des Feldes „:attribute“ ist ungültig.',
    'required' => 'Das Feld „:attribute“ ist ein Pflichtfeld.',
    'required_if' => 'Das Feld „:attribute“ ist ein Pflichtfeld, wenn „:other“ :value ist.',
    'same' => 'Die Felder „:attribute“ und „:other“ müssen übereinstimmen.',
    'string' => 'Das Feld „:attribute“ muss ein Text sein.',
    'unique' => 'Dieser Wert im Feld „:attribute“ wird bereits verwendet.',
    'url' => 'Das Feld „:attribute“ muss eine gültige URL sein.',
];
