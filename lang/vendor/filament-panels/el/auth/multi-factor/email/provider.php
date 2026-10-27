<?php

// Texts Filament's own Greek file lacks; merged over it by Laravel.
return [
    'management_schema' => [
        'actions' => [
            'label' => 'Κωδικοί επιβεβαίωσης μέσω email',
            'below_content' => 'Λαμβάνετε προσωρινό κωδικό στο email σας για να επιβεβαιώνετε την ταυτότητά σας κατά τη σύνδεση.',
            'messages' => [
                'enabled' => 'Ενεργό',
                'disabled' => 'Ανενεργό',
            ],
        ],
    ],
    'login_form' => [
        'label' => 'Αποστολή κωδικού στο email σας',
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
            ],
        ],
    ],
];
