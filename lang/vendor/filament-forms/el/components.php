<?php

// Texts Filament's own Greek file lacks; merged over it by Laravel.
return [
    'builder' => [
        'block_picker' => [
            'no_search_results_message' => 'Κανένα μπλοκ δεν ταιριάζει με την αναζήτηση.',
            'search_prompt' => 'Αναζήτηση μπλοκ',
        ],
    ],
    'checkbox_list' => [
        'required_description' => 'Επιλέξτε τουλάχιστον μία επιλογή.',
    ],
    'color_picker' => [
        'panel_label' => 'Επιλογή χρώματος',
    ],
    'date_time_picker' => [
        'month_select' => [
            'label' => 'Μήνας',
        ],
        'year_input' => [
            'label' => 'Έτος',
        ],
        'hour_input' => [
            'label' => 'Ώρα',
        ],
        'minute_input' => [
            'label' => 'Λεπτό',
        ],
        'second_input' => [
            'label' => 'Δευτερόλεπτο',
        ],
    ],
    'file_upload' => [
        'actions' => [
            'download' => [
                'label' => 'Λήψη',
            ],
            'open' => [
                'label' => 'Άνοιγμα σε νέα καρτέλα',
            ],
        ],
        'editor' => [
            'label' => 'Επεξεργασία εικόνας',
        ],
    ],
    'key_value' => [
        'columns' => [
            'actions' => [
                'label' => 'Ενέργειες',
            ],
            'reorder' => [
                'label' => 'Αναδιάταξη',
            ],
        ],
    ],
    'markdown_editor' => [
        'file_attachments_accepted_file_types_message' => 'Τα αρχεία πρέπει να είναι τύπου: :values.',
        'file_attachments_max_size_message' => 'Τα αρχεία δεν πρέπει να ξεπερνούν τα :max kilobytes.',
    ],
    'modal_table_select' => [
        'actions' => [
            'select' => [
                'label' => 'Επιλογή',
                'actions' => [
                    'select' => [
                        'label' => 'Επιλογή',
                    ],
                ],
            ],
        ],
    ],
    'repeater' => [
        'columns' => [
            'actions' => [
                'label' => 'Ενέργειες',
            ],
            'reorder' => [
                'label' => 'Αναδιάταξη',
            ],
        ],
    ],
    'rich_editor' => [
        'actions' => [
            'attach_files' => [
                'label' => 'Μεταφόρτωση αρχείου',
                'modal' => [
                    'heading' => 'Μεταφόρτωση αρχείου',
                    'form' => [
                        'file' => [
                            'label' => [
                                'new' => 'Αρχείο',
                                'existing' => 'Αντικατάσταση αρχείου',
                            ],
                        ],
                        'alt' => [
                            'label' => [
                                'new' => 'Εναλλακτικό κείμενο',
                                'existing' => 'Αλλαγή εναλλακτικού κειμένου',
                            ],
                        ],
                    ],
                ],
            ],
            'close_panel' => [
                'label' => 'Κλείσιμο πλαισίου',
            ],
            'custom_block' => [
                'modal' => [
                    'actions' => [
                        'insert' => [
                            'label' => 'Εισαγωγή',
                        ],
                        'save' => [
                            'label' => 'Αποθήκευση',
                        ],
                    ],
                ],
            ],
            'grid' => [
                'label' => 'Πλέγμα',
                'modal' => [
                    'heading' => 'Πλέγμα',
                    'form' => [
                        'preset' => [
                            'label' => 'Προεπιλογή',
                            'placeholder' => 'Καμία',
                            'options' => [
                                'two' => 'Δύο',
                                'three' => 'Τρεις',
                                'four' => 'Τέσσερις',
                                'five' => 'Πέντε',
                                'two_start_third' => 'Δύο (πρώτη στο ένα τρίτο)',
                                'two_end_third' => 'Δύο (τελευταία στο ένα τρίτο)',
                                'two_start_fourth' => 'Δύο (πρώτη στο ένα τέταρτο)',
                                'two_end_fourth' => 'Δύο (τελευταία στο ένα τέταρτο)',
                            ],
                        ],
                        'columns' => [
                            'label' => 'Στήλες',
                        ],
                        'from_breakpoint' => [
                            'label' => 'Από πλάτος οθόνης',
                            'options' => [
                                'default' => 'Όλα',
                                'sm' => 'Μικρό',
                                'md' => 'Μεσαίο',
                                'lg' => 'Μεγάλο',
                                'xl' => 'Πολύ μεγάλο',
                                '2xl' => 'Πάρα πολύ μεγάλο',
                            ],
                        ],
                        'is_asymmetric' => [
                            'label' => 'Δύο άνισες στήλες',
                        ],
                        'start_span' => [
                            'label' => 'Πλάτος πρώτης',
                        ],
                        'end_span' => [
                            'label' => 'Πλάτος τελευταίας',
                        ],
                    ],
                ],
            ],
            'link' => [
                'label' => 'Σύνδεσμος',
                'modal' => [
                    'heading' => 'Σύνδεσμος',
                    'form' => [
                        'url' => [
                            'label' => 'URL',
                        ],
                        'should_open_in_new_tab' => [
                            'label' => 'Άνοιγμα σε νέα καρτέλα',
                        ],
                    ],
                ],
            ],
            'text_color' => [
                'label' => 'Χρώμα κειμένου',
                'modal' => [
                    'heading' => 'Χρώμα κειμένου',
                    'form' => [
                        'color' => [
                            'label' => 'Χρώμα',
                            'options' => [
                                'slate' => 'Σχιστόλιθος',
                                'gray' => 'Γκρι',
                                'zinc' => 'Ψευδάργυρος',
                                'neutral' => 'Ουδέτερο',
                                'stone' => 'Πέτρα',
                                'mauve' => 'Μοβ απαλό',
                                'olive' => 'Λαδί',
                                'mist' => 'Ομίχλη',
                                'taupe' => 'Γκριζόμπεζ',
                                'red' => 'Κόκκινο',
                                'orange' => 'Πορτοκαλί',
                                'amber' => 'Κεχριμπαρί',
                                'yellow' => 'Κίτρινο',
                                'lime' => 'Λαχανί',
                                'green' => 'Πράσινο',
                                'emerald' => 'Σμαραγδί',
                                'teal' => 'Πετρόλ',
                                'cyan' => 'Κυανό',
                                'sky' => 'Γαλάζιο',
                                'blue' => 'Μπλε',
                                'indigo' => 'Λουλακί',
                                'violet' => 'Βιολετί',
                                'purple' => 'Μωβ',
                                'fuchsia' => 'Φούξια',
                                'pink' => 'Ροζ',
                                'rose' => 'Τριανταφυλλί',
                            ],
                        ],
                        'custom_color' => [
                            'label' => 'Δικό σας χρώμα',
                        ],
                    ],
                ],
            ],
        ],
        'custom_blocks' => [
            'actions' => [
                'delete' => [
                    'label' => 'Διαγραφή μπλοκ',
                ],
                'edit' => [
                    'label' => 'Επεξεργασία μπλοκ',
                ],
            ],
            'no_search_results_message' => 'Κανένα μπλοκ δεν ταιριάζει με την αναζήτηση.',
            'search_label' => 'Αναζήτηση μπλοκ',
            'search_prompt' => 'Αναζήτηση μπλοκ',
        ],
        'file_attachments_accepted_file_types_message' => 'Τα αρχεία πρέπει να είναι τύπου: :values.',
        'file_attachments_max_size_message' => 'Τα αρχεία δεν πρέπει να ξεπερνούν τα :max kilobytes.',
        'no_merge_tag_search_results_message' => 'Δεν βρέθηκαν πεδία συγχώνευσης.',
        'mentions' => [
            'no_options_message' => 'Δεν υπάρχουν διαθέσιμες επιλογές.',
            'no_search_results_message' => 'Κανένα αποτέλεσμα δεν ταιριάζει με την αναζήτηση.',
            'search_prompt' => 'Πληκτρολογήστε για αναζήτηση',
            'searching_message' => 'Αναζήτηση...',
        ],
        'toolbar' => [
            'label' => 'Εργαλεία επεξεργασίας',
        ],
        'tools' => [
            'align_center' => 'Στοίχιση στο κέντρο',
            'align_end' => 'Στοίχιση στο τέλος',
            'align_justify' => 'Πλήρης στοίχιση',
            'align_start' => 'Στοίχιση στην αρχή',
            'clear_formatting' => 'Καθαρισμός μορφοποίησης',
            'code' => 'Κώδικας',
            'custom_blocks' => 'Μπλοκ',
            'details' => 'Λεπτομέρειες',
            'h4' => 'Επικεφαλίδα 4',
            'h5' => 'Επικεφαλίδα 5',
            'h6' => 'Επικεφαλίδα 6',
            'grid' => 'Πλέγμα',
            'grid_delete' => 'Διαγραφή πλέγματος',
            'highlight' => 'Επισήμανση',
            'horizontal_rule' => 'Οριζόντια γραμμή',
            'lead' => 'Εισαγωγικό κείμενο',
            'merge_tags' => 'Πεδία συγχώνευσης',
            'paragraph' => 'Παράγραφος',
            'small' => 'Μικρό κείμενο',
            'subscript' => 'Δείκτης',
            'superscript' => 'Εκθέτης',
            'table' => 'Πίνακας',
            'table_delete' => 'Διαγραφή πίνακα',
            'table_add_column_before' => 'Προσθήκη στήλης πριν',
            'table_add_column_after' => 'Προσθήκη στήλης μετά',
            'table_delete_column' => 'Διαγραφή στήλης',
            'table_add_row_before' => 'Προσθήκη γραμμής από πάνω',
            'table_add_row_after' => 'Προσθήκη γραμμής από κάτω',
            'table_delete_row' => 'Διαγραφή γραμμής',
            'table_merge_cells' => 'Συγχώνευση κελιών',
            'table_split_cell' => 'Διαχωρισμός κελιού',
            'table_toggle_header_row' => 'Γραμμή επικεφαλίδας',
            'table_toggle_header_cell' => 'Κελί επικεφαλίδας',
            'text_color' => 'Χρώμα κειμένου',
        ],
        'uploading_file_message' => 'Μεταφόρτωση αρχείου...',
    ],
    'select' => [
        'actions' => [
            'clear' => [
                'label' => 'Καθαρισμός επιλογής',
            ],
            'remove_option' => [
                'label' => 'Αφαίρεση :label',
            ],
        ],
        'no_options_message' => 'Δεν υπάρχουν διαθέσιμες επιλογές.',
        'search_label' => 'Αναζήτηση',
    ],
    'tags_input' => [
        'actions' => [
            'delete' => [
                'label' => 'Διαγραφή',
            ],
        ],
        'tag_added' => 'Προστέθηκε: :tag',
        'tag_removed' => 'Αφαιρέθηκε: :tag',
    ],
    'text_input' => [
        'actions' => [
            'copy' => [
                'label' => 'Αντιγραφή',
                'message' => 'Αντιγράφηκε',
            ],
        ],
    ],
    'toggle_buttons' => [
        'required_description' => 'Επιλέξτε τουλάχιστον μία επιλογή.',
    ],
];
