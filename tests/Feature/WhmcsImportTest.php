<?php

namespace Tests\Feature;

use App\Actions\RecordInvoicePayment;
use App\Enums\BillingCycle;
use App\Enums\ClientFieldAccess;
use App\Enums\ClientFieldType;
use App\Enums\DomainRegistrationType;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\ClientField;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Registrar;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Plugins\WhmcsImport\WhmcsApi;
use Plugins\WhmcsImport\WhmcsCrypt;
use Plugins\WhmcsImport\WhmcsImport;
use Plugins\WhmcsImport\WhmcsImportPage;
use Plugins\WhmcsImport\WhmcsImportReport;
use Tests\FakesWhm;
use Tests\TestCase;

class WhmcsImportTest extends TestCase
{
    use FakesWhm;
    use RefreshDatabase;

    private const HASH = 'whmcs-test-encryption-hash';

    private string $whmcsFile;

    protected function setUp(): void
    {
        parent::setUp();

        // A file, not :memory:, so the database survives the page reconnecting with the typed values.
        $this->whmcsFile = tempnam(sys_get_temp_dir(), 'whmcs');
        config(['database.connections.whmcs' => ['driver' => 'sqlite', 'database' => $this->whmcsFile, 'prefix' => '', 'encryption_hash' => null]]);
        DB::purge('whmcs');
        $this->createWhmcsSchema();
    }

    protected function tearDown(): void
    {
        DB::purge('whmcs');
        @unlink($this->whmcsFile);

        parent::tearDown();
    }

    private function whmcs(): Connection
    {
        return DB::connection('whmcs');
    }

    /** The parts of WHMCS's schema the import reads. */
    private function createWhmcsSchema(): void
    {
        $schema = Schema::connection('whmcs');

        $schema->create('tblcurrencies', function (Blueprint $t) {
            $t->increments('id');
            $t->string('code');
            $t->integer('default')->default(0);
        });
        $schema->create('tblservers', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->string('ipaddress')->default('');
            $t->string('hostname')->default('');
            $t->string('type');
            $t->string('username')->default('');
            $t->text('password')->default('');
            $t->text('accesshash')->default('');
            $t->integer('port')->nullable();
            $t->integer('disabled')->default(0);
        });
        $schema->create('tblproductgroups', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->text('headline')->nullable();
            $t->integer('hidden')->default(0);
            $t->integer('order')->default(0);
        });
        $schema->create('tblproducts', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('gid')->default(0);
            $t->string('type')->default('hostingaccount');
            $t->string('name');
            $t->text('description')->default('');
            $t->integer('hidden')->default(0);
            $t->integer('retired')->default(0);
            $t->string('paytype')->default('recurring');
            $t->string('servertype')->default('');
            $t->string('configoption1')->default('');
            $t->string('autosetup')->default('');
        });
        $schema->create('tblpricing', function (Blueprint $t) {
            $t->increments('id');
            $t->string('type');
            $t->integer('currency');
            $t->integer('relid');
            foreach (['msetupfee', 'qsetupfee', 'ssetupfee', 'asetupfee', 'bsetupfee', 'tsetupfee'] as $c) {
                $t->decimal($c, 10, 2)->default(0);
            }
            foreach (['monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'] as $c) {
                $t->decimal($c, 10, 2)->default(-1);
            }
        });
        $schema->create('tblclients', function (Blueprint $t) {
            $t->increments('id');
            foreach (['firstname', 'lastname', 'companyname', 'email', 'address1', 'address2', 'city', 'state', 'postcode', 'country', 'phonenumber', 'tax_id', 'defaultgateway', 'notes'] as $c) {
                $t->string($c)->default('');
            }
            $t->integer('currency')->default(1);
            $t->integer('taxexempt')->default(0);
            $t->string('status')->default('Active');
            $t->date('datecreated')->nullable();
        });
        $schema->create('tblcustomfields', function (Blueprint $t) {
            $t->increments('id');
            $t->string('type');
            $t->string('fieldname');
            $t->string('fieldtype')->default('text');
            $t->text('fieldoptions')->nullable();
            $t->string('adminonly')->default('');
            $t->string('required')->default('');
            $t->string('showinvoice')->default('');
            $t->integer('sortorder')->default(0);
        });
        $schema->create('tblcustomfieldsvalues', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('fieldid');
            $t->integer('relid');
            $t->text('value');
        });
        $schema->create('tblhosting', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('userid');
            $t->integer('packageid');
            $t->integer('server')->default(0);
            $t->string('regdate');
            $t->string('domain')->default('');
            $t->decimal('amount', 10, 2)->default(0);
            $t->string('billingcycle');
            $t->string('nextduedate');
            $t->string('domainstatus');
            $t->string('username')->default('');
            $t->text('password')->default('');
            $t->text('notes')->default('');
            $t->string('suspendreason')->default('');
            $t->integer('overideautosuspend')->default(0);
            $t->string('overidesuspenduntil')->default('0000-00-00');
        });
        $schema->create('tblcancelrequests', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('relid');
            $t->string('type');
            $t->text('reason')->default('');
        });
        $schema->create('tbldomains', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('userid');
            $t->string('type')->default('Register');
            $t->string('registrationdate');
            $t->string('domain');
            $t->decimal('recurringamount', 10, 2)->default(0);
            $t->string('registrar')->default('');
            $t->integer('registrationperiod')->default(1);
            $t->string('expirydate');
            $t->string('nextduedate');
            $t->string('status');
            $t->integer('donotrenew')->default(0);
            $t->text('additionalnotes')->default('');
        });
        $schema->create('tblconfiguration', function (Blueprint $t) {
            $t->string('setting');
            $t->text('value');
        });
        $schema->create('tbltax', function (Blueprint $t) {
            $t->increments('id');
            $t->string('name');
            $t->decimal('taxrate', 10, 3);
        });
        $schema->create('tblinvoices', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('userid');
            $t->string('invoicenum')->default('');
            $t->string('date');
            $t->string('duedate');
            $t->decimal('total', 10, 2);
            $t->decimal('taxrate', 10, 3)->default(0);
            $t->string('status');
        });
        $schema->create('tblinvoiceitems', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('invoiceid');
            $t->string('type');
            $t->integer('relid');
            $t->text('description');
            $t->decimal('amount', 10, 2);
            $t->integer('taxed')->default(0);
            $t->string('duedate');
        });
        $schema->create('tblbillableitems', function (Blueprint $t) {
            $t->increments('id');
            $t->integer('userid');
            $t->text('description');
            $t->decimal('hours', 10, 2)->default(0);
            $t->decimal('amount', 10, 2);
            $t->integer('recur')->default(0);
            $t->string('recurcycle')->default('');
            $t->integer('recurfor')->default(0);
            $t->integer('invoiceaction')->default(0);
            $t->string('duedate');
            $t->integer('invoicecount')->default(0);
        });
    }

    /** One client with a cPanel account, a Plesk account, two domains and an unpaid invoice. */
    private function seedWhmcs(): void
    {
        $crypt = new WhmcsCrypt(self::HASH);
        $db = $this->whmcs();

        $db->table('tblcurrencies')->insert([['id' => 1, 'code' => 'EUR', 'default' => 1]]);
        $db->table('tblconfiguration')->insert(['setting' => 'TaxType', 'value' => 'Exclusive']);
        $db->table('tbltax')->insert(['name' => 'ΦΠΑ', 'taxrate' => 24]);

        $db->table('tblservers')->insert([
            ['id' => 1, 'name' => 'cp1', 'hostname' => 'cp1.example.com', 'ipaddress' => '10.0.0.1', 'type' => 'cpanel', 'username' => 'root', 'password' => '', 'accesshash' => "TOKEN123\n", 'port' => null],
            ['id' => 2, 'name' => 'pl1', 'hostname' => 'pl1.example.com', 'ipaddress' => '10.0.0.2', 'type' => 'plesk', 'username' => 'admin', 'password' => $crypt->encrypt('plesk-secret'), 'accesshash' => '', 'port' => 8443],
            ['id' => 3, 'name' => 'da1', 'hostname' => 'da1.example.com', 'ipaddress' => '', 'type' => 'directadmin', 'username' => 'admin', 'password' => $crypt->encrypt('da-secret'), 'accesshash' => '', 'port' => null],
            ['id' => 4, 'name' => 'vm1', 'hostname' => 'vm1.example.com', 'ipaddress' => '', 'type' => 'virtualmin', 'username' => '', 'password' => '', 'accesshash' => '', 'port' => null],
        ]);

        $db->table('tblproductgroups')->insert(['id' => 1, 'name' => 'Shared Hosting', 'headline' => 'Fast <b>SSD</b> hosting', 'order' => 2]);
        $db->table('tblproducts')->insert([
            ['id' => 1, 'gid' => 1, 'name' => 'Hosting Basic', 'servertype' => 'cpanel', 'configoption1' => 'basic', 'autosetup' => 'payment', 'paytype' => 'recurring', 'type' => 'hostingaccount'],
            ['id' => 2, 'gid' => 1, 'name' => 'Plesk Pro', 'servertype' => 'plesk', 'configoption1' => 'Pro plan', 'autosetup' => 'on', 'paytype' => 'recurring', 'type' => 'hostingaccount'],
            ['id' => 3, 'gid' => 0, 'name' => 'Website setup', 'servertype' => '', 'configoption1' => '', 'autosetup' => '', 'paytype' => 'onetime', 'type' => 'other'],
        ]);
        $db->table('tblpricing')->insert([
            ['type' => 'product', 'currency' => 1, 'relid' => 1, 'annually' => 60, 'asetupfee' => 10, 'monthly' => 6],
            ['type' => 'product', 'currency' => 1, 'relid' => 2, 'annually' => 120, 'asetupfee' => 0, 'monthly' => -1],
        ]);

        $db->table('tblclients')->insert([
            'id' => 7, 'firstname' => 'Γιώργος', 'lastname' => 'Παπαδόπουλος', 'companyname' => 'Παπαδόπουλος ΑΕ', 'email' => 'giorgos@example.gr',
            'address1' => 'Οδός 1', 'city' => 'Αθήνα', 'postcode' => '10431', 'country' => 'GR', 'phonenumber' => '+30.2101234567',
            'defaultgateway' => 'banktransfer', 'datecreated' => '2019-03-04',
        ]);
        $db->table('tblcustomfields')->insert([
            ['id' => 1, 'type' => 'client', 'fieldname' => 'Α.Φ.Μ.'],
            ['id' => 2, 'type' => 'client', 'fieldname' => 'ΔΟΥ|Δ.Ο.Υ.'],
            ['id' => 3, 'type' => 'client', 'fieldname' => 'Επάγγελμα'],
            ['id' => 4, 'type' => 'product', 'fieldname' => 'ΑΦΜ'],
        ]);
        $db->table('tblcustomfields')->insert([
            ['id' => 5, 'type' => 'client', 'fieldname' => 'Αριθμός ταυτότητας', 'fieldtype' => 'text', 'fieldoptions' => null, 'adminonly' => '', 'required' => 'on', 'showinvoice' => 'on', 'sortorder' => 1],
            ['id' => 6, 'type' => 'client', 'fieldname' => 'Πηγή', 'fieldtype' => 'dropdown', 'fieldoptions' => 'Google,Φίλος', 'adminonly' => 'on', 'required' => '', 'showinvoice' => '', 'sortorder' => 2],
        ]);
        $db->table('tblcustomfieldsvalues')->insert([
            ['fieldid' => 1, 'relid' => 7, 'value' => '094014201'],
            ['fieldid' => 2, 'relid' => 7, 'value' => 'Α\' Αθηνών'],
            ['fieldid' => 3, 'relid' => 7, 'value' => 'Εμπόριο'],
            ['fieldid' => 4, 'relid' => 7, 'value' => 'wrong'],
            ['fieldid' => 5, 'relid' => 7, 'value' => 'ΑΚ 123456'],
            ['fieldid' => 6, 'relid' => 7, 'value' => 'Φίλος'],
        ]);

        $db->table('tblhosting')->insert([
            ['id' => 11, 'userid' => 7, 'packageid' => 1, 'server' => 1, 'regdate' => '2020-05-31', 'domain' => 'papadopoulos.gr', 'amount' => 60, 'billingcycle' => 'Annually',
                'nextduedate' => '2026-11-30', 'domainstatus' => 'Suspended', 'username' => 'papado', 'password' => $crypt->encrypt('a-very-long-password-over-twenty-chars'), 'suspendreason' => 'Overdue on Payment'],
            ['id' => 12, 'userid' => 7, 'packageid' => 2, 'server' => 2, 'regdate' => '2021-01-10', 'domain' => 'shop.gr', 'amount' => 120, 'billingcycle' => 'Annually',
                'nextduedate' => '2027-01-10', 'domainstatus' => 'Active', 'username' => 'shop', 'password' => $crypt->encrypt('short'), 'suspendreason' => ''],
            ['id' => 13, 'userid' => 7, 'packageid' => 3, 'server' => 0, 'regdate' => '2021-01-10', 'domain' => '', 'amount' => 300, 'billingcycle' => 'One Time',
                'nextduedate' => '0000-00-00', 'domainstatus' => 'Active', 'username' => '', 'password' => '', 'suspendreason' => ''],
        ]);
        $db->table('tblcancelrequests')->insert(['relid' => 12, 'type' => 'End of Billing Period', 'reason' => 'Moving away']);

        $db->table('tbldomains')->insert([
            ['id' => 21, 'userid' => 7, 'type' => 'Register', 'registrationdate' => '2018-06-15', 'domain' => 'Papadopoulos.GR', 'recurringamount' => 15, 'registrar' => 'easy',
                'registrationperiod' => 2, 'expirydate' => '2028-06-15', 'nextduedate' => '2028-06-15', 'status' => 'Active', 'donotrenew' => 0],
            ['id' => 22, 'userid' => 7, 'type' => 'Transfer', 'registrationdate' => '2019-02-01', 'domain' => 'other.com', 'recurringamount' => 12, 'registrar' => 'enom',
                'registrationperiod' => 1, 'expirydate' => '2027-02-01', 'nextduedate' => '2027-02-01', 'status' => 'Active', 'donotrenew' => 1],
        ]);

        $db->table('tblinvoices')->insert([
            ['id' => 31, 'userid' => 7, 'invoicenum' => '', 'date' => '2026-11-16', 'duedate' => '2026-11-30', 'total' => 86.80, 'taxrate' => 24, 'status' => 'Unpaid'],
            ['id' => 32, 'userid' => 7, 'invoicenum' => '', 'date' => '2025-11-16', 'duedate' => '2025-11-30', 'total' => 74.40, 'taxrate' => 24, 'status' => 'Paid'],
        ]);
        $db->table('tblinvoiceitems')->insert([
            ['invoiceid' => 31, 'type' => 'Hosting', 'relid' => 11, 'description' => "Hosting Basic - papadopoulos.gr\n(30/11/2026 - 29/11/2027)", 'amount' => 60, 'taxed' => 1, 'duedate' => '2026-11-30'],
            ['invoiceid' => 31, 'type' => 'Setup', 'relid' => 11, 'description' => 'Setup fee', 'amount' => 5, 'taxed' => 1, 'duedate' => '2026-11-30'],
            ['invoiceid' => 31, 'type' => '', 'relid' => 0, 'description' => 'Extra work', 'amount' => 5, 'taxed' => 1, 'duedate' => '2026-11-30'],
            ['invoiceid' => 32, 'type' => 'Hosting', 'relid' => 11, 'description' => 'Hosting Basic', 'amount' => 60, 'taxed' => 1, 'duedate' => '2025-11-30'],
        ]);
    }

    private function import(bool $dryRun = false, ?string $hash = self::HASH): WhmcsImportReport
    {
        return (new WhmcsImport('whmcs', $hash))->run($dryRun);
    }

    public function test_it_imports_clients_servers_products_services_domains_and_unpaid_invoices(): void
    {
        $easy = Registrar::factory()->create();
        $this->seedWhmcs();

        $report = $this->import();

        // Client, with the Greek fields from custom fields.
        $client = Client::sole();
        $this->assertSame('Γιώργος', $client->first_name);
        $this->assertSame('Παπαδόπουλος ΑΕ', $client->company);
        $this->assertSame('094014201', $client->vat_number);
        $this->assertSame('Α\' Αθηνών', $client->tax_office);
        $this->assertSame('Εμπόριο', $client->profession);
        // and the other custom fields as client fields of their own
        $this->assertSame(['arithmos_tautotitas' => 'ΑΚ 123456', 'pighi' => 'Φίλος'], $client->custom_fields);
        $id = ClientField::where('key', 'arithmos_tautotitas')->sole();
        $this->assertTrue($id->required && $id->on_notice);
        $this->assertSame(ClientFieldAccess::Editable, $id->client_access);
        $source = ClientField::where('key', 'pighi')->sole();
        $this->assertSame([ClientFieldType::Select, ['Google', 'Φίλος'], ClientFieldAccess::Hidden], [$source->type, $source->options, $source->client_access]);
        $this->assertSame('bank_transfer', $client->default_payment_method);
        $this->assertSame('2019-03-04', $client->created_at->toDateString());

        // Servers: cPanel with its API token, Plesk and DirectAdmin with the decrypted password; Virtualmin skipped.
        $cp = Server::where('hostname', 'cp1.example.com')->sole();
        $this->assertSame('cpanel', $cp->type);
        $this->assertSame('TOKEN123', $cp->api_token);
        $this->assertSame(2087, $cp->port);
        $pl = Server::where('hostname', 'pl1.example.com')->sole();
        $this->assertSame('plesk-secret', $pl->api_token);
        $this->assertSame(8443, $pl->port);
        $da = Server::where('hostname', 'da1.example.com')->sole();
        $this->assertSame(['directadmin', 'admin', 'da-secret', 2222], [$da->type, $da->username, $da->api_token, $da->port]);
        $this->assertSame(3, Server::count());

        // Products with prices per cycle; one-time skipped.
        $basic = Product::where('name', 'Hosting Basic')->sole();
        $this->assertSame(ServiceType::Hosting, $basic->type);
        $this->assertSame('basic', $basic->package);
        $this->assertSame(6000, $basic->priceFor(BillingCycle::Annually));
        $this->assertSame(1000, $basic->setupFeeFor(BillingCycle::Annually));
        $this->assertSame(600, $basic->priceFor(BillingCycle::Monthly));
        $this->assertNull($basic->priceFor(BillingCycle::Quarterly));
        $this->assertSame('payment', $basic->auto_setup->value);
        $this->assertNull(Product::where('name', 'Plesk Pro')->sole()->priceFor(BillingCycle::Monthly));
        $this->assertSame(2, Product::count());
        $group = ProductGroup::query()->sole(); // both in WHMCS's group
        $this->assertSame(['shared-hosting', 'Shared Hosting', 'Fast SSD hosting', 2], [$group->slug, $group->name(), $group->description(), $group->sort]);
        $this->assertSame(2, $group->products()->count());

        // Hosting services.
        $cpanel = Service::where('domain', 'papadopoulos.gr')->where('type', ServiceType::Hosting)->sole();
        $this->assertSame($client->id, $cpanel->client_id);
        $this->assertSame($cp->id, $cpanel->server_id);
        $this->assertSame('papado', $cpanel->username);
        $this->assertSame('a-very-long-password-over-twenty-chars', $cpanel->password);
        $this->assertSame(ServiceStatus::Suspended, $cpanel->status);
        $this->assertSame(Service::SUSPENDED_OVERDUE, $cpanel->suspension_reason);
        $this->assertSame('2026-11-30', $cpanel->next_due_date->toDateString());
        $this->assertSame(31, $cpanel->anchorDay());
        $this->assertSame(6000, $cpanel->price_cents);

        $plesk = Service::where('domain', 'shop.gr')->sole();
        $this->assertSame('short', $plesk->password);
        $this->assertSame('Pro plan', $plesk->package);
        $this->assertTrue($plesk->auto_terminate);
        $this->assertSame('Moving away', $plesk->auto_terminate_reason);

        // Domains: easy.gr ones at the easy.gr account, others tracked only.
        $domain = Service::where('type', ServiceType::Domain)->where('domain', 'papadopoulos.gr')->sole();
        $this->assertSame(BillingCycle::Biennially, $domain->billing_cycle);
        $this->assertSame(1500, $domain->price_cents);
        $this->assertSame($easy->id, $domain->domainDetails->registrar_id);
        $this->assertSame('2028-06-15', $domain->domainDetails->expires_at->toDateString());
        $this->assertTrue($domain->autoRenew);

        $other = Service::where('domain', 'other.com')->sole();
        $this->assertSame(DomainRegistrationType::External, $other->domainDetails->registration_type);
        $this->assertFalse($other->autoRenew);

        // The unpaid invoice: one line for the service (with its setup fee) and one without a service.
        $invoice = Invoice::sole();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame('PF-2026-0001', $invoice->number);
        $this->assertSame('WHMCS invoice #31', $invoice->notes);
        $this->assertNotNull($invoice->emailed_at);
        $this->assertSame(8680, $invoice->total_cents);
        $this->assertSame(1680, $invoice->tax_cents);
        $this->assertSame('ΦΠΑ', $invoice->tax_name);
        $line = $invoice->items()->whereNotNull('service_id')->sole();
        $this->assertSame($cpanel->id, $line->service_id);
        $this->assertSame(6500, $line->amount_cents);
        $this->assertSame('2026-11-30', $line->due_date->toDateString());
        $this->assertSame(2, $invoice->items()->count());

        $this->assertSame(['created' => 1, 'matched' => 0, 'existing' => 0, 'skipped' => 0], $report->counts['client']);
        $this->assertSame(1, $report->counts['server']['skipped']);
        $this->assertSame(1, $report->counts['hosting']['skipped']);
        $this->assertNotEmpty(array_filter($report->problems, fn ($p) => str_contains($p, 'other.com') && str_contains($p, 'enom')));
        $this->assertNotEmpty(array_filter($report->problems, fn ($p) => str_contains($p, 'Extra work')));
        $this->assertSame(1, ActivityLog::where('event', 'whmcs.imported')->count());
    }

    public function test_dry_run_reports_without_saving(): void
    {
        $this->seedWhmcs();

        $report = $this->import(dryRun: true);

        $this->assertTrue($report->dryRun);
        $this->assertSame(1, $report->counts['client']['created']);
        $this->assertSame(2, $report->counts['hosting']['created']);
        $this->assertSame(2, $report->counts['domain']['created']);
        $this->assertSame(1, $report->counts['invoice']['created']);
        $this->assertSame(0, Client::count());
        $this->assertSame(0, Service::count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, DB::table('whmcs_links')->count());
    }

    public function test_running_again_creates_nothing_twice(): void
    {
        $this->seedWhmcs();
        $this->import();

        $report = $this->import();

        $this->assertSame(0, $report->created());
        $this->assertSame(1, $report->counts['client']['existing']);
        $this->assertSame(2, $report->counts['hosting']['existing']);
        $this->assertSame(1, $report->counts['invoice']['existing']);
        $this->assertSame(1, Client::count());
        $this->assertSame(4, Service::count());
        $this->assertSame(1, Invoice::count());
    }

    public function test_records_already_here_are_linked_not_copied(): void
    {
        $this->seedWhmcs();
        $client = Client::factory()->create(['email' => 'Giorgos@Example.gr', 'first_name' => 'Kept']);
        $server = Server::factory()->create(['hostname' => '10.0.0.2', 'type' => 'plesk']);

        $report = $this->import();

        $this->assertSame(1, $report->counts['client']['matched']);
        $this->assertSame('Kept', $client->fresh()->first_name);
        $this->assertSame(1, Client::count());
        $this->assertSame($client->id, Service::where('domain', 'shop.gr')->sole()->client_id);
        $this->assertSame($server->id, Service::where('domain', 'shop.gr')->sole()->server_id);
    }

    public function test_an_invoice_whose_due_date_is_already_on_a_notice_is_skipped(): void
    {
        $this->seedWhmcs();
        $this->import();
        // Imported once, then the link is lost: the service's due date is still on a notice.
        DB::table('whmcs_links')->where('entity', 'invoice')->delete();

        $report = $this->import();

        $this->assertSame(1, $report->counts['invoice']['skipped']);
        $this->assertSame(1, Invoice::count());
    }

    public function test_an_unpaid_invoice_for_a_period_whmcs_has_moved_past_is_still_owed(): void
    {
        $this->seedWhmcs();
        // Set up by hand before the client paid: WHMCS moved the due date on, the invoice is still unpaid.
        $this->whmcs()->table('tblhosting')->where('id', 11)->update(['nextduedate' => '2027-11-30']);

        $report = $this->import();

        $service = Service::where('domain', 'papadopoulos.gr')->where('type', ServiceType::Hosting)->sole();
        $this->assertSame('2026-11-30', $service->next_due_date->toDateString());
        $this->assertSame('2027-11-30', $service->override_suspend_until->toDateString());
        $this->assertSame(1, Invoice::count());
        $this->assertStringContainsString('so it is due from 30/11/2026 here', $report->grouped['invoices'][0]);

        // Paying the notice pays that period (and unsuspends the account, suspended for non-payment).
        $this->fakeWhm();
        $this->whmAccounts['papado'] = true;
        app(RecordInvoicePayment::class)->handle(Invoice::sole(), CarbonImmutable::parse('2026-12-01'), 'bank_transfer');
        $this->assertSame('2027-11-30', $service->fresh()->next_due_date->toDateString());
        $this->assertSame(InvoiceStatus::Paid, Invoice::sole()->status);
    }

    public function test_website_maintenance_hours_become_one_off_maintenance_services(): void
    {
        $this->seedWhmcs();
        $this->whmcs()->table('tblinvoices')->insert(['id' => 33, 'userid' => 7, 'invoicenum' => 'A 759', 'date' => '2026-09-20', 'duedate' => '2026-09-27', 'total' => 62, 'taxrate' => 24, 'status' => 'Unpaid']);
        $this->whmcs()->table('tblinvoiceitems')->insert(['invoiceid' => 33, 'type' => 'Item', 'relid' => 0, 'description' => 'Συντήρηση Ιστοσελίδας - 5.0 Hours @ 10.00/Hour - 5.00 Ώρες @ 10.00/Ώρα', 'amount' => 50, 'taxed' => 1, 'duedate' => '2026-09-27']);

        $report = $this->import();

        $service = Service::where('type', ServiceType::Maintenance)->sole();
        $this->assertSame('Συντήρηση Ιστοσελίδας - 5.0 Hours', $service->label);
        $this->assertSame(5000, $service->price_cents);
        $this->assertSame('2026-09-27', $service->next_due_date->toDateString());
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertTrue($service->auto_terminate);

        $invoice = Invoice::where('notes', 'WHMCS invoice #A 759')->sole();
        $this->assertSame($service->id, $invoice->items()->sole()->service_id);
        // Only the other invoice's "Extra work" line is still without a service.
        $this->assertCount(1, array_filter($report->grouped['invoices'] ?? [], fn (string $p) => str_contains($p, 'is not for a service')));

        // Overdue, so the daily run ends it; the notice stays open and can still be paid.
        $this->artisan('gaiacp:terminate-scheduled', ['--date' => '2026-10-01'])->assertSuccessful();
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);

        app(RecordInvoicePayment::class)->handle($invoice, CarbonImmutable::parse('2026-10-01'), 'bank_transfer');
        $this->assertSame(1, $service->payments()->count());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);

        // Re-running links the invoice and makes no second service.
        $this->import();
        $this->assertSame(1, Service::where('type', ServiceType::Maintenance)->count());
    }

    public function test_billable_items_become_maintenance_services(): void
    {
        $this->seedWhmcs();
        $this->whmcs()->table('tblbillableitems')->insert([
            // Annual maintenance, invoiced once and still unpaid: owed from that invoice's date.
            ['id' => 41, 'userid' => 7, 'description' => "Ετήσια συντήρηση ιστοσελίδας\nπεριλαμβάνει ενημερώσεις", 'amount' => 120, 'recur' => 1, 'recurcycle' => 'Years',
                'recurfor' => 0, 'invoiceaction' => 4, 'duedate' => '2027-09-01', 'invoicecount' => 1],
            // One-off, not invoiced yet.
            ['id' => 42, 'userid' => 7, 'description' => 'Μεταφορά ιστοσελίδας', 'amount' => 40, 'recur' => 0, 'recurcycle' => '',
                'recurfor' => 0, 'invoiceaction' => 3, 'duedate' => '2026-11-05', 'invoicecount' => 0],
            // One-off already invoiced and paid, recurring that has finished, and "Don't invoice": none come in.
            ['id' => 43, 'userid' => 7, 'description' => 'Paid work', 'amount' => 10, 'recur' => 0, 'recurcycle' => '', 'recurfor' => 0, 'invoiceaction' => 1, 'duedate' => '2026-01-01', 'invoicecount' => 1],
            ['id' => 44, 'userid' => 7, 'description' => 'Finished', 'amount' => 10, 'recur' => 1, 'recurcycle' => 'Months', 'recurfor' => 3, 'invoiceaction' => 4, 'duedate' => '2026-01-01', 'invoicecount' => 3],
            ['id' => 45, 'userid' => 7, 'description' => 'Hours only', 'amount' => 10, 'recur' => 0, 'recurcycle' => '', 'recurfor' => 0, 'invoiceaction' => 0, 'duedate' => '2026-01-01', 'invoicecount' => 0],
        ]);
        $this->whmcs()->table('tblinvoices')->insert(['id' => 34, 'userid' => 7, 'invoicenum' => 'A 800', 'date' => '2026-08-25', 'duedate' => '2026-09-01', 'total' => 148.80, 'taxrate' => 24, 'status' => 'Unpaid']);
        $this->whmcs()->table('tblinvoiceitems')->insert(['invoiceid' => 34, 'type' => 'Item', 'relid' => 41, 'description' => 'Ετήσια συντήρηση ιστοσελίδας', 'amount' => 120, 'taxed' => 1, 'duedate' => '2026-09-01']);

        $report = $this->import();

        $services = Service::where('type', ServiceType::Maintenance)->orderBy('id')->get();
        $this->assertSame(['Ετήσια συντήρηση ιστοσελίδας', 'Μεταφορά ιστοσελίδας'], $services->pluck('label')->all());

        [$annual, $oneOff] = $services->all();
        $this->assertSame(BillingCycle::Annually, $annual->billing_cycle);
        $this->assertSame(12000, $annual->price_cents);
        $this->assertSame('2026-09-01', $annual->next_due_date->toDateString());
        $this->assertFalse($annual->auto_terminate);
        $this->assertTrue($oneOff->auto_terminate);
        $this->assertSame('2026-11-05', $oneOff->next_due_date->toDateString());

        // The unpaid invoice's line is the annual service's, so paying it renews the service.
        $invoice = Invoice::where('notes', 'WHMCS invoice #A 800')->sole();
        $this->assertSame($annual->id, $invoice->items()->sole()->service_id);
        app(RecordInvoicePayment::class)->handle($invoice, CarbonImmutable::parse('2026-10-01'), 'bank_transfer');
        $this->assertSame('2027-09-01', $annual->fresh()->next_due_date->toDateString());

        $this->import();
        $this->assertSame(2, Service::where('type', ServiceType::Maintenance)->count());
    }

    public function test_domains_at_other_registrars_are_listed_together(): void
    {
        $this->seedWhmcs();
        $this->whmcs()->table('tbldomains')->insert(['id' => 23, 'userid' => 7, 'registrationdate' => '2019-02-01', 'domain' => 'third.gr', 'registrar' => 'enom',
            'expirydate' => '2027-02-01', 'nextduedate' => '2027-02-01', 'status' => 'Active']);

        $report = $this->import();

        $this->assertContains('Registrar "enom" (2): imported as tracked only, so GaiaCP does not renew or sync them: other.com, third.gr.', $report->grouped['registrars']);
    }

    public function test_without_the_encryption_hash_passwords_are_left_empty(): void
    {
        $this->seedWhmcs();

        $report = $this->import(hash: null);

        $this->assertNull(Service::where('domain', 'shop.gr')->sole()->password);
        $this->assertNull(Server::where('type', 'plesk')->sole()->api_token);
        $this->assertStringContainsString('No encryption hash', $report->problems[0]);
    }

    public function test_passwords_it_cannot_decrypt_are_decrypted_by_the_whmcs_api(): void
    {
        $this->seedWhmcs();
        $unreadable = base64_encode(str_repeat("\xff", 42));
        $this->whmcs()->table('tblhosting')->where('id', 12)->update(['password' => $unreadable]);
        Http::fake(['whmcs.example.com/includes/api.php' => Http::response(['result' => 'success', 'password' => 'Decrypted-by-WHMCS'])]);

        $report = (new WhmcsImport('whmcs', self::HASH, new WhmcsApi('https://whmcs.example.com/', 'id', 'secret')))->run();

        $this->assertSame('Decrypted-by-WHMCS', Service::where('domain', 'shop.gr')->sole()->password);
        $this->assertArrayNotHasKey('passwords', $report->grouped);
        Http::assertSent(fn ($request) => $request->url() === 'https://whmcs.example.com/includes/api.php'
            && $request['action'] === 'DecryptPassword' && $request['password2'] === $unreadable && $request['identifier'] === 'id');
        // Only the one the hash could not read went to WHMCS.
        Http::assertSentCount(1);
    }

    public function test_when_the_whmcs_api_refuses_passwords_are_imported_without_one(): void
    {
        $this->seedWhmcs();
        $this->whmcs()->table('tblhosting')->where('id', 12)->update(['password' => base64_encode(str_repeat("\xff", 42))]);
        Http::fake(['*' => Http::response(['result' => 'error', 'message' => 'Invalid IP 1.2.3.4'])]);

        $report = (new WhmcsImport('whmcs', self::HASH, new WhmcsApi('https://whmcs.example.com', 'id', 'secret')))->run();

        $this->assertNull(Service::where('domain', 'shop.gr')->sole()->password);
        $this->assertStringContainsString('WHMCS API: Invalid IP 1.2.3.4', $report->grouped['passwords'][0]);
        $this->assertStringContainsString('could not be decrypted', $report->grouped['passwords'][1]);
    }

    public function test_whmcs_crypt_round_trips_and_rejects_a_wrong_hash(): void
    {
        $crypt = new WhmcsCrypt(self::HASH);

        foreach (['x', 'exactly-twenty-chars', 'Pässwörd με ελληνικά and more than forty bytes long!'] as $plain) {
            $this->assertSame($plain, $crypt->decrypt($crypt->encrypt($plain)));
        }

        // Long passwords chained on the cipher text, as some WHMCS versions do, read too.
        $long = 'a-password-longer-than-one-twenty-byte-block';
        $this->assertSame($long, $crypt->decrypt($crypt->encrypt($long, chainCipher: true)));

        $this->assertNull((new WhmcsCrypt('another-hash'))->decrypt($crypt->encrypt('secret password')));
        $this->assertNull($crypt->decrypt('not base64!'));
    }

    public function test_parts_left_out_are_not_created_but_records_already_here_are_used(): void
    {
        $this->seedWhmcs();
        // Already in GaiaCP: the cPanel server (same hostname) and the client (same email).
        $whmcs = $this->whmcs();
        $server = Server::factory()->create(['hostname' => $whmcs->table('tblservers')->where('type', 'cpanel')->value('hostname')]);
        $client = Client::factory()->create(['email' => $whmcs->table('tblclients')->where('id', 7)->value('email')]);
        $servers = Server::count();

        $report = (new WhmcsImport('whmcs', self::HASH))->run(parts: ['hosting', 'invoice']);

        // Nothing new from the parts left out; the hosting uses the server and client already here.
        $this->assertSame($servers, Server::count());
        $this->assertSame(1, Client::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, Service::where('type', ServiceType::Domain)->count());
        $cpanel = Service::where('domain', 'papadopoulos.gr')->where('type', ServiceType::Hosting)->sole();
        $this->assertSame($server->id, $cpanel->server_id);
        $this->assertSame($client->id, $cpanel->client_id);

        // Only the imported kinds are reported, and nothing about the ones left out is linked for good.
        $this->assertSame(['hosting', 'invoice'], array_keys($report->toArray()['counts']));
        $this->assertSame(0, DB::table('whmcs_links')->whereIn('entity', ['server', 'client'])->count());

        // A later run imports the rest without doubling anything.
        (new WhmcsImport('whmcs', self::HASH))->run();
        $this->assertSame(1, Client::count());
        $this->assertSame(1, Service::where('domain', 'papadopoulos.gr')->where('type', ServiceType::Hosting)->count());
        $this->assertSame(1, Invoice::count());
    }

    public function test_invoice_lines_for_services_left_out_are_dropped(): void
    {
        $this->seedWhmcs();

        $report = (new WhmcsImport('whmcs', self::HASH))->run(parts: ['server', 'product', 'client', 'domain', 'invoice']);

        // Invoice 31's hosting line (and its setup fee) is left out; its "Extra work" line remains.
        $invoice = Invoice::sole();
        $this->assertSame(['Extra work'], $invoice->items()->pluck('description')->all());
        $this->assertNotEmpty(array_filter($report->grouped['invoices'], fn (string $p) => str_contains($p, 'line(s) for services left out of this import')));
    }

    public function test_command_leaves_out_parts(): void
    {
        $this->seedWhmcs();

        $this->artisan('gaiacp:import-whmcs', ['--hash' => self::HASH, '--without' => ['domains', 'invoice']])->assertSuccessful();
        $this->assertSame(0, Service::where('type', ServiceType::Domain)->count());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(1, Client::count());

        $this->artisan('gaiacp:import-whmcs', ['--hash' => self::HASH, '--without' => ['tickets']])
            ->expectsOutputToContain('Unknown part(s): ticket')
            ->assertFailed();
    }

    public function test_command_dry_run(): void
    {
        $this->seedWhmcs();

        $this->artisan('gaiacp:import-whmcs', ['--dry-run' => true, '--hash' => self::HASH])
            ->expectsOutputToContain('Dry run, nothing saved')
            ->assertSuccessful();

        $this->assertSame(0, Client::count());
    }

    public function test_settings_page_runs_a_dry_run_and_imports(): void
    {
        $this->actingAs(User::factory()->create());
        $this->seedWhmcs();

        $this->get('/admin/settings')->assertOk()->assertSee(WhmcsImportPage::getUrl());

        Livewire::test(WhmcsImportPage::class)
            ->fillForm(['host' => 'localhost', 'database' => $this->whmcsFile, 'username' => 'reader', 'hash' => self::HASH])
            ->call('runImport', true)
            ->assertSee('Dry run: nothing was saved')
            ->assertSee('Domains at a registrar GaiaCP has no module for (2)')
            ->assertSee('WHMCS invoices (1)')
            ->assertSee('Hosting and other services')
            // inline problem text keeps each bullet beside the item's first line
            ->assertSeeHtml('style="display: inline" class="fi-sc-text"');

        $this->assertSame(0, Client::count());

        Livewire::test(WhmcsImportPage::class)
            ->fillForm(['host' => 'localhost', 'database' => $this->whmcsFile, 'username' => 'reader', 'hash' => self::HASH])
            ->call('runImport', false)
            ->assertSee('Imported');

        $this->assertSame(1, Client::count());
    }
}
