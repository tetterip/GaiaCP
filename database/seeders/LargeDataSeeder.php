<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Invoices\GenerateInvoices;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A large made-up business, to see how the panel copes with thousands of clients (development only):
 *
 *     php artisan migrate:fresh --seed && LARGE_CLIENTS=3000 php artisan db:seed --class=LargeDataSeeder
 *
 * Each client gets one to three hosting accounts, domains and maintenance services spread over a year of due dates,
 * a payment for every period already paid, a few tickets, and the activity log rows that go with them; then the
 * daily run issues the payment notices for what is due soon or overdue.
 */
class LargeDataSeeder extends Seeder
{
    public function run(): void
    {
        $count = (int) (getenv('LARGE_CLIENTS') ?: 2000);
        $today = CarbonImmutable::today();
        $servers = Server::query()->pluck('id')->all() ?: [Server::factory()->create()->id];
        $products = Product::query()->where('type', ServiceType::Hosting)->pluck('id')->all();
        $admin = User::query()->value('id');
        $faker = fake();
        $faker->seed(2026);

        $this->command?->getOutput()->progressStart($count);

        for ($i = 0; $i < $count; $i++) {
            DB::transaction(function () use ($i, $today, $servers, $products, $admin, $faker) {
                $client = Client::query()->create([
                    'first_name' => $faker->firstName(),
                    'last_name' => $faker->lastName(),
                    'company' => $i % 3 === 0 ? $faker->company() : null,
                    'email' => "client{$i}@large.example",
                    'phone' => '+30 69'.$faker->numerify('########'),
                    'address1' => $faker->streetAddress(),
                    'city' => $faker->city(),
                    'postcode' => $faker->numerify('1####'),
                    'country' => 'GR',
                    'created_at' => $today->subDays(30 + $i % 700),
                ]);

                $services = [];
                foreach (range(1, 1 + $i % 3) as $n) {
                    $type = [ServiceType::Hosting, ServiceType::Domain, ServiceType::Maintenance][($i + $n) % 3];
                    $cycle = $type === ServiceType::Hosting && $n % 2 === 0 ? BillingCycle::Monthly : BillingCycle::Annually;
                    $due = $today->addDays(($i * 7 + $n * 31) % 400 - 35);
                    $status = match (true) {
                        $i % 41 === 0 => ServiceStatus::Terminated,
                        $due->lt($today->subDays(20)) && $type === ServiceType::Hosting => ServiceStatus::Suspended,
                        default => ServiceStatus::Active,
                    };
                    $domain = "site{$i}-{$n}.".['gr', 'com', 'eu'][$n % 3];

                    $service = Service::query()->create([
                        'client_id' => $client->id,
                        'type' => $type,
                        'product_id' => $type === ServiceType::Hosting && $products ? $products[$i % count($products)] : null,
                        'server_id' => $type === ServiceType::Hosting ? $servers[$i % count($servers)] : null,
                        'label' => match ($type) {
                            ServiceType::Hosting => 'Web hosting', ServiceType::Maintenance => 'Website maintenance', default => null
                        },
                        'domain' => $domain,
                        'username' => $type === ServiceType::Hosting ? 'u'.$i.'x'.$n : null,
                        'package' => $type === ServiceType::Hosting ? 'basic' : null,
                        'price_cents' => match ($type) {
                            ServiceType::Hosting => $cycle === BillingCycle::Monthly ? 900 : 6000, ServiceType::Domain => 1500, default => 12000
                        },
                        'currency' => 'EUR',
                        'billing_cycle' => $cycle,
                        'start_date' => $due->subMonthsNoOverflow($cycle->months() * (1 + $i % 4)),
                        'next_due_date' => $due,
                        'status' => $status,
                        'suspension_reason' => $status === ServiceStatus::Suspended ? Service::SUSPENDED_OVERDUE : null,
                    ]);

                    if ($type === ServiceType::Domain) {
                        $service->registration()->update(['registration_type' => DomainRegistrationType::Register, 'expires_at' => $due,
                            'nameservers' => ['ns1.large.example', 'ns2.large.example']]);
                    }
                    $services[] = $service;
                }

                $payments = [];
                $logs = [];
                foreach ($services as $service) {
                    $months = $service->billing_cycle->months();
                    for ($due = CarbonImmutable::parse($service->start_date); $due->lt($service->next_due_date) && $due->lte($today); $due = $due->addMonthsNoOverflow($months)) {
                        $paidOn = $due->subDays(($service->id + $due->month) % 9)->addDays($service->id % 5 === 0 ? 12 : 0);
                        $payments[] = [
                            'service_id' => $service->id, 'client_id' => $client->id, 'amount_cents' => $service->price_cents,
                            'currency' => 'EUR', 'paid_on' => $paidOn->toDateString(), 'method' => 'bank_transfer',
                            'due_date_before' => $due->toDateString(), 'due_date_after' => $due->addMonthsNoOverflow($months)->toDateString(),
                            'created_at' => $paidOn->setTime(10, 0), 'updated_at' => $paidOn->setTime(10, 0),
                        ];
                        $logs[] = [
                            'client_id' => $client->id, 'service_id' => $service->id, 'user_id' => $admin, 'event' => 'payment.recorded',
                            'description' => "Payment of {$service->price_cents} cents recorded", 'properties' => null, 'created_at' => $paidOn->setTime(10, 0),
                        ];
                    }
                }
                foreach (array_chunk($payments, 200) as $chunk) {
                    DB::table('payments')->insert($chunk);
                }
                foreach (array_chunk($logs, 200) as $chunk) {
                    DB::table('activity_logs')->insert($chunk);
                }

                if ($i % 4 === 0) {
                    $ticket = Ticket::query()->create([
                        'client_id' => $client->id,
                        'service_id' => $services[0]->id,
                        'subject' => $faker->sentence(5),
                        'status' => ['open', 'answered', 'on_hold', 'closed'][intdiv($i, 4) % 4],
                        'priority' => 'medium',
                        'last_reply_at' => $today->subHours($i % 200),
                    ]);
                    $ticket->messages()->create(['body' => $faker->paragraph(), 'from_client' => true]);
                }
            });

            $this->command?->getOutput()->progressAdvance();
        }

        $this->command?->getOutput()->progressFinish();
        $this->command?->info('Issuing the payment notices…');
        app(GenerateInvoices::class)->handle($today);
    }
}
