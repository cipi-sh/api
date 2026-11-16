<?php

namespace CipiApi\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class CipiTokenCreate extends Command
{
    protected $signature = 'cipi:token-create
                            {--name= : Token name (slug)}
                            {--abilities= : Comma-separated abilities, or * for full access}
                            {--full-access : Every ability, including the ones later versions add (the same as --abilities=*)}
                            {--expires-at= : Expiry date YYYY-MM-DD, empty for never}';

    protected $description = 'Create a Cipi API token';

    /** Sanctum's wildcard ability: a token that holds it can do everything. */
    public const FULL_ACCESS = '*';

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

        $abilities = array_values(array_filter(array_map('trim', explode(',', $abilitiesStr))));
        $expires = ($expiresAt && $expiresAt !== '') ? \Carbon\Carbon::parse($expiresAt) : null;

        // Full access is Sanctum's wildcard: tokenCan() answers yes to every
        // ability, the ones a later release adds included — which a token
        // holding today's whole list does not get. It stands alone: next to
        // it any other ability would say nothing.
        $fullAccess = (bool) $this->option('full-access')
            || in_array(self::FULL_ACCESS, $abilities, true)
            || in_array('full-access', $abilities, true);
        if ($fullAccess) {
            $abilities = [self::FULL_ACCESS];
        } else {
            $unknown = array_diff($abilities, array_keys((array) config('cipi.token_abilities', [])));
            if ($unknown !== []) {
                $this->warn('Not an ability of this API version (kept on the token, grants nothing here): ' . implode(', ', $unknown));
            }
        }

        if ($abilities === []) {
            $this->error('No abilities given. Use --abilities=a,b,… or --full-access.');
            return 1;
        }

        $token = $user->createToken($name, $abilities, $expires);

        $this->line('');
        $this->line('Token created successfully.');
        if ($fullAccess) {
            $this->line('Abilities: * (full access — every endpoint and the MCP server)');
        }
        $this->line('');
        $this->line('Token: ' . $token->plainTextToken);
        $this->line('');

        return 0;
    }
}
