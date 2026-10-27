## What changes

**Before:**

**After:**

## How

<!-- A short note on the approach, and anything a reviewer should look at first. -->

## Checklist

- [ ] A test covers the change
- [ ] `./vendor/bin/pint` and `php artisan test` pass
- [ ] New texts go through `__()` and are in `lang/el.json` (or say here that they still need translating)
- [ ] A changed plugin has its `plugin.json` version bumped and a `CHANGELOG.md` line
- [ ] A line under `## [Unreleased]` in `CHANGELOG.md`, if a user would notice the change
- [ ] Screenshots, for visible changes
