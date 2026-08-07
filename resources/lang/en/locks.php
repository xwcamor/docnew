<?php

return [
    // Record locking actions and states (Lockable trait).
    'lock'            => 'Lock',
    'unlock'          => 'Unlock',
    'locked'          => 'Record locked.',
    'unlocked'        => 'Record unlocked.',
    'locked_tag'      => 'Locked',
    'locked_by_super' => 'Locked by the system',
    'locked_hint'     => 'This record is locked: it cannot be edited, deleted or imported until unlocked.',
    'locked_by_super_hint' => 'Locked by a system administrator. Only the system can unlock it.',
    'lock_confirm'    => 'Lock this record? Nobody will be able to edit or delete it until unlocked.',
    'unlock_confirm'  => 'Unlock this record?',
    'cannot_edit_locked'   => 'The record is locked. Unlock it to edit.',
    'cannot_delete_locked' => 'The record is locked. Unlock it to delete.',
    'bulk_skipped_locked'  => ':count locked record(s) were skipped.',
];
