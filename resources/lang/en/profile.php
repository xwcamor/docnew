<?php

return [
    // Page
    'title'              => 'My profile',
    'subtitle'           => 'Manage your personal info and account settings.',

    // Tabs
    'tab_info'           => 'Information',
    'tab_password'       => 'Password',
    'tab_preferences'    => 'Preferences',

    // Info section
    'name'               => 'Name',
    'email'              => 'Email',
    'email_readonly_hint' => 'Email identifies your account and cannot be changed here. Contact your administrator if you need to change it.',
    'tenant'             => 'Organization',
    'country'            => 'Country',
    'timezone'           => 'Timezone',
    'timezone_hint'      => 'Leave "Use workspace\'s" to inherit from your workspace or country.',
    'timezone_inherit'   => 'Use workspace\'s',
    'roles'              => 'Roles',
    'member_since'       => 'Member since',
    'save_info'          => 'Save information',

    // Password section
    'password_title'     => 'Change password',
    'password_subtitle'  => 'A strong password has at least 8 characters, letters and numbers.',
    'current_password'   => 'Current password',
    'new_password'       => 'New password',
    'confirm_password'   => 'Confirm new password',
    'change_password'    => 'Change password',
    'no_password_hint'   => 'You don\'t have a local password yet (you signed in with Google). Set one to also be able to log in with email.',
    'password_updated'   => 'Password updated successfully.',
    'current_password_incorrect' => 'The current password is incorrect.',

    // Handwritten signature (private image; stamped on reports the user
    // generates, as "Prepared by").
    'signature_title'   => 'My signature',
    'signature_hint'    => 'Image of your handwritten signature (PNG/JPG, white or transparent background, max 1 MB). It is stamped automatically on the PDF reports YOU generate, on the "Prepared by" line. Nobody else can use it: it is private and only applied by your own action.',
    'signature_upload'  => 'Upload signature',
    'signature_change'  => 'Change signature',
    'signature_remove'  => 'Remove',
    'signature_updated' => 'Signature updated.',
    'signature_removed' => 'Signature removed.',
    'signature_draw'       => 'Draw signature',
    'signature_draw_hint'  => 'Draw your signature with the mouse or your finger. You can clear and retry as many times as you want.',
    'signature_draw_clear' => 'Clear',
    'signature_draw_save'  => 'Save signature',

    // Auto-sign as approver (own consent, audited, revocable)
    'autosign_label' => 'Auto-sign reports as approver',
    'autosign_hint'  => 'If you are assigned as the workspace approver, your signature will be stamped automatically on reports WITHOUT individual approval. This is your authorization: it is recorded and you can revoke it anytime.',
    'autosign_needs_signature' => 'Upload your signature first to enable auto-sign.',

    // Privacy & data (ARCO rights — data protection law)
    'privacy_title'      => 'Privacy & data',
    'privacy_hint'       => 'Your rights over your personal data (access, rectification, erasure, objection).',
    'download_my_data'   => 'Download my data',
    'request_deletion'   => 'Request account deletion',
    'request_deletion_confirm' => 'Your request will be recorded and the workspace administrator will be notified to process the deletion. This does not delete your account immediately. Continue?',
    'request_deletion_ok'      => 'Yes, request it',
    'deletion_requested'       => 'Request recorded. The administrator has been notified.',
    'deletion_already_requested' => 'Your request is already recorded and the administrator was notified. No need to send it again.',
    'privacy_rights_note'      => 'To rectify your data, edit your profile. For other questions about your personal data, contact your workspace administrator.',

    // Profile photo (click on the avatar)
    'photo_change'  => 'Change profile photo',
    'photo_updated' => 'Profile photo updated.',

    // Preferences section
    'preferences_title'  => 'App preferences',
    'preferences_hint'   => 'These preferences can also be changed from the top bar.',
    'appearance_title'   => 'Appearance',
    'appearance_hint'    => 'Customize the colors and menu position. Saved to your account and follows you on any device.',
    'color_scheme'       => 'Color scheme',
    'scheme_contrast'    => 'High contrast',
    'menu_position'      => 'Menu layout',
    'menu_top'           => 'Full screen',
    'menu_side'          => 'With sidebar',
    'menu_bottom'        => 'Bottom',
    'menu_position_hint' => 'With sidebar: fixed menu on the left. Full screen: the menu opens from the hamburger (☰) and content uses the full width. Large screens only; on mobile the menu always opens from the top button.',
    'preferred_language' => 'Preferred language',
    'preferred_theme'    => 'Theme',
    'tour_status'        => 'Onboarding tours',
    'tours_completed'    => '{0} :count tours completed|{1} 1 tour completed|[2,*] :count tours completed',
    'reset_tours'        => 'Reset tours',
    'reset_tours_done'   => 'Tours reset. You\'ll see them again next time you enter each module.',
];
