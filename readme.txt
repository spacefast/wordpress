=== Spacefast ===
Contributors: spacefast
Tags: static site, headless cms, simply static, deployment
Requires at least: 6.5
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later

Publish a Simply Static export to Spacefast, or rebuild a headless site when WordPress content changes.

== Description ==

Spacefast gives WordPress two clean static publishing paths.

* Static WordPress: Simply Static generates the site and Spacefast publishes the generated files.
* Headless CMS: public WordPress content changes rebuild the connected Astro or other repository project.

Both modes include a manual action in Settings > Spacefast. Headless repository builds can also be started from the Spacefast dashboard.

== Installation ==

1. In Spacefast, configure the WordPress connection for the Space.
2. Download and activate this plugin.
3. Open Settings > Spacefast and paste the one-time connection.
4. Choose Static WordPress or Headless CMS and test the connection.
5. For Static WordPress, install and activate the free Simply Static plugin.

== Security ==

The connection key is limited to one Space. It can publish versions, read version status, and trigger the connected production repository. It cannot change Space settings or access another Space. Secrets are stored in a non-autoloaded option and are never shown again.

== Changelog ==

= 0.2.0 =
* Add direct Simply Static export publishing.
* Add explicit Static WordPress and Headless CMS modes.
* Keep automatic and manual repository rebuilds for headless projects.

= 0.1.0 =
* Add WordPress-triggered repository rebuilds.
