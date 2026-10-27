# Provisioning modules

How each server panel (cPanel, Plesk, HestiaCP, DirectAdmin, CyberPanel, Enhance) and registrar (easy.gr, Enom, Email Notifications) plugin talks to its API. Read before touching one or adding a call.

Each server type and registrar is a plugin (`plugins/Cpanel`, `plugins/Plesk`, `plugins/Hestia`, `plugins/DirectAdmin`, `plugins/CyberPanel`, `plugins/EasyGr`, `plugins/Enom`) behind the interfaces in
`app/Provisioning/` (`ServerModule` → `HostingProvisioner`, `RegistrarModule` → `DomainRegistrar`) so the rest of the app
never calls a panel API directly.

- **cPanel:** WHM API 1 over HTTPS with an API token (`createacct`, `suspendacct`,
  `unsuspendacct`, `removeacct`, `changepackage`, `passwd`, `listaccts`, `create_user_session`, `accountsummary` +
  `showbw` + `uapi_cpanel` StatsBar for usage).
- **Plesk:** XML API (`/enterprise/control/agent.php`) for subscriptions and service plans (the REST API
  lacks them). Auth: `HTTP_AUTH_LOGIN`/`HTTP_AUTH_PASSWD`, or `KEY` (secret key) when the server's username
  is empty. One Plesk customer per client (external-id `gaiacp-client-<id>`), one subscription per hosting
  service keyed by its domain; `package` holds the service plan name. Change password sets the subscription's
  system user (`ftp_password`), not the customer login. Usage is `webspace get` with `gen_info` / `limits` / `stat` / `disk_usage`.
  **The schema is order-sensitive**: `PleskClient` takes ordered arrays. Request shapes follow the official
  `plesk/api-php-lib` (its integration tests run against real Plesk); keep to them when adding calls.
- **HestiaCP** (`plugins/Hestia`): form POST to `/api/` (port 8083) with `cmd` = a `v-*` command and `arg1`…
  (`hestiacp/hestiacp` `web/api/index.php`). Empty server username = access key `ACCESS_KEY:SECRET_KEY` (sent as
  `access_key` / `secret_key`; failures carry a `Hestia-Exit-Code` header), else the admin user + password (failures
  are HTTP 200 text starting `Error:`). Exit code 3 = missing. One HestiaCP user per service (`v-add-user` then
  `v-add-domain`, user removed again if the domain fails); `v-list-user` gives state and usage (MB, "unlimited").
  No one-time login exists, so `loginUrl()` is the login page. A password starting with `/` is refused (HestiaCP
  reads `/tmp/…` passwords as files)
- **DirectAdmin** (`plugins/DirectAdmin`): legacy API `https://host:2222/CMD_API_<COMMAND>` with HTTP basic auth (the
  admin or a reseller, login key or password); POST forms for changes, GET for reads; replies URL-encoded
  (`list[]=…`, `error=1&text=…&details=…`; a `text/html` reply = wrong port or no API access). One DirectAdmin user per
  service: `ACCOUNT_USER` (`action=create`, `notify=no`, `ip` = the server's `shared_ip` else the login's first
  `SHOW_RESELLER_IPS`), `SELECT_USERS` (`suspend=Suspend|Unsuspend`, delete = `confirmed=Confirm&delete=yes`, `select0`),
  `MODIFY_USER` (`action=package`), `USER_PASSWD`, `PACKAGES_USER`; state = `SHOW_USERS` membership + `SHOW_USER_CONFIG`
  (`suspended`, `domain`); usage = `SHOW_USER_USAGE` against the config's limits (MB, "unlimited"). Request shapes
  follow DirectAdmin's example scripts and `omines/directadmin` / `mvdgeijn/directadmin`. No one-time login: the
  login page. The plugin implements `LimitsUsernameLength` (10), which `UsernameGenerator::maxLength()` and the
  service form honour
- **Proxmox VE** (`plugins/Proxmox`, off by default; VPS): REST API `https://host:8006/api2/json` with an API token
  (`Authorization: PVEAPIToken=<server username = token id>=<api_token = secret>`), form fields, answers `{"data": …}`,
  failures in the HTTP reason phrase (plus `errors` on a 400). Request shapes were checked against Proxmox's own API
  schema (`pve-docs` `api-viewer/apidata.js`); not tried on a real Proxmox yet. Calls that run as a task return a UPID
  that `ProxmoxClient::waitForTask()` follows (`/nodes/{node}/tasks/{upid}/status`, `Sleep` every 2 s, 15 min at most).
  The package is a KVM template's name (`packages()` = `/cluster/resources?type=vm` with `template`); `createAccount()`
  = `/cluster/nextid`, `qemu/{template}/clone` (`full=1`, on the template's node, optional `proxmox.storage`), `PUT
  config` (`ciuser`, `cipassword`, `ipconfig0`, `nameserver`, `tags`, `onboot`), a client user `<username>@pve` (`POST
  /access/users` + `PUT /access/acl` with `proxmox.client_role` on `/vms/<id>`), `status/start`; any failure after the
  clone deletes it again. The VPS is found by its tags (`gaiacp`, `gaiacp-user-<username>`, `gaiacp-suspended`), never
  by a stored id. Suspend = `status/stop` + user `enable=0` + the suspended tag; terminate = stop, `DELETE` with `purge`
  and `destroy-unreferenced-disks`, delete the user, free the address. Change package copies the new template's
  `cores` / `sockets` / `memory` and grows the boot disk (`resize`, `ProxmoxProvisioner::bootDisk()`). API tokens can't
  use `PUT /access/password`, so a new password replaces the client's user (and its ACL); it also goes to `cipassword`
  and, on a running VPS, to `agent/set-user-password` (best effort). IP pool: `proxmox_addresses` (`ProxmoxAddress::
  claim()` / `release()`, Settings → Proxmox → IP addresses); no rows for a server = `ip=dhcp`. Usage = `maxdisk` only.
  It implements `ControlsPower` (`isRunning()`, `power()` with `start` / `shutdown` / `reboot` / `stop`,
  `clientsControlPower()` = `proxmox.client_power`): `ServiceLifecycle::power()` (logged `account.power`), the admin's
  Account menu and the client's `HostingSelfService`. Login = the Proxmox web interface (no one-time login for another
  user); LXC containers are not supported
- **CyberPanel** (`plugins/CyberPanel`, off by default): shapes read from CyberPanel's source (`usmannasir/cyberpanel`
  `api/views.py`, `cloudAPI/`), not tried on a real server. Two APIs on port 8090, both JSON POSTs: `/api/<endpoint>`
  with `adminUser` / `adminPass` in the body (`cyberPanelVersion` takes `username` / `password`), each answering its own
  status key = 1 (`createWebSiteStatus`, `websiteStatus`, `websiteDeleteStatus` (2 = still deleting), `changePackage`,
  `changeStatus`, `deleteStatus`) or 0 with `error_message`; and `/cloudAPI/` (`serverUserName`, `controller`) with
  the login's API token from `/api/verifyConn` (`apiToken`, masked in the log; older CyberPanel: `Basic
  base64(user:password)`) in `Authorization`, which CyberPanel allows only for `admin`, so the server login must be
  `admin`. One website per service, owned by a CyberPanel user named after the service's username: `createWebsite`
  (owner created with it, `phpSelection` from Settings → CyberPanel, it finishes in the background),
  `submitWebsiteStatus` (`Suspend` / `Unsuspend`), `deleteWebsite` then `submitUserDeletion` when the owner has no
  website left (CyberPanel may already have removed it: `getUserInfo` first), `changePackageAPI`, `changeUserPassAPI`
  (the CyberPanel login only), `listPackage`. State, `accounts()` and finding the website = cloud `fetchWebsites`
  (ten a page, `data` is a JSON string; `admin` = owner, `state` Active / Suspended); usage = cloud `fetchWebsiteData`
  (MB, a limit of 0 = unlimited). No one-time login: `cloudAPI/access` would put the owner's lasting token in a link,
  so `loginUrl()` is the login page
- **Enhance** (`plugins/Enhance`, off by default): REST JSON to `https://<host>/api/…` with `Authorization: Bearer`
  <access token>; the server's username is the master organisation id. Lists are `{items: […]}`, new things `{id}`,
  errors `{code, detail}`. Each client = a customer org (`POST /orgs/{master}/customers` {name}, then `POST /logins?orgId=`
  {email, name, password} or the existing login of that email from `GET /logins`, then `POST /orgs/{org}/members`
  {loginId, roles: [Owner]}), kept in `enhance_customers` (server + client → org; one made elsewhere is found by the
  customers' `ownerEmail`). Each hosting service = `POST /orgs/{master}/customers/{org}/subscriptions` {planId} (package =
  plan name, `GET /orgs/{master}/plans`) + `POST /orgs/{org}/websites` {domain, subscriptionId} (the subscription is
  deleted again if this fails), kept in `enhance_accounts` (service → org, subscription, website; one made elsewhere is
  found by its website's domain). `PATCH /orgs/{org}/subscriptions/{id}` {isSuspended} / {planId}; terminate deletes the
  subscription's websites, then `DELETE …/subscriptions/{id}?force=true`. Password = `PATCH /logins/{loginId}`
  {password} of the Owner member (shared by the client's services there, whose stored passwords follow). Client login
  = `GET /orgs/{org}/members/{memberId}/sso` (a JSON string). Usage = the subscription's `resources` (name, usage,
  total), read leniently. Paths follow the open-source WHMCS module (webdighost/enhance-whmcs) and Paymenter's
  extension, since apidocs.enhance.com was unreachable; the password and usage calls are guesses. Untested on a real
  server
- **Account list** (`App\Provisioning\ListsAccounts`, every panel): `accounts()` gives every hosting account as a
  `PanelAccount` (username, domain, package, suspended). cPanel `listaccts` with no search; Plesk `webspace get` with an
  empty filter (`gen_info`, `hosting` for the FTP login, `subscriptions` for the plan guid, named from `service-plan
  get`); HestiaCP `v-list-users` (the `system` package left out) and each user's first web domain; DirectAdmin
  `SHOW_USERS` and each one's `SHOW_USER_CONFIG`; CyberPanel one per website (an owner with two shows twice). Servers → Compare with the server (`CompareServerAccounts`,
  `Servers\Pages\CompareServer`) matches them to the server's hosting services by username, else by domain, and lists
  the ones only on the server (with the terminated / cancelled service they were, if any), the services missing there,
  and the differences (suspended on one side only, package, domain); pending services are left out. Reading changes
  nothing; each row has one-click fixes (`FixServerDifference`, confirmed first, logged): on the server through
  `ServiceLifecycle` (unsuspend, suspend, `applyPackage()`, create a missing account without the welcome email) or in
  GaiaCP only (mark suspended / active / terminated, take the server's package or domain; `service.synced_from_server`,
  no email). A running account billed nowhere can be suspended on the server (`server.account_suspended`); deleting
  one is never offered. The fixes read the server's values from the last reading (`#[Locked] $comparison`). Accounts only on the server are grouped: running and billed nowhere, suspended, billed on another server
  (a live service with that username or domain elsewhere: left behind after a move) and "Not billed on purpose"
  (`servers.ignored_accounts`, username => note, set on the page). "Add to GaiaCP" opens Services → New with
  `?server=&username=&domain=&package=&suspended=` filled in (`CreateService::afterFill()`, active or suspended, back to
  the comparison once saved). `gaiacp:compare-servers` (04:30, Settings → Automation → Compare servers nightly) runs
  it on every active server and logs `server.accounts_differ` (super admins' bell) only for what
  `CompareServerAccounts::problems()` finds that `servers.compare_alerted` (the last run's keys) didn't have
- **Quick shortcuts** (`App\Provisioning\OffersShortcuts`, cPanel and Plesk): `shortcuts()` (key => label, icon) and
  `shortcutUrl()`, a one-time sign-in to that page. cPanel: `create_user_session` with `app` = `Email_Accounts`,
  `FileManager_Home`, `Database_MySQL`, `Database_phpMyAdmin`, `Stats_AWStats`, `Backups_Home`. Plesk: the customer's
  `create_session` URL + `&success_redirect_url=` `/smb/email-address/list`, `/smb/app/index`, `/smb/file-manager/list`,
  `/smb/database/list`, `/smb/statistics/details`, `/smb/user/list`. Names and paths follow WHMCS's modules, not yet
  tried on real panels. The client opens one through `services/{service}/panel-login?to=<key>` (an unknown key is a
  404 before any panel call); `ServiceLifecycle::loginUrl($service, $ip, $shortcut)` logs `account.panel_login` with
  "(shortcut: key)". HestiaCP, DirectAdmin and CyberPanel have no one-time login, so no shortcuts
- **Registrars:** `registrars` rows (module, encrypted `credentials`, active, `is_default` = pre-selected for a new
  TLD), edited under Settings → Registrars (default nameservers under Settings → Domains). Prices are per TLD, not per
  registrar: `tlds` (`Tld`, Settings → Domain pricing, `TldResource`: register / transfer / renew cents for each of
  `Tld::YEARS` = 1-3, null = not offered; grace / redemption fees; `registrar_id` = preferred registrar; `costs` =
  the registrar's charge per price column, base currency, `costCents()` / `marginCents()`, used by Income forecast's
  Domain costs (`IncomeForecast::domainCost()`) and Reports → Domain profit (`byTld()`)). New orders
  pre-select it (`domains.registrar_id`, the admin can change it) and offer only priced terms (`Tld::terms()`).
  `Registrar::forDomain()` = the TLD's preferred registrar (`Tld::match()`, longest TLD match) while active; `RegistrarFactory` builds the
  `DomainRegistrar` from the account's module (`PluginManager::enabledRegistrarModule()`). Only `DomainLifecycle` calls a registrar; `RecordPayment` renews an
  active/suspended yearly domain through it, outside the transaction (a failure keeps the payment).
- **Email Notifications** (`plugins/EmailRegistrar`, module `email`, account created by the `tlds` migration, no
  login): for TLDs with no integrated registrar. Its module is a `ManualRegistrar` (`Registrar::isManual()`): every
  call emails the admins (`RegistrarRequestMail`, active admins with system emails on and Services access; none
  emailed = `ProvisioningException`, so nothing is marked done) and books nothing. `DomainLifecycle::readsRegistrar()`
  is false for it: no sync (`gaiacp:sync-domains` skips it), no admin EPP action, and `eppCode()` returns null (the
  client is told the code will be emailed)
- **easy.gr** (`plugins/EasyGr`), ported from its WHMCS module: form POST to `https://api.easy.gr` with `username`,
  `password` and a numeric `action` (1 EPP, 2/3 get/save nameservers, 4 register, 5 renew, 11 transfer,
  19 sync/expiry); replies `done` 1 ok / 0 with `errors` / 3 registry down. Keep the module's field names.
  TLS is verified by default (the module disables it; don't); `registrars.verify_tls` is a per-account escape
  hatch for a broken API certificate, like `servers.verify_tls`.
- **Enom** (`plugins/Enom`, module `enom`; credentials `uid`, `password` = the API token, `test_mode`): form POST to
  `https://reseller.enom.com/interface.asp` (`resellertest.enom.com` in test mode) with `uid`, `pw`, `command` and
  `responsetype=xml`; replies `<interface-response>` with `ErrCount` 0, or the errors in `<errors><Err1>…`. A domain
  goes as `SLD` (first label) + `TLD` (the rest). Commands: `Purchase` (NS1..NS12 or `UseDNS=default`, contacts with
  the prefixes Registrant / Admin / Tech / AuxBilling; a company adds OrganizationName, JobTitle "N/A" and Fax),
  `Extend`, `TP_CreateOrder` (Autoverification, `AuthInfo1`, `UseContacts=0`), `GetDomainInfo` (status/expiration,
  US date order), `GetDNS` / `ModifyNS`, `SynchAuthInfo` (EmailEPP). Built from open-source Enom modules, since
  Enom's docs were unreachable; not tried against Enom yet. `pw` and the EPP code are masked in the module log.
- **SSL certificates** (type `ssl`): providers are plugins implementing `App\Certificates\CertificateModule`
  (`PluginManager::certificateModules()`, keyed by `certificateKey()`); only `App\Actions\CertificateLifecycle` calls
  them. A service's `package` is `<key>:<product code>` (`CertificateLifecycle::package()`, products cached an hour as
  plain arrays), its 1:1 `certificates` row (`Service::certificate()`) keeps the remote order id, status
  (`CertificateStatus`), validation method and details, CSR, encrypted private key (made by `App\Certificates\Csr`,
  RSA 2048, unless the CSR is the client's own), certificate, CA bundle, `expires_at` (this certificate) and
  `ends_at` (the order). `SetUpService` orders it (`order()`, safe to re-run); the order length is the longest product
  period within the billing cycle. Since March 2026 a certificate lasts at most 200 days, so a yearly order gets a
  reissue inside it; `gaiacp:check-certificates` (hourly) refreshes pending ones, reissues 30 days before
  `expires_at` when `ends_at` is later, renews (a new order, old id in `previous_orders`) 30 days before `ends_at`
  when `next_due_date` is beyond it. A wildcard is never validated by file. Emails `ssl_validation` / `ssl_issued`.
- **GoGetSSL** (`plugins/GoGetSsl`, key `gogetssl`, off by default; settings `gogetssl.username` / encrypted
  `password` / `dcv_method`): `POST /auth/` (`user`, `pass`) gives an `auth_key` sent as a query parameter on
  every call to `https://my.gogetssl.com/api` (no test system: the old sandbox host is gone); errors are `{error, message,
  description}`. Calls: `/products/ssl/`, `/orders/add_ssl_order/` and `/orders/add_ssl_renew_order/` (csr,
  `dcv_method`, `webserver_type=-1`, admin_ / tech_ contacts, org_ fields for OV), `/orders/status/{id}` (`crt_code`,
  `ca_code`, `valid_till`, `end_date`, `approver_method` dns / http / https / email, `reissue_now`),
  `/orders/ssl/reissue/{id}`, `/orders/ssl/change_dcv/{id}`, `/orders/ssl/revalidate/{id}`,
  `/orders/ssl/resend_validation_email/{id}`, `/orders/cancel_ssl_order/`, `/account/balance/`. Built from GoGetSSL's
  PHP SDK, since its docs were unreachable; not tried against the real API yet.
- Registrars that email the EPP code instead of returning it (Enom) implement `App\Provisioning\EmailsEppCode`:
  their `eppCode()` returns '', `DomainLifecycle::eppCode()` returns null and logs that it was emailed, and
  `emailsEppCode()` lets the admin and client actions say so before and after. Phone numbers go out as
  `App\Support\EppPhone::format()` ("+30.2101234567").
- Optional registrar capabilities, offered by the admin's Registrar menu and the client's domain page
  (`App\Filament\Actions\DomainRegistrarActions`, client switches `client_area.domain_contacts` /
  `domain_child_nameservers` / `domain_lock`) only when `DomainLifecycle::supports()` finds them:
  `ManagesDomainContacts` (`contactRoles()` without a call, `contacts()` → `App\Provisioning\DomainContact` by role,
  `saveContacts()`; easy.gr actions 6 / 7 with registrant, admin, tech; Enom `GetContacts` / `Contacts` one
  ContactType at a time, with AuxBilling as billing), `ManagesChildNameservers` (private nameservers under the domain;
  easy.gr action 8 registers or changes, 10 deletes; Enom `RegisterNameServer` / `UpdateNameServer` /
  `DeleteNameServer`; registrars can't list them, so the ones made here are kept in `domains.child_nameservers`)
  and `ManagesRegistrarLock` (Enom `GetRegLock` / `SetRegLock`; easy.gr has none). `ChecksAvailability` answers the
  client area's domain search (`App\Support\DomainSearch`; Enom `Check`, RRPCode 210 free / 211 taken; easy.gr action 0 as a GET, as its
  whoisservers.php for WHMCS asks: a reply with "not exist" is free, an error or `done` 0 falls back to RDAP / DNS). Enom's commands come from memory
  of its API and are untested
- Servers also keep optional `max_accounts`, `monthly_cost_cents` (base currency), `ip_address`, `status_url`.
  `Server::accounts()` = active / suspended hosting (`withAccountsCount()`, `accountsText()` "33/120 (28%)",
  `optionLabel()` in the order / service pickers); the accounts bar (list and dashboard) is `filament/servers/accounts-bar`, drawn like the uptime bar by the shared square meter `filament/servers/meter`.
  "Log in to control panel" = `Admin\ServerPanelLoginController` (`filament.admin.server-panel-login`, super admins) →
  `App\Actions\ServerPanelLogin` (logs `server.panel_login`) → `HostingProvisioner::adminLoginUrl()`.
  `IncomeForecast::serverCosts()` (active servers) gives Income forecast's and Server revenue's profit columns
