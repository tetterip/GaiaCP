# The plugin catalogue

GaiaCP installs read a list of plugins from a catalogue, by default the official one on
`https://plugins.gaiacp.digital`. It is static files built by the public `gaiacp-plugins` repository: authors send a
plugin there as a pull request, a maintainer reviews and merges, and a GitHub Action builds and signs the catalogue
onto that repository's `catalogue` branch, which the web server deploys. No code runs on the catalogue's server.

## Files

```
catalogue.json         the plugins and their versions (format below)
catalogue.json.sig     base64 Ed25519 signature of catalogue.json's exact bytes
zips/<Folder>-<version>.zip
index.html             the public page
```

## catalogue.json, format 1

```json
{
    "format": 1,
    "generated_at": "2026-10-25T17:00:00Z",
    "plugins": [{
        "folder": "HelloWorld",
        "class": "Plugins\\HelloWorld\\HelloWorldPlugin",
        "name": "Hello World",
        "description": {"en": "…", "el": "…"},
        "category": "Integrations",
        "author": {"name": "GaiaCP", "url": "https://gaiacp.digital"},
        "homepage": "https://…",
        "licence": "GPL-3.0-or-later",
        "official": true,
        "versions": [{
            "version": "1.0.0",
            "requires": "0.1.0",
            "php": "8.3",
            "released_at": "2026-10-25T17:00:00Z",
            "url": "zips/HelloWorld-1.0.0.zip",
            "sha256": "…",
            "size": 1005,
            "changelog": "- First version",
            "source": "…"
        }]
    }]
}
```

- `url` is relative to the catalogue's address, or an absolute `https://` one.
- `requires` / `php`: the oldest GaiaCP / PHP the version runs on. An install offers the newest version that runs on it
  (`Listing::best()`).
- `source` is the builder's hash of the plugin's files, so a published version can't be changed later (GaiaCP ignores it).
- Unknown keys are ignored; a change GaiaCP can't read raises `format`.

## How GaiaCP checks it

`App\Plugins\Catalogue\Catalogue`:

- `fetch()` reads both files, checks the signature with `gaiacp.plugins.catalogue.keys` (the official public key in
  `config/gaiacp.php`, plus `GAIACP_PLUGIN_CATALOGUE_KEYS`), refuses one older than the copy it kept
  (`storage/app/private/plugins/catalogue/`) so an old catalogue can't be served again, and keeps the new one.
- `download()` fetches a version's zip and checks its size and SHA-256 against the signed catalogue; then
  `PluginInstaller` runs its usual checks on it.
- Requests carry only `User-Agent: GaiaCP/<version>`; they show in the Module log as "Plugin catalogue".
- Settings → System → Plugin catalogue (`App\Filament\Pages\PluginCatalogue`, super admins) lists the stored copy;
  Install / Update ask for the admin's password and go through `CatalogueInstaller` (newest version that runs here →
  `download()` → `PluginInstaller::install()`), so the plugin starts disabled and is uninstalled like an uploaded one.
  Both are hidden while `GAIACP_PLUGIN_UPLOADS=false`; the page is closed in the demo.
- `gaiacp:check-plugin-catalogue` (daily 05:40) reads it and logs `plugin.update_available` once per plugin and version,
  which rings the super admins' bell; the Plugins page shows the update from the stored copy without a request.
- Off with `GAIACP_PLUGIN_CATALOGUE=false`, or while no key is set. Another catalogue: `GAIACP_PLUGIN_CATALOGUE_URL`
  and its public key in `GAIACP_PLUGIN_CATALOGUE_KEYS`.

## The signing key

Made once with `php bin/catalogue keygen` in gaiacp-plugins, on the maintainer's own computer. The secret key is only
the `CATALOGUE_SIGNING_KEY` secret of that repository's GitHub Actions; the public key goes in `config/gaiacp.php`.
To change keys, add the new public key next to the old one, release GaiaCP, then sign with the new key; remove the
old public key a few releases later.
