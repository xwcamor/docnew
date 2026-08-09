<?php

return [
    // Login
    'rememberme'       => 'Remember me',
    'forgot_password'  => 'Forgot your password?',
    'login'            => 'Log in',
    'back_to_login'    => 'Back to login',
    'login_google'     => 'Sign in with Google account',
    'disclaimer_affiliation'  => ':app is not affiliated with or endorsed by any company whose names or logos may appear uploaded by users.',
    'client_content_responsibility' => 'Content uploaded by each client is their sole responsibility.',
    'all_rights_reserved'     => 'All rights reserved.',
    'start_session'    => 'Session started',
    'end_session'      => 'Session ended',
    'error_session'    => 'Invalid username or password',
    'locked'           => 'Too many failed attempts. Your account is temporarily locked. Try again in :minutes minutes.',

    // Password reset email
    'password_reset_subject'    => 'Reset your password — :app',
    'password_reset_greeting'   => 'Hello!',
    'password_reset_line_intro' => 'You are receiving this email because we received a password reset request for your account.',
    'password_reset_button'     => 'Reset password',
    'password_reset_line_expire'=> 'This link will expire in :count minutes.',
    'password_reset_line_ignore'=> 'If you did not request a password reset, no further action is required.',
    'password_reset_salutation' => 'Regards, the :app team',
    'login_error'      => 'Something went wrong or you have rejected the app.',
    'account_inactive' => 'Your account is not active. Please contact the administrator.',
    'account_created_needs_activation' => 'Your account has been created but needs to be activated.',

    // Forgot Password
    'request_title'         => 'Recover password',
    'request_message'       => 'Type your email and we will send you a link to set a new password.',
    'send_reset_link'       => 'Send link',
    'reset_title'           => 'Set a new password',
    'reset_message'         => 'Choose the password you will use from now on.',
    'reset_password_button' => 'Save password',

    // Recover password — full screen
    'forgot_tagline'        => 'Can\'t get in?',
    'forgot_back'           => 'Back',
    'forgot_point_link'     => 'A link goes to your work email',
    'forgot_point_minutes'  => 'Takes a minute, straight from your phone',
    'forgot_point_nosupport' => 'No need to call anyone',
    'forgot_remembered'     => 'Remembered it?',
    'forgot_sent_title'     => 'Check your email',
    'forgot_sent_body'      => 'If :email is registered, the link to set your password is there.',
    'forgot_notfound_title' => 'Can\'t find it?',
    'forgot_tip_wait'       => 'It can take a minute or two.',
    'forgot_tip_spam'       => 'Check your junk mail folder.',
    'forgot_tip_typo'       => 'Make sure the email is spelled right.',
    'forgot_resend'         => 'Send it again',
    'forgot_other_email'    => 'Use another email',

    // New password — full screen
    'reset_tagline'         => 'Choose your password',
    'reset_new_password'    => 'New password',
    'reset_repeat_password' => 'Repeat the password',
    'reset_min_placeholder' => 'At least 8 characters',
    'reset_repeat_placeholder' => 'Type it again',
    'reset_rule_length'     => 'At least 8 characters',
    'reset_rule_mix'        => 'Mix letters and numbers',
    'reset_rule_instant'    => 'It works right away',

    // First time in: users carried over from the old system have no password
    // of their own, so "forgot my password" is their ONLY way in. If the login
    // screen doesn't say so, they stay locked out.
    'first_time_title' => 'First time here?',
    'first_time_body'  => 'If your account comes from the old system you don\'t have a password yet. Tap "Forgot your password?" and set yours with the link you receive.',

    // Token Reset
    'reset'     => 'Your password has been reset!',
    'sent'      => 'We have emailed your password reset link!',
    'throttled' => 'Please wait before retrying.',
    'token'     => 'This password reset token is invalid.',
    'user'      => "We can't find a user with that email address.",

    // Login page UI
    'tagline'                  => 'Daily safety documentation on site',
    'feature_tests'            => 'AST, PTF, PPE, hand tools and any format your company defines',
    'feature_health'           => 'Face-verified signatures with the evidence kept',
    'feature_methods'          => 'One worker, one identity, across every contractor',
    'feature_reports'          => 'Signed, auditable work plans',
    'signin_subtitle'          => 'Enter your credentials to continue',
    'verify_report'            => 'Report verification',
    'email'                    => 'Email',
    'password'                 => 'Password',
    'email_placeholder'        => 'you@company.com',
    'show_password'            => 'Show password',
    'hide_password'            => 'Hide password',
    'or_continue_with'         => 'or continue with',
    'continue_with_google'     => 'Continue with Google',
    'disclosure'               => 'By signing in you accept the :terms and :privacy.',
    'terms_short'              => 'Terms',
    'privacy_short'            => 'Privacy',
    'app_default_name'         => 'DOCUFIZ',

    // Recorded Terms/Privacy acceptance modal (data protection law).
    'legal_modal_title'  => 'Terms and Privacy Policy',
    'legal_modal_body'   => 'To keep using the system you must review and accept the Terms of Service and the Privacy Policy (personal data processing). Your acceptance is recorded with date and version.',
    'legal_modal_check'  => 'I have read and accept the Terms of Service and the Privacy Policy.',
    'legal_modal_accept' => 'Accept and continue',
    'terms'              => 'Terms of Service',
    'privacy'            => 'Privacy Policy',
];