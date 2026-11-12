# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.0] - 2026-11-12

- Hosting accounts on Virtualmin as its master administrator (`root`, port 10000), through its remote API
  (`/virtual-server/remote.cgi`, JSON): create a top-level virtual server on a plan (`create-domain`), suspend and
  unsuspend (`disable-domain` / `enable-domain`), delete (`delete-domain`), change plan (`modify-domain --apply-plan`)
  and password (`modify-domain --pass`), plans (`list-plans`), account state, usage and every account on the server
  (`list-domains --multiline`)
- Client area and Servers login open the Virtualmin login page
- Calls follow Blesta's Virtualmin module (MIT); not yet tried against a real Virtualmin server
