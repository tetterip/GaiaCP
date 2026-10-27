<?php

namespace Plugins\WhmcsImport;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ClientFieldAccess;
use App\Enums\ClientFieldType;
use App\Enums\ClientStatus;
use App\Enums\CurrencyFormat;
use App\Enums\DomainRegistrationType;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientField;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\Service;
use App\Plugins\PluginManager;
use App\Providers\AppServiceProvider;
use App\Support\ClientFields;
use App\Support\Currencies;
use App\Support\DomainName;
use App\Support\Locales;
use App\Support\Money;
use App\Support\Tax;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Copies a WHMCS installation into GaiaCP: servers (cPanel, Plesk), products and their prices, clients
 * (ΑΦΜ, Δ.Ο.Υ. and profession from WHMCS custom fields), hosting and other services, domains and,
 * optionally, unpaid invoices as payment notices. It only reads the WHMCS database (SELECTs, in a
 * read-only session on MySQL).
 *
 * Safe to re-run: each imported row is remembered in whmcs_links and skipped next time, and a record
 * that is already here (client with the same email, server with the same hostname, product with the
 * same name, service or domain with the same domain) is linked instead of copied. Records imported
 * before are never updated. Everything happens in one transaction; a dry run rolls it back, so its
 * counts and problems are exactly what the import would do.
 */
class WhmcsImport
{
    private const CYCLES = [
        'monthly' => BillingCycle::Monthly,
        'quarterly' => BillingCycle::Quarterly,
        'semi-annually' => BillingCycle::Semiannually,
        'semiannually' => BillingCycle::Semiannually,
        'annually' => BillingCycle::Annually,
        'biennially' => BillingCycle::Biennially,
        'triennially' => BillingCycle::Triennially,
    ];

    /** tblpricing columns: setup fee, price. */
    private const PRICE_COLUMNS = [
        'monthly' => ['msetupfee', 'monthly'],
        'quarterly' => ['qsetupfee', 'quarterly'],
        'semiannually' => ['ssetupfee', 'semiannually'],
        'annually' => ['asetupfee', 'annually'],
        'biennially' => ['bsetupfee', 'biennially'],
        'triennially' => ['tsetupfee', 'triennially'],
    ];

    private const SERVICE_STATUSES = [
        'pending' => ServiceStatus::Pending,
        'active' => ServiceStatus::Active,
        'suspended' => ServiceStatus::Suspended,
        'terminated' => ServiceStatus::Terminated,
        'completed' => ServiceStatus::Terminated,
        'cancelled' => ServiceStatus::Cancelled,
        'fraud' => ServiceStatus::Cancelled,
    ];

    private const DOMAIN_STATUSES = [
        'pending' => ServiceStatus::Pending,
        'pending registration' => ServiceStatus::Pending,
        'pending transfer' => ServiceStatus::Pending,
        'active' => ServiceStatus::Active,
        'grace' => ServiceStatus::Active,
        'redemption' => ServiceStatus::Active,
        'expired' => ServiceStatus::Terminated,
        'transferred away' => ServiceStatus::Terminated,
        'cancelled' => ServiceStatus::Cancelled,
        'fraud' => ServiceStatus::Cancelled,
    ];

    /** Custom field names (lowercase, no accents, dots or spaces) → client column. */
    private const CLIENT_FIELDS = [
        'vat_number' => ['αφμ', 'afm', 'vat', 'taxid', 'vatnumber'],
        'tax_office' => ['δου', 'doy', 'taxoffice', 'εφορια'],
        'profession' => ['επαγγελμα', 'δραστηριοτητα', 'profession', 'occupation', 'activity'],
    ];

    private WhmcsImportReport $report;

    /** @var array<string, array<int, int>> entity → WHMCS id → GaiaCP id */
    private array $links = [];

    /** @var Collection<int, object> tblcurrencies by id */
    private Collection $currencies;

    private ?WhmcsCrypt $crypt;

    /** @param  WhmcsApi|null  $api  WHMCS's own decryption, for the passwords WhmcsCrypt can't read */
    public function __construct(private string $connection = WhmcsConnection::NAME, ?string $encryptionHash = null, private ?WhmcsApi $api = null)
    {
        $this->crypt = filled($encryptionHash) ? new WhmcsCrypt($encryptionHash) : null;
    }

    /**
     * What can be imported, in this order. A part left out still links the records already in GaiaCP (same email,
     * hostname, product name or domain) so the parts that are imported can find them; it creates nothing.
     */
    public const PARTS = [
        'server' => 'Servers',
        'product' => 'Products',
        'client' => 'Clients',
        'hosting' => 'Hosting and other services',
        'domain' => 'Domains',
        'billable' => 'Billable items',
        'invoice' => 'Unpaid invoices',
    ];

    /** @var list<string> the PARTS keys being imported */
    private array $parts = [];

    /** Lines of the last invoiceLines() call dropped because their service's part was left out. */
    private int $linesLeftOut = 0;

    /** While a left-out part runs: link what is already here, create and count nothing. */
    private bool $linkOnly = false;

    /** @param  list<string>|null  $parts  PARTS keys to import; null = all */
    public function run(bool $dryRun = false, ?array $parts = null): WhmcsImportReport
    {
        $this->parts = $parts === null ? array_keys(self::PARTS) : array_values(array_intersect(array_keys(self::PARTS), $parts));
        $this->report = new WhmcsImportReport($dryRun, $this->parts);
        $whmcs = $this->whmcs();

        if ($this->crypt === null && $this->api === null && ($this->wants('server') || $this->wants('hosting'))) {
            $this->report->problem('No encryption hash given: hosting passwords and Plesk server passwords are not imported (cc_encryption_hash in WHMCS\'s configuration.php).', 'passwords');
        }

        DB::beginTransaction();

        try {
            $this->links = DB::table('whmcs_links')->get()
                ->groupBy('entity')
                ->map(fn (Collection $rows) => $rows->pluck('local_id', 'whmcs_id')->all())
                ->all();
            $this->currencies = $whmcs->table('tblcurrencies')->get()->keyBy('id');

            foreach (['server' => 'servers', 'product' => 'products', 'client' => 'clients', 'hosting' => 'hosting', 'domain' => 'domains'] as $part => $method) {
                $this->wants($part) ? $this->{$method}($whmcs) : $this->linkExisting(fn () => $this->{$method}($whmcs));
            }

            if ($this->wants('billable')) {
                $this->billableItems($whmcs);
            }

            if ($this->wants('invoice')) {
                $this->invoices($whmcs);
            }

            if (! $dryRun) {
                ActivityLog::record('whmcs.imported', 'Imported from WHMCS: '.$this->report->summary(), properties: $this->report->counts);
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $dryRun ? DB::rollBack() : DB::commit();

        return $this->report;
    }

    /** The WHMCS connection, read-only where the database can enforce it. */
    private function whmcs(): Connection
    {
        $connection = DB::connection($this->connection);

        if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $connection->statement('SET SESSION TRANSACTION READ ONLY');
        }

        return $connection;
    }

    private function servers(Connection $whmcs): void
    {
        foreach ($whmcs->table('tblservers')->orderBy('id')->get() as $row) {
            $name = $row->name ?: $row->hostname;

            if ($this->linked('server', $row->id)) {
                $this->report->count('server', 'existing');

                continue;
            }

            // WHMCS's module names match GaiaCP's server types (cpanel, plesk, directadmin).
            $type = app(PluginManager::class)->serverModule(strtolower((string) $row->type));

            if ($type === null) {
                $this->report->count('server', 'skipped');
                $this->report->problem("Server {$name}: type \"{$row->type}\" has no server plugin in GaiaCP; its services are imported without a server.");

                continue;
            }

            $hostname = trim((string) ($row->hostname ?: $row->ipaddress));
            $existing = Server::query()->whereIn('hostname', array_filter([$hostname, trim((string) $row->ipaddress)]))->first();

            if ($existing) {
                $this->link('server', $row->id, $existing, 'matched');

                continue;
            }

            if ($this->linkOnly) {
                continue;
            }

            $token = $type->key() === 'cpanel'
                ? (preg_replace('/\s+/', '', (string) $row->accesshash) ?: null)
                : $this->decrypt($row->password, "server {$name}");

            if ($token === null) {
                $this->report->problem($type->key() === 'cpanel'
                    ? "Server {$name}: no API token in WHMCS; create one in WHM and add it under Settings → Servers."
                    : "Server {$name}: password not imported; enter it under Settings → Servers.");
            } elseif ($type->key() === 'cpanel' && strlen($token) > 64) {
                $this->report->problem("Server {$name}: WHMCS has an old WHM access hash, not an API token; create an API token in WHM and enter it under Settings → Servers.");
            }

            $server = Server::create([
                'name' => $name,
                'type' => $type->key(),
                'hostname' => $hostname,
                'port' => (int) $row->port ?: $type->defaultPort(),
                'username' => $row->username ?: null,
                'api_token' => $token,
                'verify_tls' => true,
                'active' => ! $row->disabled,
                'notes' => 'Imported from WHMCS.',
            ]);

            $this->link('server', $row->id, $server, 'created');
        }
    }

    /** @var array<int, ?ProductGroup> WHMCS product group id => the GaiaCP one */
    private array $productGroups = [];

    /** The product group (Settings → Product groups) for a WHMCS one: the one with its name, else a new one. */
    private function productGroup(Connection $whmcs, int $gid): ?ProductGroup
    {
        if ($gid === 0) {
            return null;
        }

        return $this->productGroups[$gid] ??= (function () use ($whmcs, $gid) {
            $row = $whmcs->table('tblproductgroups')->where('id', $gid)->first();
            $name = trim((string) ($row->name ?? ''));

            if ($name === '') {
                return null;
            }

            return ProductGroup::query()->get()->first(fn (ProductGroup $group) => mb_strtolower($group->name()) === mb_strtolower($name))
                ?? ProductGroup::create([
                    'slug' => Str::slug($name),
                    'names' => [Locales::default() => $name],
                    'descriptions' => filled($row->headline ?? null) ? [Locales::default() => strip_tags((string) $row->headline)] : null,
                    'sort' => (int) ($row->order ?? 0),
                    'hidden' => (bool) ($row->hidden ?? false),
                ]);
        })();
    }

    private function products(Connection $whmcs): void
    {
        $currency = $this->currencies->firstWhere('code', Currencies::base()) ?? $this->currencies->firstWhere('default', 1);
        $pricing = $whmcs->table('tblpricing')->where('type', 'product')->where('currency', $currency?->id)->get()->keyBy('relid');

        foreach ($whmcs->table('tblproducts')->orderBy('id')->get() as $row) {
            if ($this->linked('product', $row->id)) {
                $this->report->count('product', 'existing');

                continue;
            }

            if ($row->paytype === 'onetime') {
                $this->report->count('product', 'skipped');
                $this->report->problem("Product {$row->name}: one-time products are not supported (only recurring).");

                continue;
            }

            $existing = Product::query()->whereRaw('lower(name) = ?', [mb_strtolower($row->name)])->first();

            if ($existing) {
                $this->link('product', $row->id, $existing, 'matched');

                continue;
            }

            if ($this->linkOnly) {
                continue;
            }

            $prices = [];

            if ($row->paytype === 'free') {
                $prices['annually'] = ['enabled' => true, 'price_cents' => 0, 'setup_cents' => 0];
            } elseif ($price = $pricing->get($row->id)) {
                foreach (self::PRICE_COLUMNS as $cycle => [$setup, $amount]) {
                    $cents = $this->cents($price->{$amount});
                    $prices[$cycle] = ['enabled' => $cents !== null, 'price_cents' => $cents, 'setup_cents' => $cents === null ? null : (int) $this->cents($price->{$setup})];
                }
            }

            $enabled = array_keys(array_filter($prices, fn (array $p) => $p['enabled']));

            if ($enabled === []) {
                $this->report->problem('Product '.$row->name.': no price in '.Currencies::base().'; set its prices under Settings → Products.');
            }

            $hosting = in_array($row->servertype, ['cpanel', 'plesk'], true) || in_array($row->type, ['hostingaccount', 'reselleraccount'], true);

            $product = Product::create([
                'name' => $row->name,
                'type' => $hosting ? ServiceType::Hosting : ServiceType::Other,
                'description' => strip_tags((string) $row->description) ?: null,
                'prices' => $prices,
                'currency' => Currencies::base(),
                'billing_cycle' => $enabled[0] ?? BillingCycle::Annually->value,
                'package' => $hosting ? ($row->configoption1 ?: null) : null,
                'product_group_id' => $this->productGroup($whmcs, (int) ($row->gid ?? 0))?->id,
                'hidden' => (bool) $row->hidden,
                'retired' => (bool) ($row->retired ?? false),
                'auto_setup' => match ($row->autosetup) {
                    'order' => AutoSetup::OnOrder,
                    'payment' => AutoSetup::OnPayment,
                    'on' => AutoSetup::OnAccept,
                    default => AutoSetup::Never,
                },
            ]);

            $this->link('product', $row->id, $product, 'created');
        }
    }

    private function clients(Connection $whmcs): void
    {
        $fields = $this->clientCustomFields($whmcs);

        foreach ($whmcs->table('tblclients')->orderBy('id')->get() as $row) {
            $name = trim("{$row->firstname} {$row->lastname}") ?: "#{$row->id}";

            if ($this->linked('client', $row->id)) {
                $this->report->count('client', 'existing');

                continue;
            }

            if (blank($row->email)) {
                $this->report->count('client', 'skipped');
                $this->report->problem("Client {$name}: no email address.");

                continue;
            }

            $existing = Client::query()->whereRaw('lower(email) = ?', [mb_strtolower(trim($row->email))])->first();

            if ($existing) {
                $this->link('client', $row->id, $existing, 'matched');

                continue;
            }

            if ($this->linkOnly) {
                continue;
            }

            $custom = $fields[$row->id] ?? [];
            $currency = $this->currency($row->currency, $name);

            $client = new Client([
                'first_name' => (string) $row->firstname,
                'last_name' => (string) $row->lastname,
                'company' => $row->companyname ?: null,
                'email' => trim($row->email),
                'phone' => $row->phonenumber ?: null,
                'address1' => $row->address1 ?: null,
                'address2' => $row->address2 ?: null,
                'city' => $row->city ?: null,
                'state' => $row->state ?: null,
                'postcode' => $row->postcode ?: null,
                'country' => strtoupper((string) $row->country) ?: null, // none: Settings → General → Country
                'vat_number' => ($row->tax_id ?? null) ?: ($custom['vat_number'] ?? null),
                'tax_office' => $custom['tax_office'] ?? null,
                'profession' => $custom['profession'] ?? null,
                'custom_fields' => $custom['custom_fields'] ?? null,
                'tax_exempt' => (bool) $row->taxexempt,
                'currency' => $currency,
                'status' => ClientStatus::tryFrom(strtolower((string) $row->status)) ?? ClientStatus::Active,
                'default_payment_method' => str_contains(strtolower((string) $row->defaultgateway), 'bank') ? 'bank_transfer' : null,
                'notes' => $row->notes ?: null,
            ]);
            $client->created_at = $this->date($row->datecreated) ?? now();
            $client->save();

            $this->link('client', $row->id, $client, 'created');
        }
    }

    /**
     * A client's WHMCS currency as a code. One that is not on Settings → Currencies yet is added there with WHMCS's
     * prefix, suffix and rate (its rate is to WHMCS's default currency, so check it when that is not the base one).
     */
    private function currency(int|string|null $whmcsId, string $client): string
    {
        $row = $this->currencies->get($whmcsId);
        $code = strtoupper((string) ($row?->code ?: Currencies::base()));

        if ($code === Currencies::base()) {
            return $code;
        }

        $this->report->problem("Client {$client}: pays in {$code}; their amounts are imported in {$code}.");

        return $this->addCurrency($row);
    }

    /** Adds a WHMCS currency to Settings → Currencies when it is not there yet; its code. */
    private function addCurrency(object $row): string
    {
        $code = strtoupper((string) $row->code);

        if ($code !== Currencies::base() && ! Currencies::get($code)) {
            Currency::create([
                'code' => $code,
                'prefix' => trim((string) ($row->prefix ?? '')) ?: null,
                'suffix' => trim((string) ($row->suffix ?? '')) ?: null,
                'format' => CurrencyFormat::Locale,
                'rate' => (float) ($row->rate ?? 0) > 0 ? (float) $row->rate : 1,
            ]);
            $this->report->problem("Currency {$code} added under Settings → Currencies; check its rate.");
        }

        return $code;
    }

    /**
     * WHMCS client id → client column → value, from the custom fields that look like ΑΦΜ, Δ.Ο.Υ. or profession; every
     * other client custom field becomes one of Settings → Client fields (when none has its key yet), its values under
     * `custom_fields`.
     *
     * @return array<int, array<string, mixed>>
     */
    private function clientCustomFields(Connection $whmcs): array
    {
        $columns = [];
        $own = [];

        foreach ($whmcs->table('tblcustomfields')->where('type', 'client')->get() as $field) {
            $name = trim(explode('|', (string) $field->fieldname)[0]);
            $key = $this->fieldKey($name);

            foreach (self::CLIENT_FIELDS as $column => $names) {
                if (in_array($key, $names, true)) {
                    $columns[$field->id] = $column;
                }
            }

            if (! isset($columns[$field->id]) && $name !== '') {
                $own[$field->id] = $this->clientField($field, $name);
            }
        }

        $values = [];

        if ($columns !== [] || $own !== []) {
            foreach ($whmcs->table('tblcustomfieldsvalues')->whereIn('fieldid', [...array_keys($columns), ...array_keys($own)])->get() as $value) {
                if (! filled($value->value)) {
                    continue;
                }
                if (isset($columns[$value->fieldid])) {
                    $values[$value->relid][$columns[$value->fieldid]] = trim($value->value);
                } else {
                    $field = $own[$value->fieldid];
                    $values[$value->relid]['custom_fields'][$field->key] = $field->type === ClientFieldType::Checkbox ? in_array(strtolower(trim($value->value)), ['on', '1', 'yes'], true) : trim($value->value);
                }
            }
        }

        return $values;
    }

    /** The GaiaCP client field for a WHMCS client custom field: the one with its key, or a new one like it. */
    private function clientField(object $field, string $name): ClientField
    {
        $key = Str::slug($name, '_') ?: 'field_'.$field->id;
        $key = substr(preg_match('/^[a-z]/', $key) ? $key : 'f_'.$key, 0, 50);

        if ($existing = ClientField::query()->where('key', $key)->first()) {
            return $existing;
        }

        $type = match (strtolower((string) ($field->fieldtype ?? 'text'))) {
            'textarea' => ClientFieldType::Textarea,
            'dropdown' => ClientFieldType::Select,
            'tickbox' => ClientFieldType::Checkbox,
            default => ClientFieldType::Text,
        };
        $options = $type === ClientFieldType::Select
            ? array_values(array_filter(array_map(fn (string $o) => trim(explode('|', $o)[0]), explode(',', (string) ($field->fieldoptions ?? '')))))
            : null;

        $created = ClientField::query()->create([
            'key' => $key,
            'labels' => [Locales::default() => $name],
            'type' => $type === ClientFieldType::Select && $options === [] ? ClientFieldType::Text : $type,
            'options' => $options ?: null,
            'required' => ($field->required ?? '') === 'on',
            'client_access' => ($field->adminonly ?? '') === 'on' ? ClientFieldAccess::Hidden : ClientFieldAccess::Editable,
            'on_notice' => ($field->showinvoice ?? '') === 'on',
            'sort' => (int) ($field->sortorder ?? 0),
        ]);
        ClientFields::flush();
        $this->report->problem("Client field \"{$name}\" added under Settings → Client fields; check its settings.");

        return $created;
    }

    private function fieldKey(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = strtr($name, ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω']);

        return preg_replace('/[\s.\-_:]+/u', '', $name);
    }

    private function hosting(Connection $whmcs): void
    {
        $cancellations = $whmcs->table('tblcancelrequests')->where('type', 'End of Billing Period')->get()->keyBy('relid');

        foreach ($whmcs->table('tblhosting')->orderBy('id')->get() as $row) {
            $label = "Service #{$row->id}".($row->domain ? " ({$row->domain})" : '');

            if ($this->linked('hosting', $row->id)) {
                $this->report->count('hosting', 'existing');

                continue;
            }

            $client = $this->find(Client::class, 'client', $row->userid);
            $cycle = strtolower((string) $row->billingcycle);

            if ($client === null || ($cycle !== 'free account' && ! isset(self::CYCLES[$cycle]))) {
                $this->report->count('hosting', 'skipped');
                $this->report->problem($client === null ? "{$label}: its client was not imported." : "{$label}: billing cycle \"{$row->billingcycle}\" is not supported (only recurring).");

                continue;
            }

            $product = $this->find(Product::class, 'product', $row->packageid);
            $type = $product?->type ?? ($row->server ? ServiceType::Hosting : ServiceType::Other);
            $domain = filled($row->domain) ? DomainName::normalize($row->domain) : null;

            $existing = $domain === null ? null : Service::query()
                ->where('client_id', $client->id)
                ->where('type', $type)
                ->where('domain', $domain)
                ->first();

            if ($existing) {
                $this->link('hosting', $row->id, $existing, 'matched');

                continue;
            }

            if ($this->linkOnly) {
                continue;
            }

            $server = $type === ServiceType::Hosting ? $this->find(Server::class, 'server', $row->server) : null;

            if ($type === ServiceType::Hosting && $server === null) {
                $this->report->problem("{$label}: no server (WHMCS server #{$row->server} was not imported); pick one on the service.");
            }

            $password = $this->decrypt($row->password, $label);
            $start = $this->date($row->regdate) ?? $this->date($row->nextduedate) ?? CarbonImmutable::today();
            $cancellation = $cancellations->get($row->id);

            if ($cycle === 'free account') {
                $this->report->problem("{$label}: free account, imported as yearly at 0.");
            }

            $service = new Service([
                'client_id' => $client->id,
                'type' => $type,
                'product_id' => $product?->id,
                'server_id' => $server?->id,
                'label' => $product?->name ?? ($domain ?: 'Service'),
                'domain' => $domain,
                'username' => $row->username ?: null,
                'password' => $password,
                'package' => $product?->package,
                'price_cents' => $cycle === 'free account' ? 0 : (int) $this->cents($row->amount),
                'currency' => $this->clientCurrency($whmcs, $row->userid),
                'billing_cycle' => self::CYCLES[$cycle] ?? BillingCycle::Annually,
                'start_date' => $start,
                'next_due_date' => $this->date($row->nextduedate) ?? $start,
                'status' => self::SERVICE_STATUSES[strtolower((string) $row->domainstatus)] ?? ServiceStatus::Pending,
                'suspension_reason' => $this->suspensionReason($row->suspendreason ?? null, $row->domainstatus),
                'override_suspend_until' => $row->overideautosuspend ? ($this->date($row->overidesuspenduntil ?? null) ?? '2099-12-31') : null,
                'auto_terminate' => $cancellation !== null,
                'auto_terminate_reason' => $cancellation?->reason ?: null,
                'auto_setup' => $product?->auto_setup ?? AutoSetup::OnAccept,
                'notes' => $row->notes ?: null,
            ]);
            $service->created_at = $start;
            $service->save();

            $this->link('hosting', $row->id, $service, 'created');
        }
    }

    private function domains(Connection $whmcs): void
    {
        foreach ($whmcs->table('tbldomains')->orderBy('id')->get() as $row) {
            $name = DomainName::normalize($row->domain);

            if ($this->linked('domain', $row->id)) {
                $this->report->count('domain', 'existing');

                continue;
            }

            $client = $this->find(Client::class, 'client', $row->userid);

            if ($client === null || $name === '') {
                $this->report->count('domain', 'skipped');
                $this->report->problem("Domain {$row->domain}: ".($client === null ? 'its client was not imported.' : 'no domain name.'));

                continue;
            }

            $existing = Service::query()->where('type', ServiceType::Domain)->where('domain', $name)->first();

            if ($existing) {
                $this->link('domain', $row->id, $existing, 'matched');

                continue;
            }

            if ($this->linkOnly) {
                continue;
            }

            $years = (int) $row->registrationperiod ?: 1;
            $cycle = [1 => BillingCycle::Annually, 2 => BillingCycle::Biennially, 3 => BillingCycle::Triennially][$years] ?? null;

            if ($cycle === null) {
                $this->report->problem("Domain {$name}: renews every {$years} years; imported as yearly.");
            }

            [$registrar, $registrationType] = $this->registrarFor($name, (string) $row->registrar, (string) $row->type);
            $start = $this->date($row->registrationdate) ?? $this->date($row->nextduedate) ?? CarbonImmutable::today();

            $service = new Service([
                'client_id' => $client->id,
                'type' => ServiceType::Domain,
                'domain' => $name,
                'price_cents' => (int) $this->cents($row->recurringamount),
                'currency' => $this->clientCurrency($whmcs, $row->userid),
                'billing_cycle' => $cycle ?? BillingCycle::Annually,
                'start_date' => $start,
                'next_due_date' => $this->date($row->nextduedate) ?? $this->date($row->expirydate) ?? $start,
                'status' => self::DOMAIN_STATUSES[strtolower((string) $row->status)] ?? ServiceStatus::Pending,
                'auto_terminate' => (bool) $row->donotrenew,
                'auto_setup' => AutoSetup::Never,
                'notes' => ($row->additionalnotes ?? null) ?: null,
            ]);
            $service->created_at = $start;
            $service->save();

            $service->domainDetails()->create([
                'registrar_id' => $registrar?->id,
                'registration_type' => $registrationType,
                'registered_at' => $this->date($row->registrationdate),
                'expires_at' => $this->date($row->expirydate),
            ]);

            $this->link('domain', $row->id, $service, 'created');
        }

        foreach ($this->otherRegistrars as $module => $domains) {
            $this->report->problem(($module === 'none' ? 'No registrar in WHMCS' : "Registrar \"{$module}\"")
                .' ('.count($domains).'): imported as tracked only, so GaiaCP does not renew or sync them: '.implode(', ', $domains).'.', 'registrars');
        }
    }

    /** @var array<string, list<string>> WHMCS registrar module → domains imported as tracked only */
    private array $otherRegistrars = [];

    /** @return array{0: Registrar|null, 1: DomainRegistrationType} */
    private function registrarFor(string $domain, string $module, string $type): array
    {
        $registrationType = strtolower($type) === 'transfer' ? DomainRegistrationType::Transfer : DomainRegistrationType::Register;

        // easy.gr's WHMCS module is "easy".
        if (str_contains(strtolower($module), 'easy')) {
            $registrar = Registrar::query()->where('module', 'easygr')->orderByDesc('is_default')->orderBy('id')->first();

            if ($registrar === null) {
                $this->report->problem("Domain {$domain}: at easy.gr, but no easy.gr account is set up under Settings → Registrars.", 'registrars');
            }

            return [$registrar, $registrationType];
        }

        $this->otherRegistrars[$module ?: 'none'][] = $domain;

        return [null, DomainRegistrationType::External];
    }

    /**
     * Billable items (Billing → Billable Items) become Maintenance services: a recurring one renews like any service,
     * one not invoiced yet is a one-off service (Auto-terminate on). One already invoiced comes in with its invoice.
     */
    private function billableItems(Connection $whmcs): void
    {
        foreach ($whmcs->table('tblbillableitems')->orderBy('id')->get() as $row) {
            $recurring = (int) $row->invoiceaction === 4 && (int) $row->recur > 0;
            $what = 'Billable item "'.$this->firstLine((string) $row->description).'"';

            if ($this->linked('billable', $row->id)) {
                $this->report->count('hosting', 'existing');

                continue;
            }

            // Recurring and done repeating, already invoiced (comes in with its invoice), or set to "Don't invoice".
            if ($recurring ? ((int) $row->recurfor > 0 && (int) $row->invoicecount >= (int) $row->recurfor) : ((int) $row->invoicecount > 0 || (int) $row->invoiceaction === 0)) {
                continue;
            }

            $client = $this->find(Client::class, 'client', $row->userid);

            if ($client === null) {
                $this->report->count('hosting', 'skipped');
                $this->report->problem("{$what}: its client was not imported.");

                continue;
            }

            $months = (int) $row->recur * match (strtolower((string) $row->recurcycle)) {
                'months' => 1,
                'years' => 12,
                default => 0,
            };
            $cycle = collect(BillingCycle::recurring())->first(fn (BillingCycle $c) => $c->months() === $months);

            if ($recurring && $cycle === null) {
                $this->report->count('hosting', 'skipped');
                $this->report->problem("{$what}: repeats every {$row->recur} {$row->recurcycle}, which GaiaCP has no billing cycle for; add it by hand.");

                continue;
            }

            // WHMCS moves a recurring item's due date on when it invoices it, so an unpaid invoice holds the date still owed.
            $unpaid = $whmcs->table('tblinvoiceitems')
                ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
                ->where('tblinvoiceitems.type', 'Item')
                ->where('tblinvoiceitems.relid', $row->id)
                ->where('tblinvoices.status', 'Unpaid')
                ->min('tblinvoiceitems.duedate');
            $due = $this->date($unpaid) ?? $this->date($row->duedate) ?? CarbonImmutable::today();

            $service = new Service([
                'client_id' => $client->id,
                'type' => ServiceType::Maintenance,
                'label' => mb_substr($this->firstLine((string) $row->description), 0, 255) ?: 'Maintenance',
                'price_cents' => (int) $this->cents($row->amount),
                'currency' => $this->clientCurrency($whmcs, $row->userid),
                'billing_cycle' => $cycle ?? BillingCycle::Monthly,
                'start_date' => $due,
                'next_due_date' => $due,
                'status' => ServiceStatus::Active,
                'auto_terminate' => ! $recurring,
                'auto_terminate_reason' => $recurring ? null : 'One-off charge',
                'auto_setup' => AutoSetup::Never,
                'notes' => trim((string) $row->description) ?: null,
            ]);
            $service->save();

            if ($recurring && (int) $row->recurfor > 0) {
                $left = (int) $row->recurfor - (int) $row->invoicecount;
                $this->report->problem("{$what}: WHMCS bills it {$left} more time(s); GaiaCP renews it until you turn on Auto-terminate.");
            }

            $this->link('billable', $row->id, $service, 'created', 'hosting');
        }
    }

    private function firstLine(string $text): string
    {
        return trim(strtok(trim($text), "\n") ?: '');
    }

    private function invoices(Connection $whmcs): void
    {
        $inclusive = strtolower((string) $whmcs->table('tblconfiguration')->where('setting', 'TaxType')->value('value')) === 'inclusive';
        $taxNames = $whmcs->table('tbltax')->get();

        foreach ($whmcs->table('tblinvoices')->where('status', 'Unpaid')->orderBy('id')->get() as $row) {
            $number = $row->invoicenum ?: $row->id;

            if ($this->linked('invoice', $row->id)) {
                $this->report->count('invoice', 'existing');

                continue;
            }

            $client = $this->find(Client::class, 'client', $row->userid);

            if ($client === null) {
                $this->report->count('invoice', 'skipped');
                $this->report->problem("Invoice #{$number}: its client was not imported.", 'invoices');

                continue;
            }

            $rate = (float) $row->taxrate ?: null;
            $lines = $this->invoiceLines($whmcs, $row, $rate, $inclusive);

            $taken = $lines->filter(fn (array $line) => $line['service'] !== null && InvoiceItem::query()
                ->where('service_id', $line['service']->id)
                ->whereDate('due_date', $line['due_date'])
                ->whereHas('invoice', fn ($q) => $q->where('status', '!=', InvoiceStatus::Cancelled))
                ->exists());

            if ($lines->isEmpty() || $taken->isNotEmpty()) {
                $this->report->count('invoice', 'skipped');
                $this->report->problem("Invoice #{$number}: not imported, ".($lines->isEmpty()
                    ? ($this->linesLeftOut > 0 ? 'it is only for services left out of this import.' : 'it has no lines.')
                    : $taken->pluck('service.label')->join(', ').' is already on a payment notice for that due date.'), 'invoices');

                continue;
            }

            $date = $this->date($row->date) ?? CarbonImmutable::today();
            $lines = $lines->map(fn (array $line) => $line['service'] === null && $this->isMaintenance($line['description'])
                ? [...$line, 'service' => $this->maintenanceService($whmcs, $client, $row, $line, $date)]
                : $line);
            $tax = $lines->sum('tax_cents');
            $total = $lines->sum('total_cents');

            if ($this->linesLeftOut > 0) {
                $this->report->problem("Invoice #{$number}: imported without its {$this->linesLeftOut} line(s) for services left out of this import, so its total differs from WHMCS.", 'invoices');
            } elseif (abs($total - (int) $this->cents($row->total)) > $lines->count()) {
                $this->report->problem("Invoice #{$number}: total here ".Money::format($total).', in WHMCS '.Money::format((int) $this->cents($row->total)).' (rounding or a second tax).', 'invoices');
            }

            $invoice = Invoice::create([
                'number' => Invoice::nextNumber($date),
                'client_id' => $client->id,
                'status' => InvoiceStatus::Unpaid,
                'date' => $date,
                'due_date' => $this->date($row->duedate) ?? $date,
                'currency' => $this->clientCurrency($whmcs, $row->userid),
                'subtotal_cents' => $total - $tax,
                'tax_name' => $rate ? ($taxNames->first(fn ($t) => (float) $t->taxrate === $rate)?->name ?: 'Tax') : null,
                'tax_rate' => $rate,
                'tax_inclusive' => $rate !== null && $inclusive,
                'tax_cents' => $tax,
                'total_cents' => $total,
                // WHMCS already sent it: no "created" email again.
                'emailed_at' => $date,
                'notes' => "WHMCS invoice #{$number}",
            ]);

            foreach ($lines as $line) {
                $service = $line['service'];
                $invoice->items()->create([
                    'service_id' => $service?->id,
                    'description' => $line['description'],
                    'due_date' => $line['due_date'],
                    'period_end' => $service ? $service->billing_cycle->advance($line['due_date'], $service->anchorDay()) : $line['due_date'],
                    'amount_cents' => $line['amount_cents'],
                    'tax_cents' => $line['tax_cents'],
                    'total_cents' => $line['total_cents'],
                ]);

                // Unpaid in WHMCS although the service's due date was moved on (set up by hand before payment):
                // the period is still owed, so the service is due from it again. "Do not suspend until" keeps it from
                // being suspended before the date WHMCS had.
                if ($service && $line['due_date']->lessThan($service->next_due_date)) {
                    $paidUntil = $service->next_due_date;
                    $service->update([
                        'next_due_date' => $line['due_date'],
                        'override_suspend_until' => $service->override_suspend_until?->greaterThan($paidUntil) ? $service->override_suspend_until : $paidUntil,
                    ]);
                    $this->report->problem("Invoice #{$number}: {$service->label} was due again on {$paidUntil->format(AppServiceProvider::dateFormat())} in WHMCS, but this invoice for {$line['due_date']->format(AppServiceProvider::dateFormat())} is unpaid, so it is due from {$line['due_date']->format(AppServiceProvider::dateFormat())} here. It is not suspended before {$paidUntil->format(AppServiceProvider::dateFormat())} (Do not suspend until).", 'invoices');
                } elseif ($service && ! $service->next_due_date->equalTo($line['due_date'])) {
                    $this->report->problem("Invoice #{$number}: {$service->label} is due {$service->next_due_date->format(AppServiceProvider::dateFormat())}, the invoice line {$line['due_date']->format(AppServiceProvider::dateFormat())}; record that payment on the service.", 'invoices');
                }
            }

            foreach ($lines->whereNull('service') as $line) {
                $this->report->problem("Invoice #{$number}: \"{$line['description']}\" is not for a service. Recording the notice's payment marks it paid, but records no payment for this line.", 'invoices');
            }

            $this->link('invoice', $row->id, $invoice, 'created');
        }
    }

    /**
     * One line per service (setup fees and domain add-ons are added to their service's line, so paying
     * the notice moves each due date once) plus a line for each item that belongs to no service.
     *
     * @return Collection<int, array{service: Service|null, description: string, due_date: CarbonImmutable, amount_cents: int, tax_cents: int, total_cents: int}>
     */
    private function invoiceLines(Connection $whmcs, object $invoice, ?float $rate, bool $inclusive): Collection
    {
        $this->linesLeftOut = 0;
        $lines = [];

        foreach ($whmcs->table('tblinvoiceitems')->where('invoiceid', $invoice->id)->orderBy('id')->get() as $item) {
            $type = (string) $item->type;
            $entity = match (true) {
                in_array($type, ['Hosting', 'Setup'], true) => 'hosting',
                str_starts_with($type, 'Domain') => 'domain',
                $type === 'Item' => 'billable',
                default => null,
            };
            $service = $entity && $item->relid ? $this->find(Service::class, $entity, $item->relid) : null;

            // A hosting or domain line whose service was left out of this import (and isn't here already).
            if ($service === null && in_array($entity, ['hosting', 'domain'], true) && $item->relid && ! $this->wants($entity)) {
                $this->linesLeftOut++;

                continue;
            }
            $amount = (int) $this->cents($item->amount);
            $tax = $item->taxed ? Tax::apply($amount, $rate, $inclusive) : ['tax' => 0, 'total' => $amount];
            $key = $service ? "s{$service->id}" : "i{$item->id}";

            if (isset($lines[$key])) {
                if (in_array($type, ['Hosting', 'Domain', 'DomainRegister', 'DomainTransfer', 'DomainRenew'], true)) {
                    $this->report->problem('Invoice #'.($invoice->invoicenum ?: $invoice->id).": bills {$service->label} for more than one period; merged into one line.", 'invoices');
                }

                $lines[$key]['amount_cents'] += $amount;
                $lines[$key]['tax_cents'] += $tax['tax'];
                $lines[$key]['total_cents'] += $tax['total'];
                $lines[$key]['description'] .= ' + '.strtok((string) $item->description, "\n");

                continue;
            }

            $lines[$key] = [
                'service' => $service,
                'description' => mb_substr(str_replace("\n", ' · ', trim((string) $item->description)), 0, 255) ?: $type,
                'due_date' => $this->date($item->duedate) ?? $this->date($invoice->duedate) ?? CarbonImmutable::today(),
                'amount_cents' => $amount,
                'tax_cents' => $tax['tax'],
                'total_cents' => $tax['total'],
            ];
        }

        return collect(array_values($lines));
    }

    /** Website maintenance hours, billed in WHMCS as a one-off item ("Συντήρηση Ιστοσελίδας - 5.0 Hours @ 10.00/Hour"). */
    private function isMaintenance(string $description): bool
    {
        return (bool) preg_match('/^\s*(Συντήρηση Ιστοσελίδας|Website maintenance)/iu', $description);
    }

    /**
     * A one-off maintenance service for an invoice line, so recording the notice's payment records a payment for it.
     * Auto-terminate ends it once its period is over, so it gets no further notices.
     *
     * @param  array{description: string, due_date: CarbonImmutable, amount_cents: int}  $line
     */
    private function maintenanceService(Connection $whmcs, Client $client, object $invoice, array $line, CarbonImmutable $date): Service
    {
        $service = new Service([
            'client_id' => $client->id,
            'type' => ServiceType::Maintenance,
            'label' => mb_substr(trim(strtok($line['description'], '@')), 0, 255),
            'price_cents' => $line['amount_cents'],
            'currency' => $this->clientCurrency($whmcs, $invoice->userid),
            'billing_cycle' => BillingCycle::Monthly,
            'start_date' => $date,
            'next_due_date' => $line['due_date'],
            'status' => ServiceStatus::Active,
            'auto_terminate' => true,
            'auto_terminate_reason' => 'One-off charge',
            'auto_setup' => AutoSetup::Never,
            'notes' => 'WHMCS invoice #'.($invoice->invoicenum ?: $invoice->id).": {$line['description']}",
        ]);
        $service->created_at = $date;
        $service->save();
        $this->report->count('hosting', 'created');

        return $service;
    }

    private function suspensionReason(?string $reason, ?string $status): ?string
    {
        if (strtolower((string) $status) !== 'suspended') {
            return null;
        }

        // WHMCS's automatic suspension; GaiaCP unsuspends these when a payment is recorded.
        return blank($reason) || str_contains(strtolower($reason), 'overdue') ? Service::SUSPENDED_OVERDUE : $reason;
    }

    /** @var array<int, string> */
    private array $clientCurrencies = [];

    private function clientCurrency(Connection $whmcs, int|string $clientId): string
    {
        if (! isset($this->clientCurrencies[(int) $clientId])) {
            $row = $this->currencies->get($whmcs->table('tblclients')->where('id', $clientId)->value('currency'));
            $this->clientCurrencies[(int) $clientId] = $row && filled($row->code) ? $this->addCurrency($row) : Currencies::base();
        }

        return $this->clientCurrencies[(int) $clientId];
    }

    private function decrypt(?string $value, string $what): ?string
    {
        if (($this->crypt === null && $this->api === null) || blank($value)) {
            return null;
        }

        $plain = $this->crypt?->decrypt($value);

        if ($plain === null && $this->api !== null) {
            try {
                $plain = $this->api->decryptPassword(trim($value));
            } catch (Throwable $e) {
                $this->report->problem("{$e->getMessage()} The remaining passwords are not sent to it.", 'passwords');
                $this->api = null;
            }
        }

        if ($plain === null) {
            $length = max(0, strlen((string) base64_decode(trim($value))) - 20);
            $this->report->problem("{$what}: the password ({$length} characters) could not be decrypted, so it is imported without one.", 'passwords');
        }

        return $plain;
    }

    private function wants(string $part): bool
    {
        return in_array($part, $this->parts, true);
    }

    /** Runs a left-out part: its counts and problems go to a report nobody reads. */
    private function linkExisting(callable $part): void
    {
        [$report, $this->report, $this->linkOnly] = [$this->report, new WhmcsImportReport(true), true];

        try {
            $part();
        } finally {
            [$this->report, $this->linkOnly] = [$report, false];
        }
    }

    private function linked(string $entity, int|string|null $whmcsId): ?int
    {
        return $this->links[$entity][(int) $whmcsId] ?? null;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    private function find(string $model, string $entity, int|string|null $whmcsId): ?Model
    {
        $id = $this->linked($entity, $whmcsId);

        return $id ? $model::query()->find($id) : null;
    }

    /** $countAs: the report row it counts on, when not $entity (billable items count as services). */
    private function link(string $entity, int|string $whmcsId, Model $model, string $outcome, ?string $countAs = null): void
    {
        if ($this->linkOnly) {
            $this->links[$entity][(int) $whmcsId] = $model->getKey();

            return;
        }

        DB::table('whmcs_links')->insert([
            'entity' => $entity,
            'whmcs_id' => (int) $whmcsId,
            'local_id' => $model->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->links[$entity][(int) $whmcsId] = $model->getKey();
        $this->report->count($countAs ?? $entity, $outcome);
    }

    /** WHMCS decimal → cents; null for its "-1.00" (not offered) or an empty value. */
    private function cents(mixed $value): ?int
    {
        if ($value === null || $value === '' || (float) $value < 0) {
            return null;
        }

        return Money::toCents(number_format((float) $value, 2, '.', ''));
    }

    /** WHMCS dates, where "0000-00-00" means none. */
    private function date(mixed $value): ?CarbonImmutable
    {
        if (blank($value) || str_starts_with((string) $value, '0000-00-00')) {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }
}
