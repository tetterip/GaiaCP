# Changelog

Every version of this plugin, newest first. The version is in `plugin.json`; it ships with GaiaCP.

## [1.0.1] - 2026-10-26

- Translated into German, Spanish, French, Italian, Dutch, Polish, Brazilian Portuguese and Turkish (machine translated)

## [1.0.0] - 2026-10-25

- Hosting accounts on CyberPanel as its `admin` user (port 8090): create (`/api/createWebsite`, the owner user with
  it), suspend and unsuspend (`/api/submitWebsiteStatus`), delete (`/api/deleteWebsite`, then the owner user once it
  has no websites left), change package (`/api/changePackageAPI`) and password (`/api/changeUserPassAPI`), packages
  (`/api/listPackage`), account state and every website on the server (cloud API `fetchWebsites`), usage (cloud API
  `fetchWebsiteData`)
- New websites start on the PHP version picked under Settings → CyberPanel (PHP 8.2 unless changed)
- Client area and Servers login open the CyberPanel login page
