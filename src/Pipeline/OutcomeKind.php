<?php

namespace AgenticActions\Pipeline;

/**
 * How a call ended.
 *
 * @internal
 */
enum OutcomeKind: string
{
    /** handle() ran and returned. */
    case Ok = 'ok';

    /** 422. */
    case Invalid = 'invalid';

    /** 404: unknown, not exposed, not a member, shouldRegister() false, guest refused, token refused. */
    case NotFound = 'not_found';

    /** 403. */
    case Denied = 'denied';

    /** A Refusal: its status, default 409. */
    case Refused = 'refused';

    /** 500: a crash, MissingContext, a Read that wrote. */
    case Failed = 'failed';
}
