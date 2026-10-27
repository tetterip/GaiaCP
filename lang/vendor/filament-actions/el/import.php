<?php

// Texts Filament's own Greek file lacks; merged over it by Laravel.
return [
    'modal' => [
        'form' => [
            'file' => [
                'rules' => [
                    'duplicate_columns' => '{0} Το αρχείο δεν πρέπει να έχει πάνω από μία κενή επικεφαλίδα στήλης.|{1,*} Το αρχείο δεν πρέπει να έχει διπλές επικεφαλίδες στηλών: :columns.',
                ],
            ],
        ],
    ],
    'failure_csv' => [
        'column_mapping_required_for_new_record' => 'Η στήλη :attribute δεν αντιστοιχίστηκε σε στήλη του αρχείου, αλλά χρειάζεται για τη δημιουργία νέων εγγραφών.',
    ],
];
