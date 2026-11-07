<?php

// Texts Filament's own Greek file lacks or leaves in English; merged over it by Laravel.
return [
    'modal' => [
        'form' => [
            'columns' => [
                'actions' => [
                    'select_all' => [
                        'label' => 'Επιλογή όλων',
                    ],
                    'deselect_all' => [
                        'label' => 'Αποεπιλογή όλων',
                    ],
                ],
                'form' => [
                    'is_enabled' => [
                        'label' => 'Στήλη :column',
                    ],
                    'label' => [
                        'label' => 'Τίτλος για :column',
                    ],
                ],
                'label' => 'Στήλες',
            ],
        ],
        'actions' => [
            'export' => [
                'label' => 'Εξαγωγή',
            ],
        ],
        'heading' => 'Εξαγωγή :label',
    ],
    'notifications' => [
        'no_columns' => [
            'title' => 'Δεν επιλέχθηκαν στήλες',
            'body' => 'Επιλέξτε τουλάχιστον μία στήλη για εξαγωγή.',
        ],
        'completed' => [
            'actions' => [
                'download_csv' => [
                    'label' => 'Λήψη .csv',
                ],
                'download_xlsx' => [
                    'label' => 'Λήψη .xlsx',
                ],
            ],
            'title' => 'Η εξαγωγή ολοκληρώθηκε',
        ],
        'max_rows' => [
            'body' => 'Δεν μπορείτε να εξαγάγετε πάνω από 1 γραμμή τη φορά.|Δεν μπορείτε να εξαγάγετε πάνω από :count γραμμές τη φορά.',
            'title' => 'Η εξαγωγή είναι πολύ μεγάλη',
        ],
        'started' => [
            'title' => 'Η εξαγωγή ξεκίνησε',
        ],
    ],
    'label' => 'Εξαγωγή :label',
];
