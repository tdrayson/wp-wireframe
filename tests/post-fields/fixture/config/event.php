<?php

declare(strict_types=1);

return [
    'tabs' => [
        [
            'id'       => 'details',
            'title'    => 'Details',
            'sections' => [
                [
                    'id'     => 'details',
                    'title'  => 'Details',
                    'fields' => [
                        ['id' => 'is_featured', 'type' => 'toggle', 'label' => 'Featured', 'default' => true],
                        [
                            'id'         => 'ticket_url',
                            'type'       => 'url',
                            'label'      => 'Ticket URL',
                            'required'   => true,
                            'conditions' => ['all' => [['field' => 'is_featured', 'operator' => 'truthy']]],
                        ],
                        ['id' => 'price', 'type' => 'number', 'label' => 'Price'],
                        [
                            'id'      => 'genres',
                            'type'    => 'checkboxes',
                            'label'   => 'Genres',
                            'default' => ['jazz'],
                            'args'    => ['options' => ['jazz' => 'Jazz', 'rock' => 'Rock', 'folk' => 'Folk']],
                        ],
                        ['id' => 'secret_code', 'type' => 'password', 'label' => 'Secret code'],
                        ['id' => 'public_tagline', 'type' => 'text', 'label' => 'Tagline', 'public' => true],
                        [
                            'id'     => 'admin_notes',
                            'type'   => 'textarea',
                            'label'  => 'Admin notes',
                            'access' => ['view' => 'administrator', 'edit' => 'administrator'],
                        ],
                        ['id' => 'logo', 'type' => 'file', 'label' => 'Logo'],
                        ['id' => 'gallery', 'type' => 'file', 'label' => 'Gallery', 'args' => ['multiple' => true]],
                    ],
                ],
            ],
        ],
        [
            'id'       => 'schedule',
            'title'    => 'Schedule',
            'sections' => [
                [
                    'id'     => 'schedule',
                    'title'  => 'Schedule',
                    'fields' => [
                        ['id' => 'event_starts', 'type' => 'date', 'label' => 'Starts', 'required' => true],
                        [
                            'id'    => 'sessions',
                            'type'  => 'repeater',
                            'label' => 'Sessions',
                            'args'  => [
                                'subfields' => [
                                    ['id' => 'starts', 'type' => 'date', 'label' => 'Starts', 'indexed' => true],
                                    ['id' => 'venue', 'type' => 'text', 'label' => 'Venue', 'indexed' => true],
                                    ['id' => 'seats', 'type' => 'number', 'label' => 'Seats', 'indexed' => true],
                                    ['id' => 'notes', 'type' => 'textarea', 'label' => 'Notes'],
                                    [
                                        'id'      => 'tags',
                                        'type'    => 'checkboxes',
                                        'label'   => 'Tags',
                                        'indexed' => true,
                                        'args'    => ['options' => ['a' => 'A', 'b' => 'B', 'c' => 'C']],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
