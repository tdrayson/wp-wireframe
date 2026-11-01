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
                        ['id' => 'is_featured', 'type' => 'toggle', 'label' => 'Featured', 'default' => true, 'column' => ['after' => 'wireframe_event_starts']],
                        [
                            'id'         => 'ticket_url',
                            'type'       => 'url',
                            'label'      => 'Ticket URL',
                            'required'   => true,
                            'conditions' => ['all' => [['field' => 'is_featured', 'operator' => 'truthy']]],
                        ],
                        ['id' => 'price', 'type' => 'number', 'label' => 'Price', 'previous_ids' => ['cost'], 'column' => ['sortable' => true, 'after' => 'wireframe_event_starts']],
                        [
                            'id'      => 'genres',
                            'type'    => 'checkboxes',
                            'label'   => 'Genres',
                            'column'  => ['after' => 'wireframe_event_starts'],
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
                            'column' => ['after' => 'wireframe_event_starts'],
                        ],
                        ['id' => 'logo', 'type' => 'file', 'label' => 'Logo', 'column' => ['after' => 'wireframe_event_starts']],
                        ['id' => 'gallery', 'type' => 'file', 'label' => 'Gallery', 'args' => ['multiple' => true]],
                        [
                            'id'     => 'speakers',
                            'type'   => 'select',
                            'label'  => 'Speakers',
                            'column' => ['after' => 'wireframe_event_starts'],
                            'args'   => [
                                'multiple' => true,
                                'populate' => [
                                    'type'      => 'query',
                                    'query'     => 'posts',
                                    'arguments' => ['post_type' => 'speaker'],
                                    'fetch'     => true,
                                ],
                            ],
                        ],
                        [
                            'id'    => 'event_tags',
                            'type'  => 'checkboxes',
                            'label' => 'Event tags',
                            'args'  => [
                                'populate' => ['type' => 'query', 'query' => 'terms', 'arguments' => ['taxonomy' => 'event_type']],
                            ],
                        ],
                        [
                            'id'    => 'host',
                            'type'  => 'radio',
                            'label' => 'Host',
                            'args'  => [
                                'options'  => ['0' => 'None'],
                                'populate' => [
                                    'type'      => 'query',
                                    'query'     => 'users',
                                    'arguments' => ['role' => 'editor'],
                                    'position'  => 'after',
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'id'     => 'internal',
                    'title'  => 'Internal',
                    'access' => 'administrator',
                    'fields' => [
                        ['id' => 'internal_ref', 'type' => 'text', 'label' => 'Internal reference'],
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
                        ['id' => 'event_starts', 'type' => 'date', 'label' => 'Starts', 'required' => true, 'column' => ['sortable' => true]],
                        [
                            'id'     => 'sessions',
                            'type'   => 'repeater',
                            'label'  => 'Sessions',
                            'column' => ['after' => 'wireframe_event_starts'],
                            'args'   => [
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
