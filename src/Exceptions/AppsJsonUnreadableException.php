<?php

namespace CipiApi\Exceptions;

use Exception;

class AppsJsonUnreadableException extends Exception
{
    public function __construct(string $path)
    {
        parent::__construct(
            "Cannot read apps.json at {$path}. Ensure CIPI_APPS_JSON is correct and the web server user has read access. " .
            "If the file has restricted permissions (chmod 600), add to sudoers: <user> ALL=(ALL) NOPASSWD: /usr/bin/cat {$path}"
        );
    }
}
