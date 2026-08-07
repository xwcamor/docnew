<?php

return [
    'title'              => 'Notifications',
    'count_one'          => ':count notification',
    'count_many'         => ':count notifications',
    'auto_delete_hint'   => 'Downloadable files are automatically removed the next day',

    // Status labels (download jobs)
    'status_processing'  => 'Generating',
    'status_ready'       => 'Ready',
    'status_failed'      => 'Failed',
    'status_expired'     => 'Expired',

    // Item labels
    'generated'          => 'Generated',
    'downloaded'         => 'Downloaded',
    'expires'            => 'Expires',

    // Actions
    'download'           => 'Download',
    'dismiss'            => 'Dismiss',

    // Empty state
    'empty'              => 'You have no notifications',
    'empty_hint'         => 'When you export a report or a task completes, it will show up here.',
];
