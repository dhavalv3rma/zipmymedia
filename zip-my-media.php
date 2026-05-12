<?php
/**
 * Plugin Name:       ZipMyMedia
 * Plugin URI:        https://github.com/dhavalv3rma/ZipMyMedia
 * Description:       Adds a "Download Selected" button to the WordPress Media Library when you're in bulk-select mode. Downloads selected files as a ZIP — works in both grid and list view.
 * Version:           1.3.0
 * Requires at least: 5.5
 * Requires PHP:      7.4
 * Author:            Dhaval Verma
 * Author URI:        https://dhavalverma.in
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       zipmymedia
 * Domain Path:       /languages
 *
 * @package ZipMyMedia
 */

// Don't allow direct access to this file
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants for use elsewhere if we extend this later
if (!defined('ZIPMYM_VERSION')) {
    define('ZIPMYM_VERSION', '1.3.0');
}
if (!defined('ZIPMYM_PLUGIN_DIR')) {
    define('ZIPMYM_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('ZIPMYM_PLUGIN_URL')) {
    define('ZIPMYM_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (!class_exists('ZIPMYM_Plugin')) {

class ZIPMYM_Plugin {

    public function __construct() {

        // Load translation files for self-hosted installs. Plugins on
        // wordpress.org get translations auto-loaded since WP 4.6.
        // Fires on `init` per WP 6.7 i18n timing guidelines.
        add_action('init', [$this, 'load_textdomain']);

        // Register / enqueue CSS + JS on the right admin pages
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        // Handle the AJAX request when user clicks "Download Selected"
        add_action('wp_ajax_zipmym_download_media', [$this, 'handle_download']);
    }

    /**
     * Load the plugin's translation files.
     * If someone translates the plugin into their language, the .mo files
     * go in the /languages folder and get loaded here.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'zipmymedia',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * Single entry point for asset enqueuing — routes to the right helper
     * based on which admin page we're on.
     */
    public function enqueue_admin_assets($hook) {
        if ($hook === 'upload.php') {
            $this->enqueue_media_library_assets();
        }
    }

    /**
     * Register and enqueue the Media Library button styles + behaviour.
     *
     * We use the empty-source ($src = false) pattern so we can ship the
     * inline CSS / JS via wp_add_inline_style() / wp_add_inline_script()
     * without needing physical asset files in the plugin folder.
     */
    private function enqueue_media_library_assets() {

        // ---- styles ----
        wp_register_style('zipmym-media', false, [], ZIPMYM_VERSION);
        wp_enqueue_style('zipmym-media');
        wp_add_inline_style('zipmym-media', $this->get_media_css());

        // ---- script ----
        wp_register_script('zipmym-media', false, [], ZIPMYM_VERSION, true);
        wp_enqueue_script('zipmym-media');

        // Pre-translate the JS strings so they can be localized
        $strings = [
            'download'    => __('Download Selected', 'zipmymedia'),
            'zipping'     => __('Zipping…', 'zipmymedia'),
            /* translators: %d: number of selected media items */
            'with_count'  => __('Download Selected (%d)', 'zipmymedia'),
            'none_select' => __('No media selected. Please select at least one item.', 'zipmymedia'),
            'tooltip'     => __('Download all selected media as a ZIP file', 'zipmymedia'),
        ];

        $data = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('zipmym_download_nonce'),
            'strings' => $strings,
        ];

        // Inject the data global BEFORE the main script body
        wp_add_inline_script(
            'zipmym-media',
            'var zipmymMediaData = ' . wp_json_encode($data) . ';',
            'before'
        );
        wp_add_inline_script('zipmym-media', $this->get_media_js());
    }

    /**
     * CSS for the "Download Selected" button on the Media Library page.
     * Matches WP's native admin button styling, just in blue so it stands
     * out as a positive action next to "Delete permanently".
     */
    private function get_media_css() {
        return <<<'CSS'
.zipmym-download-btn {
    background: #2271b1 !important;
    border-color: #2271b1 !important;
    color: #fff !important;
    margin-left: 8px !important;
    cursor: pointer;
}
.zipmym-download-btn:hover {
    background: #135e96 !important;
    border-color: #135e96 !important;
    color: #fff !important;
}
.zipmym-download-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}
CSS;
    }

    /**
     * JS for the Media Library page.
     *
     * Handles two scenarios:
     *   1. Grid view — injects a blue "Download Selected" button next to
     *      the "Delete permanently" button when bulk-select mode is on
     *   2. List view — adds a "Download Selected" option to the native
     *      bulk actions dropdown
     *
     * v1.3 fixes:
     *   1. RAF batching — MutationObserver fires are collapsed into one
     *      handler call per browser repaint (~16ms)
     *   2. Idempotent updates — we only set DOM properties if they
     *      actually changed, so re-running our handler is a no-op
     *   3. Skip our own mutations — if a mutation comes from inside
     *      our own button, we ignore it
     */
    private function get_media_js() {
        return <<<'JS'
(function() {
    'use strict';

    var AJAX_URL = zipmymMediaData.ajaxUrl;
    var NONCE    = zipmymMediaData.nonce;
    var STRINGS  = zipmymMediaData.strings;

    // ------------------------------------------------------------------
    // Figure out which media items the user has ticked/selected.
    // Works for both grid view (thumbnails) and list view (table rows).
    // ------------------------------------------------------------------
    function getSelectedIds() {

        // Try grid mode first — WP keeps a Backbone "selection" collection
        // on the media frame when bulk select is active
        if (typeof wp !== 'undefined' && wp.media && wp.media.frame) {
            try {
                var sel = wp.media.frame.state().get('selection');
                if (sel && sel.length) return sel.pluck('id');
            } catch(e) {
                // Frame might not have a selection yet, that's fine
            }
        }

        // Fallback for grid mode — check the DOM for thumbnails that
        // WordPress has marked with aria-checked="true" or .selected class
        var gridChecked = document.querySelectorAll(
            '.attachments-browser .attachment.selected, ' +
            '.attachments-browser .attachment[aria-checked="true"]'
        );
        if (gridChecked.length) {
            var ids = [];
            gridChecked.forEach(function(el) {
                var dataId = el.getAttribute('data-id');
                if (dataId) ids.push(parseInt(dataId, 10));
            });
            if (ids.length) return ids;
        }

        // List mode — just grab the checked checkboxes from the table
        var checked = document.querySelectorAll('input[name="media[]"]:checked');
        if (checked.length) {
            return Array.from(checked).map(function(cb) {
                return parseInt(cb.value, 10);
            });
        }

        return [];
    }

    // ------------------------------------------------------------------
    // Check if we're currently in bulk-select mode (grid view).
    //
    // When user clicks "Bulk Select", WordPress toggles a CSS class
    // on the media frame container. We check for that so we only
    // show the button during bulk selection — not during normal browsing.
    //
    // WP adds "mode-select" when bulk mode is on and removes it
    // (or switches to "mode-edit") when it's off.
    // ------------------------------------------------------------------
    function isBulkSelectActive() {
        var frame = document.querySelector('.media-frame');
        if (frame && frame.classList.contains('mode-select')) {
            return true;
        }
        return false;
    }

    // ------------------------------------------------------------------
    // Submit the selected IDs to our PHP handler via a hidden form.
    //
    // We use a form POST instead of fetch() because we need the browser
    // to actually download the ZIP file (fetch can't trigger a file save).
    // ------------------------------------------------------------------
    function triggerDownload(ids, btn) {

        // Show a loading state on the button
        if (btn) {
            btn.disabled    = true;
            btn.textContent = STRINGS.zipping;
        }

        // Build a hidden form with the attachment IDs and submit it
        var form    = document.createElement('form');
        form.method = 'POST';
        form.action = AJAX_URL;
        form.style.display = 'none';

        var fields = {
            action:   'zipmym_download_media',
            _wpnonce: NONCE,
            ids:      ids.join(',')
        };

        for (var key in fields) {
            var inp   = document.createElement('input');
            inp.type  = 'hidden';
            inp.name  = key;
            inp.value = fields[key];
            form.appendChild(inp);
        }

        document.body.appendChild(form);
        form.submit();

        // Reset the button after a few seconds — by then the download
        // has already started in the browser
        setTimeout(function() {
            if (btn) {
                btn.disabled    = false;
                btn.textContent = STRINGS.download;
            }
            form.remove();
        }, 3000);
    }

    // ------------------------------------------------------------------
    // IDEMPOTENT BUTTON STATE UPDATE
    //
    // This is the function that previously caused an infinite loop.
    // The fix: only touch the DOM if the value is actually different.
    // If we set textContent to the same string it already has, the
    // browser still treats it as a mutation and fires the observer.
    // So we check first, then only assign if different.
    // ------------------------------------------------------------------
    function updateButtonState() {
        var btn = document.querySelector('.media-frame .zipmym-download-btn');
        if (!btn) return;

        var count       = getSelectedIds().length;
        var newDisabled = (count === 0);
        var newText     = count > 0
            ? STRINGS.with_count.replace('%d', count)
            : STRINGS.download;

        // Only update if changed — this is what stops the infinite loop
        if (btn.disabled !== newDisabled) {
            btn.disabled = newDisabled;
        }
        if (btn.textContent !== newText) {
            btn.textContent = newText;
        }
    }

    // ------------------------------------------------------------------
    // Create the actual download button element
    // ------------------------------------------------------------------
    function createDownloadButton() {
        var btn       = document.createElement('button');
        btn.type      = 'button';
        btn.className = 'zipmym-download-btn button media-button';
        btn.textContent = STRINGS.download;
        btn.title     = STRINGS.tooltip;
        btn.disabled  = true;

        btn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();

            var ids = getSelectedIds();
            if (!ids.length) {
                alert(STRINGS.none_select);
                return;
            }
            triggerDownload(ids, btn);
        });

        return btn;
    }

    // ------------------------------------------------------------------
    // GRID MODE — add or remove the download button based on whether
    // bulk-select mode is currently active.
    // ------------------------------------------------------------------
    function handleGridMode() {
        var bulkActive  = isBulkSelectActive();
        var existingBtn = document.querySelector('.media-frame .zipmym-download-btn');

        // Bulk mode is ON but our button isn't there yet — add it
        if (bulkActive && !existingBtn) {

            var toolbars = document.querySelectorAll(
                '.media-toolbar-secondary, .media-frame-toolbar .media-toolbar'
            );

            toolbars.forEach(function(toolbar) {
                if (toolbar.querySelector('.zipmym-download-btn')) return;

                // Find the "Delete permanently" button — WP uses various
                // class names depending on version
                var deleteBtn = toolbar.querySelector(
                    '.delete-selected-button, ' +
                    '.button.media-button-delete, ' +
                    'button.button-primary.delete-selected, ' +
                    'button[class*="delete"]'
                );

                if (deleteBtn) {
                    deleteBtn.parentNode.insertBefore(
                        createDownloadButton(),
                        deleteBtn.nextSibling
                    );
                }
            });

            updateButtonState();
        }

        // Bulk mode is OFF but our button is still hanging around — remove it
        if (!bulkActive && existingBtn) {
            document.querySelectorAll('.zipmym-download-btn').forEach(function(btn) {
                btn.remove();
            });
        }

        // If bulk mode is on and button exists, update the selection count
        if (bulkActive && existingBtn) {
            updateButtonState();
        }
    }

    // ------------------------------------------------------------------
    // LIST MODE — add "Download Selected" to the bulk actions dropdown.
    //
    // WP's list mode uses a standard <select> dropdown for bulk actions.
    // We just add our option to it, and intercept the form submit when
    // our option is the selected one.
    // ------------------------------------------------------------------
    function injectListModeOption() {
        var selects = document.querySelectorAll('select[name="action"], select[name="action2"]');
        selects.forEach(function(sel) {
            if (sel.querySelector('option[value="zipmym_download"]')) return;

            var opt       = document.createElement('option');
            opt.value     = 'zipmym_download';
            opt.textContent = STRINGS.download;
            sel.appendChild(opt);
        });

        // Intercept the "Apply" button click when our action is selected
        var forms = document.querySelectorAll('#posts-filter, .wp-list-table');
        if (forms.length) {
            document.addEventListener('click', function(e) {
                if (!e.target.matches('#doaction, #doaction2')) return;

                var selectName = e.target.id === 'doaction' ? 'action' : 'action2';
                var selectEl   = document.querySelector('select[name="' + selectName + '"]');
                if (!selectEl || selectEl.value !== 'zipmym_download') return;

                e.preventDefault();

                var ids = getSelectedIds();
                if (!ids.length) {
                    alert(STRINGS.none_select);
                    return;
                }
                triggerDownload(ids, null);
            });
        }
    }

    // ------------------------------------------------------------------
    // RAF-BATCHED UPDATE SCHEDULER
    //
    // The MutationObserver can fire dozens of times per second on a
    // busy admin page. Instead of running our handler on every single
    // mutation, we use requestAnimationFrame to batch them — at most
    // one handler call per browser repaint (~16ms).
    //
    // If multiple mutations come in before the frame fires, they all
    // collapse into one handler call. This is the main fix for the
    // memory leak / page freeze issue.
    // ------------------------------------------------------------------
    var rafScheduled = false;
    function scheduleUpdate() {
        if (rafScheduled) return;
        rafScheduled = true;
        requestAnimationFrame(function() {
            rafScheduled = false;
            handleGridMode();
        });
    }

    // ------------------------------------------------------------------
    // MUTATION OBSERVER — watches for relevant DOM changes.
    //
    // We filter out mutations that came from inside our own button
    // (its text changes when selection count updates). Without this
    // filter, our own text update would re-trigger the observer and
    // — combined with non-idempotent updates — could cause the loop
    // we hit earlier.
    // ------------------------------------------------------------------
    var observer = new MutationObserver(function(mutations) {

        // Check if any mutation came from outside our own button.
        // If every mutation is from our button, ignore them all.
        var hasExternalMutation = false;
        for (var i = 0; i < mutations.length; i++) {
            var target = mutations[i].target;
            // Walk up from the target to see if it's inside our button
            var isOurs = false;
            var node   = target;
            while (node && node !== document.body) {
                if (node.classList && node.classList.contains('zipmym-download-btn')) {
                    isOurs = true;
                    break;
                }
                node = node.parentNode;
            }
            if (!isOurs) {
                hasExternalMutation = true;
                break;
            }
        }

        if (hasExternalMutation) {
            scheduleUpdate();
        }
    });

    // Start observing — we need subtree + childList to catch WP's
    // Backbone-driven UI changes, and attributes to catch aria-checked
    // toggles on grid thumbnails
    observer.observe(document.body, {
        childList:  true,
        subtree:    true,
        attributes: true,
        attributeFilter: ['class', 'aria-checked']
    });

    // Clean up on page unload so the observer doesn't linger
    window.addEventListener('beforeunload', function() {
        observer.disconnect();
    });

    // ------------------------------------------------------------------
    // INIT — run once on page load, then retry a few times because
    // WP's media grid loads asynchronously via Backbone
    // ------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function() {
        handleGridMode();
        injectListModeOption();
    });

    // Retries — WP media grid loads asynchronously
    [500, 1500, 3000, 5000].forEach(function(ms) {
        setTimeout(handleGridMode, ms);
    });
})();
JS;
    }
 
    /**
     * Handle the download request from the browser.
     *
     * It grabs the selected files, zips them up, and streams the ZIP
     * back to the browser. Single files get sent directly without zipping.
     */
    public function handle_download() {

        // Make sure the request is legit (nonce check prevents CSRF attacks)
        if (!check_ajax_referer('zipmym_download_nonce', '_wpnonce', false)) {
            wp_die(
                esc_html__('Security check failed.', 'zipmymedia'),
                esc_html__('Error', 'zipmymedia'),
                ['response' => 403]
            );
        }

        // Only people who can upload files should be able to download them
        if (!current_user_can('upload_files')) {
            wp_die(
                esc_html__('Permission denied.', 'zipmymedia'),
                esc_html__('Error', 'zipmymedia'),
                ['response' => 403]
            );
        }

        // Parse the comma-separated list of attachment IDs from the form
        $raw_ids = isset($_POST['ids']) ? sanitize_text_field(wp_unslash($_POST['ids'])) : '';
        if (empty($raw_ids)) {
            wp_die(
                esc_html__('No media IDs provided.', 'zipmymedia'),
                esc_html__('Error', 'zipmymedia'),
                ['response' => 400]
            );
        }

        // Convert to an array of integers and remove any zeros/garbage
        $ids = array_filter(array_map('intval', explode(',', $raw_ids)));
        if (empty($ids)) {
            wp_die(
                esc_html__('Invalid media IDs.', 'zipmymedia'),
                esc_html__('Error', 'zipmymedia'),
                ['response' => 400]
            );
        }

        // Long-running download — make sure PHP doesn't kill us mid-stream.
        // function_exists() handles hosts that have set_time_limit in
        // disable_functions, so the @ suppression isn't needed.
        wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        // If they only selected one file, just send it directly —
        // no need to create a whole ZIP for a single file
        if (count($ids) === 1) {
            $file_path = $this->get_attachment_path($ids[0]);
            if (!$file_path) {
                wp_die(
                    esc_html__('The selected media file could not be found on disk.', 'zipmymedia'),
                    esc_html__('Error', 'zipmymedia'),
                    ['response' => 404]
                );
            }

            $filename = sanitize_file_name(basename($file_path));
            $type     = wp_check_filetype($file_path);
            $mime     = !empty($type['type']) ? $type['type'] : 'application/octet-stream';

            $this->stream_file($file_path, $filename, $mime);
            exit;
        }

        // Multiple files — we need to ZIP them together.
        // ZipArchive is a PHP extension that most hosts have enabled
        if (!class_exists('ZipArchive')) {
            wp_die(
                esc_html__(
                    'The ZipArchive PHP extension is required but not available on your server. Ask your hosting provider to enable it.',
                    'zipmymedia'
                ),
                esc_html__('Error', 'zipmymedia'),
                ['response' => 500]
            );
        }

        // Create the ZIP in the uploads folder (we'll delete it right after sending).
        // Random suffix prevents two simultaneous downloads from colliding and
        // makes the temp file unguessable while it briefly exists on disk.
        $upload_dir = wp_upload_dir();
        $zip_name   = 'media-download-' . gmdate('Y-m-d-His') . '.zip';
        $zip_path   = trailingslashit($upload_dir['basedir'])
            . 'zipmym-' . wp_generate_password(12, false) . '-' . $zip_name;

        // Guarantee cleanup even if the client disconnects mid-stream
        register_shutdown_function(function () use ($zip_path) {
            if (file_exists($zip_path)) {
                wp_delete_file($zip_path);
            }
        });

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die(
                esc_html__('Could not create ZIP file.', 'zipmymedia'),
                esc_html__('Error', 'zipmymedia'),
                ['response' => 500]
            );
        }

        $added      = 0;
        $used_names = [];

        foreach ($ids as $id) {
            $file_path = $this->get_attachment_path($id);

            // Skip if the file doesn't exist on disk or the ID isn't an attachment
            if (!$file_path) {
                continue;
            }

            // Handle duplicate filenames — if two attachments have the same
            // name (e.g. two "image.jpg"), append the ID so they don't overwrite
            // each other inside the ZIP
            $name = basename($file_path);
            if (isset($used_names[$name])) {
                $ext  = pathinfo($name, PATHINFO_EXTENSION);
                $base = pathinfo($name, PATHINFO_FILENAME);
                $name = $base . '-' . $id . ($ext ? '.' . $ext : '');
            }
            $used_names[$name] = true;

            $zip->addFile($file_path, $name);
            $added++;
        }

        $zip->close();

        // If none of the files were found on disk, error out (shutdown handler cleans up)
        if ($added === 0) {
            wp_die(
                esc_html__('None of the selected media files could be found on disk.', 'zipmymedia'),
                esc_html__('Error', 'zipmymedia'),
                ['response' => 404]
            );
        }

        $this->stream_file($zip_path, sanitize_file_name($zip_name), 'application/zip');
        exit;
    }

    /**
     * Resolve an attachment ID to a verified on-disk file path.
     *
     * Returns the absolute path only when:
     *   - the post exists and is of type "attachment"
     *   - get_attached_file() returns a path
     *   - the file actually exists on disk
     *
     * The post-type check stops anyone with the `upload_files` cap from
     * coaxing the handler into reading non-attachment files via a forged
     * post ID — defense in depth even though `_wp_attached_file` meta
     * should only exist for real attachments.
     */
    private function get_attachment_path($id) {
        $id = absint($id);
        if (!$id || 'attachment' !== get_post_type($id)) {
            return false;
        }
        $file_path = get_attached_file($id);
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }
        return $file_path;
    }

    /**
     * Stream a file to the browser as a download.
     *
     * Clears any buffered output first so stray notices/warnings can't corrupt
     * the binary payload, then reads in 8 KB chunks instead of slurping the
     * whole file into memory via readfile().
     */
    private function stream_file($path, $filename, $mime) {

        // Drop any output buffered by WP / other plugins so it doesn't
        // get mixed into the binary stream
        while (ob_get_level()) {
            ob_end_clean();
        }

        // RFC 5987 encoded filename — survives quotes, CR/LF, and unicode safely.
        // The plain ASCII fallback is kept for older clients.
        $ascii_fallback = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        nocache_headers();
        header('Content-Type: ' . $mime);
        header(
            'Content-Disposition: attachment; '
            . 'filename="' . $ascii_fallback . '"; '
            . "filename*=UTF-8''" . rawurlencode($filename)
        );
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');

        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return;
        }
        while (!feof($fh)) {
            echo fread($fh, 8192);
            flush();
        }
        fclose($fh);
    }
}

} // end if (!class_exists)

// Fire it up
if (class_exists('ZIPMYM_Plugin')) {
    new ZIPMYM_Plugin();
}
