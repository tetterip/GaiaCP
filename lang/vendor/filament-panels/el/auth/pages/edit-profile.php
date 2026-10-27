<?php

// Texts Filament's own Greek file lacks; merged over it by Laravel.
return [
    'form' => [
        'password' => [
            'validation_attribute' => 'κωδικός',
        ],
        'password_confirmation' => [
            'validation_attribute' => 'επιβεβαίωση κωδικού',
        ],
        'current_password' => [
            'label' => 'Τρέχων κωδικός',
            'below_content' => 'Για λόγους ασφαλείας, επιβεβαιώστε τον κωδικό σας για να συνεχίσετε.',
            'validation_attribute' => 'τρέχων κωδικός',
        ],
    ],
    'multi_factor_authentication' => [
        'label' => 'Έλεγχος ταυτότητας δύο παραγόντων (2FA)',
    ],
    'notifications' => [
        'email_change_verification_sent' => [
            'title' => 'Στάλθηκε αίτημα αλλαγής email',
            'body' => 'Στείλαμε αίτημα αλλαγής της διεύθυνσης email σας στο :email. Ελέγξτε τα email σας για να επιβεβαιώσετε την αλλαγή.',
        ],
        'throttled' => [
            'title' => 'Πάρα πολλά αιτήματα. Δοκιμάστε ξανά σε :seconds δευτερόλεπτα.',
            'body' => 'Δοκιμάστε ξανά σε :seconds δευτερόλεπτα.',
        ],
    ],
];
