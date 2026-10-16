<?php

declare(strict_types=1);

// One field per storage kind, so each is easy to inspect with plain get_post_meta().
return [
    'tabs' => [
        [
            'id'       => 'details',
            'title'    => __('Details', 'events'),
            'sections' => [
                [
                    'id'     => 'basics',
                    'title'  => __('Basics', 'events'),
                    'fields' => [
                        // One meta row.
                        ['id' => 'event_summary', 'type' => 'textarea', 'label' => __('Summary', 'events')],
                        ['id' => 'event_price', 'type' => 'number', 'label' => __('Price', 'events'), 'columns' => 6, 'column' => ['sortable' => true]],
                        ['id' => 'event_featured', 'type' => 'toggle', 'label' => __('Featured', 'events'), 'columns' => 6, 'public' => true, 'column' => true],
                        // One meta row per selected value, so meta_query IN / = works natively.
                        [
                            'id'     => 'event_genres',
                            'type'   => 'checkboxes',
                            'label'  => __('Genres', 'events'),
                            'column' => true,
                            'args'   => ['options' => ['jazz' => __('Jazz', 'events'), 'rock' => __('Rock', 'events'), 'folk' => __('Folk', 'events')]],
                        ],
                        // One meta row holding an ordered array of attachment IDs.
                        ['id' => 'event_gallery', 'type' => 'file', 'label' => __('Gallery', 'events'), 'args' => ['multiple' => true]],
                    ],
                ],
            ],
        ],
        [
            'id'       => 'schedule',
            'title'    => __('Schedule', 'events'),
            'sections' => [
                [
                    'id'     => 'sessions',
                    'title'  => __('Sessions', 'events'),
                    'fields' => [
                        // One meta row holding the rows; `indexed` subfields are queryable.
                        [
                            'id'     => 'sessions',
                            'type'   => 'repeater',
                            'label'  => __('Sessions', 'events'),
                            'column' => true,
                            'args'   => [
                                'subfields' => [
                                    ['id' => 'starts', 'type' => 'date', 'label' => __('Starts', 'events'), 'indexed' => true],
                                    ['id' => 'venue', 'type' => 'text', 'label' => __('Venue', 'events'), 'indexed' => true],
                                    ['id' => 'seats', 'type' => 'number', 'label' => __('Seats', 'events'), 'indexed' => true],
                                    ['id' => 'notes', 'type' => 'textarea', 'label' => __('Notes', 'events')],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
