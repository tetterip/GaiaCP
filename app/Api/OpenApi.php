<?php

namespace App\Api;

use App\Actions\ClientOrder;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\DomainRegistrationType;
use App\Enums\InvoiceStatus;
use App\Enums\LeadStatus;
use App\Enums\OrderStatus;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Enums\ServiceType;
use App\Http\Requests\Api\SendInvoiceRequest;
use App\Models\ApiKey;
use App\Support\Version;
use BackedEnum;

/**
 * The REST API's description (OpenAPI 3.1), served at /api/v1/openapi.json and shown on Settings → API keys →
 * Documentation. endpoints() is the one list of calls both are made from; keep it in step with routes/api.php
 * (ApiTest checks that every route is in it). The texts are for developers and stay in English.
 */
class OpenApi
{
    /**
     * Every call, grouped by area.
     *
     * @return list<array{method: string, path: string, area: string, permission: string|null, summary: string, description?: string, query?: list<string>, body?: string, response: string|null, status?: int}>
     */
    public static function endpoints(): array
    {
        $list = ['per_page', 'page'];

        return [
            ['method' => 'get', 'path' => '/', 'area' => 'key', 'permission' => null, 'summary' => 'The key making the call', 'description' => 'Its name, its permissions and the GaiaCP version.', 'response' => 'Key'],
            ['method' => 'get', 'path' => '/openapi.json', 'area' => 'key', 'permission' => null, 'summary' => 'This description, as OpenAPI 3.1', 'response' => null],

            ['method' => 'get', 'path' => '/clients', 'area' => 'clients', 'permission' => 'clients.read', 'summary' => 'List clients', 'query' => ['search', 'email', 'status', 'country', ...$list], 'response' => 'Client[]'],
            ['method' => 'post', 'path' => '/clients', 'area' => 'clients', 'permission' => 'clients.create', 'summary' => 'Create a client', 'description' => 'first_name, last_name and email are required. The country defaults to Settings → General → Region, the currency to the base one. No email is sent and no client area login is made.', 'body' => 'ClientInput', 'response' => 'Client', 'status' => 201],
            ['method' => 'get', 'path' => '/clients/{id}', 'area' => 'clients', 'permission' => 'clients.read', 'summary' => 'Get a client', 'response' => 'Client'],
            ['method' => 'patch', 'path' => '/clients/{id}', 'area' => 'clients', 'permission' => 'clients.update', 'summary' => 'Change a client', 'description' => 'Only the fields sent change. The currency stays as it is once the client has services or payment notices.', 'body' => 'ClientInput', 'response' => 'Client'],
            ['method' => 'delete', 'path' => '/clients/{id}', 'area' => 'clients', 'permission' => 'clients.delete', 'summary' => 'Delete a client', 'description' => 'Only a client with no services, payments or credit (409 otherwise): close the others with "status": "closed".', 'response' => null, 'status' => 204],

            ['method' => 'get', 'path' => '/services', 'area' => 'services', 'permission' => 'services.read', 'summary' => 'List services', 'description' => 'Hosting accounts, domains, maintenance and other services. No passwords or EPP codes.', 'query' => ['client_id', 'order_id', 'type', 'status', 'domain', 'product_id', 'server_id', 'parent_service_id', ...$list], 'response' => 'Service[]'],
            ['method' => 'get', 'path' => '/services/{id}', 'area' => 'services', 'permission' => 'services.read', 'summary' => 'Get a service', 'response' => 'Service'],
            ['method' => 'patch', 'path' => '/services/{id}', 'area' => 'services', 'permission' => 'services.update', 'summary' => 'Change a service', 'description' => 'Its label (not for a domain), notes, whether the client area shows it, the date auto-suspension leaves it alone until, and auto_terminate (end at the next due date; for a domain, auto renew off). Prices, products and due dates change in the panel.', 'body' => 'ServiceInput', 'response' => 'Service'],
            ['method' => 'post', 'path' => '/services/{id}/suspend', 'area' => 'services', 'permission' => 'services.update', 'summary' => 'Suspend a service', 'description' => 'On its server too, for hosting, and the client gets the Service Suspension email. 502 when the panel call fails (nothing changes then).', 'body' => 'SuspendInput', 'response' => 'Service'],
            ['method' => 'post', 'path' => '/services/{id}/unsuspend', 'area' => 'services', 'permission' => 'services.update', 'summary' => 'Unsuspend a service', 'response' => 'Service'],
            ['method' => 'post', 'path' => '/services/{id}/terminate', 'area' => 'services', 'permission' => 'services.delete', 'summary' => 'Terminate a service', 'description' => 'Removes a hosting account from its server and takes the service off unpaid payment notices. Services are never deleted.', 'response' => 'Service'],

            ['method' => 'get', 'path' => '/orders', 'area' => 'orders', 'permission' => 'orders.read', 'summary' => 'List orders', 'query' => ['client_id', 'status', ...$list], 'response' => 'Order[]'],
            ['method' => 'post', 'path' => '/orders', 'area' => 'orders', 'permission' => 'orders.create', 'summary' => 'Place an order', 'description' => 'For a client, like the client area\'s cart: products clients can order (shown in the client area), with their options and add-ons, and domains. Prices always come from the products and Settings → Domain pricing. The order\'s payment notice is made and emailed, and items are set up as their product\'s Auto setup says; setup_errors lists those that could not be.', 'body' => 'OrderInput', 'response' => 'Order', 'status' => 201],
            ['method' => 'get', 'path' => '/orders/{id}', 'area' => 'orders', 'permission' => 'orders.read', 'summary' => 'Get an order', 'response' => 'Order'],
            ['method' => 'post', 'path' => '/orders/{id}/complete', 'area' => 'orders', 'permission' => 'orders.update', 'summary' => 'Complete an order', 'description' => 'Sets up every pending item (domains, then hosting, then the rest), as Complete order does. Safe to call again: only what is still pending is retried; setup_errors says why an item is.', 'response' => 'Order'],
            ['method' => 'post', 'path' => '/orders/{id}/cancel', 'area' => 'orders', 'permission' => 'orders.delete', 'summary' => 'Cancel an order', 'description' => 'Only while it and all its items are pending; its unpaid payment notice is cancelled too.', 'response' => 'Order'],

            ['method' => 'get', 'path' => '/invoices', 'area' => 'invoices', 'permission' => 'invoices.read', 'summary' => 'List payment notices', 'description' => 'Payment notices (proformas, PF-2026-0001), not legal invoices.', 'query' => ['client_id', 'order_id', 'status', 'number', ...$list], 'response' => 'Invoice[]'],
            ['method' => 'get', 'path' => '/invoices/{id}', 'area' => 'invoices', 'permission' => 'invoices.read', 'summary' => 'Get a payment notice', 'response' => 'Invoice'],
            ['method' => 'post', 'path' => '/invoices/{id}/pay', 'area' => 'invoices', 'permission' => 'payments.create', 'summary' => 'Record the payment of a payment notice', 'description' => 'One payment per unpaid line, each moving its service\'s due date one cycle on (unsuspending it if it was suspended for non-payment). GaiaCP charges nothing: this records money already received.', 'body' => 'InvoicePaymentInput', 'response' => 'InvoicePaid', 'status' => 201],
            ['method' => 'post', 'path' => '/invoices/{id}/send', 'area' => 'invoices', 'permission' => 'invoices.update', 'summary' => 'Email a payment notice to the client', 'body' => 'SendInput', 'response' => 'Message'],
            ['method' => 'post', 'path' => '/invoices/{id}/cancel', 'area' => 'invoices', 'permission' => 'invoices.delete', 'summary' => 'Cancel a payment notice', 'description' => 'Only an unpaid one. While a service on it stays active and due, the next daily run makes a new notice for it.', 'response' => 'Invoice'],

            ['method' => 'get', 'path' => '/payments', 'area' => 'payments', 'permission' => 'payments.read', 'summary' => 'List payments', 'query' => ['client_id', 'service_id', 'method', 'reference', 'paid_from', 'paid_to', ...$list], 'response' => 'Payment[]'],
            ['method' => 'post', 'path' => '/payments', 'area' => 'payments', 'permission' => 'payments.create', 'summary' => 'Record a payment for a service', 'description' => 'One billing cycle: the due date moves on one cycle, its payment notice line is marked paid, a service suspended for non-payment is unsuspended and a domain is renewed at its registrar (renewal_error when that failed; the payment stays). amount_cents defaults to the service\'s price.', 'body' => 'PaymentInput', 'response' => 'Payment', 'status' => 201],
            ['method' => 'get', 'path' => '/payments/{id}', 'area' => 'payments', 'permission' => 'payments.read', 'summary' => 'Get a payment', 'response' => 'Payment'],
            ['method' => 'delete', 'path' => '/payments/{id}', 'area' => 'payments', 'permission' => 'payments.delete', 'summary' => 'Reverse a payment', 'description' => 'Only the latest payment of a service, while its due date is still the one that payment set.', 'response' => null, 'status' => 204],

            ['method' => 'get', 'path' => '/products', 'area' => 'products', 'permission' => 'products.read', 'summary' => 'List products', 'query' => ['type', 'addon', 'hidden', 'retired', ...$list], 'response' => 'Product[]'],
            ['method' => 'post', 'path' => '/products', 'area' => 'products', 'permission' => 'products.create', 'summary' => 'Create a product', 'body' => 'ProductInput', 'response' => 'Product', 'status' => 201],
            ['method' => 'get', 'path' => '/products/{id}', 'area' => 'products', 'permission' => 'products.read', 'summary' => 'Get a product', 'response' => 'Product'],
            ['method' => 'patch', 'path' => '/products/{id}', 'area' => 'products', 'permission' => 'products.update', 'summary' => 'Change a product', 'description' => 'Its services keep their own price and cycle. prices, when sent, replaces every cycle\'s price; the type can\'t change.', 'body' => 'ProductInput', 'response' => 'Product'],
            ['method' => 'delete', 'path' => '/products/{id}', 'area' => 'products', 'permission' => 'products.delete', 'summary' => 'Delete a product', 'description' => 'Only one with no services: retire the others ("retired": true).', 'response' => null, 'status' => 204],

            ['method' => 'get', 'path' => '/leads', 'area' => 'leads', 'permission' => 'leads.read', 'summary' => 'List leads', 'description' => 'Prospective clients (Clients → Leads), kept apart from the clients until they buy.', 'query' => ['search', 'status', 'source', 'email', 'assigned_to', 'client_id', ...$list], 'response' => 'Lead[]'],
            ['method' => 'post', 'path' => '/leads', 'area' => 'leads', 'permission' => 'leads.create', 'summary' => 'Add a lead', 'description' => 'For example from a contact form on your website: only first_name is required. The bell tells the admins in Sales. No email is sent.', 'body' => 'LeadInput', 'response' => 'Lead', 'status' => 201],
            ['method' => 'get', 'path' => '/leads/{id}', 'area' => 'leads', 'permission' => 'leads.read', 'summary' => 'Get a lead', 'response' => 'Lead'],
            ['method' => 'patch', 'path' => '/leads/{id}', 'area' => 'leads', 'permission' => 'leads.update', 'summary' => 'Change a lead', 'description' => 'Only an open one (new, contacted, quoted); only the fields sent change. Converting it to a client and marking it lost are done in the panel.', 'body' => 'LeadInput', 'response' => 'Lead'],
            ['method' => 'delete', 'path' => '/leads/{id}', 'area' => 'leads', 'permission' => 'leads.delete', 'summary' => 'Delete a lead', 'description' => 'Not one that became a client.', 'response' => null, 'status' => 204],

            ['method' => 'get', 'path' => '/quotes', 'area' => 'quotes', 'permission' => 'quotes.read', 'summary' => 'List quotes', 'query' => ['status', 'lead_id', 'client_id', 'number', 'order_id', ...$list], 'response' => 'Quote[]'],
            ['method' => 'post', 'path' => '/quotes', 'area' => 'quotes', 'permission' => 'quotes.create', 'summary' => 'Write a quote', 'description' => 'For a lead or a client, saved as a draft (send it with /quotes/{id}/send). Items are as the panel\'s New order makes them, at the prices you set (kept on renewals once accepted); extras are one-off lines, ordered as other services billed once. valid_until defaults to Settings → Sales.', 'body' => 'QuoteInput', 'response' => 'Quote', 'status' => 201],
            ['method' => 'get', 'path' => '/quotes/{id}', 'area' => 'quotes', 'permission' => 'quotes.read', 'summary' => 'Get a quote', 'response' => 'Quote'],
            ['method' => 'post', 'path' => '/quotes/{id}/send', 'area' => 'quotes', 'permission' => 'quotes.update', 'summary' => 'Email a quote', 'description' => 'The Quote email with the link to its page (url), where it is accepted or declined; a draft is sent from then on. Needs an email on the lead or client and a valid_until not past.', 'response' => 'Quote'],
            ['method' => 'post', 'path' => '/quotes/{id}/accept', 'area' => 'quotes', 'permission' => 'quotes.update', 'summary' => 'Accept a quote for the client', 'description' => 'When they said yes another way: a lead becomes a client (with the client area invitation), then the order is placed with its payment notice (order_id). Only once.', 'response' => 'Quote'],
            ['method' => 'post', 'path' => '/quotes/{id}/decline', 'area' => 'quotes', 'permission' => 'quotes.update', 'summary' => 'Mark a quote as declined', 'description' => 'Only a sent one.', 'body' => 'DeclineInput', 'response' => 'Quote'],
            ['method' => 'delete', 'path' => '/quotes/{id}', 'area' => 'quotes', 'permission' => 'quotes.delete', 'summary' => 'Delete a quote', 'description' => 'Not an accepted one.', 'response' => null, 'status' => 204],
        ];
    }

    /** @return array<string, mixed> */
    public static function spec(): array
    {
        $paths = [];

        foreach (self::endpoints() as $endpoint) {
            $paths[$endpoint['path']][$endpoint['method']] = self::operation($endpoint);
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'GaiaCP API',
                'version' => Version::current(),
                'description' => "Clients, services, orders, payment notices, payments, products, leads and quotes of this GaiaCP.\n\n"
                    .'Authenticate with a key from Settings → API keys: `Authorization: Bearer gcp_…` (or `X-API-Key: gcp_…` where the web server drops the Authorization header). Each key has its own permissions (`clients.read`, `payments.create`…) and may be limited to some IP addresses. '
                    .'Calls go through the same actions as the admin panel: they are in the activity log with the key\'s name, send the same emails and raise the same events (webhooks). '
                    .'Money is in integer cents with its currency, dates are Y-m-d, times ISO 8601. Lists are paginated (`per_page` up to '.config('gaiacp.api.max_per_page', 100).'). '
                    .'Each key may make '.config('gaiacp.api.per_minute', 120).' calls a minute (429 after that). Errors are JSON with a `message` (and `errors` per field on 422).',
            ],
            'servers' => [['url' => url('api/v1')]],
            'security' => [['bearer' => []]],
            'tags' => collect(self::areas())->map(fn (string $name, string $area) => ['name' => $area, 'description' => $name])->values()->all(),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'An API key (gcp_…)']],
                'schemas' => self::schemas(),
            ],
        ];
    }

    /** @return array<string, string> the areas, in English, for the spec's tags */
    public static function areas(): array
    {
        return [
            'key' => 'The key',
            'clients' => 'Clients',
            'services' => 'Services',
            'orders' => 'Orders',
            'invoices' => 'Payment notices',
            'payments' => 'Payments',
            'products' => 'Products',
            'leads' => 'Leads',
            'quotes' => 'Quotes',
        ];
    }

    /** @return array<string, mixed> */
    private static function operation(array $endpoint): array
    {
        $parameters = [];

        if (str_contains($endpoint['path'], '{id}')) {
            $parameters[] = ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']];
        }

        foreach ($endpoint['query'] ?? [] as $name) {
            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => self::queryType($name)];
        }

        $status = (string) ($endpoint['status'] ?? 200);
        $response = $endpoint['response'];
        $content = match (true) {
            $response === null => null,
            str_ends_with($response, '[]') => ['type' => 'object', 'properties' => [
                'data' => ['type' => 'array', 'items' => self::ref(substr($response, 0, -2))],
                'links' => ['type' => 'object'],
                'meta' => ['type' => 'object', 'description' => 'current_page, last_page, per_page, total…'],
            ]],
            in_array($response, ['Key', 'Message', 'InvoicePaid'], true) => self::ref($response),
            default => ['type' => 'object', 'properties' => ['data' => self::ref($response)]],
        };

        $operation = array_filter([
            'tags' => [$endpoint['area']],
            'summary' => $endpoint['summary'],
            'description' => trim(($endpoint['description'] ?? '').($endpoint['permission'] ? "\n\nNeeds the `{$endpoint['permission']}` permission." : '')),
            'parameters' => $parameters ?: null,
            'requestBody' => isset($endpoint['body']) ? ['required' => true, 'content' => ['application/json' => ['schema' => self::ref($endpoint['body'])]]] : null,
        ]);

        $operation['responses'] = [
            $status => ['description' => $status === '204' ? 'Done' : 'OK'] + ($content ? ['content' => ['application/json' => ['schema' => $content]]] : []),
            '401' => ['description' => 'Missing, wrong or revoked key'],
            '403' => ['description' => 'The key lacks the permission, or is not allowed from this IP'],
        ];

        if (isset($endpoint['body'])) {
            $operation['responses']['422'] = ['description' => 'Invalid input: `errors` lists the problem of each field'];
        }

        if (in_array($endpoint['method'], ['post', 'patch', 'delete'], true) && str_contains($endpoint['path'], '{id}')) {
            $operation['responses']['409'] = ['description' => 'Not possible in the record\'s state (`message` says why)'];
        }

        return $operation;
    }

    /** @return array<string, string> */
    private static function queryType(string $name): array
    {
        return match (true) {
            in_array($name, ['per_page', 'page', 'assigned_to'], true), str_ends_with($name, '_id') => ['type' => 'integer'],
            in_array($name, ['addon', 'hidden', 'retired'], true) => ['type' => 'boolean'],
            in_array($name, ['paid_from', 'paid_to'], true) => ['type' => 'string', 'format' => 'date'],
            default => ['type' => 'string'],
        };
    }

    /** @return array<string, string> */
    private static function ref(string $schema): array
    {
        return ['$ref' => "#/components/schemas/{$schema}"];
    }

    /** @return array<string, mixed> */
    private static function schemas(): array
    {
        $string = ['type' => 'string'];
        $nullableString = ['type' => ['string', 'null']];
        $int = ['type' => 'integer'];
        $nullableInt = ['type' => ['integer', 'null']];
        $bool = ['type' => 'boolean'];
        $date = ['type' => ['string', 'null'], 'format' => 'date'];
        $time = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $cycle = self::enum(BillingCycle::class);

        $leadFields = [
            'first_name' => $string, 'last_name' => $nullableString, 'company' => $nullableString,
            'email' => ['type' => ['string', 'null'], 'format' => 'email'], 'phone' => $nullableString,
            'country' => ['type' => ['string', 'null'], 'description' => 'ISO 3166 code, e.g. GR'],
            'source' => ['type' => ['string', 'null'], 'description' => 'Where it came from, e.g. one of Settings → Sales → Lead sources'],
            'notes' => ['type' => ['string', 'null'], 'description' => 'What they are looking for'],
            'assigned_to' => ['type' => ['integer', 'null'], 'description' => 'The admin who has it'],
            'next_contact_on' => ['type' => ['string', 'null'], 'format' => 'date', 'description' => 'That morning the bell reminds the admin'],
        ];

        $clientFields = [
            'first_name' => $string, 'last_name' => $string, 'email' => ['type' => 'string', 'format' => 'email'],
            'company' => $nullableString, 'phone' => $nullableString, 'address1' => $nullableString, 'address2' => $nullableString,
            'city' => $nullableString, 'state' => $nullableString, 'postcode' => $nullableString,
            'country' => ['type' => 'string', 'description' => 'ISO 3166 code, e.g. GR'],
            'vat_number' => $nullableString, 'tax_office' => $nullableString, 'profession' => $nullableString, 'tax_exempt' => $bool,
            'locale' => ['type' => ['string', 'null'], 'description' => 'Email language, e.g. en, el'],
            'currency' => ['type' => 'string', 'description' => 'One of Settings → Currencies'],
            'status' => self::enum(ClientStatus::class),
            'default_payment_method' => $nullableString,
            'renewal_reminders' => $bool, 'overdue_notices' => $bool, 'informational_emails' => $bool,
            'custom_fields' => ['type' => 'object', 'description' => 'Settings → Client fields, by key'],
            'notes' => $nullableString,
        ];

        return [
            'Key' => ['type' => 'object', 'properties' => [
                'name' => $string,
                'permissions' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::permissions()]],
                'version' => $string,
            ]],
            'Message' => ['type' => 'object', 'properties' => ['message' => $string]],
            'Client' => ['type' => 'object', 'properties' => ['id' => $int, ...$clientFields, 'credit_cents' => $int, 'created_at' => $time, 'updated_at' => $time]],
            'ClientInput' => ['type' => 'object', 'properties' => $clientFields, 'required' => ['first_name', 'last_name', 'email']],
            'Service' => ['type' => 'object', 'properties' => [
                'id' => $int, 'client_id' => $int, 'order_id' => $nullableInt, 'parent_service_id' => ['type' => ['integer', 'null'], 'description' => 'For an add-on: the service it was added to'],
                'type' => self::enum(ServiceType::class), 'product_id' => $nullableInt, 'server_id' => $nullableInt,
                'label' => $nullableString, 'domain' => $nullableString, 'username' => $nullableString, 'package' => $nullableString,
                'options' => ['type' => 'array', 'description' => 'Product options picked, each with its price then'],
                'status' => self::enum(ServiceStatus::class), 'suspension_reason' => $nullableString,
                'price_cents' => $int, 'first_payment_cents' => $nullableInt, 'new_price_cents' => $nullableInt, 'new_price_from' => $date,
                'currency' => $string, 'billing_cycle' => $cycle, 'start_date' => $date, 'next_due_date' => $date,
                'auto_terminate' => $bool, 'override_suspend_until' => $date, 'client_visible' => $bool, 'notes' => $nullableString,
                'registration' => ['type' => ['object', 'null'], 'description' => 'For a domain: registrar_id, registration_type, nameservers, registered_at, expires_at, last_synced_at'],
                'created_at' => $time, 'updated_at' => $time,
            ]],
            'ServiceInput' => ['type' => 'object', 'properties' => [
                'label' => $nullableString, 'notes' => $nullableString, 'client_visible' => $bool,
                'override_suspend_until' => $date, 'auto_terminate' => $bool,
            ]],
            'SuspendInput' => ['type' => 'object', 'properties' => ['reason' => ['type' => ['string', 'null'], 'description' => 'Shown to the client in the suspension email']]],
            'Order' => ['type' => 'object', 'properties' => [
                'id' => $int, 'client_id' => $int, 'status' => self::enum(OrderStatus::class), 'notes' => $nullableString,
                'total_cents' => ['type' => 'integer', 'description' => 'What the first payment of every item comes to'],
                'invoice_ids' => ['type' => 'array', 'items' => $int],
                'items' => ['type' => 'array', 'items' => self::ref('Service')],
                'completed_at' => $time, 'created_at' => $time,
            ]],
            'OrderInput' => ['type' => 'object', 'required' => ['client_id', 'items'], 'properties' => [
                'client_id' => $int,
                'items' => ['type' => 'array', 'maxItems' => ClientOrder::CART_LIMIT, 'items' => ['type' => 'object', 'properties' => [
                    'product_id' => ['type' => ['integer', 'null'], 'description' => 'Left out for a domain on its own'],
                    'billing_cycle' => ['type' => ['string', 'null'], 'enum' => [...self::values(BillingCycle::class), null], 'description' => 'The product\'s default cycle when left out'],
                    'options' => ['type' => 'object', 'description' => 'Product options: {option id: choice id, yes/no or quantity}'],
                    'addons' => ['type' => 'array', 'items' => $int, 'description' => 'Add-on products offered with this product'],
                    'domain' => ['type' => ['string', 'null'], 'description' => 'Required for hosting and for a domain on its own'],
                    'domain_option' => ['type' => 'string', 'enum' => [ClientOrder::OWN, ClientOrder::REGISTER, ClientOrder::TRANSFER], 'description' => 'For a product: own (default) or also register / transfer the domain; a domain on its own: register (default) or transfer'],
                    'years' => ['type' => 'integer', 'description' => 'Domain term, 1 by default'],
                    'epp_code' => ['type' => ['string', 'null'], 'description' => 'For a transfer'],
                ]]],
                'notes' => $nullableString,
            ]],
            'Invoice' => ['type' => 'object', 'properties' => [
                'id' => $int, 'number' => $string, 'client_id' => $int, 'order_id' => $nullableInt, 'status' => self::enum(InvoiceStatus::class),
                'date' => $date, 'due_date' => $date, 'paid_on' => $date, 'currency' => $string,
                'subtotal_cents' => $int, 'tax_name' => $nullableString, 'tax_rate' => ['type' => ['number', 'null']], 'tax_inclusive' => $bool,
                'tax_cents' => $int, 'total_cents' => $int,
                'bill_to' => ['type' => 'object', 'description' => 'The client\'s details on the day it was issued'],
                'emailed_at' => $time,
                'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => [
                    'id' => $int, 'service_id' => $nullableInt, 'fee' => $nullableString, 'description' => $string,
                    'due_date' => $date, 'period_end' => $date, 'amount_cents' => $int, 'tax_cents' => $int, 'total_cents' => $int,
                    'payment_id' => ['type' => ['integer', 'null'], 'description' => 'Set once the line is paid'],
                ]]],
                'created_at' => $time,
            ]],
            'InvoicePaymentInput' => ['type' => 'object', 'properties' => [
                'paid_on' => ['type' => 'string', 'format' => 'date', 'description' => 'Today when left out'],
                'method' => ['type' => 'string', 'description' => 'A payment method key (cash, other, bank_transfer…); the client\'s default when left out'],
                'reference' => $nullableString,
            ]],
            'InvoicePaid' => ['type' => 'object', 'properties' => [
                'data' => self::ref('Invoice'),
                'payments' => ['type' => 'array', 'items' => self::ref('Payment')],
                'renewal_errors' => ['type' => 'object', 'description' => 'Domains the registrar did not renew, by line'],
            ]],
            'SendInput' => ['type' => 'object', 'properties' => [
                'template' => ['type' => ['string', 'null'], 'enum' => [...array_map(fn ($template) => $template->value, SendInvoiceRequest::TEMPLATES), null]],
            ]],
            'Payment' => ['type' => 'object', 'properties' => [
                'id' => $int, 'service_id' => $int, 'client_id' => $int, 'amount_cents' => $int, 'currency' => $string,
                'paid_on' => $date, 'method' => $string, 'reference' => $nullableString,
                'due_date_before' => $date, 'due_date_after' => $date, 'notes' => $nullableString, 'created_at' => $time,
            ]],
            'PaymentInput' => ['type' => 'object', 'required' => ['service_id'], 'properties' => [
                'service_id' => $int,
                'amount_cents' => ['type' => 'integer', 'description' => 'The service\'s price when left out'],
                'paid_on' => ['type' => 'string', 'format' => 'date', 'description' => 'Today when left out'],
                'method' => ['type' => 'string', 'description' => 'The client\'s default when left out'],
                'reference' => $nullableString, 'notes' => $nullableString,
            ]],
            'Product' => ['type' => 'object', 'properties' => [
                'id' => $int, 'name' => $string, 'type' => self::enum(ServiceType::class), 'addon' => $bool, 'description' => $nullableString,
                'billing_cycle' => $cycle,
                'prices' => ['type' => 'object', 'description' => '{cycle: {enabled, price_cents, setup_cents}} in the base currency'],
                'currency_prices' => ['type' => 'object', 'description' => '{currency: {cycle: {price_cents, setup_cents}}}'],
                'package' => $nullableString, 'server_ids' => ['type' => 'array', 'items' => $int],
                'hidden' => $bool, 'retired' => $bool, 'client_visible' => $bool, 'auto_setup' => self::enum(AutoSetup::class),
                'created_at' => $time, 'updated_at' => $time,
            ]],
            'ProductInput' => ['type' => 'object', 'required' => ['name', 'type', 'prices'], 'properties' => [
                'name' => $string,
                'type' => ['type' => 'string', 'enum' => self::values(ServiceType::class), 'description' => 'Only when creating'],
                'addon' => $bool, 'description' => $nullableString,
                'billing_cycle' => ['type' => 'string', 'enum' => self::values(BillingCycle::class), 'description' => 'The default cycle; the first priced one when left out'],
                'prices' => ['type' => 'object', 'description' => '{"monthly": {"price_cents": 500, "setup_cents": 0}}: the cycles offered, in the base currency', 'additionalProperties' => ['type' => 'object', 'properties' => ['price_cents' => $int, 'setup_cents' => $int]]],
                'package' => $nullableString, 'server_ids' => ['type' => 'array', 'items' => $int, 'description' => 'Hosting: the servers that have its package'],
                'hidden' => $bool, 'retired' => $bool, 'client_visible' => $bool, 'auto_setup' => self::enum(AutoSetup::class),
            ]],
            'Lead' => ['type' => 'object', 'properties' => [
                'id' => $int, ...$leadFields, 'status' => self::enum(LeadStatus::class), 'lost_reason' => $nullableString,
                'client_id' => ['type' => ['integer', 'null'], 'description' => 'The client it became'],
                'closed_at' => $time, 'created_at' => $time, 'updated_at' => $time,
            ]],
            'LeadInput' => ['type' => 'object', 'required' => ['first_name'], 'properties' => [
                ...$leadFields,
                'status' => ['type' => 'string', 'enum' => array_map(fn (LeadStatus $status) => $status->value, LeadStatus::open())],
            ]],
            'Quote' => ['type' => 'object', 'properties' => [
                'id' => $int, 'number' => $string, 'status' => self::enum(QuoteStatus::class), 'lead_id' => $nullableInt, 'client_id' => $nullableInt,
                'currency' => $string, 'locale' => $nullableString,
                'items' => ['type' => 'array', 'items' => self::ref('QuoteItem')],
                'extras' => ['type' => 'array', 'items' => self::ref('QuoteExtra')],
                'lines' => ['type' => 'array', 'description' => 'As its page shows them: description, cycle, first_cents, renews_cents (null for a one-off line)', 'items' => ['type' => 'object']],
                'net_cents' => $int, 'tax_cents' => $int, 'total_cents' => ['type' => 'integer', 'description' => 'The first payment of everything in it, with tax'],
                'intro' => $nullableString, 'footer' => $nullableString, 'valid_until' => $date,
                'url' => ['type' => 'string', 'description' => 'Its page, where it is accepted or declined (works once sent)'],
                'sent_at' => $time, 'accepted_at' => $time, 'declined_at' => $time, 'decline_reason' => $nullableString,
                'order_id' => ['type' => ['integer', 'null'], 'description' => 'The order placed when it was accepted'],
                'notes' => $nullableString, 'created_at' => $time,
            ]],
            'QuoteItem' => ['type' => 'object', 'required' => ['type', 'billing_cycle', 'price_cents'], 'properties' => [
                'type' => self::enum(ServiceType::class),
                'product_id' => $nullableInt,
                'label' => ['type' => ['string', 'null'], 'description' => 'Required but for a domain'],
                'domain' => ['type' => ['string', 'null'], 'description' => 'Required for a domain'],
                'registration_type' => ['type' => ['string', 'null'], 'enum' => [...self::values(DomainRegistrationType::class), null]],
                'registrar_id' => $nullableInt, 'server_id' => $nullableInt, 'package' => $nullableString,
                'billing_cycle' => ['type' => 'string', 'enum' => self::values(BillingCycle::class), 'description' => 'onetime only for type other'],
                'price_cents' => ['type' => 'integer', 'description' => 'Per cycle, kept on renewals'],
                'first_payment_cents' => ['type' => ['integer', 'null'], 'description' => 'With a setup fee, or a domain\'s registration price; the price when left out'],
                'options' => ['type' => ['object', 'null'], 'description' => 'Product options: {option id: choice}'],
                'nameservers' => ['type' => ['array', 'null'], 'items' => $string],
                'auto_renew' => $bool,
            ]],
            'QuoteExtra' => ['type' => 'object', 'required' => ['description', 'amount_cents'], 'properties' => ['description' => $string, 'amount_cents' => $int]],
            'QuoteInput' => ['type' => 'object', 'properties' => [
                'lead_id' => ['type' => ['integer', 'null'], 'description' => 'Or client_id'],
                'client_id' => ['type' => ['integer', 'null'], 'description' => 'Or lead_id; prices are in the client\'s currency'],
                'items' => ['type' => 'array', 'items' => self::ref('QuoteItem'), 'description' => 'Or only extras'],
                'extras' => ['type' => 'array', 'items' => self::ref('QuoteExtra')],
                'valid_until' => $date, 'intro' => $nullableString, 'footer' => $nullableString,
                'notes' => ['type' => ['string', 'null'], 'description' => 'For the admins only'],
            ]],
            'DeclineInput' => ['type' => 'object', 'properties' => ['reason' => $nullableString]],
        ];
    }

    /** @return list<string> every permission a key can have */
    public static function permissions(): array
    {
        return ApiKey::permissionsFrom(ApiKey::PERMISSIONS);
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, mixed>
     */
    private static function enum(string $enum): array
    {
        return ['type' => 'string', 'enum' => self::values($enum)];
    }

    /**
     * @param  class-string<BackedEnum>  $enum
     * @return list<string>
     */
    private static function values(string $enum): array
    {
        return array_map(fn (BackedEnum $case) => (string) $case->value, $enum::cases());
    }
}
