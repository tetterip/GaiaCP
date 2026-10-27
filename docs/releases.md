# Releases


GaiaCP's version is in the `VERSION` file (`1.2.3`; `1.2.3-beta.1` for a pre-release). The signed-in panel footer,
System health and `php artisan about` show it. `Version::STAGE` (`app/Support/Version.php`) says how far along GaiaCP
is: `alpha` now, shown as a badge next to the version in the admin footer, in the installer and in `php artisan about`;
set it to `beta`, then `null` once it is stable. It stays out of `VERSION`, so plugins' `requires` and updates keep
comparing plain numbers. To release:

1. Set `VERSION` to the new number and move the `## [Unreleased]` notes in `CHANGELOG.md` under
   `## [<version>] - <date>`; merge that to `main`.
2. On GitHub: Releases → Draft a new release → a new tag `v<version>` (or `<version>`) on `main` → Publish. Pushing the
   tag from Git (`git tag v0.2.0 && git push origin v0.2.0`) works too.

The Release workflow (`.github/workflows/release.yml`) then runs the tests, checks the tag matches `VERSION`, builds
`gaiacp-<version>.zip` with `bin/build-release.sh` and attaches it with a SHA-256 checksum to the tag's release (created
with the changelog section as notes when the tag was pushed without one). Actions → Release → Run workflow with a tag
rebuilds an existing tag's zip. The zip holds the code with its production dependencies (`vendor/`) and Filament's assets, without
tests or dev files, so a server needs neither Composer nor Git. `bin/build-release.sh` builds the same zip locally
(into `build/`) from the committed code.

## Signing

Each release's `gaiacp-<version>.zip.sha256` is signed with Ed25519: the Release workflow writes
`gaiacp-<version>.zip.sha256.sig` (the base64 signature of the checksum file's exact bytes) with the secret key in the
repository's `RELEASE_SIGNING_KEY` Actions secret, and fails without it. An install with a trusted public key
(`gaiacp.updates.keys` in `config/gaiacp.php`, plus `GAIACP_UPDATE_KEYS` for releases of your own) installs a release
only when that signature matches one of its keys and the signed checksum names this version's zip; then the zip must
match the checksum. So a release changed on GitHub, or an older signed one passed off as newer, is refused. With no
key at all only the SHA-256 is checked, which catches a broken download but not a changed release.

The key pair is made once with `php bin/release-key keygen` on the maintainer's own computer (PHP with sodium, no
Laravel needed): the secret key goes only into the `RELEASE_SIGNING_KEY` secret (GitHub → Settings → Secrets and
variables → Actions), the public key into `config/gaiacp.php`. It is a separate key from the plugin catalogue's.
`php bin/release-key verify <file> <public key>` checks a downloaded `.sha256` against its `.sig`. To change keys, add
the new public key next to the old one, release, then sign with the new key; remove the old public key a few
releases later. An install that has a key can no longer install a release made before signing started.
