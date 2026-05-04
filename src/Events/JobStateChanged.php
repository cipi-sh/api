<?php

namespace CipiApi\Events;

use CipiApi\Models\CipiJob;

class JobStateChanged
{
    /**
     * @param string $state One of: started, completed, failed
     */
    public function __construct(
        public CipiJob $job,
        public string $state,
    ) {}
}
