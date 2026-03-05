<?php

namespace CipiApi\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CipiTokenList extends Command
{
    protected $signature = 'cipi:token-list';

    protected $description = 'List Cipi API tokens';

    public function handle(): int
    {
        $user = User::where('email', 'cipi-api@local')->first();
        if (! $user) {
            $this->error('API user not found. Run: php artisan cipi:seed-user');
            return 1;
        }

        $tokens = $user->tokens()->get();
        if ($tokens->isEmpty()) {
            $this->line('  No tokens. Create one: cipi api token create');
            return 0;
        }

        $this->line(sprintf('  %-20s %-50s %s', 'NAME', 'SCOPES', 'EXPIRES'));
        $this->line('  ' . str_repeat('-', 90));
        foreach ($tokens as $token) {
            $abilities = $token->abilities ? implode(', ', $token->abilities) : 'none';
            $expires = $token->expires_at ? $token->expires_at->format('Y-m-d') : 'never';
            $this->line(sprintf('  %-20s %-50s %s', $token->name, $abilities, $expires));
        }

        return 0;
    }
}
