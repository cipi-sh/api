<?php

namespace CipiApi\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CipiTokenCreate extends Command
{
    protected $signature = 'cipi:token-create
                            {--name= : Token name (slug)}
                            {--abilities= : Comma-separated abilities}
                            {--expires-at= : Expiry date YYYY-MM-DD, empty for never}';

    protected $description = 'Create a Cipi API token';

    public function handle(): int
    {
        $user = User::where('email', 'cipi-api@local')->first();
        if (! $user) {
            $this->error('API user not found. Run: php artisan cipi:seed-user');
            return 1;
        }

        $name = $this->option('name');
        $abilitiesStr = $this->option('abilities') ?? '';
        $expiresAt = $this->option('expires-at');

        $abilities = array_filter(array_map('trim', explode(',', $abilitiesStr)));
        $expires = ($expiresAt && $expiresAt !== '') ? \Carbon\Carbon::parse($expiresAt) : null;

        $token = $user->createToken($name, $abilities, $expires);

        $this->line('');
        $this->line('Token created successfully.');
        $this->line('');
        $this->line('Token: ' . $token->plainTextToken);
        $this->line('');

        return 0;
    }
}
