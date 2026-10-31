# Plugins and integrations

Everything that talks to another system is a **plugin**: server panels, registrars, payment methods, captchas, sign-in
with Google, monitoring, chat, webhooks, themes, the WHMCS import. That keeps GaiaCP small and lets you add what you
need.

## The Plugins page

Settings → System → **Plugins** (super admins) lists every plugin with its version, what changed in it and when it was
installed or updated.

- **Disable** a plugin you don't use: its pages and options disappear, but its servers, accounts and settings stay, so
  enabling it again brings everything back.
- Plugins bundled with GaiaCP can't be uninstalled, only disabled. Most start enabled; easy.gr, ΑΑΔΕ, VIES VAT check
  and GoGetSSL start off.
- A plugin that needs a newer GaiaCP stays off until you update GaiaCP.

### The plugin catalogue

Settings → System → **Plugin catalogue** lists the plugins on offer at plugins.gaiacp.digital: each one is read by
the GaiaCP project before it is listed. **Official** ones are made by GaiaCP, **Community** ones by others. Search
by name, open **Details** for its changelog, author and licence, and **Install** it (your password is asked). It is
added disabled, like an uploaded zip: enable it on the Plugins page. Only versions that run on your GaiaCP are offered.

GaiaCP reads the catalogue once a day (and when you press **Refresh**). When an installed plugin has a newer version,
the bell rings once and the Plugins page shows it; **Update** on the catalogue page installs it, keeping the old
version to put back if the update fails. The catalogue is signed: GaiaCP refuses a catalogue or a zip that was changed
on the way, so even someone who broke into plugins.gaiacp.digital could not hand you a different plugin.

### Installing other plugins

**Upload plugin** takes a plugin's zip (your password is asked). It is checked before it goes live, its database tables
are made, and it starts disabled. Upload a newer zip to update it. Once it is disabled you can uninstall it, keeping its data unless
you ask otherwise. A folder copied into `plugins/` on the server is found too.

**An uploaded plugin runs as GaiaCP itself, with access to everything.** Only install plugins you trust. Setting
`GAIACP_PLUGIN_UPLOADS=false` in `.env` turns uploads off; System health shows whether they are on.

To write your own: the [plugin tutorial](../../plugin-tutorial.md) and the
[reference](../../writing-plugins.md).

## What ships with GaiaCP

| Kind | Plugins | Where you set them up |
|---|---|---|
| Server panels | cPanel / WHM, Plesk, HestiaCP, DirectAdmin; CyberPanel, Enhance, Proxmox VE (off until switched on) | Settings → Servers |
| Registrars | easy.gr, Enom, Email Notifications | Settings → Registrars |
| Payment methods | Bank transfer, Stripe, PayPal | Settings → Payment methods |
| SSL certificates | GoGetSSL | Settings → GoGetSSL |
| Monitoring | Uptime Kuma, UptimeRobot, HetrixTools, Better Stack, IP blacklist check, Site checks | Settings → Integrations |
| Client sign-in and safety | Google sign-in, Google reCAPTCHA, Cloudflare Turnstile | Settings → Client area |
| Tax details | VIES VAT check, ΑΑΔΕ registry lookup | their own settings pages |
| Notifications | Slack, Discord, Telegram, Webhooks | Settings → Email & notifications |
| Support | Email to ticket | Settings → Support |
| AI | Claude, ChatGPT, DeepSeek, Kimi, Ollama | Settings → AI assistant (its own group: the assistant's page and each service's) |
| Look | Gaia and Flat themes | Profile, Settings → General, Settings → Client area |
| Moving in | WHMCS import | Settings → System |

## Webhooks

The **Webhooks** plugin (off until switched on, super admins) sends GaiaCP's events (orders, sign ups, payment
notices, payments, invoices and credit notes, service changes, domains, tickets, servers) as a JSON POST to the addresses you add, each with the
events it wants. Use it to feed your own scripts, Zapier, Make or n8n.

Each request is signed with the endpoint's secret (shown once) in the `X-GaiaCP-Signature` header, so the receiver can
check it came from you. Deliveries go out within a minute and are retried after 1, 5 and 30 minutes, 2 and 12 hours.
**Deliveries** shows what was sent and the answer, with Resend; **Test** sends a sample. An endpoint that keeps
failing rings the bell and is switched off after 50 failures in a row. Addresses on your local network are refused
unless `GAIACP_WEBHOOKS_ALLOW_PRIVATE=true`.

## Slack, Discord and Telegram

Each posts the events you pick (by default new orders, payments, tickets, client replies, servers down and up, failed
panel calls) to a channel, one short message each with a link to its page here. Paste the channel's webhook address
(for Telegram, the bot token and chat ID; **Find chat ID** reads it for you) and press **Send a test message**. With
**Answer commands** on, the Telegram bot also answers `/owed`, `/today`, `/tickets` and `/servers` in that chat only.

## AI assistant

The AI assistant works with any of the AI service plugins: **Claude**, **ChatGPT**, **DeepSeek**, **Kimi** or
**Ollama** (all off until you switch them on, super admins; see [Choosing an AI service](#choosing-an-ai-service)).
With one on and set up under Settings → AI assistant, an **AI** menu appears after the label of the texts you write: ticket replies and new
tickets, canned replies, email templates, messages to clients, knowledge base articles, announcements, product and
product group descriptions, quote texts and sales emails. It offers:

- **Improve**: fix spelling and grammar, or make the text friendlier, clearer, shorter or more formal, in its own
  language;
- **Translate into** one of the panel's languages (a ticket reply offers the client's language first), or, on a text
  kept in several languages, **Translate from** the language you already wrote, or **Translate this text into** the field's language when you wrote it in another one.

The answer opens in a window: change it if you like, then **Use this text** puts it in the field (nothing is sent or
saved until you do). Only that field's text is sent, with fixed instructions that keep placeholders, links and
formatting; never client details or passwords. Settings → **AI assistant** (its own group, with each AI service's page) turns each feature on or
off, sets a monthly limit in tokens for all admins together and shows how many were used this month.

It also writes texts from GaiaCP's own details, each with its own switch there:

- **Write from the product's details** in a product's Description menu: two or three sentences from its name, type,
  package, group and compare table values (never its prices), in the panel's default language;
- **Write from the servers' status** in Settings → Client area → Server problems → Message: the notice from what the
  monitors say now (each server by its public name only, its status, since when and how many accounts are on it; never
  hostnames, addresses or clients). Empty the message again once the problem is over;
- **Suggest domain names** (off until you switch it on): in the client area's domain search, **Suggest other names**
  lists free names close to the one searched, with the same ending, ready to add to the cart. Clients and visitors
  press it, so each client or address may press it 5 times an hour; only the name searched is sent.

Two more switches answer questions:

- **Questions about your data**: Reports → **Ask in plain words** takes a question such as "who owes more than €100?",
  "how much did we receive last month?" or "which domains expire in the next 30 days?". The AI service only reads which
  of GaiaCP's ready-made questions it is (unpaid amounts, overdue notices, payments, best-paying clients, services due,
  service counts, expiring domains, new clients, open tickets) and with what dates or amounts; GaiaCP then answers it
  from your data, read-only, and the answer never goes to the AI service. **Read as** shows how it was understood, so
  check it. An admin is offered only the questions of the areas they can open;
- **Panel help**: in System → **Help**, type a question in the guide's search and press **Ask the AI assistant**: it
  answers from the closest sections of this guide (only the question and those sections are sent), with links to
  them.

Three more help with tickets (Support → Tickets, for admins with the Support area):

- **Draft ticket replies**: in a ticket's **Reply**, the AI menu's **Draft a reply** writes an answer to the client's
  last message from the conversation, the client's services (type, name, status, paid until) and the three published
  knowledge base articles closest to it, in the client's language. Where it would need to look something up it writes
  `[check: …]` for you to fill in; read it, change it and send it yourself;
- **Summarise tickets**: **Summarise** on a ticket reads in one line what the client needs, and suggests a priority, a
  department and the client's mood. **Keep** saves the line (shown under the subject in the list until the client
  writes again) with the priority and department you left chosen;
- **Knowledge base articles from tickets**: on a ticket you answered, More → **Write a knowledge base article** turns
  the answer into an article for anyone with the same question, in the default language. **Save as draft** keeps it
  unpublished under Support → Knowledge base, for you to check, finish and publish.

What they send is the ticket's conversation, never its notes or files, with the client's name, company, email
addresses and phone numbers replaced by placeholders (the reply draft gets the first name back); the article also
hides the client's domain names.

Two more look at one client, without sending who they are:

- **Personal payment reminders**: on an unpaid payment notice, **Personal reminder** rewrites the reminder email (its
  Email templates text, placeholders kept) for how the client pays. It sends that text, the amount and days, and the
  client's record of the last 24 months (how many notices were paid late and by how much, what else is overdue);
- **Suggestions about clients**: on a client's page, **Suggestions** lists what they might buy, signs they may leave
  and what to look after. It sends their services (product, cycle, price, since when, each domain only as "site 1",
  "site 2"…), warnings that their hosting was nearly full, open cancellation requests, the last year's ticket subjects
  and your products on offer.

**Translate the panel** adds **Translate with AI** to Settings → Languages (super admins): the panel's texts that a
language misses go to the AI service in batches of 40 and come back as uploaded translations. Only those texts are
sent; a translation that loses a placeholder or an HTML tag is left out and stays in English. A whole new language is
up to about 300,000 tokens.

### Choosing an AI service

Every feature above works the same with each service; each has its own plugin and page under Settings → AI assistant,
where **Test connection** checks the key and the model. With more than one on, Settings → AI assistant picks which one
answers. The service bills your account directly.

**Claude** (Anthropic) needs an API key from console.anthropic.com. Opus writes best, Sonnet costs half, Haiku is the
cheapest and good enough for spelling and translations.

**ChatGPT** (OpenAI), **DeepSeek** and **Kimi** (Moonshot AI) each need their own API key; DeepSeek and Kimi are often
cheaper. ChatGPT needs an API key with credit from platform.openai.com: a ChatGPT Plus subscription does not include
the API. Their models change often, so the model is typed in: **Test connection** lists the models your key can use and
offers them in the field.

**Ollama** runs an open model (Llama, Qwen, Gemma, Mistral…) on your own server or computer, so texts never leave your
machines and nothing is billed. Install Ollama (ollama.com), download a model (`ollama pull llama3.3`) and fill in its
**Address** under Settings → Ollama (`http://localhost:11434/v1` when it runs on the same server). On another machine,
start Ollama with `OLLAMA_HOST=0.0.0.0` and let only the GaiaCP server reach port 11434; the API key is only for a
proxy in front of it that asks for one. Small models need about 8 GB of memory and write less well than the paid
services; a slow machine may take a minute or more per answer.

## The REST API

Settings → Integrations → **API keys** (super admins) makes keys for your own scripts and website: one per use, each
with its permissions per area (read, create, update, delete) and the IP addresses it may call from. A key is shown once;
revoke it at once if it leaks. Calls go through the same actions as the panel, so they send the same emails and are in
the activity log under the key's name. **Documentation** on that page lists every call, with `openapi.json` to
download. Examples: [using the API](../../using-the-api.md).

Next: [Keeping GaiaCP healthy](14-maintenance.md).
