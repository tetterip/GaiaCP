<?php

// Texts Filament's own Greek file lacks; merged over it by Laravel.
return [
    'management_schema' => [
        'actions' => [
            'label' => 'Εφαρμογή επαλήθευσης',
            'below_content' => 'Χρησιμοποιήστε μια ασφαλή εφαρμογή που δίνει προσωρινό κωδικό για την επιβεβαίωση της σύνδεσης.',
            'messages' => [
                'enabled' => 'Ενεργό',
                'disabled' => 'Ανενεργό',
            ],
        ],
    ],
    'login_form' => [
        'label' => 'Κωδικός από την εφαρμογή επαλήθευσης',
        'code' => [
            'label' => 'Δώστε τον 6ψήφιο κωδικό από την εφαρμογή επαλήθευσης',
            'validation_attribute' => 'κωδικός',
            'actions' => [
                'use_recovery_code' => [
                    'label' => 'Χρήση κωδικού ανάκτησης',
                ],
            ],
            'messages' => [
                'invalid' => 'Ο κωδικός που δώσατε δεν είναι σωστός.',
            ],
        ],
        'recovery_code' => [
            'label' => 'Ή δώστε έναν κωδικό ανάκτησης',
            'validation_attribute' => 'κωδικός ανάκτησης',
            'messages' => [
                'invalid' => 'Ο κωδικός ανάκτησης που δώσατε δεν είναι σωστός.',
            ],
        ],
    ],
];
