<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development only (`php artisan migrate:fresh --seed`).
 * Log in as admin@example.com / password. The servers are fake, so panel
 * actions on them fail with "Cannot reach …".
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
        ]);

        $whm = Server::factory()->create(['name' => 'WHM 1', 'hostname' => 'whm1.example.com']);
        $plesk = Server::factory()->plesk()->create(['name' => 'Plesk 1', 'hostname' => 'plesk1.example.com']);

        $shared = ProductGroup::create([
            'slug' => 'shared-hosting',
            'names' => ['en' => 'Shared hosting', 'el' => 'Φιλοξενία ιστοσελίδων'],
            'descriptions' => ['en' => 'Websites and email on fast servers.', 'el' => 'Ιστοσελίδες και email σε γρήγορους servers.'],
            'icon' => 'server',
            'sort' => 1,
            'features' => [
                ['key' => 'disk', 'names' => ['en' => 'Disk space', 'el' => 'Χώρος']],
                ['key' => 'sites', 'names' => ['en' => 'Websites', 'el' => 'Ιστοσελίδες']],
                ['key' => 'ssl', 'names' => ['en' => 'Free SSL', 'el' => 'Δωρεάν SSL']],
                ['key' => 'backup', 'names' => ['en' => 'Daily backup', 'el' => 'Ημερήσιο backup']],
            ],
        ]);

        Product::factory()->create(['name' => 'Hosting Basic', 'package' => 'basic', 'product_group_id' => $shared->id,
            'features' => ['disk' => '10 GB', 'sites' => '1', 'ssl' => 'Yes', 'backup' => 'No']])->servers()->attach($whm);
        Product::factory()->create(['name' => 'Hosting Pro', 'package' => 'pro', 'product_group_id' => $shared->id,
            'features' => ['disk' => '50 GB', 'sites' => '10', 'ssl' => 'Yes', 'backup' => 'Yes'], 'prices' => [
                'annually' => ['enabled' => true, 'price_cents' => 12000, 'setup_cents' => 0],
                'biennially' => ['enabled' => true, 'price_cents' => 22000, 'setup_cents' => 0],
            ]])->servers()->attach($whm);
        $maintenance = Product::factory()->maintenance()->create();
        Product::factory()->priced(1500)->create(['name' => '.gr domain', 'type' => 'domain', 'package' => null]);

        $today = now()->startOfDay();

        foreach ([
            ['days' => -12, 'server' => $whm, 'status' => ServiceStatus::Suspended],
            ['days' => -3, 'server' => $plesk],
            ['days' => 5, 'server' => $whm, 'maintenance' => true],
            ['days' => 20, 'server' => $plesk, 'cycle' => BillingCycle::Monthly, 'price' => 900],
            ['days' => 140, 'server' => $whm, 'maintenance' => true],
        ] as $row) {
            $client = Client::factory()->create();
            $due = $today->copy()->addDays($row['days']);
            $cycle = $row['cycle'] ?? BillingCycle::Annually;

            $hosting = Service::factory()->for($client)->for($row['server'])->create([
                'label' => 'Web hosting',
                'billing_cycle' => $cycle,
                'price_cents' => $row['price'] ?? 6000,
                'start_date' => $due->copy()->subMonthsNoOverflow($cycle->months()),
                'next_due_date' => $due,
                'status' => $row['status'] ?? ServiceStatus::Active,
                'suspension_reason' => isset($row['status']) ? Service::SUSPENDED_OVERDUE : null,
            ]);

            Service::factory()->for($client)->domain()->create([
                'domain' => $hosting->domain,
                'start_date' => $due->copy()->subYear(),
                'next_due_date' => $due,
            ]);

            if ($row['maintenance'] ?? false) {
                Service::factory()->for($client)->maintenance()->create([
                    'product_id' => $maintenance->id,
                    'label' => 'Maintenance',
                    'domain' => $hosting->domain,
                    'start_date' => $due->copy()->subYear(),
                    'next_due_date' => $due,
                ]);
            }
        }

        // A new order waiting to be created on the server.
        Service::factory()->for(Client::factory()->create())->for($whm)->create([
            'label' => 'Hosting Basic',
            'status' => ServiceStatus::Pending,
            'username' => null,
            'start_date' => $today,
            'next_due_date' => $today,
        ]);
    }
}
