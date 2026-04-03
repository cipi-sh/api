<?php

namespace CipiApi\Exceptions;

use RuntimeException;
use Throwable;

class MysqlDatabaseListingUnavailableException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
