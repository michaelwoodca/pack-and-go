=== Pack & Go – Easy Site Migration ===
Contributors: notrouble
Tags: migration, import, export, portfolio, custom post types
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Pack up your WordPress posts, portfolio, and custom content and move it into your NoTrouble profile — no trouble.

== Description ==

Pack & Go reads your WordPress content **from the inside** — including custom post types and custom fields from ACF, Toolset, and Meta Box that the WordPress REST API normally hides — then helps you map it onto your NoTrouble profile and moves it across in a few clicks.

You stay in control the whole way:

* **Connect securely.** Sign in to NoTrouble and authorize the move — no passwords stored, revocable any time.
* **See what you're moving.** Pack & Go discovers your post types and their fields and suggests a sensible mapping, and shows you at a glance what's already synced, what's changed, and what's new.
* **Map it your way.** Match each post type to a NoTrouble section, and each field to the right place.
* **Move all, or pick and choose.** Push everything in one click, or hand-pick exactly which posts and pages go across from a simple checklist.
* **Land it as drafts.** Everything arrives unpublished so you can review before it goes live.

Re-running is safe: Pack & Go remembers what it already moved, so pushing again updates what changed and skips what's already there — no duplicates.

Media (images and videos) comes along too — Pack & Go hands NoTrouble the URLs and it fetches them for you.

Need a hand at any step? Every screen links straight to the [NoTrouble help centre](https://notrouble.com/help).

== Installation ==

1. Download the latest release from https://notrouble.com/pack-and-go/download
2. In WordPress, go to *Plugins → Add New → Upload Plugin*, choose the downloaded `pack-and-go.zip`, and install it. (Or unzip it into `/wp-content/plugins/`.)
3. Activate the plugin through the *Plugins* screen in WordPress.
4. Open *Pack & Go* in the admin menu and click **Connect to NoTrouble**.

Once installed, WordPress will notify you when a new version is available and can update it in one click.

== Frequently Asked Questions ==

= Does this change my WordPress site? =

No. Pack & Go only reads from WordPress; it never edits or deletes your WordPress content.

= What happens to my custom fields? =

Pack & Go reads ACF, Toolset, and Meta Box field definitions directly, so your structured content maps cleanly onto NoTrouble — not just the visible post body.

== Changelog ==

= 0.2.2 =
* Re-importing no longer duplicates videos or gallery images: media is replaced, not piled up.
* Media that fails to import (for example, hitting a plan limit) is retried automatically on the next push.
* Re-imports skip media that hasn't changed, so editing text no longer re-uploads or re-processes images and video.
* Clearer messages that say whether it was a video or an image that could not be imported.

= 0.2.1 =
* Reorder the fields and custom text in the post body with up/down controls.
* Imported sections now show each post's full content on its own page, so nothing is hidden.
* Keep each post's original publish date instead of dating everything to the import time.

= 0.2.0 =
* Import WooCommerce and WooCommerce Subscriptions pricing (regular, sale, and subscription price).
* Build the post body from multiple fields, and add your own headings or text to label them.
* Much faster imports that stay within rate limits by sending each batch in a single request.
* Migrate uploaded videos, not just video links (requires the matching NoTrouble update).
* Galleries can carry multiple images per post.
* Cleaner data: prices, dates, addresses, and yes/no fields are converted to the right format.
* Import multiple links per item, and get a heads-up when a required field isn't mapped.

= 0.1.0 =
* First release.
* Discovers WordPress post types and their fields, including ACF, Toolset, and Meta Box.
* Secure connect to NoTrouble with no stored passwords, revocable any time.
* Guided step-by-step flow with a single page to see your content and its status.
* Choose to move everything or hand-pick items from a checklist with live sync status.
* Remembers what's already been moved, so re-running updates changes and skips duplicates.
* Progress with resume, cancel, and accessible status announcements.
* Troubleshooting screen to clear a stuck import, reset sync history, or reset setup.
