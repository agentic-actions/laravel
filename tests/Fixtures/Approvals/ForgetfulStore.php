<?php

namespace Tests\Fixtures\Approvals;

use Tests\Fixtures\Streaming\PlainStore;

/**
 * A conversation store that stores and nothing more: it reads neither the calls a conversation waits on nor whom it
 * belongs to, so no agent is offered a Destructive or External action while it is bound.
 */
final class ForgetfulStore extends PlainStore {}
