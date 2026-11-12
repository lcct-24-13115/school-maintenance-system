<?php

namespace App\Services;

use DomainException;

/** The ticket cannot be routed. `$field` is the form field the message belongs to. */
class TicketRoutingException extends DomainException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
