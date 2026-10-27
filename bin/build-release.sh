#!/usr/bin/env bash
# Builds the release zip from the committed code (HEAD): build/gaiacp-<version>.zip, its .sha256 and the
# release notes (build/RELEASE_NOTES.md, the version's CHANGELOG.md section). The zip holds vendor/ and
# Filament's published assets, so a server needs neither Composer nor Git to run it.
# Run from a clean checkout; the release workflow (.github/workflows/release.yml) runs it for each v* tag.
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION="$(tr -d '[:space:]' < VERSION)"
if ! [[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ ]]; then
    echo "VERSION must be a version number like 1.2.3, not '$VERSION'" >&2
    exit 1
fi

NAME="gaiacp-$VERSION"
BUILD="$PWD/build"
# Staged outside this checkout: a package Composer has to clone must not find (and reconfigure) our .git
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
STAGE="$TMP/$NAME"
COMPOSER="${COMPOSER_BIN:-composer}"

rm -rf "$BUILD/$NAME.zip" "$BUILD/$NAME.zip.sha256" "$BUILD/RELEASE_NOTES.md"
mkdir -p "$STAGE" "$BUILD"

# Committed files only, minus what .gitattributes marks export-ignore (tests, CI, dev tooling)
git archive --format=tar HEAD | tar -x -C "$STAGE"

# Production dependencies; post-autoload-dump publishes Filament's assets into public/
(
    cd "$STAGE"
    $COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
)

# Nothing generated on this machine goes in: an install builds its own caches. A package Composer had to
# clone (no dist zip) brings its Git history, which a server never needs.
rm -f "$STAGE"/bootstrap/cache/*.php "$STAGE/.env"
find "$STAGE/vendor" -name .git -prune -exec rm -rf {} +
find "$STAGE" -mindepth 1 -maxdepth 1 -type d -empty -delete

# Release notes: the "## [<version>]" section of CHANGELOG.md
awk -v v="$VERSION" '
    index($0, "## [" v "]") == 1 { found = 1; next }
    found && /^## \[/ { exit }
    found { print }
' CHANGELOG.md | sed -e '/./,$!d' > "$BUILD/RELEASE_NOTES.md"
if ! grep -q '[^[:space:]]' "$BUILD/RELEASE_NOTES.md"; then
    echo "CHANGELOG.md has no section for $VERSION (## [$VERSION] - date)" >&2
    exit 1
fi

(cd "$TMP" && zip -qr -X "$BUILD/$NAME.zip" "$NAME")
(cd "$BUILD" && sha256sum "$NAME.zip" > "$NAME.zip.sha256")

echo "Built build/$NAME.zip ($(du -h "$BUILD/$NAME.zip" | cut -f1))"
