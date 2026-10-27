<?php

// Texts Filament's own Greek file lacks; merged over it by Laravel.
return [
    'label' => 'Ρύθμιση',
    'modal' => [
        'heading' => 'Ρύθμιση εφαρμογής επαλήθευσης',
        'description' => 'Θα χρειαστείτε μια εφαρμογή όπως το Google Authenticator (<x-filament::link href="https://itunes.apple.com/us/app/google-authenticator/id388497605" target="_blank">iOS</x-filament::link>, <x-filament::link href="https://play.google.com/store/apps/details?id=com.google.android.apps.authenticator2" target="_blank">Android</x-filament::link>) για να ολοκληρώσετε τη ρύθμιση.',
        'content' => [
            'qr_code' => [
                'instruction' => 'Σαρώστε αυτόν τον κωδικό QR με την εφαρμογή επαλήθευσης:',
                'alt' => 'Κωδικός QR για σάρωση με εφαρμογή επαλήθευσης',
            ],
            'text_code' => [
                'instruction' => 'Ή πληκτρολογήστε αυτόν τον κωδικό:',
                'messages' => [
                    'copied' => 'Αντιγράφηκε',
                ],
            ],
            'recovery_codes' => [
                'instruction' => 'Φυλάξτε τους παρακάτω κωδικούς ανάκτησης σε ασφαλές μέρος. Εμφανίζονται μόνο μία φορά, αλλά θα τους χρειαστείτε αν χάσετε την πρόσβαση στην εφαρμογή επαλήθευσης:',
            ],
        ],
        'form' => [
            'password' => [
                'label' => 'Τρέχων κωδικός',
                'validation_attribute' => 'τρέχων κωδικός',
            ],
            'code' => [
                'label' => 'Δώστε τον 6ψήφιο κωδικό από την εφαρμογή επαλήθευσης',
                'validation_attribute' => 'κωδικός',
                'below_content' => 'Θα δίνετε τον 6ψήφιο κωδικό της εφαρμογής επαλήθευσης σε κάθε σύνδεση ή ευαίσθητη ενέργεια.',
                'messages' => [
                    'invalid' => 'Ο κωδικός που δώσατε δεν είναι σωστός.',
                    'rate_limited' => 'Πάρα πολλές προσπάθειες. Δοκιμάστε ξανά αργότερα.',
                ],
            ],
        ],
        'actions' => [
            'submit' => [
                'label' => 'Ενεργοποίηση εφαρμογής επαλήθευσης',
            ],
        ],
    ],
    'notifications' => [
        'enabled' => [
            'title' => 'Η εφαρμογή επαλήθευσης ενεργοποιήθηκε',
        ],
    ],
];
