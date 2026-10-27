<?php

// Texts Filament's own Greek file lacks; merged over it by Laravel.
return [
    'label' => 'Ρύθμιση',
    'modal' => [
        'heading' => 'Ρύθμιση κωδικών μέσω email',
        'description' => 'Θα δίνετε τον 6ψήφιο κωδικό που σας στέλνουμε με email σε κάθε σύνδεση ή ευαίσθητη ενέργεια. Δείτε τα email σας για τον 6ψήφιο κωδικό που ολοκληρώνει τη ρύθμιση.',
        'form' => [
            'code' => [
                'label' => 'Δώστε τον 6ψήφιο κωδικό που σας στείλαμε με email',
                'validation_attribute' => 'κωδικός',
                'actions' => [
                    'resend' => [
                        'label' => 'Αποστολή νέου κωδικού με email',
                        'notifications' => [
                            'resent' => [
                                'title' => 'Σας στείλαμε νέο κωδικό με email',
                            ],
                            'throttled' => [
                                'title' => 'Πάρα πολλά αιτήματα νέου κωδικού. Περιμένετε λίγο πριν ζητήσετε άλλον.',
                            ],
                        ],
                    ],
                ],
                'messages' => [
                    'invalid' => 'Ο κωδικός που δώσατε δεν είναι σωστός.',
                    'rate_limited' => 'Πάρα πολλές προσπάθειες. Δοκιμάστε ξανά αργότερα.',
                ],
            ],
        ],
        'actions' => [
            'submit' => [
                'label' => 'Ενεργοποίηση κωδικών μέσω email',
            ],
        ],
    ],
    'notifications' => [
        'enabled' => [
            'title' => 'Οι κωδικοί μέσω email ενεργοποιήθηκαν',
        ],
    ],
];
