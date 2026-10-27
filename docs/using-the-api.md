# Using the REST API

GaiaCP's REST API lets your own scripts and sites read and change clients, services, orders, payment notices,
payments and products: a website order form, a sync with your accounting tool, a script that records bank payments.
Every call goes through the same code as the admin panel, so it sends the same emails, is written to the activity log
(under the key's name) and raises the same webhooks.

The full list of calls, with every field, is in the panel under **Settings → API keys → Documentation**, and as an
OpenAPI file there (Download openapi.json) or at `/api/v1/openapi.json`. Import that file into Postman, Insomnia or
a code generator. This guide walks through getting started and the common tasks.

## 1. Make a key

1. Sign in to `/admin` as a super admin and open **Settings → API keys** (Integrations group).
2. **New API key**: a name that says what uses it ("Website order form"), the permissions it needs and, if it always
   calls from the same server, that server's IP address under Allowed IPs (one per line, ranges like
   `203.0.113.0/24` work too).
3. Copy the key (`gcp_…`) from the notice. It is shown only this once; GaiaCP keeps only its hash. Lost it? Revoke
   the key and make a new one.

Give each script its own key with only what it needs: a key that reads clients can't record payments. The list shows
when and from which IP each key was last used, and **Revoke** stops a key at once.

| Area | read | create | update | delete |
|---|---|---|---|---|
| Clients | list, get | create | change | delete (only without services, payments or credit) |
| Services | list, get | (made by orders) | change, suspend, unsuspend | terminate |
| Orders | list, get | place an order | complete | cancel |
| Payment notices | list, get | (made by orders and the daily run) | email to the client | cancel |
| Payments | list, get | record (also paying a whole notice) | | reverse |
| Products | list, get | create | change | delete (only without services) |

## 2. The first call

Every call is under `https://your-gaiacp/api/v1` (with the subfolder, if GaiaCP is installed in one) and sends the
key as a bearer token:

```bash
curl https://billing.example.com/api/v1 \
  -H "Authorization: Bearer gcp_your_key" \
  -H "Accept: application/json"
```

```json
{"name": "Website order form", "permissions": ["clients.read", "orders.create"], "version": "0.1.0"}
```

Some Apache and FastCGI setups drop the `Authorization` header: if a correct key gets 401, send it as
`X-API-Key: gcp_your_key` instead.

Things that hold for every call:

- Send and receive JSON (`Content-Type: application/json`, `Accept: application/json`).
- Money is in **integer cents** with its currency (`"price_cents": 9000, "currency": "EUR"` is €90.00). Dates are
  `Y-m-d`, times ISO 8601.
- Lists are paginated: `?per_page=` (25 by default, at most 100) and `?page=`; the answer has `data`, `links` and
  `meta` (`total`, `last_page`, …). Lists take filters, e.g. `/services?client_id=12&status=active`.
- PATCH changes only the fields you send.
- Each key may make 120 calls a minute (the host can change it with `GAIACP_API_PER_MINUTE`); more get 429.

| Status | Meaning |
|---|---|
| 200, 201, 204 | Done (201 = created, 204 = nothing to return) |
| 401 | Missing, wrong or revoked key |
| 403 | The key lacks the permission (the message names it), or is not allowed from this IP |
| 404 | No such record |
| 409 | Not possible in the record's state, e.g. cancelling an order that is already set up (`message` says why) |
| 422 | Invalid input: `errors` lists the problem of each field |
| 429 | Too many calls this minute |
| 502 | The server panel or registrar failed; nothing changed in GaiaCP |

## 3. Common tasks

### A client orders on your website

Find the client by email, or create them:

```bash
curl "https://billing.example.com/api/v1/clients?email=maria@example.com" -H "Authorization: Bearer $KEY" -H "Accept: application/json"

curl -X POST https://billing.example.com/api/v1/clients \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"first_name": "Maria", "last_name": "Papadopoulou", "email": "maria@example.com", "country": "GR"}'
```

Then place the order. Each item is a product (with its billing cycle, options and add-ons, and for hosting its
domain), or a domain on its own. Products are those clients can order in the client area, and **prices always come
from the catalogue** (the product and Settings → Domain pricing), never from the call:

```bash
curl -X POST https://billing.example.com/api/v1/orders \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{
    "client_id": 42,
    "items": [
      {"product_id": 3, "billing_cycle": "annually", "domain": "maria-shop.gr", "domain_option": "register"},
      {"domain": "maria-shop.com", "domain_option": "register", "years": 1}
    ],
    "notes": "From the website"
  }'
```

The answer is the order with its items (pending services) and `invoice_ids`: the payment notice was made and emailed
to the client, as for an order in the panel. Items whose product says "set up on order" are set up at once;
`setup_errors` lists any that could not be. Products list their ids and prices at `/products`.

### Record a payment

When the money arrives (a bank transfer, cash), record it for the whole payment notice:

```bash
curl -X POST https://billing.example.com/api/v1/invoices/117/pay \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"method": "bank_transfer", "reference": "TX-88231", "paid_on": "2026-10-25"}'
```

Each unpaid line gets a payment and its service's next due date moves on one billing cycle; a service suspended for
non-payment is unsuspended, and items set up "on payment" are set up. GaiaCP charges nothing: this only records money
you already received. `POST /payments` with a `service_id` records one cycle of a single service instead, and
`DELETE /payments/{id}` reverses a payment recorded by mistake (only a service's latest one).

### Who owes what

```bash
curl "https://billing.example.com/api/v1/invoices?status=unpaid&per_page=100" -H "Authorization: Bearer $KEY" -H "Accept: application/json"
```

Each notice has its number (`PF-2026-0001`), `due_date`, `total_cents`, `currency` and lines. Add `client_id=` for one
client.

### A contact form adds a lead

Give the website's key only `leads.create`, then send what the visitor typed:

```bash
curl -X POST https://billing.example.com/api/v1/leads \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"first_name": "Maria", "email": "maria@example.com", "phone": "+30 690 000 0000", "source": "Website", "notes": "Wants an online shop"}'
```

Only `first_name` is required. The lead shows under Clients → Leads and the bell tells the admins in Sales; nothing
is emailed to the visitor.

### Send a quote

```bash
curl -X POST https://billing.example.com/api/v1/quotes \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"lead_id": 12, "items": [{"type": "other", "label": "Website care", "billing_cycle": "annually", "price_cents": 12000}],
       "extras": [{"description": "Moving the site", "amount_cents": 5000}]}'
```

It is saved as a draft (`QT-2026-0001`). `POST /quotes/{id}/send` emails it with the link to its page (`url`), where
the client accepts or declines it; `accept` does it for them when they said yes on the phone, and the order is placed
(`order_id`).

### Suspend a service

```bash
curl -X POST https://billing.example.com/api/v1/services/88/suspend \
  -H "Authorization: Bearer $KEY" -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"reason": "Abuse report"}'
```

For hosting this suspends the account on its server too, and the client gets the suspension email. `unsuspend` and
`terminate` work the same way.

## 4. The same in PHP

With Laravel's HTTP client (Guzzle, or any other client, works the same way):

```php
use Illuminate\Support\Facades\Http;

$gaiacp = Http::baseUrl('https://billing.example.com/api/v1')
    ->withToken(env('GAIACP_API_KEY'))
    ->acceptJson()
    ->timeout(20);

$client = $gaiacp->get('clients', ['email' => 'maria@example.com'])->json('data.0')
    ?? $gaiacp->post('clients', [
        'first_name' => 'Maria',
        'last_name' => 'Papadopoulou',
        'email' => 'maria@example.com',
        'country' => 'GR',
    ])->throw()->json('data');

$response = $gaiacp->post('orders', [
    'client_id' => $client['id'],
    'items' => [['product_id' => 3, 'billing_cycle' => 'annually', 'domain' => 'maria-shop.gr', 'domain_option' => 'register']],
]);

if ($response->status() === 422) {
    // e.g. the domain is already with you, or the product has no price for that cycle
    $problems = $response->json('errors');
}

$order = $response->throw()->json('data');
```

## 5. Following what happens

The API answers questions; to hear about things as they happen (a new order from the client area, a payment through
Stripe, a server down), use the Webhooks plugin (Settings → Webhooks): GaiaCP POSTs each event as signed JSON to your
URL. Together they cover most integrations: webhooks tell your script something happened, the API lets it look
closer or act.

Everything a key does is in **Activity log**, with "API: key name" under By.
