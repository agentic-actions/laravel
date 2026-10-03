<?php

return [
    'abilities' => [
        'read' => 'Read what you can see',
        'write' => 'Create and change what you can change',
        'destructive' => 'Delete what you can delete',
        'external' => 'Act outside :app for you, such as sending messages',
    ],
    'title' => 'Connect :client to :app?',
    'can' => ':client will be able to:',
    'tenant' => 'Only in :tenant.',
    'redirect' => 'After you answer, you go to :host.',
    'local' => 'After you answer, you go back to an app on this device (:host).',
    'person' => 'Signed in as :person',
    'trust' => 'Continue only if you started connecting :client yourself, just now.',
    'allow' => 'Allow',
    'deny' => 'Deny',
    'refused' => 'This app cannot be connected to this address. Check the URL you entered.',
];
