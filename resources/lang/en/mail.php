<?php

return [
    // ── DownloadReady ─────────────────────────────────────────────────
    'download_ready_intro'     => 'Your :type export is ready to download.',
    'download_ready_filename'  => 'File: :filename',
    'download_ready_expires'   => 'The link expires on :date. After that you will have to generate the report again.',
    'download_ready_footer'    => 'If you did not request this action you can ignore this email.',

    // ── DownloadFailed ────────────────────────────────────────────────
    'download_failed_intro'    => 'We could not complete the :type export you requested.',
    'download_failed_filename' => 'Requested file: :filename',
    'download_failed_reason'   => 'Reason: :reason',
    'download_failed_retry'    => 'Try again from the module screen. If the problem persists, contact support.',

    // ── PlanChanged ──────────────────────────────────────────────────
    'plan_changed_subject'        => 'Your plan changed: :workspace is now :plan',
    'plan_changed_intro'          => 'Your workspace plan changed from :previous to :current.',
    'plan_upgraded_intro'         => 'Your workspace upgraded from :previous to :current. You now have access to the new features included in the plan.',
    'plan_downgraded_intro'       => 'Your workspace downgraded from :previous to :current. Some features may no longer be available.',
    'plan_downgraded_apis'        => 'If you had API keys generated, they remain saved but are paused until you return to a plan with the API feature. Requests will respond with 402.',
    'plan_downgraded_upgrade_hint'=> 'You can upgrade the plan at any time from your workspace module to reactivate everything.',
    'plan_changed_footer'         => 'If you did not expect this change, contact the platform administrator.',

    // ── Welcome (account creation) ──────────────────────────────────
    'welcome_subject'              => 'Welcome to :app',
    'welcome_greeting'             => 'Hi :name,',
    'welcome_intro'                => 'An account has been created for you on :app. Below are your credentials to access for the first time.',
    'welcome_credentials_intro'    => 'Your login details are:',
    'welcome_email_label'          => 'Email',
    'welcome_password_label'       => 'Password',
    'welcome_button'               => 'Sign in to the system',
    'welcome_change_password_hint' => 'For your security, change your password from your profile as soon as you log in.',
    'welcome_salutation'           => 'Welcome aboard, :app',

    // ── PasswordChanged (security alert) ────────────────────────────
    'password_changed_subject'        => 'Your password was updated — :app',
    'password_changed_greeting'       => 'Hi :name,',
    'password_changed_intro_self'     => 'This is a confirmation that your password was changed successfully.',
    'password_changed_intro_admin'    => 'A system administrator changed your password. Contact them if you need the new credentials.',
    'password_changed_intro_reset'    => 'You completed the "forgot password" flow and your password has been reset.',
    'password_changed_when'           => 'Change date and time: :datetime.',
    'password_changed_security_warning'=> 'If you did NOT make this change, someone may have accessed your account. Reset your password immediately using the button below.',
    'password_changed_button'         => 'Reset my password',
    'password_changed_salutation'     => 'Regards, the :app team',

    // Account deletion request (erasure right — data protection)
    'deletion_request_subject'  => ':app — Account deletion request',
    'deletion_request_greeting' => 'Hello :name,',
    'deletion_request_body'     => ':name (:email) requested the deletion of their account and personal data.',
    'deletion_request_hint'     => 'Process the request from the Users module (delete the user). The request was recorded in the audit log.',
    'deletion_request_button'   => 'Go to Users',
];
