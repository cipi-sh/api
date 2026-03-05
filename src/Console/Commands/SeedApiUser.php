<?php

namespace CipiApi\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class SeedApiUser extends Command
{
    protected $signature = 'cipi:seed-user';

    protected $description = 'Create the Cipi API service user (idempotent)';

    public function handle(): int
    {
        User::firstOrCreate(
            ['email' => 'cipi-api@local'],
            [
                'name' => 'Cipi API',
                'password' => Hash::make(bin2hex(random_bytes(32))),
            ]
        );

        $this->info('Cipi API user ready.');
        return 0;
    }
}
