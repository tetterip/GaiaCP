<?php

// Greek texts of Laravel's validation messages for the rules GaiaCP's forms use; any other rule falls back to English.
// :attribute is the field's label as the form shows it.

return [
    'accepted' => 'Πρέπει να αποδεχτείτε το πεδίο «:attribute».',
    'confirmed' => 'Η επιβεβαίωση του πεδίου «:attribute» δεν ταιριάζει.',
    'current_password' => 'Ο κωδικός δεν είναι σωστός.',
    'date' => 'Το πεδίο «:attribute» πρέπει να είναι έγκυρη ημερομηνία.',
    'different' => 'Τα πεδία «:attribute» και «:other» πρέπει να διαφέρουν.',
    'email' => 'Το πεδίο «:attribute» πρέπει να είναι έγκυρη διεύθυνση email.',
    'exists' => 'Η επιλογή στο πεδίο «:attribute» δεν είναι έγκυρη.',
    'in' => 'Η επιλογή στο πεδίο «:attribute» δεν είναι έγκυρη.',
    'integer' => 'Το πεδίο «:attribute» πρέπει να είναι ακέραιος αριθμός.',
    'max' => [
        'numeric' => 'Το πεδίο «:attribute» δεν μπορεί να είναι μεγαλύτερο από :max.',
        'string' => 'Το πεδίο «:attribute» δεν μπορεί να έχει περισσότερους από :max χαρακτήρες.',
        'array' => 'Το πεδίο «:attribute» δεν μπορεί να έχει περισσότερα από :max στοιχεία.',
        'file' => 'Το πεδίο «:attribute» δεν μπορεί να είναι μεγαλύτερο από :max kilobytes.',
    ],
    'min' => [
        'numeric' => 'Το πεδίο «:attribute» πρέπει να είναι τουλάχιστον :min.',
        'string' => 'Το πεδίο «:attribute» πρέπει να έχει τουλάχιστον :min χαρακτήρες.',
        'array' => 'Το πεδίο «:attribute» πρέπει να έχει τουλάχιστον :min στοιχεία.',
        'file' => 'Το πεδίο «:attribute» πρέπει να είναι τουλάχιστον :min kilobytes.',
    ],
    'numeric' => 'Το πεδίο «:attribute» πρέπει να είναι αριθμός.',
    'password' => [
        'letters' => 'Το πεδίο «:attribute» πρέπει να περιέχει τουλάχιστον ένα γράμμα.',
        'mixed' => 'Το πεδίο «:attribute» πρέπει να περιέχει τουλάχιστον ένα κεφαλαίο και ένα πεζό γράμμα.',
        'numbers' => 'Το πεδίο «:attribute» πρέπει να περιέχει τουλάχιστον έναν αριθμό.',
        'symbols' => 'Το πεδίο «:attribute» πρέπει να περιέχει τουλάχιστον ένα σύμβολο.',
        'uncompromised' => 'Αυτός ο κωδικός έχει εμφανιστεί σε διαρροή δεδομένων. Επιλέξτε άλλον.',
    ],
    'regex' => 'Η μορφή του πεδίου «:attribute» δεν είναι έγκυρη.',
    'required' => 'Το πεδίο «:attribute» είναι υποχρεωτικό.',
    'required_if' => 'Το πεδίο «:attribute» είναι υποχρεωτικό όταν το «:other» είναι :value.',
    'same' => 'Τα πεδία «:attribute» και «:other» πρέπει να ταιριάζουν.',
    'string' => 'Το πεδίο «:attribute» πρέπει να είναι κείμενο.',
    'unique' => 'Αυτή η τιμή στο πεδίο «:attribute» χρησιμοποιείται ήδη.',
    'url' => 'Το πεδίο «:attribute» πρέπει να είναι έγκυρη διεύθυνση URL.',
];
