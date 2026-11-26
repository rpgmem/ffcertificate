---
paths:
  - "includes/**/*app-screen*.php"
  - "includes/shortcodes/class-ffc-app-home.php"
  - "includes/core/class-ffc-public-design.php"
  - "includes/settings/class-ffc-settings-reader.php"
  - "includes/admin/class-ffc-settings-save-handler.php"
  - "assets/js/ffc-dark-mode.js"
---

# The Site PWA contract

Site PWA (`rpgmem/site-pwa`, epic #2738) turns the site into an installable app, and this plugin is its first consumer. The two meet in PHP, in both directions, and both sides read the other defensively: a hook nobody fires does nothing, and a `class_exists()` / `is_callable()` that answers false makes the app fall back to its own defaults. So **a break on this side is silent**: no error, no failing test in either repository, just an app that stopped showing a screen or stopped following the site's look. This file is the contract as this side sees it; Site PWA documents its own half in `docs/screens.md` there. `SitePwaContractTest` pins this side, and it cannot see the other repository.

## What this plugin hooks into

| Hook | Kind | Consumer here |
| --- | --- | --- |
| `site_pwa_register_screens` | action, `( $screens, int $api )` | `Shortcodes\DashboardAppScreens`, `Audience\AudienceAppScreens` |
| `site_pwa_profile_line` | filter, `( string $line, WP_User $user )` | `DashboardAppScreens` |
| `site_pwa_home` | filter, `( array $home, WP_User $user, int $api )` | `Shortcodes\AppHome` |
| `site_pwa_lock_required` | filter, `( bool $required, WP_User $user )` | `AudienceAppScreens` |
| `site_pwa_app_url` | filter, `( string $url )` | `Shortcodes\AgendaAppScreen` |

**`$api` is the contract's version, and each consumer refuses one it does not know.** `API_VERSION` on each consumer class names the version it was written for; a register call carrying another one registers nothing. Site PWA raises `$api` only for a change that would break a consumer of the previous one, so a bump there is the signal to adapt here, never a value to mirror blindly. A screen's arguments (`label`, `render` / `url`, `icon`, `place`, `slot`, `section`, `badge`, `capability`, `position`) and the reserved ids are Site PWA's to define: read its `docs/screens.md` before adding or changing one, and give the screen a capability, because the app checks it everywhere the screen appears.

## What Site PWA reads from this plugin

- **`Core\PublicDesign::from_settings()`** and the public properties `enabled`, `accent`, `radius` and `card_radius`: the app's accent and corners follow Settings → Public Design when it is on. `radius` is the controls' corner and `card_radius` the cards'.
- **`Settings\SettingsReader::get( 'dark_mode' )`** with exactly `off`, `on` and `auto`: the app's light or dark scheme follows this setting by default. A new value is invisible to the app until Site PWA maps it.
- **The `ffc-dark-mode` class on `<html>`**: the app's frame sets it, before the first paint, so this plugin's `:root.ffc-dark-mode` palette applies to the screens drawn inside the frame.

## Changing any of it

A rename, a removed property, a new `dark_mode` value or a changed hook signature on this side ships **together with** the matching change in Site PWA, or stays backward-compatible until it does: keep the old name working, or keep accepting the old shape. Say so in the PR, link the Site PWA issue, and update `SitePwaContractTest` in the same PR — a red test there means the contract moved, not that the test is stale. The app is installed on people's phones from a release, so the two plugins can run at different versions on the same site; never assume they ship together.
