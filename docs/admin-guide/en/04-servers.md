# Servers

Hosting accounts are created on your servers through their panel's API. GaiaCP works with **cPanel / WHM**, **Plesk**,
**HestiaCP**, **DirectAdmin**, **CyberPanel** and **Enhance**, and with **Proxmox VE** for VPS. Each panel is a plugin
under Settings → Plugins: the first four are on from the start, the others are off until you switch them on, and you
can switch off the ones you don't use. Servers are under Settings → Products & domains → **Servers** (super admins only).

## Adding a server

Fill in the name, type, hostname and port, then the API login. Credentials are stored encrypted and never shown again:
leave the field blank to keep the current one. Press **Test connection**: it shows the panel's version and the packages
it found.

| Panel | Port | Login |
|---|---|---|
| cPanel / WHM | 2087 | `root` or a reseller, with an API token (WHM → Development → Manage API Tokens) |
| Plesk | 8443 | the admin login and password, or an empty login and a secret key (`plesk bin secret_key -c -ip-address <GaiaCP's IP>`) |
| HestiaCP | 8083 | an empty login and an access key as `ACCESS_KEY:SECRET_KEY` (allow the API and GaiaCP's IP first) |
| DirectAdmin | 2222 | the admin or a reseller, with a login key that may use the API |
| CyberPanel | 8090 | `admin` and its password, with API access on (Users → API Access) and two-factor authentication off |
| Enhance | 443 | your organisation's ID as the username, with an access token (Settings → Access tokens) |
| Proxmox VE | 8006 | the API token ID (`root@pam!gaiacp`) and its secret (Datacenter → Permissions → API Tokens, Privilege Separation unticked) |

Most panels only answer the IP addresses you allow: allow your GaiaCP server's address. **Verify TLS certificate** is
for a server still on a self-signed certificate; turn it back on once it has a real one.

The optional **Capacity and costs** fields help you run the servers:

- **Maximum accounts**: the Servers list shows accounts against it ("33/120") and new orders skip a full server.
- **Monthly cost**: Reports → Income forecast and Server revenue show the profit after it.
- **IP address**: used for new accounts where the panel needs one, by Site checks and by the IP blacklist check.
- **Status page URL**: opened from the Servers list.

**Log in** on the Servers list opens the panel already signed in (on HestiaCP, DirectAdmin, CyberPanel and Enhance, its login
page).

On CyberPanel a hosting account is a **website** (the service's domain) owned by a CyberPanel user with the service's
username; the password is that user's CyberPanel login. Packages read like `admin_Default`. New websites start on the
PHP version picked under Settings → Integrations → **CyberPanel** (PHP 8.2 unless you change it).

### Enhance

The **Enhance** plugin is off until you switch it on under Settings → Plugins. The hostname is the control panel's
address (e.g. `cp.example.com`). A hosting product's package is the name of an Enhance plan. Each client becomes an
Enhance customer with a login under their email, and each hosting service a subscription to the plan with a website for
its domain. The client has one Enhance login for all their services on that server: its password is the first
service's, and **Change password** changes it for all of them. The client area's **Log in** opens Enhance already
signed in. Customers and websites you made in Enhance yourself are found by the client's email and the domain.
It was built without a real Enhance server: try Test connection and one order first, and tell us what it says.

## Which server a new account goes to

A hosting product lists the **servers** that have its package. A new order, a new service and a client's order go to
the least full active one; full servers are skipped, and inactive ones are never offered. New order and Complete order
still let you pick another. When every server is full, setup stops and asks you for one.

## The Account menu

A hosting service's page has an **Account** menu:

- **Create on server**: creates the account with the service's domain and package. The username (from the domain) and
  password are generated when left empty and kept encrypted on the service. It also works on an active account, to
  recreate it on another server after a move (the old server is left alone).
- **Change package**, **Change password**.
- **Suspend** and **Unsuspend**: these check the panel first, so they work even if the status was edited by hand.
- **Terminate**: deletes the account and its files on the server. You type the username to confirm.
- On a Proxmox VPS: **Start**, **Shut down**, **Restart** and **Force stop** (cuts the power, for a VPS that no longer
  answers).

Every action first asks the panel for the account's current state, so repeating one or retrying after a timeout does
no harm. When a call fails, the service is left as it was, the error shows on screen, and it is in the Activity log
and the dashboard's Needs attention. Turn on Settings → Logs → Logging → **Module log** to see each request and answer
(passwords masked) when you need to find out why.

The **Usage** section on the service shows disk space and this month's bandwidth against the package's limits, read
from the panel.

## VPS on Proxmox VE

The **Proxmox VE** plugin is off until you switch it on under Settings → Plugins. A VPS is a hosting service on a
Proxmox server:

- **Package = a template.** In Proxmox, make a KVM template with cloud-init for each plan (for example
  `debian12-2cpu-4gb`) and use its name as the product's package; Test connection lists the templates it found. A new
  VPS is a full clone of it, with its CPU, memory and disk, on the template's node. Changing the package takes the new
  template's CPU and memory (applied at the next shutdown and start) and grows the disk to its size; a disk never
  shrinks.
- **Address.** Settings → Proxmox → **IP addresses** keeps each server's addresses: a new VPS takes the first free one
  and gives it back when it is terminated. A server with none there gets its address by DHCP.
- **Password.** cloud-init gives the service's password to the login user (root, or the one under Settings → Proxmox).
  Change password sets it for the next start, and at once when the VPS runs the QEMU guest agent.
- **The client** gets a Proxmox user named after the service's username, with its password, which sees only their VPS
  (role PVEVMUser by default). Log in on their service page opens the Proxmox web interface, where they find its
  console. Start, Shut down and Restart are on their service page too, unless you switch them off under Settings →
  Proxmox.
- **Suspend** shuts the VPS down and locks the client's Proxmox user; **Terminate** deletes the VPS with its disks, the
  user, and frees its address.

GaiaCP finds each VPS again by its tags in Proxmox (`gaiacp`, `gaiacp-user-<username>`): don't remove them.

## Compare with the server

**Compare with the server** (on the server's page) reads every account on the panel and lists what doesn't match
GaiaCP: accounts only on the server (left behind, or never billed), services the server doesn't have, and differences
in suspension, package or domain. Each row has a one-click fix, on the server or in GaiaCP only; deleting an account is
never offered. Mark accounts you host for free as **Not billed on purpose** with a note, and **Add to GaiaCP** turns an
unbilled account into a service. With Settings → Automation → Compare servers nightly on, the bell tells you about new
differences each morning.

## Monitoring

Link each server to its monitor in an uptime service to see whether it is up: **Uptime Kuma**, **UptimeRobot**,
**HetrixTools** or **Better Stack** (each a plugin with its own settings page under Integrations, where you enter the
API key). On the server, pick the monitoring service and the monitor. Every 5 minutes GaiaCP reads the status:

- the Servers list shows Up / Down / Pending / Maintenance and an uptime bar for 24 hours or 30 days;
- the dashboard's Servers card shows the same;
- super admins get the bell when a server goes down and when it is back;
- clients with services on it see a notice at the top of the client area while it is down or in maintenance (Settings
  → Client area → Server problems, with your own message).

Two more plugins watch your servers:

- **IP blacklist check** looks up each active server's IP in spam blacklists (Spamhaus, SpamCop, Barracuda, PSBL)
  every hour and rings the bell when one is listed or removed.
- **Site checks** checks every day that each active hosting account's domain still points to its server and that its
  HTTPS certificate is valid and not about to expire. Problems are listed under Services → **Site checks** and go to
  the bell once.

Next: [Domains and SSL](05-domains-and-ssl.md).
