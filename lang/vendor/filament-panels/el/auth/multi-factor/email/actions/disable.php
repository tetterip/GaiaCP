<?php

// Texts Filament's own Greek file lacks; merged over it by Laravel.
return [
    'label' => 'Απενεργοποίηση',
    'modal' => [
        'heading' => 'Απενεργοποίηση κωδικών μέσω email',
        'description' => 'Θέλετε σίγουρα να σταματήσετε να λαμβάνετε κωδικούς επιβεβαίωσης με email; Ο λογαριασμός σας θα χάσει ένα επιπλέον επίπεδο ασφάλειας.',
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
                'label' => 'Απενεργοποίηση κωδικών μέσω email',
            ],
        ],
    ],
    'notifications' => [
        'disabled' => [
            'title' => 'Οι κωδικοί μέσω email απενεργοποιήθηκαν',
        ],
    ],
];
