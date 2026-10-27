# Admin guide

How to run a hosting business with GaiaCP: setting it up, selling, billing, provisioning and support. The same
chapters are in the admin panel under System → Help, in the admin's language where a translation exists
([Ελληνικά](el/01-getting-started.md)).

1. [Getting started](en/01-getting-started.md)
2. [Your business and email](en/02-business-and-email.md)
3. [Products](en/03-products.md)
4. [Servers](en/04-servers.md)
5. [Domains and SSL](en/05-domains-and-ssl.md)
6. [Billing](en/06-billing.md)
7. [Orders and quotes](en/07-orders-and-quotes.md)
8. [Clients and leads](en/08-clients-and-leads.md)
9. [Automation](en/09-automation.md)
10. [The client area](en/10-client-area.md)
11. [Support](en/11-support.md)
12. [Admins and security](en/12-admins-and-security.md)
13. [Plugins and integrations](en/13-plugins-and-integrations.md)
14. [Keeping GaiaCP healthy](en/14-maintenance.md)
15. [Moving from WHMCS](en/15-whmcs-import.md)

## Writing and translating

- Each chapter is `en/NN-name.md`; its `# ` line is the title the panel lists, and `NN` its place. Link another chapter
  as `NN-name.md` (or `NN-name.md#a-heading`) and the other docs as `../../name.md`: both work on GitHub and in the
  panel.
- A translation is the same file name under the language's folder, e.g. `el/03-products.md` (Greek is complete).
  Chapters a language doesn't have yet show in English. Use the panel's own labels in that language, as
  `lang/<locale>.json` has them.
- A change to a chapter updates its translations too.
- When a change adds or changes something an admin does, update the chapter that describes it.
