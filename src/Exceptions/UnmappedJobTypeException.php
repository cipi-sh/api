<?php

namespace CipiApi\Exceptions;

use LogicException;

/**
 * Thrown when code queues a job type that has no entry in {@see \CipiApi\Services\CipiJobAbilities::ABILITIES}.
 * Reading a job needs the ability of its type; a type without one could be read by nobody — or, before 1.36.1, by anybody.
 */
class UnmappedJobTypeException extends LogicException
{
    public function __construct(string $type)
    {
        parent::__construct(
            'Job type has no token ability (add it to CipiJobAbilities::ABILITIES): ' . $type
        );
    }
}
