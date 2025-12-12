<?php

namespace Database\Seeders;

use App\Enums\Crypto;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Database\Seeder;

class AssetsTableSeeder extends Seeder
{
    /**
     * Seed users' crypto assets to enable SELL orders.
     */
    public function run(): void
    {
        // Ensure two known users exist from UsersTableSeeder
        /** @var User|null $user2 */
        $user2 = User::query()->where('email', 'user2@example.com')->first();
        /** @var User|null $user3 */
        $user3 = User::query()->where('email', 'user3@example.com')->first();

        if ($user2) {
            Asset::query()->updateOrCreate(
                ['user_id' => $user2->id, 'symbol' => Crypto::BTC],
                [
                    'amount'        => '5.000000000000000000',
                    'locked_amount' => '0',
                ]
            );
        }

        if ($user3) {
            Asset::query()->updateOrCreate(
                ['user_id' => $user3->id, 'symbol' => Crypto::ETH],
                [
                    'amount'        => '20.000000000000000000',
                    'locked_amount' => '0',
                ]
            );
        }
    }
}
