<?php

namespace CipiApi\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CipiTokenRevoke extends Command
{
    protected $signature = 'cipi:token-revoke {--name= : Token name to revoke}';

    protected $description = 'Revoke a Cipi API token';

    public function handle(): int
    {
        $name = $this->option('name');
        if (! $name) {
            $this->error('Usage: cipi api token revoke <name>');
            return 1;
        }

        $user = User::where('email', 'cipi-api@local')->first();
        if (! $user) {
            $this->error('API user not found.');
            return 1;
        }

        $deleted = $user->tokens()->where('name', $name)->delete();
        if ($deleted > 0) {
            $this->info("Token '{$name}' revoked.");
            return 0;
        }

        $this->error("Token '{$name}' not found.");
        return 1;
    }
}
