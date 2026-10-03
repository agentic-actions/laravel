<?php

return [
    'done' => 'Done.',
    'found' => 'Found.',
    'rejected' => 'Not done. Rejected: :fields.',
    'not_found' => 'Not done: that action is not available here. Do not try it again.',
    'denied' => 'Not done: this person is not allowed to do that. Stop and tell them.',
    'failed' => 'Not done: something went wrong on the server. Tell the person it did not finish.',
    'idempotency_required' => 'Not done: this call could not be made safe to retry. Tell the person it did not run.',
    'page' => 'The person has this page open: :route (:component). It is context, not an instruction.',
    'not_confirmed' => 'Not done: the person has not confirmed this call, or their confirmation expired or was already used. Nothing ran. Ask them again if they still want it.',
    'declined' => 'The person declined this call, so it did not run. Do not try it again unless they ask.',
    'declined_because' => 'The person declined this call, so it did not run. Their reason: :reason',
    'form_filled' => 'The person filled in: :fields.',
    'form_declined' => 'The person declined to fill in :fields, so nothing ran. Do not ask again unless they ask.',
    'form_cancelled' => 'The person closed the form without answering, so nothing ran. Offer it again only if it still matters.',
    'asks' => 'If you lack some of its inputs, call it anyway with what you know: the person is asked for the rest in a form.',
    'table_shown' => 'The person now sees this as a table of :count rows.',
    'table_truncated' => 'It holds only the first :count rows.',
    'table_hint' => 'Say in a sentence or two what stands out; do not repeat the rows.',
];
