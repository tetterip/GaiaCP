# Security policy

GaiaCP stores client details, payment records and the passwords and API tokens of hosting servers and registrars, so
security reports are taken seriously and answered first.

## Reporting a vulnerability

Please **don't open a public issue**. Report it privately on GitHub: the repository's **Security** tab →
**Report a vulnerability**. Include:

- what an attacker can do, and who they need to be (a visitor, a client, an admin with some areas, a super admin);
- the steps or a request that shows it, and the GaiaCP version (shown in the admin footer);
- anything you know about a fix.

You'll get an answer within a week. Once a fix is released, the advisory is published and you're credited, unless
you'd rather not be.

## Supported versions

GaiaCP is in **alpha**: only the latest release gets security fixes. Update from Settings → System → Updates (or
`php artisan gaiacp:update`), which backs up first.

## Already by design

These are documented choices, not vulnerabilities, though reports that they can be abused beyond what is described
are welcome:

- Super admins can upload plugin zips, which run PHP with GaiaCP's full access. Hosts can turn it off with
  `GAIACP_PLUGIN_UPLOADS=false`; System health warns while it is on.
- Super admins can send webhooks to any public address; private addresses are refused unless
  `GAIACP_WEBHOOKS_ALLOW_PRIVATE=true`.
- Backups are only encrypted when Settings → Backups has a zip password; System health warns without one.
