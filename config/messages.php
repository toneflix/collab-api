<?php

$frontend = rtrim(env('FRONTEND_URL', 'https://collab.toneflix.ng'), '/');

return [
    /*
    |--------------------------------------------------------------------------
    | Signature
    |--------------------------------------------------------------------------
    |
    | This is required and will be attached to the end of every message
    |
    */
    'signature' => 'Regards.<br />',

    /*
    |--------------------------------------------------------------------------
    | Messages
    |--------------------------------------------------------------------------
    |
    | The message body is made up of lines, each line
    | represents a new line in the message sent, inline html is also supported
    | If a line is required to be a button it should be an array in the
    | following format: ['link' => 'https://tech4all.greysoft.ng/login', 'title' => 'Get Started', 'color' => '#fff']
    | the color property is optional.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | SendCode messages
    |--------------------------------------------------------------------------
    |
    | These are messages sent during account or request verification.
    |
    */
    'send_code::reset' => [
        'subject' => 'Reset your Toneflix Music password.',
        'lines' => [
            'Hello :firstname,',
            'You are receiving this email because we received a password reset request for your account on Toneflix Music.',
            'Use the code or link below to recover your account.',
            '<h3 style="text-align: center;">:code</h3>',
            [
                'link' => $frontend . '/reset/password?token=:token',
                'title' => 'Reset Password',
            ],
            'This password reset code will expire in :duration.',
            'If you did not request a password reset, no further action is required.',
        ],
    ],
    'send_code::verify' => [
        'subject' => 'Verify your account on Toneflix Music.',
        'lines' => [
            'Hello :firstname,',
            'You are receiving this email because you created an account on <b>Toneflix Music</b> and we needed to verify that you own this email address. <br />Use the code or link below to verify your email address.',
            '<h3 style="text-align: center;">:code</h3>',
            [
                'link' => $frontend . '/account/verify/email?code=:code',
                'title' => 'Verify Account',
            ],
            'This verification code will expire in :duration.',
            'If you do not recognize this activity, no further action is required as the associated account will be deleted in few days if left unverified.',
        ],
    ],
    'send_code::otp' => [
        'subject' => 'Your One Time Password.',
        'lines' => [
            'Use the code below to verify your request.',
            '<h3 style="text-align: center;">:code</h3>',
            'This OTP will expire in :duration.',
            'If you do not recognize this request no further action is required or you can take steps to secure your account.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | SendVerified messages
    |--------------------------------------------------------------------------
    |
    | These are messages sent after an account is verified.
    |
    */
    'send_verified' => [
        'subject' => 'Welcome to the Toneflix Music community.',
        'lines' => [
            'Hello :firstname,',
            'Your Toneflix Music account has been verified successfully and we want to use this opportunity to welcome you to our community.',
            [
                'link' => $frontend . '/login',
                'title' => 'Get Started',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Other messages
    |--------------------------------------------------------------------------
    |
    |
    */
    'email_verification' => [
        'subject' => 'Please verify your email.',
        'lines' => [
            'Hello :fullname,',
            'You initiated an account opening process at :app_name, please use the code below to complete your request',
            '<h3 style="text-align: center;">:code</h3>',
            'If you need any further assistance please reach out to support.',
        ],
    ],
    'welcome' => [
        'subject' => ':firstname, welcome to :app_name.',
        'lines' => [
            'Hello :fullname,',
            "We are happy to have you onboard :app_name, your registration was successful and we can't wait to see what you do next.",
            "If you do need any assistance please don't fail to reach out to one of our numerous support channels.",
        ],
    ],
    'project::request' => [
        'subject' => 'Request to join project.',
        'lines' => [
            'Hello :fullname,',
            ':sendername is requesting to join project: :title as a :role.',
        ],
    ],
    'project::request_approved' => [
        'subject' => 'Request to join project approved.',
        'lines' => [
            'Hello :fullname,',
            'Your request to join project: :title as a :role as been approved.',
        ],
    ],
    'project::invitation' => [
        'subject' => 'Invitation to join project.',
        'lines' => [
            'Hello :fullname,',
            ':sendername is inviting you to join project: :title as a :role.',
        ],
    ],
    'project::invitation_accepted' => [
        'subject' => ':sendername joined project.',
        'lines' => [
            'Hello :fullname,',
            ':sendername has accepted your invitaion to join project: :title as a :role.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity messages
    |--------------------------------------------------------------------------
    |
    | Sent by the App\Notifications\ActivityNotification classes to the app's
    | notification list, by email and by push. Each has a subject (also the push
    | title), a body and the label of the button that opens the app.
    |
    */
    'activity::collabs' => [
        'request' => [
            'subject' => ':sendername wants to join :title',
            'body' => ':sendername asked to join your project ":title" as :role.',
            'action' => 'Review request',
        ],
        'invitation' => [
            'subject' => ':sendername invited you to :title',
            'body' => ':sendername invited you to join the project ":title" as :role.',
            'action' => 'View invitation',
        ],
        'request_approved' => [
            'subject' => 'You\'re in: :title',
            'body' => 'Your request to join ":title" as :role was approved.',
            'action' => 'Open project',
        ],
        'invitation_accepted' => [
            'subject' => ':sendername joined :title',
            'body' => ':sendername accepted your invitation to join ":title" as :role.',
            'action' => 'Open project',
        ],
    ],
    'activity::messages' => [
        'one' => [
            'subject' => 'New message in :title',
            'body' => ':senders: :preview',
            'action' => 'Reply',
        ],
        'many' => [
            'subject' => ':count new messages in :title',
            'body' => ':senders sent :count messages you haven\'t read.',
            'action' => 'Read messages',
        ],
    ],
    'activity::sales' => [
        'release' => [
            'subject' => ':payer bought :title',
            'body' => ':payer bought your release ":title". You earned :amount.',
            'action' => 'See earnings',
        ],
        'stems' => [
            'subject' => ':payer bought the stems for :title',
            'body' => ':payer bought the stems for ":title". You earned :amount.',
            'action' => 'See earnings',
        ],
        'tip' => [
            'subject' => ':payer sent you a tip',
            'body' => ':payer sent you a tip. You earned :amount.',
            'action' => 'See earnings',
        ],
        'tip_note' => [
            'subject' => ':payer sent you a tip',
            'body' => ':payer sent you a tip: ":note" You earned :amount.',
            'action' => 'See earnings',
        ],
        'referral' => [
            'subject' => 'You earned :amount from an invite',
            'body' => ':payer, who joined with your invite, made a payment. You earned :amount.',
            'action' => 'See earnings',
        ],
        'streams' => [
            'subject' => 'You earned :amount from streams',
            'body' => 'Your paid streams in :title earned :amount.',
            'action' => 'See earnings',
        ],
    ],
    'activity::payouts' => [
        'paid' => [
            'subject' => 'Your :amount withdrawal was paid',
            'body' => ':amount has been sent to your :bank account.',
            'action' => 'See withdrawals',
        ],
        'failed' => [
            'subject' => 'Your :amount withdrawal didn\'t go through',
            'body' => 'Your :amount withdrawal to :bank failed: :reason The amount is back in your balance.',
            'action' => 'See withdrawals',
        ],
        'rejected' => [
            'subject' => 'Your :amount withdrawal was declined',
            'body' => 'Your :amount withdrawal was declined. The amount is back in your balance.',
            'action' => 'See withdrawals',
        ],
        'rejected_reason' => [
            'subject' => 'Your :amount withdrawal was declined',
            'body' => 'Your :amount withdrawal was declined: ":reason" The amount is back in your balance.',
            'action' => 'See withdrawals',
        ],
    ],
];
