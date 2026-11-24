# Plugins and integrations

Everything that talks to another system is a **plugin**: server panels, registrars, payment methods, monitoring,
captchas, AI services, themes, the WHMCS import. Some ship with GaiaCP, more are in the plugin catalogue, and you can
upload your own.

## The plugins page

Settings → System → **Plugins** (super admins) lists every installed plugin by type, with its version and status.
Search by name, or filter by type, status (enabled or disabled) and, once you have uploaded one, source.

- **Enable** / **Disable**: a disabled plugin's menu items and settings pages disappear, but its servers, accounts and
  settings are kept, so enabling it again brings everything back. Tick several to switch them together.
- The **gear** on an enabled plugin opens its settings page.
- **Changelog** shows what changed in each version.
- **Roll back** (after an update, your password is asked) puts back the version it replaced.
- Plugins that ship with GaiaCP can't be uninstalled, only disabled.
- A plugin that needs a newer GaiaCP shows **Needs a newer GaiaCP** and stays off until you update.

An enabled plugin does nothing until you fill in its settings (keys, logins), so leaving one on costs nothing.

## The plugin catalogue

Settings → System → **Plugin catalogue** lists the plugins on plugins.gaiacp.digital. **Official** ones are made by
GaiaCP, **Community** ones by others, and each is checked before it is listed. Search, or filter by tag, type or
status; a plugin's own page shows its changelog, what it needs, its author and licence.

**Install** (your password is asked) adds a plugin disabled: enable it on the Plugins page. Only versions that run on
your GaiaCP are offered.

Nothing updates by itself. GaiaCP reads the catalogue once a day (or when you press **Refresh**), and when an
installed plugin has a newer version the bell rings once, the daily summary lists it and the Plugins page shows it.
Press **Update to …** on the catalogue to install it; if it fails, the old version stays. The catalogue and its zips are
signed, so a plugin changed on the way is refused.

## Uploading a plugin

**Upload plugin** on the Plugins page takes a plugin's zip (your password is asked, and you tick that you trust it).
It is checked, its tables are made, and it starts disabled. Upload a newer zip to update it. A disabled uploaded
plugin can be uninstalled, keeping its data unless you tick **Also delete its tables and settings**.

**An uploaded plugin runs with GaiaCP's full access.** Install only plugins you trust. `GAIACP_PLUGIN_UPLOADS=false`
in `.env` turns uploads (and catalogue installs) off; System health shows whether they are on.

To write your own: the [plugin tutorial](../../plugin-tutorial.md) and the [reference](../../writing-plugins.md).

## What ships with GaiaCP

| Kind | Plugins | Starts | Where you set them up |
|---|---|---|---|
| Server panels | cPanel / WHM, Plesk, HestiaCP, DirectAdmin | on | on each server: Settings → Products & domains → Servers |
| | CyberPanel, Virtualmin, Proxmox VE | off | the same; CyberPanel and Proxmox also under Settings → Integrations |
| Registrars | Enom, Namecheap, NameSilo, Openprovider, ResellerClub, Email Notifications | on | Settings → Products & domains → Registrars |
| Payment methods | Bank transfer, Stripe | on | Settings → Billing → Payment methods |
| | PayPal, Nexi XPay, Mastercard Gateway, Other ways to pay | off | the same |
| Tax | VIES VAT check | off | Settings → Billing → VIES VAT check |
| SSL certificates | GoGetSSL | off | Settings → Integrations → GoGetSSL |
| Monitoring | Uptime Kuma, UptimeRobot, Better Stack, IP blacklist check, Site checks | on | Settings → Integrations |
| Sign-in and captcha | Google sign-in, Google reCAPTCHA, Cloudflare Turnstile | on | Settings → Client area |
| Support | Email to ticket | off | Settings → Support → Email to ticket |
| Notifications | Webhooks | off | Settings → Email & notifications → Webhooks |
| AI | Claude, ChatGPT, DeepSeek | off | Settings → AI assistant |
| Look | Gaia, Flat and Glass themes | on | Profile; Settings → General (admin default); Settings → Client area |
| Moving in | WHMCS import | on | Settings → System → Import from WHMCS |

## From the plugin catalogue

Installed only if you want them:

- **Payments**: Alpha Bank, Eurobank, Piraeus Bank, IRIS (see [Payment methods](02-business-and-email.md#payment-methods))
- **Server panel**: Enhance
- **Registrars**: easy.gr, GoDaddy
- **Monitoring**: HetrixTools
- **Tax**: ΑΑΔΕ registry lookup for Greek VAT numbers
- **AI**: Kimi, Ollama
- **Chat**: Slack, Discord, Telegram

An install that had one of these before it moved to the catalogue keeps it, with its settings, and updates it from
the catalogue.

**Slack**, **Discord** and **Telegram** post the events you pick (new orders, payments, tickets, servers down and
up…) to a channel, one short message each with a link back here. Their pages are under Settings → Email &
notifications: paste the channel's webhook address (for Telegram, the bot token and chat ID) and press **Send a test
message**. The Telegram bot can also answer `/owed`, `/today`, `/tickets` and `/servers` in that chat.

## Webhooks

The **Webhooks** plugin (off until you switch it on) sends GaiaCP's events (orders, sign ups, payment notices,
payments, invoices, service changes, domains, tickets, servers) as a JSON POST to your own scripts, Zapier, Make or
n8n. Under Settings → Email & notifications → **Webhooks**, **New webhook** takes the address and the events it wants.

Each request is signed with the webhook's secret (shown once) in the `X-GaiaCP-Signature` header, so the receiver can
check it came from you. Deliveries go out within a minute and are retried after 1, 5 and 30 minutes, 2 and 12 hours.
**Deliveries** shows what was sent and the answer, with **Resend**; **Test** sends a sample. A webhook that keeps
failing rings the bell and is switched off after 50 failures in a row. Addresses on your local network are refused
unless `GAIACP_WEBHOOKS_ALLOW_PRIVATE=true`.

## AI assistant

Switch on one AI service plugin (**Claude**, **ChatGPT** or **DeepSeek**; **Kimi** and **Ollama** from the catalogue),
enter its API key on its page under Settings → AI assistant and press **Test connection**. Then Settings → **AI
assistant** picks the service (when more than one is on), switches each feature on or off under **What it may do**
and sets a **Monthly limit** in tokens for all admins together. The service bills your account directly.

The features, each with its own switch:

- **Improve texts** and **Translate texts**: an **AI** menu next to the texts you write (ticket replies, canned
  replies, email templates, messages to clients, knowledge base articles, announcements, product descriptions, quotes)
  fixes spelling, changes the tone or translates. The answer opens in a window; **Use this text** puts it in the field.
- **Write product descriptions**: in a product's Description menu, from its name, type, package and compare table.
- **Write the server problem notice**: under Settings → Client area → Server problems, from what the monitors say now.
- **Questions about your data**: Reports → **Ask in plain words** ("who owes more than €100?"). The AI only picks one
  of GaiaCP's ready-made questions; GaiaCP answers it from your data, which never goes to the AI service.
- **Panel help**: **Ask the AI assistant** in this guide's search answers from the closest sections of the guide.
- **Draft ticket replies**, **Summarise tickets** and **Knowledge base articles from tickets**: on a ticket.
- **Personal payment reminders**: **Personal reminder** on an unpaid payment notice rewrites the reminder for how this
  client usually pays.
- **Suggestions about clients**: **Suggestions** on a client's page: what they might buy, signs they may leave.
- **Translate the panel**: **Translate with AI** under Settings → Languages fills a language's missing texts.
- **Suggest domain names** and **Domain names from a description** (off until you switch them on): in the domain
  search, **Suggest other names** offers free names close to the one searched, and a client with no name yet can
  describe their business and get names for it (visitors too, if you tick **Also for visitors without an account**).
  Each client or address may use them 5 times an hour, and **Domain suggestions a day** caps them for everyone together.

**What leaves GaiaCP.** The writing tools send only the field's text. The ticket, reminder and client features send
some of your clients' data (ticket conversations, services, payment history), with names, email addresses and phone
numbers replaced where they can be, and never passwords, ticket notes or files. Check the AI service's privacy terms,
mention it in your own privacy policy if needed, and switch on only what you use. Ollama on your own server keeps
everything with you.

### Choosing an AI service

- **Claude** (Anthropic): an API key from console.anthropic.com. Opus writes best, Sonnet costs less, Haiku is the
  cheapest and enough for spelling and translations.
- **ChatGPT** (OpenAI): an API key with credit from platform.openai.com (a ChatGPT Plus subscription does not include
  the API).
- **DeepSeek** and **Kimi**: their own API keys; often cheaper.
- **Ollama**: an open model on your own server or computer, free and private, but slower and less polished. Fill in
  its **Address** (`http://localhost:11434/v1` on the same server).

For ChatGPT, DeepSeek and Kimi, **Test connection** lists the models your key can use.

## The REST API

Settings → Integrations → **API keys** (super admins) makes keys for your own scripts and website: one per use, each
with its permissions per area (read, create, update, delete) and the IP addresses it may call from. A key is shown
once; revoke it at once if it leaks. It works only while the admin who made it is an active super admin.

Calls go through the same actions as the panel, so they send the same emails and are in the activity log under the
key's name. **Documentation** on that page lists every call, with `openapi.json` to download. A script that may send
a call twice (after a timeout) adds an `Idempotency-Key` header, so a payment is never recorded twice. Examples:
[using the API](../../using-the-api.md).

Next: [Keeping GaiaCP healthy](14-maintenance.md).
