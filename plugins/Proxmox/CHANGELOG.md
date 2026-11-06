# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.1.2] - 2026-11-07

- An error shows Proxmox's own reason ("no such user") also behind a proxy or HTTP/2, which leave only "Internal Server Error" in the status line; checked against a real Proxmox VE 9.2

## [1.1.1] - 2026-11-06

- Its times are stored in UTC like GaiaCP's (StoresTimesInUtc); the GaiaCP update converts the ones already kept

## [1.1.0] - 2026-11-06

- The VPS's page (admin and client area) shows its state, processors, memory, disk size and IP addresses (from the pool,
  or from the QEMU guest agent), and the user to log in with inside it; no Open website button or bandwidth for a VPS
- VPS Welcome Email (off until switched on under Settings → Email templates): its address, user and password, and the
  Proxmox login with the server's port (the welcome email used port 2083)
- After a password change on a VPS without the QEMU guest agent, a note says it works inside the VPS after a restart
- The New VPS settings link to the IP addresses page and recommend qemu-guest-agent in the template

## [1.0.2] - 2026-11-06

- Greek translation: the texts were still in English

## [1.0.1] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.0] - 2026-10-26

- VPS on Proxmox VE over its API (`/api2/json`, API token): each hosting service is a full clone of the KVM template named by its package, on the template's node, with cloud-init setting the user, password and address; suspend, unsuspend, terminate, change package (the new template's CPU and memory, disk grown) and password, account state, packages (the templates)
- IP addresses per server under Settings → Proxmox → IP addresses: a new VPS takes the first free one, a removed one gives it back; DHCP when the server has none
- The client gets a Proxmox user for their VPS only (PVEVMUser by default), for its console in the Proxmox web interface
- Start, Shut down, Restart and Force stop in the Account menu, and for clients on their service page (Settings → Proxmox switch)
- Lists the VPS made by GaiaCP (`ListsAccounts`), for Servers → Compare with the server
