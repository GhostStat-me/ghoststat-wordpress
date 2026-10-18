=== GhostStat Analytics ===
Contributors: ghoststat
Tags: analytics, statistics, privacy, cookieless, visitor intelligence
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookieless visitor intelligence: see who visits, why they buy and why they leave, right in WordPress. No cookie banner needed.

== Description ==

**Know who visits your site, without cookies, without a consent banner and without leaving WordPress.**

GhostStat measures your visitors without cookies, without local storage and without fingerprinting, so it needs no consent banner. This plugin connects your WordPress site to your GhostStat account and puts your numbers where you already work: on your WordPress dashboard.

= Set up in a minute =

Paste your GhostStat site key under Settings → GhostStat and save. That's it: every page is measured. You can also paste the whole GhostStat snippet; the plugin takes the key out of it. The tracker is loaded with `defer`, so it never holds up your page.

= New in 1.1: why visitors buy, and why they leave =

GhostStat reads behaviour, not cookies, and scores every visit. With your API key, Dashboard → GhostStat now shows:

* **Intent, frustration and attention**: three scores out of 100, with the change against the period before
* **The three scores over time**, in one chart
* **Who your visitors were**: casual browsers, skimmers, researchers, high-intent buyers and more
* **Where they were in their journey**: exploring, evaluating, comparing or ready to act
* **Pages where visitors struggle most**, each with a link to see why in GhostStat

= New in 1.1: software tracking =

Optional. Add a report key and this site tells GhostStat which WordPress version, PHP version, themes and plugins it runs, plus every install, update, activation and removal. GhostStat draws those changes on your traffic, so you see what changed right before visits dropped. Reporting fatal PHP errors, with the plugin or theme they came from, is a separate opt-in.

= Your statistics, inside WordPress =

Add an optional read-only API key and you get a full statistics page under **Dashboard → GhostStat**:

* **Visitors, visits, pageviews, average visit and bounce rate** at a glance
* **Online now**: how many people are on your site right now
* **Visitors over time**: a clear chart of visitors and pageviews
* **Top pages, top sources and countries**: what people read and where they come from
* **Devices and browsers**: phone, desktop or tablet; Chrome, Safari, Firefox and more
* **7 days, 30 days, 90 days or 12 months**: switch with one click

And a **dashboard widget** with the last 7 days (visitors, pageviews, bounce rate, a trend line and your top pages), the first thing you see when you sign in.

= Only real visitors =

Choose which signed-in roles are not measured. Administrators are left out by default, so your own clicks while editing don't fill up your numbers.

= Built to stay out of your way =

* **Fast:** one small tracker script, loaded deferred. In wp-admin, reports are cached for five minutes.
* **Self-contained:** charts are drawn by the plugin itself; no chart library or other code is loaded from elsewhere in wp-admin.
* **Works with page caching:** the tracker tag is the same for every visitor.
* **Safe keys:** the API key is read-only, stays on your server and is never sent to a browser.
* **Check now:** one button tells you whether GhostStat knows your site key and whether your API key works.
* **Translation-ready.**

= Want to go deeper? =

The plugin shows the essentials. The GhostStat app adds the full reports: heatmaps, session recordings, funnels, visitor journeys, software changes on your traffic and the GhostStat Guardian app for your phone. Every "See why in GhostStat" link opens the matching report for this site.

A GhostStat account is needed. There is a free plan and no card is needed to start: [ghoststat.me](https://ghoststat.me/).

== External services ==

This plugin relies on GhostStat (https://ghoststat.me/), a web analytics service run by GhostStat. It talks to the service in these ways:

1. **The tracker (on your public pages).** Once you have saved a site key, every page of your site loads the GhostStat tracker script from `https://cdn.ghoststat.me/gs.js`. Your visitors' browsers then send GhostStat the address and title of the page viewed, the referring page, UTM campaign parameters, screen size and pixel ratio, language, browser and operating system details (including browser client hints where the browser offers them), and a few device capabilities such as touch support and dark mode, to `https://api.ghoststat.me`. Plugins you switch on in your GhostStat account (for example heatmaps or session recordings) load their own scripts from `https://cdn.ghoststat.me` and send what that feature measures. GhostStat sets no cookies, stores nothing in the visitor's browser and does not fingerprint. The tracker's script tag also carries this plugin's version (for example `data-via="wordpress/1.1.2"`), which the browser passes on once per page load, so GhostStat knows the site uses this plugin. Signed-in users whose role you exclude are not measured, and nothing is loaded until a site key is saved.
2. **Your statistics (in wp-admin only).** If you save an API key, your WordPress server asks `https://api.ghoststat.me/stats/…` for this site's reports when someone opens Dashboard → GhostStat or the dashboard widget, and when you press "Check now" in the settings. These requests send the site key and the API key; answers are cached for five minutes. "Check now" also asks `https://api.ghoststat.me/collect/config` whether the site key exists. Like every request WordPress makes, these carry WordPress's standard User-Agent (the WordPress version and the site address).
3. **Software reports (only if you save a report key).** Your WordPress server sends `https://api.ghoststat.me/collect/software` the WordPress version, the PHP version, and for each installed plugin and theme its folder name, name, version, author, whether it is active and whether an update is available. It also sends each install, update, activation, deactivation and removal, with the old and new version and whether a person, an automatic update or WP-CLI did it. This happens once a day through WP-Cron, about a minute after a change, and when you press "Check now". Only if you also switch on error reporting: fatal PHP errors, with the error type, the first line of the message, the file path relative to WordPress and the line number. Nothing about your visitors or your users is sent. Remove the report key to stop all software reports.
4. **Plugin usage statistics (only with a site key saved).** Once you have saved a site key, so this site is connected to GhostStat, your WordPress server sends `https://api.ghoststat.me/collect/insights` once a day (when an administrator opens wp-admin) that the plugin is in use, with the plugin version, the WordPress version, the PHP version and a random install ID made at that moment, and a short notice when the plugin is activated, deactivated, updated or deleted. This shows GhostStat which versions of the plugin are in use. GhostStat looks up the country of the server from the request and does not store the IP address. No site address, nothing about your visitors or users. Without a site key nothing is sent; removing the site key stops it and forgets the install ID.

* GhostStat terms of service: https://ghoststat.me/terms
* GhostStat privacy policy: https://ghoststat.me/privacy

== Installation ==

1. Install and activate the plugin.
2. In the GhostStat app (https://app.ghoststat.me), add your site and copy the site key from **Install**.
3. In WordPress go to **Settings → GhostStat**, paste the site key and save. Every page is now measured.
4. Optional: in the GhostStat app open **Site settings → API keys**, create a key, and paste it under **Statistics in WordPress**. Dashboard → GhostStat and the dashboard widget now show your numbers.
5. Optional: in the GhostStat app open **Software Development → Connect**, make a report key, and paste it under **Software tracking**.
6. Press **Check now** to confirm the keys work.

== Frequently Asked Questions ==

= Do I need a cookie banner for GhostStat? =

GhostStat sets no cookies and stores nothing on the visitor's device, so it does not need cookie consent. Check your own legal situation if you are unsure.

= Is GhostStat free? =

There is a free plan, and no card is needed to start. See https://ghoststat.me/pricing for what each plan includes.

= Will it slow down my site? =

No. The tracker is one small script, loaded with `defer`, so your page shows first. The plugin adds nothing else to your public pages.

= Will my own visits be counted? =

Not while you are signed in as a role you excluded. Administrators are excluded by default; change this under Settings → GhostStat.

= Is the API key safe to store here? =

It is read-only: it can read this one site's reports and nothing else. It cannot change settings or sign in to GhostStat. It is kept in your WordPress database and only used server-side. You can revoke it at any time in the GhostStat app.

= Does it work with caching plugins? =

Yes. The tracker tag is the same for every visitor, so cached pages measure correctly. After saving the site key, clear your page cache once.

= What are intent, frustration and attention? =

Three scores out of 100 that GhostStat works out for every visit from behaviour, without cookies. Intent: how much visitors want what you offer. Frustration: how hard your site made it (lower is better). Attention: how much of your pages people really read.

= What does software tracking send? =

Only names and versions of WordPress, PHP, your plugins and themes, and when they change. Fatal PHP errors only if you switch that on. See "External services" for the full list. Nothing about visitors or users.

= Where are heatmaps, funnels and session recordings? =

Those are in the GhostStat app. Press **Open GhostStat** on the statistics page.

== Screenshots ==

1. Visitor Intelligence on Dashboard → GhostStat: intent, frustration and attention against the period before, the three scores over time, who your visitors were, where they were in their journey, and the pages where they struggle most.
2. The whole statistics page: visitors, visits, pageviews, average visit, bounce rate, visitors online, visitors over time, Visitor Intelligence and the top lists.
3. The dashboard widget: the last 7 days at a glance, right when you sign in.
4. Settings: site key, roles not counted, the read-only API key, software tracking with a report key, and the connection check.
5. Visitor Intelligence on a phone.

== Changelog ==

= 1.2.0 =
* Plugin usage statistics: with a site key saved, the plugin tells GhostStat once a day that it is in use (plugin, WordPress and PHP version, server country, a random install ID). No site address, no visitor or user data. See "External services".

= 1.1.3 =
* Settings: a "Get your report key" button opens Software Development → Connect in GhostStat for this site.

= 1.1.2 =
* The tracker tag now says it comes from this plugin and which version, so GhostStat can show which sites use it. See "External services".

= 1.1.1 =
* Fix: the score cards say "1 point" instead of "1 points".
* Fix: the intent, frustration and attention chart on phones: the top label was cut off and the dates touched the bottom line.

= 1.1.0 =
* New: Visitor Intelligence on Dashboard → GhostStat: intent, frustration and attention with the change against the period before, the three scores over time, who your visitors were, where they were in their journey, and the pages where they struggle most.
* New: "See why in GhostStat" links open the matching report for this site.
* New: optional software tracking with a report key: WordPress, PHP, plugins and themes, every install, update, activation and removal; fatal PHP errors as a separate opt-in.
* "Check now" also sends a software report when a report key is saved.
* Fix: uninstalling also removes the cached reports, so a reinstall never shows old numbers.

= 1.0.0 =
* First release: tracker on every page, roles not counted, statistics page, dashboard widget, connection check.

== Upgrade Notice ==

= 1.2.0 =
Plugin usage statistics for connected sites (plugin, WordPress and PHP version). No site address, no visitor data.

= 1.1.3 =
Intent, frustration and attention on your statistics page, optional software tracking with a one-click way to get your report key.

= 1.1.2 =
Intent, frustration and attention on your statistics page, optional software tracking, and small fixes for phones.

= 1.1.1 =
Intent, frustration and attention on your statistics page, optional software tracking, and small fixes for phones.

= 1.1.0 =
Intent, frustration and attention on your statistics page, and optional software tracking.

= 1.0.0 =
First release.
