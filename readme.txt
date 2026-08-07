=== Spacefast ===
Contributors: spacefast
Tags: static site, headless cms, simply static, deployment
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.3.1
License: GPLv2 or later

Publish a Simply Static export to Spacefast, or rebuild a headless site when WordPress content changes.

== Description ==

Spacefast gives WordPress two clean static publishing paths.

* Static WordPress: Simply Static generates the site and Spacefast publishes the generated files.
* Headless CMS: public WordPress content changes rebuild the connected Astro or other repository project.

Both modes include a manual action in Settings > Spacefast. Headless repository builds can also be started from the Spacefast dashboard.

== Installation ==

1. Download and activate this plugin.
2. Open Settings > Spacefast and choose Static WordPress or Headless CMS.
3. Continue to Spacefast, sign in, and authorize one Team.
4. Back in WordPress, choose the Space to publish or rebuild.
5. For Static WordPress, use the provided link to install or activate Simply Static.

== Security ==

OAuth access is limited to the Team you authorize and the mode you choose. WordPress then verifies the selected Space before showing Connected. Tokens are stored in non-autoloaded options and are never displayed.

== Changelog ==

= 0.3.1 =
* Debounce automatic headless builds until public content has been quiet for 60 seconds.
* Keep manual builds immediate and avoid duplicate scheduled delivery afterward.

= 0.3.0 =
* Replace copied API keys with Team-scoped OAuth 2 authorization and Space selection in WordPress.
* Add refresh-token rotation and remote revocation on disconnect.
* Add one-click Simply Static setup, human delivery states, WP-Cron health, and deletion-safe static publishing.

= 0.2.0 =
* Add direct Simply Static export publishing.
* Add explicit Static WordPress and Headless CMS modes.
* Keep automatic and manual repository rebuilds for headless projects.

= 0.1.0 =
* Add WordPress-triggered repository rebuilds.
