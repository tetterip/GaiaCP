<?php

// Texts Filament's own Greek file lacks or leaves in English; merged over it by Laravel.
return [
    'column_manager' => [
        'position' => 'Θέση :position από :count.',
        'position_in_group' => 'Θέση :position από :count στην ομάδα :group.',
        'reordered' => ':column: μετακινήθηκε στη θέση :position από :count.',
        'reordered_deferred' => ':column: μετακινήθηκε στη θέση :position από :count. Πατήστε Εφαρμογή στηλών για να αποθηκευτεί η αλλαγή.',
        'reordered_in_group' => ':column: μετακινήθηκε στη θέση :position από :count στην ομάδα :group.',
        'reordered_in_group_deferred' => ':column: μετακινήθηκε στη θέση :position από :count στην ομάδα :group. Πατήστε Εφαρμογή στηλών για να αποθηκευτεί η αλλαγή.',
        'actions' => [
            'reorder' => [
                'label' => 'Αναδιάταξη στήλης',
            ],
            'move_up' => [
                'label' => 'Μετακίνηση της στήλης :column πάνω',
            ],
            'move_down' => [
                'label' => 'Μετακίνηση της στήλης :column κάτω',
            ],
        ],
    ],
    'columns' => [
        'icon' => [
            'boolean' => [
                'true' => 'Ναι',
                'false' => 'Όχι',
            ],
        ],
        'editable' => [
            'saving' => 'Αποθήκευση αλλαγής...',
            'saved' => 'Η αλλαγή αποθηκεύτηκε.',
            'failure' => 'Η αποθήκευση δεν επιβεβαιώθηκε. Ανανεώστε τη σελίδα πριν δοκιμάσετε ξανά.',
            'unavailable' => 'Αυτό το στοιχείο δεν μπορεί πια να αλλάξει.',
        ],
        'select' => [
            'no_options_message' => 'Δεν υπάρχουν διαθέσιμες επιλογές.',
        ],
    ],
    'actions' => [
        'move_record_up' => [
            'label' => 'Μετακίνηση :label πάνω',
        ],
        'move_record_down' => [
            'label' => 'Μετακίνηση :label κάτω',
        ],
        'reorder_record' => [
            'label' => 'Αναδιάταξη στοιχείου :key',
        ],
        'toggle_record_content' => [
            'label' => 'Ανάπτυξη/σύμπτυξη στοιχείου :key',
        ],
        'column_manager' => [
            'label' => 'Διαχείριση στηλών',
        ],
    ],
    'loading' => 'Φόρτωση...',
    'reordering' => [
        'position' => ':label, θέση :position από :count.',
        'moved' => ':label: μετακινήθηκε στη θέση :position από :count.',
    ],
    'result_count' => '{0} Κανένα αποτέλεσμα|{1} :count αποτέλεσμα|[2,*] :count αποτελέσματα',
    'grouping' => [
        'fields' => [
            'direction' => [
                'label' => 'Σειρά ομάδων',
            ],
        ],
    ],
    'summary' => [
        'subheadings' => [
            'page' => 'Αυτή η σελίδα',
        ],
    ],
];
