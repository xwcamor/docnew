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

    // Status labels (system alerts)
    'status_unread'      => 'Unread',
    'status_read'        => 'Read',

    // Item labels
    'generated'          => 'Generated',
    'downloaded'         => 'Downloaded',
    'expires'            => 'Expires',
    'received'           => 'Received',

    // Actions
    'download'           => 'Download',
    'dismiss'            => 'Dismiss',
    'mark_read'          => 'Mark as read',
    'mark_all_read'      => 'Mark all as read',

    // Empty state
    'empty'              => 'You have no notifications',
    'empty_hint'         => 'Files you export and system alerts show up here.',
];
