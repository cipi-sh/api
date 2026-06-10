<?php

namespace CipiApi\Console\Commands;

use Illuminate\Console\Command;

class CipiTokenAbilities extends Command
{
    protected $signature = 'cipi:token-abilities';

    protected $description = 'List token abilities as ability|description lines (for cipi api token create)';

    public function handle(): int
    {
        foreach (config('cipi.token_abilities', []) as $ability => $description) {
            $this->line("{$ability}|{$description}");
        }

        return 0;
    }
}
