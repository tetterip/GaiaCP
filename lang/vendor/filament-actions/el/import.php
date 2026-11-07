<?php

// Texts Filament's own Greek file lacks or leaves in English; merged over it by Laravel.
return [
    'modal' => [
        'form' => [
            'file' => [
                'rules' => [
                    'duplicate_columns' => '{0} Το αρχείο δεν πρέπει να έχει πάνω από μία κενή επικεφαλίδα στήλης.|{1,*} Το αρχείο δεν πρέπει να έχει διπλές επικεφαλίδες στηλών: :columns.',
                ],
                'label' => 'Αρχείο',
                'placeholder' => 'Ανεβάστε ένα αρχείο CSV',
            ],
            'columns' => [
                'label' => 'Στήλες',
                'placeholder' => 'Επιλέξτε στήλη',
            ],
        ],
        'actions' => [
            'download_example' => [
                'label' => 'Λήψη υποδείγματος CSV',
            ],
            'import' => [
                'label' => 'Εισαγωγή',
            ],
        ],
        'heading' => 'Εισαγωγή :label',
    ],
    'failure_csv' => [
        'column_mapping_required_for_new_record' => 'Η στήλη :attribute δεν αντιστοιχίστηκε σε στήλη του αρχείου, αλλά χρειάζεται για τη δημιουργία νέων εγγραφών.',
        'error_header' => 'σφάλμα',
        'system_error' => 'Σφάλμα συστήματος, επικοινωνήστε με την υποστήριξη.',
    ],
    'label' => 'Εισαγωγή :label',
    'notifications' => [
        'completed' => [
            'actions' => [
                'download_failed_rows_csv' => [
                    'label' => 'Λήψη στοιχείων για τη γραμμή που απέτυχε|Λήψη στοιχείων για τις γραμμές που απέτυχαν',
                ],
            ],
            'title' => 'Η εισαγωγή ολοκληρώθηκε',
        ],
        'max_rows' => [
            'body' => 'Δεν μπορείτε να εισαγάγετε πάνω από 1 γραμμή τη φορά.|Δεν μπορείτε να εισαγάγετε πάνω από :count γραμμές τη φορά.',
            'title' => 'Το αρχείο CSV είναι πολύ μεγάλο',
        ],
        'started' => [
            'body' => 'Η εισαγωγή ξεκίνησε και 1 γραμμή θα επεξεργαστεί στο παρασκήνιο.|Η εισαγωγή ξεκίνησε και :count γραμμές θα επεξεργαστούν στο παρασκήνιο.',
            'title' => 'Η εισαγωγή ξεκίνησε',
        ],
    ],
];
