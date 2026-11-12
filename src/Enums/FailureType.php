<?php

namespace Noop\Moloni\Enums;

enum FailureType: string
{
    /** Moloni rejected the input; retrying the same request will fail again. */
    case Validation = 'validation';

    /** The credentials, token or company access were refused. */
    case Authentication = 'authentication';

    /** Nothing was changed in Moloni; the request can safely be retried. */
    case Transient = 'transient';

    /** A write may or may not have been applied; never retry without reconciling first. */
    case Unknown = 'unknown';
}
