=== Spacefast ===
Contributors: spacefast
Tags: static site, headless cms, simply static, deployment
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.5.6
License: GPLv2 or later

Publish a Simply Static export to Spacefast, or rebuild a headless site when WordPress content changes.

== Description ==

Spacefast gives WordPress two clean static publishing paths.

* Static WordPress: Simply Static generates the site and Spacefast publishes the generated files.
* Headless CMS: public WordPress content changes rebuild the connected Astro or other repository project.

Both modes include a manual action in Settings > Spacefast. Headless repository builds can also be started from the Spacefast dashboard.

== Installation ==

1. Download and activate this plugin.
2. Open Settings > Spacefast and select Publish with Spacefast.
3. Install Simply Static from the same action when prompted, then authorize one Team in Spacefast.
4. Back in WordPress, create a Space with the suggested name or choose an existing one.
5. Wait for the first version to publish. The plugin shows the live URL when it is ready.

== Security ==

OAuth access is limited to the Team you authorize and the mode you choose. WordPress syncs only the selected Space and waits for a real first publish or build before showing the connection as complete. Tokens are stored in non-autoloaded options and are never displayed.

== Changelog ==

= 0.5.6 =
* Make static WordPress exports publicly readable by default while preserving an explicit sf.jsonc access policy.

= 0.5.5 =
* Declare and send each exported file's media type so managed hosting accepts binary uploads.

= 0.5.4 =
* Treat repeated same-second upload progress as a successful no-op instead of a sync-state conflict.

= 0.5.3 =
* Reload the WordPress option cache after a concurrent sync-state update so large publishes keep progressing.

= 0.5.2 =
* Keep large first-publish upload receipts intact for WordPress sites with thousands of files.

= 0.5.1 =
* Accept Spacefast's opaque Team identifiers when creating a Space from WordPress.

= 0.5.0 =
* Replace the mode-first wizard with a WordPress-first Publish with Spacefast journey.
* Install and activate Simply Static from the primary setup action when permitted.
* Create or choose a Space in WordPress, then verify the first live publish or build before showing Connected.
* Automatically publish static-site changes as well as headless content changes with one debounced delivery lane.
* Keep at most one headless build active and coalesce edits into one successor.
* Sync WordPress title, description, search visibility, and headless source settings without overwriting unrelated Space configuration.
* Put diagnostics and connection management behind secondary disclosures.

= 0.4.0 =
* Create and connect a new Space directly from the Static WordPress setup flow.
* Request Space management permission so the authorized Team can receive the new Space.

= 0.3.2 =
* Preserve the complete WordPress admin callback URL during Spacefast authorization.

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
