# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.1] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.0] - 2026-10-26

- VPS on Proxmox VE over its API (`/api2/json`, API token): each hosting service is a full clone of the KVM template named by its package, on the template's node, with cloud-init setting the user, password and address; suspend, unsuspend, terminate, change package (the new template's CPU and memory, disk grown) and password, account state, packages (the templates)
- IP addresses per server under Settings → Proxmox → IP addresses: a new VPS takes the first free one, a removed one gives it back; DHCP when the server has none
- The client gets a Proxmox user for their VPS only (PVEVMUser by default), for its console in the Proxmox web interface
- Start, Shut down, Restart and Force stop in the Account menu, and for clients on their service page (Settings → Proxmox switch)
- Lists the VPS made by GaiaCP (`ListsAccounts`), for Servers → Compare with the server
