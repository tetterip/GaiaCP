<?php

// Texts Filament's own Greek file lacks or leaves in English; merged over it by Laravel.
return [
    'form' => [
        'or_groups' => [
            'block' => [
                'picker_group' => 'Λογική',
            ],
            'group' => [
                'label' => 'Ομάδα',
            ],
        ],
    ],
    'max_rules_reached_tooltip' => 'Φτάσατε το όριο των :count κανόνων.',
    'operators' => [
        'date' => [
            'unit_labels' => [
                'second' => 'Δευτερόλεπτα',
                'minute' => 'Λεπτά',
                'hour' => 'Ώρες',
                'day' => 'Ημέρες',
                'week' => 'Εβδομάδες',
                'month' => 'Μήνες',
                'quarter' => 'Τρίμηνα',
                'year' => 'Έτη',
            ],
            'presets' => [
                'past_decade' => 'Τελευταία δεκαετία',
                'past_5_years' => 'Τελευταία 5 έτη',
                'past_2_years' => 'Τελευταία 2 έτη',
                'past_year' => 'Τελευταίο έτος',
                'past_6_months' => 'Τελευταίοι 6 μήνες',
                'past_quarter' => 'Τελευταίο τρίμηνο',
                'past_month' => 'Τελευταίος μήνας',
                'past_2_weeks' => 'Τελευταίες 2 εβδομάδες',
                'past_week' => 'Τελευταία εβδομάδα',
                'past_hour' => 'Τελευταία ώρα',
                'past_minute' => 'Τελευταίο λεπτό',
                'this_decade' => 'Αυτή η δεκαετία',
                'this_year' => 'Φέτος',
                'this_quarter' => 'Αυτό το τρίμηνο',
                'this_month' => 'Αυτός ο μήνας',
                'today' => 'Σήμερα',
                'this_hour' => 'Αυτή η ώρα',
                'this_minute' => 'Αυτό το λεπτό',
                'next_minute' => 'Επόμενο λεπτό',
                'next_hour' => 'Επόμενη ώρα',
                'next_week' => 'Επόμενη εβδομάδα',
                'next_2_weeks' => 'Επόμενες 2 εβδομάδες',
                'next_month' => 'Επόμενος μήνας',
                'next_quarter' => 'Επόμενο τρίμηνο',
                'next_6_months' => 'Επόμενοι 6 μήνες',
                'next_year' => 'Επόμενο έτος',
                'next_2_years' => 'Επόμενα 2 έτη',
                'next_5_years' => 'Επόμενα 5 έτη',
                'next_decade' => 'Επόμενη δεκαετία',
                'custom' => 'Προσαρμογή',
            ],
            'form' => [
                'mode' => [
                    'label' => 'Τύπος ημερομηνίας',
                    'options' => [
                        'absolute' => 'Συγκεκριμένη ημερομηνία',
                        'relative' => 'Κυλιόμενο διάστημα',
                    ],
                ],
                'preset' => [
                    'label' => 'Χρονικό διάστημα',
                ],
                'relative_value' => [
                    'label' => 'Πόσα',
                ],
                'relative_unit' => [
                    'label' => 'Μονάδα χρόνου',
                ],
                'tense' => [
                    'label' => 'Χρόνος',
                    'options' => [
                        'past' => 'Παρελθόν',
                        'future' => 'Μέλλον',
                    ],
                ],
            ],
        ],
        'boolean' => [
            'is_true' => [
                'label' => [
                    'direct' => 'Είναι αληθές',
                    'inverse' => 'Είναι ψευδές',
                ],
                'summary' => [
                    'direct' => ':attribute είναι αληθές',
                    'inverse' => ':attribute είναι ψευδές',
                ],
            ],
        ],
        'is_filled' => [
            'label' => [
                'direct' => 'Είναι συμπληρωμένο',
                'inverse' => 'Είναι κενό',
            ],
            'summary' => [
                'direct' => ':attribute είναι συμπληρωμένο',
                'inverse' => ':attribute είναι κενό',
            ],
        ],
        'number' => [
            'aggregates' => [
                'average' => [
                    'summary' => 'Μέσος όρος :attribute',
                ],
                'max' => [
                    'summary' => 'Μέγιστο :attribute',
                ],
                'min' => [
                    'summary' => 'Ελάχιστο :attribute',
                ],
                'sum' => [
                    'summary' => 'Άθροισμα :attribute',
                ],
            ],
            'form' => [
                'aggregate' => [
                    'label' => 'Συνάθροιση',
                ],
            ],
            'is_max' => [
                'label' => [
                    'direct' => 'Είναι το πολύ',
                ],
                'summary' => [
                    'direct' => ':attribute είναι το πολύ :number',
                ],
            ],
            'is_min' => [
                'label' => [
                    'direct' => 'Είναι τουλάχιστον',
                ],
                'summary' => [
                    'direct' => ':attribute είναι τουλάχιστον :number',
                ],
            ],
        ],
        'relationship' => [
            'has_max' => [
                'label' => [
                    'direct' => 'Έχει το πολύ',
                ],
                'summary' => [
                    'direct' => 'Έχει το πολύ :count :relationship',
                ],
            ],
            'has_min' => [
                'label' => [
                    'direct' => 'Έχει τουλάχιστον',
                ],
                'summary' => [
                    'direct' => 'Έχει τουλάχιστον :count :relationship',
                ],
            ],
            'is_related_to' => [
                'summary' => [
                    'values_glue' => [
                        'final' => ' ή ',
                    ],
                ],
            ],
        ],
        'select' => [
            'is' => [
                'summary' => [
                    'values_glue' => [
                        'final' => ' ή ',
                    ],
                ],
            ],
        ],
    ],
    'item_separators' => [
        'and' => 'ΚΑΙ',
        'or' => 'Ή',
    ],
    'label' => 'Δημιουργία ερωτήματος',
];
