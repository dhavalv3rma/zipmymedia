=== ZipMyMedia ===
Contributors: dhaval Verma
Tags: media, media library, download, bulk, zip
Requires at least: 5.5
Tested up to: 6.9
Stable tag: 1.3.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds a "Download Selected" button to the WordPress Media Library so you can bulk-download multiple media files as a single ZIP.

== Description ==

WordPress lets you bulk-select media files in the Media Library, but only to delete them. **ZipMyMedia** adds the missing piece: a "Download Selected" button that zips up all the selected files and sends them to your browser as a single download.

It works seamlessly in both grid view and list view, and uses WordPress's native UI patterns so the button feels like a built-in feature.

= Features =

* Adds a "Download Selected" button next to "Delete permanently" in grid view bulk-select mode
* Adds a "Download Selected" option in the bulk actions dropdown in list view
* Button stays disabled until you actually select something, with a live count of selected items
* Single files download directly (no unnecessary zipping)
* Multiple files are bundled into a timestamped ZIP archive
* Handles duplicate filenames automatically (appends attachment IDs)
* Uses nonce verification and capability checks for security
* No settings page, no database tables — install and it just works

= Use cases =

* Migrating media from one site to another
* Downloading a batch of client photos in one go
* Backing up specific media files without grabbing the entire uploads folder
* Pulling assets for offline editing

= Requirements =

* WordPress 5.5 or higher
* PHP 7.4 or higher
* PHP `ZipArchive` extension (available on virtually all hosts by default)

== Installation ==

= Automatic install =

1. Go to **Plugins > Add New** in your WordPress admin
2. Search for "ZipMyMedia"
3. Click **Install Now**, then **Activate**
4. Open your **Media Library** — the new button is ready to use

= Manual install =

1. Download the plugin ZIP file
2. Go to **Plugins > Add New > Upload Plugin** in your WordPress admin
3. Choose the ZIP file and click **Install Now**
4. Click **Activate Plugin**

= How to use it =

**Grid view:**
1. Go to **Media > Library**
2. Click the **Bulk Select** button in the top toolbar
3. Click on the media items you want to download
4. Click the blue **Download Selected** button next to "Delete permanently"

**List view:**
1. Switch to list view from the toggle near the search bar
2. Tick the checkboxes next to the items you want to download
3. Choose **Download Selected** from the **Bulk actions** dropdown
4. Click **Apply**

== Frequently Asked Questions ==

= Where do the downloaded files come from? =

The plugin downloads the original files from your `/wp-content/uploads/` folder — the same files WordPress stored when you uploaded them.

= Are image thumbnails included in the ZIP? =

No. Only the original full-size files are included. The smaller thumbnail versions (e.g. `image-150x150.jpg`) are skipped.

= Is there a file size or count limit? =

The plugin itself doesn't enforce a limit, but your server's PHP `memory_limit`, `max_execution_time`, and available disk space will. For very large batches (hundreds of MB or more), make sure your hosting allows long-running processes.

= Where is the ZIP file created? =

The ZIP is temporarily created in your `/wp-content/uploads/` folder, streamed to your browser, and then immediately deleted. Nothing is left behind on your server.

= Who can use the download button? =

Any user with the `upload_files` capability — by default that's Authors, Editors, and Administrators. The same group of users who can upload media can download it in bulk.

= Does this work on multisite? =

Yes. Each subsite's Media Library has its own button, and downloads are scoped to that subsite's uploads.

= Will this conflict with other media library plugins? =

The plugin is intentionally non-invasive — it only adds a button and an AJAX handler. It shouldn't conflict with other plugins that modify the media library, but if you run into issues, please open a support ticket.

= Why does the button briefly show "Zipping…" after clicking? =

Building the ZIP takes a moment for larger batches. Once the browser starts the download, the button resets.

== Screenshots ==

1. The "Download Selected" button appears next to "Delete permanently" when you bulk-select media in grid view.
2. In list view, "Download Selected" is added to the standard bulk actions dropdown.
3. The button shows a live count of selected items and stays disabled until at least one is picked.

== Changelog ==

= 1.3.0 =
* Fixed an infinite-loop bug in the MutationObserver that caused the browser to slow down on busy admin pages
* Added requestAnimationFrame batching for DOM mutation handling
* Idempotent button state updates to prevent self-triggering observer callbacks
* Skip mutations originating from inside the plugin's own button
* Added cleanup on page unload to disconnect the observer

= 1.2.0 =
* Button now starts disabled and only enables when items are selected
* Live selection count shown in the button label, e.g. "Download Selected (3)"
* Removed dashicons icon (was rendering as a blank box on some setups) in favor of plain text

= 1.1.0 =
* Button now only appears when bulk-select mode is active (was previously visible all the time)
* Improved CSS to match WordPress native button styling
* Better DOM observation to react to mode toggles

= 1.0.0 =
* Initial release
* Grid view download button
* List view bulk action option
* Single-file direct download, multi-file ZIP archive

== Upgrade Notice ==

= 1.3.0 =
Important performance fix. If you're on 1.2.0, please upgrade — this version eliminates a browser-freezing bug.

= 1.2.0 =
Better UX: button is now disabled until you select something, with a live count.

= 1.1.0 =
Fixes the button appearing outside of bulk-select mode.
