<?php

namespace Database\Seeders;

use App\Actions\Leads;
use App\Actions\PlaceOrder;
use App\Actions\Quotes;
use App\Actions\RecordInvoicePayment;
use App\Actions\Tickets;
use App\Enums\AdminArea;
use App\Enums\BillingCycle;
use App\Enums\DomainRegistrationType;
use App\Enums\InvoiceStatus;
use App\Enums\LeadStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Invoices\GenerateInvoices;
use App\Models\Announcement;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductTag;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\Service;
use App\Models\TicketDepartment;
use App\Models\Tld;
use App\Models\User;
use App\Support\Demo;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The public demo's made-up data (App\Support\Demo, gaiacp:demo-reset): a small hosting company with about 25
 * clients, their hosting, domains and care plans on three demo servers, a year of payments, the payment notices
 * the daily run would have issued, a pending order, tickets, leads, quotes, knowledge base articles and
 * announcements. Dates are relative to today. No Faker, so it runs where dev packages are not installed.
 */
class DemoSeeder extends Seeder
{
    /** first, last, company, locale, country, city, [hosting product, cycle, days to due, status], maintenance? */
    private const CLIENTS = [
        ['Alex', 'Morgan', 'Morgan Studio', 'en', 'GB', 'London', ['pro', 'annually', 9, 'active'], true],
        ['Maria', 'Papadopoulou', 'Papadopoulou Bakery', 'el', 'GR', 'Athens', ['basic', 'annually', -16, 'suspended'], false],
        ['Nikos', 'Georgiou', null, 'el', 'GR', 'Thessaloniki', ['pro', 'monthly', 3, 'active'], false],
        ['Sofia', 'Konstantinou', 'Konstantinou Law', 'el', 'GR', 'Patras', ['business', 'annually', 41, 'active'], true],
        ['Lukas', 'Schneider', 'Schneider Fotografie', 'en', 'DE', 'Munich', ['pro', 'annually', 120, 'active'], false],
        ['Emma', 'Laurent', null, 'en', 'FR', 'Lyon', ['basic', 'annually', -4, 'active'], false],
        ['Giorgos', 'Dimitriou', 'Dimitriou Travel', 'el', 'GR', 'Heraklion', ['business', 'annually', 12, 'active'], true],
        ['Olivia', 'Brown', 'Brown & Co', 'en', 'GB', 'Bristol', ['pro', 'annually', 200, 'active'], false],
        ['Eleni', 'Vasileiou', null, 'el', 'GR', 'Larissa', ['basic', 'monthly', 18, 'active'], false],
        ['Marco', 'Rossi', 'Rossi Ristorante', 'en', 'IT', 'Milan', ['pro', 'annually', 1, 'active'], true],
        ['Anna', 'Nikolaou', 'Nikolaou Pharmacy', 'el', 'GR', 'Volos', ['basic', 'annually', 65, 'active'], false],
        ['Jan', 'de Vries', 'De Vries Design', 'en', 'NL', 'Utrecht', ['business', 'biennially', 300, 'active'], false],
        ['Kostas', 'Ioannou', null, 'el', 'GR', 'Ioannina', ['basic', 'annually', -30, 'suspended'], false],
        ['Chloe', 'Martin', 'Martin Yoga', 'en', 'FR', 'Nice', ['basic', 'annually', 150, 'active'], false],
        ['Dimitra', 'Alexiou', 'Alexiou Jewellery', 'el', 'GR', 'Athens', ['pro', 'annually', 27, 'active'], true],
        ['Pedro', 'Santos', null, 'en', 'PT', 'Porto', ['basic', 'monthly', 6, 'active'], false],
        ['Yiannis', 'Christou', 'Christou Builders', 'el', 'GR', 'Chania', ['business', 'annually', 88, 'active'], false],
        ['Hannah', 'Fischer', null, 'en', 'AT', 'Vienna', ['pro', 'annually', -2, 'active'], false],
        ['Katerina', 'Pappa', 'Pappa Cosmetics', 'el', 'GR', 'Kalamata', ['basic', 'annually', 230, 'active'], false],
        ['Tom', 'Wilson', 'Wilson Garage', 'en', 'IE', 'Dublin', ['basic', 'annually', null, 'terminated'], false],
        ['Vasilis', 'Antoniou', null, 'el', 'GR', 'Rhodes', ['pro', 'annually', 55, 'active'], false],
        ['Sara', 'Lindqvist', 'Lindqvist Café', 'en', 'SE', 'Gothenburg', ['basic', 'annually', 175, 'active'], true],
    ];

    private CarbonImmutable $today;

    /** @var array<string, Product> */
    private array $products = [];

    /** @var list<Server> */
    private array $servers = [];

    private Registrar $registrar;

    public function run(): void
    {
        $this->today = CarbonImmutable::today();

        $this->settings();
        [$admin, $support] = $this->admins();
        $this->catalogue();

        foreach (self::CLIENTS as $index => $row) {
            $this->client($index, $row);
        }

        $this->history();
        $this->notices();
        $this->order();
        $this->support($support);
        $this->sales($admin);
        $this->content($admin);
        $this->uptime();
    }

    private function settings(): void
    {
        Settings::save([
            'company.name' => 'Aurora Hosting',
            'company.email' => 'hello@aurora-hosting.example',
            'company.phone' => '+30 210 000 0000',
            'company.website' => 'https://aurora-hosting.example',
            'company.address' => "12 Example Street\n10552 Athens\nGreece",
            'languages.enabled' => ['en', 'el'],
            'region.timezone' => 'Europe/Athens',
            'region.date_format' => 'd/m/Y',
            'region.country' => 'GR',
            'public_area.home' => true,
            'public_area.welcome_title' => 'Hosting and domains, simply',
            'public_area.welcome_text' => 'This is the GaiaCP demo. Everything here is made up and put back every few minutes, so click anything.',
            'public_area.domain_search' => true,
            'public_area.domain_prices' => true,
            'public_area.knowledge_base' => true,
            'public_area.announcements' => true,
            'public_area.status' => true,
            'public_area.contact' => true,
            'domains.nameservers' => ['ns1.demo-hosting.example', 'ns2.demo-hosting.example'],
            'automation.suspend_after_days' => 14,
        ]);
    }

    /** @return array{User, User} the demo admin and a support admin with fewer areas */
    private function admins(): array
    {
        $admin = User::query()->create([
            'name' => 'Demo Admin',
            'email' => Demo::ADMIN_EMAIL,
            'password' => Hash::make(Demo::PASSWORD),
            'role' => UserRole::Admin,
            'super_admin' => true,
        ]);

        $support = User::query()->create([
            'name' => 'Eleni Support',
            'email' => 'support@aurora-hosting.example',
            'password' => Hash::make(Str::random(32)),
            'role' => UserRole::Admin,
            'super_admin' => false,
            'admin_areas' => [AdminArea::Support->value, AdminArea::Clients->value],
        ]);

        User::query()->whereKey([$admin->id, $support->id])->update(['email_verified_at' => now()]);

        return [$admin, $support];
    }

    private function catalogue(): void
    {
        foreach ([['Athens 1', 'ath1', 200, 4500], ['Frankfurt 1', 'fra1', 300, 6000], ['Amsterdam 2', 'ams2', 150, 3800]] as [$name, $host, $max, $cost]) {
            $server = Server::query()->create([
                'name' => $name,
                'public_name' => $name,
                'on_status_page' => true,
                'type' => 'demo',
                'hostname' => "{$host}.demo-hosting.example",
                'port' => 443,
                'max_accounts' => $max,
                'monthly_cost_cents' => $cost,
                'ip_address' => '192.0.2.'.(count($this->servers) + 10),
                'monitor_module' => 'demo',
                'active' => true,
            ]);
            $server->update(['monitor_ref' => (string) $server->id]);
            $this->servers[] = $server;
        }

        $this->registrar = Registrar::query()->create(['name' => 'Demo registrar', 'module' => 'demo', 'credentials' => [], 'verify_tls' => true, 'active' => true, 'is_default' => true]);

        foreach (['.com' => [1300, 900], '.gr' => [1500, 1000], '.eu' => [900, 600], '.net' => [1500, 1050], '.org' => [1500, 1000], '.io' => [4900, 3600]] as $tld => [$price, $cost]) {
            Tld::query()->create([
                'tld' => $tld,
                'registrar_id' => $this->registrar->id,
                'active' => true,
                'epp_required' => $tld !== '.gr',
                'register_1y_cents' => $price, 'register_2y_cents' => $price * 2,
                'transfer_1y_cents' => $price, 'renew_1y_cents' => $price, 'renew_2y_cents' => $price * 2,
                'costs' => ['register_1y_cents' => $cost, 'renew_1y_cents' => $cost],
            ]);
        }

        $shared = ProductGroup::query()->create([
            'slug' => 'web-hosting',
            'names' => ['en' => 'Web hosting', 'el' => 'Φιλοξενία ιστοσελίδων'],
            'descriptions' => ['en' => 'Websites and email on fast servers, with free SSL.', 'el' => 'Ιστοσελίδες και email σε γρήγορους servers, με δωρεάν SSL.'],
            'icon' => 'server',
            'sort' => 1,
            'features' => [
                ['key' => 'disk', 'names' => ['en' => 'Disk space', 'el' => 'Χώρος']],
                ['key' => 'sites', 'names' => ['en' => 'Websites', 'el' => 'Ιστοσελίδες']],
                ['key' => 'email', 'names' => ['en' => 'Email accounts', 'el' => 'Λογαριασμοί email']],
                ['key' => 'backup', 'names' => ['en' => 'Daily backup', 'el' => 'Ημερήσιο backup']],
            ],
        ]);
        $care = ProductGroup::query()->create([
            'slug' => 'website-care',
            'names' => ['en' => 'Website care', 'el' => 'Συντήρηση ιστοσελίδων'],
            'descriptions' => ['en' => 'Updates, backups and small changes, done for you.', 'el' => 'Ενημερώσεις, backups και μικρές αλλαγές, από εμάς.'],
            'icon' => 'wrench',
            'sort' => 2,
        ]);

        foreach ([
            'basic' => ['Hosting Basic', 500, 5000, null, ['disk' => '10 GB', 'sites' => '1', 'email' => '10', 'backup' => 'Weekly'], 'teal'],
            'pro' => ['Hosting Pro', 1000, 10000, 18000, ['disk' => '50 GB', 'sites' => '10', 'email' => '50', 'backup' => 'Daily'], 'indigo'],
            'business' => ['Hosting Business', null, 20000, 36000, ['disk' => '100 GB', 'sites' => 'Unlimited', 'email' => 'Unlimited', 'backup' => 'Daily'], 'purple'],
        ] as $package => [$name, $monthly, $annually, $biennially, $features, $color]) {
            $product = Product::query()->create([
                'name' => $name,
                'type' => ServiceType::Hosting,
                'product_group_id' => $shared->id,
                'package' => $package,
                'features' => $features,
                'color' => $color,
                'popular' => $package === 'pro',
                'currency' => 'EUR',
                'billing_cycle' => BillingCycle::Annually,
                'prices' => array_filter([
                    'monthly' => $monthly ? ['enabled' => true, 'price_cents' => $monthly, 'setup_cents' => 0] : null,
                    'annually' => ['enabled' => true, 'price_cents' => $annually, 'setup_cents' => 0],
                    'biennially' => $biennially ? ['enabled' => true, 'price_cents' => $biennially, 'setup_cents' => 0] : null,
                ]),
            ]);
            $product->servers()->attach(collect($this->servers)->pluck('id'));
            $this->products[$package] = $product;
        }

        $this->products['care'] = Product::query()->create([
            'name' => 'Website care',
            'type' => ServiceType::Maintenance,
            'product_group_id' => $care->id,
            'color' => 'amber',
            'currency' => 'EUR',
            'billing_cycle' => BillingCycle::Annually,
            'prices' => [
                'monthly' => ['enabled' => true, 'price_cents' => 1500, 'setup_cents' => 0],
                'annually' => ['enabled' => true, 'price_cents' => 15000, 'setup_cents' => 0],
            ],
        ]);

        // Product tags: badges on the order pages, and a hidden one that only filters the admin lists
        $tag = fn (int $sort, string $en, string $el, string $color, bool $visible = true) => ProductTag::query()->create([
            'names' => ['en' => $en, 'el' => $el], 'color' => $color, 'visible' => $visible, 'sort' => $sort,
        ]);
        $ssd = $tag(1, 'NVMe SSD', 'NVMe SSD', 'blue');
        $new = $tag(2, 'New', 'Νέο', 'green');
        $value = $tag(3, 'Best value', 'Καλύτερη τιμή', 'red');
        $wordpress = $tag(4, 'WordPress', 'WordPress', 'gray', false);
        foreach (['basic', 'pro', 'business'] as $package) {
            $this->products[$package]->tags()->attach($ssd);
        }
        $this->products['pro']->tags()->attach([$value->id, $wordpress->id]);
        $this->products['business']->tags()->attach([$new->id]);
        $this->products['care']->tags()->attach($wordpress);
    }

    /** @param  array{0: string, 1: string, 2: ?string, 3: string, 4: string, 5: string, 6: array{0: string, 1: string, 2: ?int, 3: string}, 7: bool}  $row */
    private function client(int $index, array $row): void
    {
        [$first, $last, $company, $locale, $country, $city, [$package, $cycleValue, $days, $statusValue], $care] = $row;

        $email = $index === 0 ? Demo::CLIENT_EMAIL : Str::lower(Str::ascii($first).'.'.Str::ascii(str_replace(' ', '', $last))).'@example.com';
        $client = Client::query()->create([
            'first_name' => $first,
            'last_name' => $last,
            'company' => $company,
            'email' => $email,
            'locale' => $locale,
            'currency' => 'EUR',
            'phone' => $country === 'GR' ? '+30 69'.str_pad((string) (40000000 + $index * 1234567 % 9999999), 8, '0', STR_PAD_LEFT) : '+44 20 7946 0'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'address1' => ($index + 3).' Example Street',
            'city' => $city,
            'postcode' => (string) (10000 + $index * 371),
            'country' => $country,
        ]);
        $client->forceFill(['created_at' => $this->today->subDays(400 - $index * 15)])->save();

        if ($index === 0) {
            $user = User::query()->create([
                'name' => $client->name,
                'email' => Demo::CLIENT_EMAIL,
                'password' => Hash::make(Demo::PASSWORD),
                'role' => UserRole::Client,
                'client_id' => $client->id,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $cycle = BillingCycle::from($cycleValue);
        $status = ServiceStatus::from($statusValue);
        $due = $days === null ? $this->today->subMonths(2) : $this->today->addDays($days);
        $start = $due->subMonthsNoOverflow($cycle->months() * ($cycle === BillingCycle::Monthly ? 8 : ($days !== null && $days > 180 ? 1 : 2)));
        $product = $this->products[$package];
        $domain = Str::slug(Str::ascii($company ?? $first.' '.$last)).($country === 'GR' ? '.gr' : '.com');
        $server = $this->servers[$index % count($this->servers)];

        $hosting = Service::query()->create([
            'client_id' => $client->id,
            'type' => ServiceType::Hosting,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'label' => $product->name,
            'domain' => $domain,
            'username' => substr(preg_replace('/[^a-z]/', '', explode('.', $domain)[0]), 0, 8).$index,
            'password' => Str::password(16, symbols: false),
            'package' => $package,
            'price_cents' => $product->priceFor($cycle),
            'currency' => 'EUR',
            'billing_cycle' => $cycle,
            'start_date' => $start,
            'next_due_date' => $due,
            'status' => $status,
            'suspension_reason' => $status === ServiceStatus::Suspended ? Service::SUSPENDED_OVERDUE : null,
        ]);

        $tld = Tld::query()->where('tld', '.'.Str::after($domain, '.'))->first();
        $domainService = Service::query()->create([
            'client_id' => $client->id,
            'type' => ServiceType::Domain,
            'domain' => $domain,
            'price_cents' => $tld?->renew_1y_cents ?? 1500,
            'currency' => 'EUR',
            'billing_cycle' => BillingCycle::Annually,
            'start_date' => $start,
            'next_due_date' => $status === ServiceStatus::Terminated ? $this->today->addMonths(4) : $due,
            'status' => $status === ServiceStatus::Terminated ? ServiceStatus::Active : ServiceStatus::Active,
        ]);
        $domainService->registration()->update([
            'registrar_id' => $this->registrar->id,
            'registration_type' => DomainRegistrationType::Register,
            'nameservers' => ['ns1.demo-hosting.example', 'ns2.demo-hosting.example'],
            'registered_at' => $start,
            'expires_at' => $domainService->next_due_date,
            'last_synced_at' => now(),
        ]);

        if ($care) {
            Service::query()->create([
                'client_id' => $client->id,
                'type' => ServiceType::Maintenance,
                'product_id' => $this->products['care']->id,
                'label' => 'Website care',
                'domain' => $domain,
                'price_cents' => 15000,
                'currency' => 'EUR',
                'billing_cycle' => BillingCycle::Annually,
                'start_date' => $start,
                'next_due_date' => $due,
                'status' => $status === ServiceStatus::Terminated ? ServiceStatus::Terminated : ServiceStatus::Active,
            ]);
        }

        // The demo client also has a second domain, parked and paid well ahead
        if ($index === 0) {
            $second = Service::query()->create([
                'client_id' => $client->id,
                'type' => ServiceType::Domain,
                'domain' => 'morgan-photos.eu',
                'price_cents' => 900,
                'currency' => 'EUR',
                'billing_cycle' => BillingCycle::Annually,
                'start_date' => $this->today->subMonths(5),
                'next_due_date' => $this->today->addMonths(7),
                'status' => ServiceStatus::Active,
            ]);
            $second->registration()->update(['registrar_id' => $this->registrar->id, 'registration_type' => DomainRegistrationType::Register,
                'nameservers' => ['ns1.demo-hosting.example', 'ns2.demo-hosting.example'], 'expires_at' => $second->next_due_date]);
        }
    }

    /** One payment for each period already paid, so the reports and the dashboard chart have a year to show. */
    private function history(): void
    {
        $methods = ['bank_transfer', 'bank_transfer', 'bank_transfer', 'cash'];

        Service::query()->with('client')->get()->each(function (Service $service) use ($methods) {
            $due = CarbonImmutable::parse($service->start_date);
            $end = CarbonImmutable::parse($service->next_due_date);
            $months = $service->billing_cycle->months();

            while ($due->lt($end) && $due->lte($this->today)) {
                $next = $due->addMonthsNoOverflow($months);
                $paidOn = $due->subDays(crc32($service->domain.$due) % 9);

                if ($paidOn->lte($this->today)) {
                    Payment::query()->create([
                        'service_id' => $service->id,
                        'client_id' => $service->client_id,
                        'amount_cents' => $service->price_cents,
                        'currency' => $service->currency,
                        'paid_on' => $paidOn,
                        'method' => $methods[$service->id % count($methods)],
                        'due_date_before' => $due,
                        'due_date_after' => $next,
                    ]);
                }

                $due = $next;
            }
        });
    }

    /** The notices the daily run issues for what is due soon or overdue; a few of today's are already paid. */
    private function notices(): void
    {
        app(GenerateInvoices::class)->handle($this->today);

        Invoice::query()->where('status', InvoiceStatus::Unpaid)->orderBy('id')->get()
            ->filter(fn (Invoice $invoice) => $invoice->due_date->gt($this->today->addDays(8)) && $invoice->client->email !== Demo::CLIENT_EMAIL)
            ->take(2)
            ->each(fn (Invoice $invoice) => app(RecordInvoicePayment::class)->handle($invoice, $this->today->subDay(), 'bank_transfer', 'Bank transfer'));
    }

    /** A new client's order from yesterday, waiting for its payment. */
    private function order(): void
    {
        $client = Client::query()->create([
            'first_name' => 'Daniel', 'last_name' => 'Weber', 'company' => 'Weber Bikes', 'email' => 'daniel.weber@example.com',
            'locale' => 'en', 'currency' => 'EUR', 'address1' => '7 Example Street', 'city' => 'Berlin', 'postcode' => '10115', 'country' => 'DE',
        ]);

        app(PlaceOrder::class)->handle($client, [
            ['type' => 'domain', 'label' => 'Domain registration', 'domain' => 'weber-bikes.com', 'price_cents' => 1300,
                'billing_cycle' => BillingCycle::Annually, 'registration_type' => DomainRegistrationType::Register],
            ['type' => 'hosting', 'label' => 'Hosting Pro', 'domain' => '', 'product_id' => $this->products['pro']->id,
                'server_id' => $this->servers[1]->id, 'package' => 'pro', 'price_cents' => 10000, 'billing_cycle' => BillingCycle::Annually],
        ], 'Ordered from the website');
    }

    private function support(User $support): void
    {
        $sales = TicketDepartment::query()->create(['name' => 'Sales', 'email' => 'sales@aurora-hosting.example', 'client_visible' => true, 'sort' => 1]);
        $technical = TicketDepartment::query()->create(['name' => 'Technical support', 'email' => 'support@aurora-hosting.example', 'client_visible' => true, 'sort' => 2]);
        $tickets = app(Tickets::class);
        $clients = Client::query()->with('user')->orderBy('id')->get();
        $demo = $clients->first();
        $demoUser = $demo->user;

        $ticket = $tickets->open($demo, $demoUser, 'Email on my phone', 'Hello, which settings do I use to read my email on an iPhone?', $technical);
        $tickets->reply($ticket, $support, "Hello Alex,\n\nUse mail.morgan-studio.com for both incoming (IMAP, port 993) and outgoing (SMTP, port 465), with your full email address as the username.\n\nEleni");

        $tickets->open($demo, $demoUser, 'Moving a second site to my plan', 'I would like to add morgan-photos.eu as a second website on my Pro plan. Can you help?', $sales);

        foreach ([[1, 'Site is down', 'My website shows an error since this morning.', TicketPriority::High, $technical],
            [3, 'Invoice for my company', 'Can the payment notice show our VAT number?', TicketPriority::Low, $sales],
            [6, 'SSL certificate', 'The browser says my site is not secure.', TicketPriority::Medium, $technical]] as [$index, $subject, $body, $priority, $department]) {
            $client = $clients[$index];
            $tickets->openFromEmail($client, $client->email, $client->name, $subject, $body, $department)->update(['priority' => $priority]);
        }

        $closed = $tickets->openFromEmail($clients[4], $clients[4]->email, $clients[4]->name, 'Change of password', 'I forgot my control panel password.', $technical);
        $tickets->reply($closed, $support, 'Done: a new password is on its way to your email.', status: TicketStatus::Answered);
        $tickets->close($closed);
    }

    private function sales(User $admin): void
    {
        auth()->setUser($admin);
        $leads = app(Leads::class);

        foreach ([['Irene', 'Kalogirou', 'Kalogirou Wines', 'Website', LeadStatus::New, 1],
            ['Ben', 'Carter', 'Carter Plumbing', 'Phone', LeadStatus::Contacted, 0],
            ['Petros', 'Markou', 'Markou Hotels', 'Referral', LeadStatus::Contacted, 3],
            ['Lena', 'Hoffmann', null, 'Email', LeadStatus::Lost, null]] as [$first, $last, $company, $source, $status, $nextContact]) {
            $lead = $leads->create([
                'first_name' => $first, 'last_name' => $last, 'company' => $company,
                'email' => Str::lower(Str::ascii($first.'.'.$last)).'@example.com', 'source' => $source, 'country' => 'GR',
                'next_contact_on' => $nextContact === null ? null : $this->today->addDays($nextContact),
                'assigned_to' => $admin->id,
            ]);
            $lead->update(['status' => $status, 'lost_reason' => $status === LeadStatus::Lost ? 'Went with a cheaper host' : null]);
            $leads->addNote($lead, $status === LeadStatus::New ? 'Asked about hosting for an online shop.' : 'Called, wants a price for hosting and care.');
        }

        $quotes = app(Quotes::class);
        $lead = Lead::query()->where('last_name', 'Markou')->first();
        $quote = $quotes->create([
            'lead_id' => $lead->id,
            'items' => [
                ['type' => 'hosting', 'label' => 'Hosting Business', 'product_id' => $this->products['business']->id, 'billing_cycle' => 'annually', 'price_cents' => 20000],
                ['type' => 'maintenance', 'label' => 'Website care', 'product_id' => $this->products['care']->id, 'billing_cycle' => 'annually', 'price_cents' => 15000],
            ],
            'extras' => [['description' => 'Moving three websites', 'amount_cents' => 9000]],
            'intro' => 'Thank you for your interest. Here is our offer for your hotels\' websites.',
        ]);
        $quotes->send($quote);

        $quotes->create([
            'client_id' => Client::query()->where('email', Demo::CLIENT_EMAIL)->value('id'),
            'items' => [['type' => 'other', 'label' => 'Online shop set-up', 'billing_cycle' => 'onetime', 'price_cents' => 45000]],
        ]);

        auth()->forgetUser();
    }

    private function content(User $admin): void
    {
        $start = KbCategory::query()->create(['names' => ['en' => 'Getting started', 'el' => 'Πρώτα βήματα'], 'sort' => 1]);
        $email = KbCategory::query()->create(['names' => ['en' => 'Email', 'el' => 'Email'], 'sort' => 2]);

        foreach ([
            [$start, 'point-your-domain', 'Point your domain to us', 'Στρέψτε το domain σας σε εμάς', 'Set your domain\'s nameservers to ns1.demo-hosting.example and ns2.demo-hosting.example. Changes take a few hours.', 'Ορίστε τους nameservers του domain σας σε ns1.demo-hosting.example και ns2.demo-hosting.example. Οι αλλαγές θέλουν λίγες ώρες.'],
            [$start, 'upload-your-website', 'Upload your website', 'Ανεβάστε την ιστοσελίδα σας', 'Use the File Manager of your control panel, or FTP with your hosting username and password.', 'Χρησιμοποιήστε τη Διαχείριση αρχείων του control panel ή FTP με το όνομα χρήστη και τον κωδικό της φιλοξενίας σας.'],
            [$email, 'email-on-your-phone', 'Email on your phone', 'Email στο κινητό σας', 'Incoming: IMAP, port 993, SSL. Outgoing: SMTP, port 465, SSL. The username is your full email address.', 'Εισερχόμενα: IMAP, θύρα 993, SSL. Εξερχόμενα: SMTP, θύρα 465, SSL. Όνομα χρήστη είναι όλη η διεύθυνση email.'],
        ] as $sort => [$category, $slug, $titleEn, $titleEl, $bodyEn, $bodyEl]) {
            KbArticle::query()->create([
                'kb_category_id' => $category->id,
                'slug' => $slug,
                'texts' => ['en' => ['title' => $titleEn, 'body' => $bodyEn], 'el' => ['title' => $titleEl, 'body' => $bodyEl]],
                'published' => true,
                'clients_only' => false,
                'sort' => $sort,
                'created_by' => $admin->id,
            ]);
        }

        Announcement::query()->create([
            'slug' => 'welcome-to-the-demo',
            'texts' => [
                'en' => ['title' => 'Welcome to the GaiaCP demo', 'body' => 'Everything here is made up and put back every few minutes. Try ordering, paying a notice or opening a ticket.'],
                'el' => ['title' => 'Καλώς ήρθατε στο demo του GaiaCP', 'body' => 'Όλα εδώ είναι φανταστικά και επανέρχονται κάθε λίγα λεπτά. Δοκιμάστε μια παραγγελία, μια πληρωμή ή ένα ticket.'],
            ],
            'published_at' => now()->subDays(2),
            'clients_only' => false,
            'created_by' => $admin->id,
        ]);
    }

    /** Thirty days of checks for the uptime bars: hourly, every five minutes for the last day, one short outage. */
    private function uptime(): void
    {
        $now = CarbonImmutable::now()->startOfMinute();

        foreach ($this->servers as $position => $server) {
            $rows = [];

            for ($at = $now->subDays(30); $at->lt($now); $at = $at->addMinutes($at->lt($now->subDay()) ? 60 : 5)) {
                $down = $position === 2 && $at->between($now->subDays(9)->setTime(3, 0), $now->subDays(9)->setTime(4, 30));
                $rows[] = ['server_id' => $server->id, 'status' => $down ? 'down' : 'up', 'checked_at' => $at->utc()];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('server_monitor_checks')->insert($chunk);
            }

            $server->forceFill(['monitor_status' => 'up', 'monitor_status_since' => $now->subDays($position === 2 ? 9 : 30), 'monitor_checked_at' => $now,
                'monitor_uptime' => ['1d' => 1.0, '30d' => $position === 2 ? 0.998 : 1.0]])->save();
        }
    }
}
