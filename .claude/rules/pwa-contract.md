---
paths:
  - "includes/**/*app-screen*.php"
  - "includes/shortcodes/class-ffc-app-home.php"
  - "includes/shortcodes/class-ffc-app-look.php"
  - "includes/core/class-ffc-public-design.php"
  - "includes/settings/class-ffc-settings-reader.php"
  - "includes/admin/class-ffc-settings-save-handler.php"
  - "assets/js/ffc-dark-mode.js"
  - ".github/scripts/site-pwa-integration-check.php"
  - ".github/workflows/site-pwa-integration.yml"
---

# The Site PWA contract

Site PWA (`rpgmem/site-pwa`, epic #2738) turns the site into an installable app, and this plugin is its first consumer. The two meet in PHP, and both sides are defensive: a hook nobody fires does nothing, and a `class_exists()` / `is_callable()` that answers false makes the app fall back to its own defaults. So **a break on this side is silent**: no error, no failing unit test in either repository, just an app that stopped showing a screen or stopped following the site's look. This file is the contract as this side sees it; Site PWA documents its own half — every hook's arguments, the screen keys, the reserved ids and the look sources — in `docs/screens.md` there (https://github.com/rpgmem/site-pwa/blob/develop/docs/screens.md); that file is the reference for *using* the app, and this one does not copy it, so the two cannot drift. A session without the other repository reads it at that address. `SitePwaContractTest` pins this side, and it cannot see the other repository; the integration check ("Checked with both plugins running", below) runs the two together.

## What this plugin hooks into

| Hook | Kind | Consumer here |
| --- | --- | --- |
| `site_pwa_register_screens` | action, `( $screens, int $api )` | `Shortcodes\DashboardAppScreens`, `Audience\AudienceAppScreens` |
| `site_pwa_profile_line` | filter, `( string $line, WP_User $user )` | `DashboardAppScreens` |
| `site_pwa_details_screen` | filter, `( string $id, WP_User $user )` | `DashboardAppScreens` |
| `site_pwa_preference_sections` | filter, `( array $sections, WP_User $user )` | `DashboardAppScreens` |
| `site_pwa_home` | filter, `( array $home, WP_User $user, int $api )` | `Shortcodes\AppHome` |
| `site_pwa_lock_required` | filter, `( bool $required, WP_User $user )` | `AudienceAppScreens` |
| `site_pwa_app_url` | filter, `( string $url )` | `Shortcodes\AgendaAppScreen` |
| `site_pwa_identity_sources` | filter, `( array $sources )` | `Shortcodes\AppLook` |
| `site_pwa_scheme_sources` | filter, `( array $sources )` | `AppLook` |
| `site_pwa_dark_root_classes` | filter, `( array $classes )` | `AppLook` |

**`$api` is the contract's version, and each consumer refuses one it does not know.** `API_VERSION` on each consumer class names the version it was written for; a register call carrying another one registers nothing. Site PWA raises `$api` only for a change that would break a consumer of the previous one, so a bump there is the signal to adapt here, never a value to mirror blindly. A screen's arguments (`label`, `render` / `url`, `icon`, `place`, `slot`, `section`, `badge`, `capability`, `position`) and the reserved ids are Site PWA's to define: read its `docs/screens.md` (address above) before adding or changing one, and give the screen a capability, because the app checks it everywhere the screen appears.

## The look: offered, not read (#2873)

This plugin offers the app its look through the three filters `AppLook` hooks: Public Design's accent and corners as an identity source, Dark Mode as a scheme source, and `ffc-dark-mode` as a class the app adds to `<html>` when it turns dark, so the screens drawn here follow it. Both sources carry the id **`ffc`, which is a stored value** — Site PWA keeps it in its settings as the source an administrator chose (`identity_source`, `color_scheme`, whose default it is) — so it never renames. The readers return Site PWA's shapes: `accent` / `radius` (cards) / `control_radius` (buttons and fields), or null while Public Design is off; `light` / `dark` / `auto`, or null for a Dark Mode value the app does not know.

Inside the app, the theme a person chose on their device (More → Appearance, rpgmem/site-pwa#62) marks `<html>` with `data-site-pwa-scheme` `dark` or `light` before the first paint; `assets/js/ffc-dark-mode.js` treats `dark` as on and `light` as off, whatever this plugin's Dark Mode says (#2883).

Site PWA reads no class of this plugin by name (rpgmem/site-pwa#49, #2877): everything it takes from this side comes through the hooks above. This side reads one name of Site PWA's: `DashboardAppScreens::app_has_preference_sections()` asks whether `SitePwa\App\Frame::preferenceSections()` exists, so the app's Messages screen hands its two toggles to Preferences only where Preferences can draw them (#2885). The two plugins update apart, and the integration check reports it.

## Checked with both plugins running (#2875)

`SitePwaContractTest` reads this side's source and cannot see the other repository. What crosses the boundary is checked by `.github/scripts/site-pwa-integration-check.php`. It boots a WordPress with both plugins active and asks Site PWA's own classes what this plugin gave them:
- the `$api` each consumer here speaks against the one Site PWA sends;
- the screens registered for an administrator, with none refused, More's "My details" opening the Profile screen, and the Messages section in Preferences while Date Messages is on;
- both look sources under `ffc` and the dark root class;
- with `--write`, Public Design and Dark Mode read back through `Identity::current()` and `Scheme::current()`, with the options restored afterwards.

The one script runs in three places:

| Where | Against | Mode |
| --- | --- | --- |
| `site-pwa-integration.yml` here: pull requests, `develop`, daily | Site PWA's `develop` | `--write` |
| Site PWA's `ffc-integration.yml` | this repository's `develop` | `--write` |
| Both testes deploys, after the smoke | the real site | `--read-only`, skipped where Site PWA is not active |

The workflows are not required checks: a red run can be the other repository's `develop`, which no PR here fixes. A red run is a contract that moved. Fix the side that moved, or keep the old shape working until the other side ships ("Changing any of it", below). When the contract grows, the script grows with it in the same PR.

## Changing any of it

A rename, a removed property, a new `dark_mode` value or a changed hook signature on this side ships **together with** the matching change in Site PWA, or stays backward-compatible until it does: keep the old name working, or keep accepting the old shape. Say so in the PR, link the Site PWA issue, and update `SitePwaContractTest` in the same PR — a red test there means the contract moved, not that the test is stale. The app is installed on people's phones from a release, so the two plugins can run at different versions on the same site; never assume they ship together.
