<?php

return [
    // Headers
    'singular'              => 'Message',
    'plural'                => 'Messages',
    'inbox'                 => 'Inbox',
    'new_message'           => 'New message',
    'edit_message'          => 'Edit message',
    'message_detail'        => 'Message detail',
    'unread'                => 'Unread',
    'read'                  => 'Read',
    'empty_bell'            => 'You have no messages',
    'empty_bell_hint'       => 'Announcements, notices and threads from the administrator will appear here.',
    'view_inbox'            => 'View inbox',

    // Fields
    'subject'               => 'Subject',
    'body'                  => 'Body',
    'audience'              => 'Audience',
    'audience_type'         => 'Audience type',
    'audience_target'       => 'Recipient',
    'audience_global'       => 'All users (Global)',
    'audience_tenant'       => 'Workspace',
    'audience_user'         => 'User',
    'audience_select_tenant'=> 'Select workspace',
    'audience_select_user'  => 'Select user',
    'allow_replies'         => 'Allow replies / discussion',
    'is_active'             => 'Active',
    'published_at'          => 'Published at',
    'expires_at'            => 'Expires at',
    'no_expiration'         => 'No expiration',
    'created_by'            => 'Created by',
    'created_at'            => 'Created at',
    'status_published'      => 'Published',
    'status_draft'          => 'Draft',
    'status_expired'        => 'Expired',

    // Stats
    'recipients_count'      => 'Recipients',
    'read_count'            => 'Read',
    'replies_count'         => 'Replies',
    'read_pct'              => '% read',

    // Actions
    'save_draft'            => 'Save draft',
    'save_and_publish'      => 'Save and publish',
    'publish_now'           => 'Publish now',
    'reply'                 => 'Reply',
    'send_reply'            => 'Send reply',
    'mark_all_read'         => 'Mark all as read',
    'view_message'          => 'View message',

    // Filters
    'filter_subject'        => 'Search by subject',
    'filter_audience'       => 'Filter by audience',
    'filter_active'         => 'Status',
    'only_unread'           => 'Unread',
    'only_repliable'        => 'Repliable',
    'tab_all'               => 'All',
    'badge_new'             => 'New',

    // Empty states
    'inbox_empty_title'     => 'No messages',
    'inbox_empty_hint'      => 'When you receive an announcement, it will appear here.',
    'messages_empty_title'  => 'No messages yet',
    'messages_empty_hint'   => 'Create your first announcement to send it to your users.',
    'replies_empty'         => 'No replies yet.',

    // Flash messages
    'created_success'       => 'Message created successfully.',
    'updated_success'       => 'Message updated.',
    'deleted_success'       => 'Message deleted.',
    'published_success'     => 'Message published.',
    'reply_sent'            => 'Reply sent.',
    'mark_all_read_success' => ':count messages marked as read.',

    // Validation
    'subject_required'           => 'Subject is required.',
    'body_required'              => 'Body is required.',
    'audience_type_required'     => 'Select the audience.',
    'audience_id_required'       => 'Select a recipient.',
    'reply_body_required'        => 'Write a reply before sending.',
    'reply_body_max'             => 'Reply cannot exceed 5000 characters.',
    'confirm_subject_mismatch'   => 'Subject does not match.',

    // Errors
    'not_a_recipient'      => 'You do not have access to this message.',
    'replies_not_allowed'  => 'Replies are disabled on this message.',

    // Delete confirmation
    'delete_title'         => 'Delete message',
    'delete_warning'       => 'This soft-deletes the message. To confirm, type the exact subject.',
    'delete_subject_label' => 'Confirm subject',
    'delete_reason_label'  => 'Reason',

    // In-app notifications
    'notify_new_reply_title' => 'New reply',
    'notify_new_reply_body'  => ':user replied to "  :subject "',

    // Form help (tooltips) and placeholders
    'subject_help'                  => 'Short title of the message. Appears in the recipient inbox.',
    'subject_placeholder'           => 'E.g.: Important system changes',
    'body_help'                     => 'Message content. Supports rich text.',
    'audience_type_help'            => 'Defines the scope: all users, a specific workspace or an individual user.',
    'audience_select_tenant_help'   => 'Select the destination workspace for this message.',
    'audience_select_user_help'     => 'Select the destination user for this message.',
    'allow_replies_help'            => 'When enabled, recipients can reply and start a thread.',
    'expires_at_help'               => 'Date when the message stops being shown. Leave empty for no expiration.',
    'is_active_help'                => 'Inactive messages are not shown in the inbox.',
];
