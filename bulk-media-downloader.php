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
define('BMD_VERSION', '1.3.0');
define('BMD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('BMD_PLUGIN_URL', plugin_dir_url(__FILE__));

class Bulk_Media_Downloader {

    public function __construct() {

        // Load translation files (if anyone ever translates this plugin)
        add_action('init', [$this, 'load_textdomain']);

        // Hook into the media library page footer to add our JS + CSS
        add_action('admin_footer-upload.php', [$this, 'inject_download_button_script']);

        // Handle the AJAX request when user clicks "Download Selected"
        add_action('wp_ajax_bmd_download_media', [$this, 'handle_download']);

        // Show a "Leave a review?" modal when someone deactivates the plugin
        add_action('admin_footer-plugins.php', [$this, 'inject_deactivation_modal']);
    }

    /**
     * Load the plugin's translation files.
     * If someone translates the plugin into their language, the .mo files
     * go in the /languages folder and get loaded here.
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'bulk-media-downloader',
            false,
            dirname(plugin_basename(__FILE__)) . '/languages'
        );
    }

    /**
     * This puts our CSS and JavaScript into the Media Library admin page.
     * The JS watches for bulk-select mode and adds the download button
     * only when that mode is active. The button stays disabled until
     * the user actually selects some media items.
     */
    public function inject_download_button_script() {
        $ajax_url = admin_url('admin-ajax.php');
        $nonce    = wp_create_nonce('bmd_download_nonce');

        // Pre-translate the JS strings so they can be localized
        $strings = [
            'download'    => __('Download Selected', 'bulk-media-downloader'),
            'zipping'     => __('Zipping…', 'bulk-media-downloader'),
            'with_count'  => __('Download Selected (%d)', 'bulk-media-downloader'),
            'none_select' => __('No media selected. Please select at least one item.', 'bulk-media-downloader'),
            'tooltip'     => __('Download all selected media as a ZIP file', 'bulk-media-downloader'),
        ];
        ?>
        <style>
            /*
             * Style the download button to match WP's native "Delete permanently"
             * button. We use the same WP button classes and just add a blue
             * background so it stands out as a positive action.
             */
            .bmd-download-btn {
                background: #2271b1 !important;
                border-color: #2271b1 !important;
                color: #fff !important;
                margin-left: 8px !important;
                cursor: pointer;
            }
            .bmd-download-btn:hover {
                background: #135e96 !important;
                border-color: #135e96 !important;
                color: #fff !important;
            }
            .bmd-download-btn:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
        </style>

        <script>
        (function() {
            'use strict';

            // ==================================================================
            // ZipMyMedia — Client-side JavaScript
            //
            // This script handles two scenarios:
            //   1. Grid view — injects a blue "Download Selected" button next to
            //      the "Delete permanently" button when bulk-select mode is on
            //   2. List view — adds a "Download Selected" option to the native
            //      bulk actions dropdown
            //
            // v1.3 fixes:
            //   1. RAF batching — MutationObserver fires are collapsed into one
            //      handler call per browser repaint (~16ms)
            //   2. Idempotent updates — we only set DOM properties if they
            //      actually changed, so re-running our handler is a no-op
            //   3. Skip our own mutations — if a mutation comes from inside
            //      our own button, we ignore it
            // ==================================================================

            var AJAX_URL = <?php echo wp_json_encode($ajax_url); ?>;
            var NONCE    = <?php echo wp_json_encode($nonce); ?>;
            var STRINGS  = <?php echo wp_json_encode($strings); ?>;

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
                    action:   'bmd_download_media',
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
                var btn = document.querySelector('.media-frame .bmd-download-btn');
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
                btn.className = 'bmd-download-btn button media-button';
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
                var existingBtn = document.querySelector('.media-frame .bmd-download-btn');

                // Bulk mode is ON but our button isn't there yet — add it
                if (bulkActive && !existingBtn) {

                    var toolbars = document.querySelectorAll(
                        '.media-toolbar-secondary, .media-frame-toolbar .media-toolbar'
                    );

                    toolbars.forEach(function(toolbar) {
                        if (toolbar.querySelector('.bmd-download-btn')) return;

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
                    document.querySelectorAll('.bmd-download-btn').forEach(function(btn) {
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
                    if (sel.querySelector('option[value="bmd_download"]')) return;

                    var opt       = document.createElement('option');
                    opt.value     = 'bmd_download';
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
                        if (!selectEl || selectEl.value !== 'bmd_download') return;

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
                        if (node.classList && node.classList.contains('bmd-download-btn')) {
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
        </script>
        <?php
    }

    /**
     * Inject a "Leave a review?" modal on the Plugins page.
     *
     * When someone clicks "Deactivate" for our plugin, instead of
     * deactivating immediately we pop up a friendly modal asking if
     * they'd like to leave a review first. Two buttons:
     *   1. "Leave a Review" — opens the WP.org review page in a new tab,
     *      then deactivates the plugin
     *   2. "Deactivate" — just deactivates with a thank-you message
     *
     * This only fires on the Plugins admin page (admin_footer-plugins.php).
     */
    public function inject_deactivation_modal() {
        // We need the plugin's basename to find its "Deactivate" link in the DOM
        $plugin_basename = plugin_basename(__FILE__);

        // Translatable strings for the modal
        $strings = [
            'title'       => __('We\'re sorry to see you go!', 'bulk-media-downloader'),
            'message'     => __('If you enjoyed ZipMyMedia, would you mind leaving a quick review? It really helps other WordPress users find the plugin.', 'bulk-media-downloader'),
            'review_btn'  => __('Leave a Review ★', 'bulk-media-downloader'),
            'proceed_btn' => __('Deactivate', 'bulk-media-downloader'),
            'thank_you'   => __('Thank you for using ZipMyMedia!', 'bulk-media-downloader'),
        ];

        // The WP.org review URL — users land directly on the review tab
        $review_url = 'https://wordpress.org/support/plugin/bulk-media-downloader/reviews/#new-post';
        ?>

        <!-- Deactivation feedback modal — hidden by default -->
        <div id="bmd-deactivation-modal" style="display:none;">
            <div class="bmd-modal-overlay"></div>
            <div class="bmd-modal-content">
                <div class="bmd-modal-icon">
                    <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="24" cy="24" r="23" stroke="#2271b1" stroke-width="2" fill="#f0f6fc"/>
                        <path d="M24 13l3.09 6.26L34 20.27l-5 4.87 1.18 6.88L24 28.77l-6.18 3.25L19 25.14l-5-4.87 6.91-1.01L24 13z" fill="#2271b1"/>
                    </svg>
                </div>
                <h2 class="bmd-modal-title"><?php echo esc_html($strings['title']); ?></h2>
                <p class="bmd-modal-message"><?php echo esc_html($strings['message']); ?></p>
                <div class="bmd-modal-buttons">
                    <a href="<?php echo esc_url($review_url); ?>"
                       target="_blank"
                       rel="noopener noreferrer"
                       id="bmd-review-btn"
                       class="button button-primary">
                        <?php echo esc_html($strings['review_btn']); ?>
                    </a>
                    <button type="button"
                            id="bmd-proceed-btn"
                            class="button button-secondary">
                        <?php echo esc_html($strings['proceed_btn']); ?>
                    </button>
                </div>
                <p class="bmd-modal-thankyou" id="bmd-thankyou" style="display:none;">
                    <?php echo esc_html($strings['thank_you']); ?>
                </p>
            </div>
        </div>

        <style>
            /*
             * Modal overlay — covers the entire screen with a semi-transparent
             * dark background so the modal feels focused
             */
            .bmd-modal-overlay {
                position: fixed;
                top: 0; left: 0; right: 0; bottom: 0;
                background: rgba(0, 0, 0, 0.6);
                z-index: 100000;
            }

            /*
             * The modal box itself — centered on screen, white card with
             * rounded corners and a subtle shadow
             */
            .bmd-modal-content {
                position: fixed;
                top: 50%;
                left: 50%;
                transform: translate(-50%, -50%);
                background: #fff;
                border-radius: 12px;
                padding: 40px 36px 32px;
                max-width: 440px;
                width: 90%;
                z-index: 100001;
                text-align: center;
                box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
                animation: bmd-modal-appear 0.25s ease-out;
            }

            @keyframes bmd-modal-appear {
                from {
                    opacity: 0;
                    transform: translate(-50%, -48%);
                }
                to {
                    opacity: 1;
                    transform: translate(-50%, -50%);
                }
            }

            /* Star icon at the top */
            .bmd-modal-icon {
                margin-bottom: 16px;
            }

            /* Title */
            .bmd-modal-title {
                font-size: 20px;
                font-weight: 600;
                color: #1d2327;
                margin: 0 0 12px;
                line-height: 1.3;
            }

            /* Body text */
            .bmd-modal-message {
                font-size: 14px;
                color: #50575e;
                line-height: 1.6;
                margin: 0 0 24px;
            }

            /* Button row — stacked on small screens, side by side on larger */
            .bmd-modal-buttons {
                display: flex;
                gap: 12px;
                justify-content: center;
                flex-wrap: wrap;
            }

            /* Review button — blue primary, matches WP admin styling */
            .bmd-modal-buttons .button-primary {
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
                padding: 8px 24px;
                font-size: 14px;
                text-decoration: none;
                border-radius: 4px;
                line-height: 1.5;
                min-width: 160px;
                text-align: center;
            }
            .bmd-modal-buttons .button-primary:hover {
                background: #135e96;
                border-color: #135e96;
                color: #fff;
            }

            /* Proceed/Deactivate button — subtle secondary style */
            .bmd-modal-buttons .button-secondary {
                background: #f0f0f1;
                border-color: #c3c4c7;
                color: #50575e;
                padding: 8px 24px;
                font-size: 14px;
                border-radius: 4px;
                line-height: 1.5;
                min-width: 130px;
                cursor: pointer;
            }
            .bmd-modal-buttons .button-secondary:hover {
                background: #e0e0e1;
                color: #1d2327;
            }

            /* Thank-you message — shown briefly before deactivation proceeds */
            .bmd-modal-thankyou {
                margin-top: 16px;
                font-size: 14px;
                color: #2271b1;
                font-weight: 500;
            }
        </style>

        <script>
        (function() {
            'use strict';

            // ------------------------------------------------------------------
            // DEACTIVATION MODAL
            //
            // We find the "Deactivate" link for our specific plugin in the
            // Plugins table, intercept the click, and show our modal instead.
            // The original deactivation URL is stored so we can redirect to
            // it when the user chooses to proceed.
            // ------------------------------------------------------------------

            // WP renders each plugin row with an ID based on the plugin file path.
            // The deactivate link lives inside that row under a span.deactivate
            var pluginSlug  = <?php echo wp_json_encode($plugin_basename); ?>;
            var deactivateUrl = '';

            document.addEventListener('DOMContentLoaded', function() {

                // Find the deactivate link for our plugin — WP puts it in a
                // <span class="deactivate"> inside the plugin's row
                var pluginRow = document.querySelector(
                    'tr[data-plugin="' + pluginSlug + '"], ' +
                    '#' + CSS.escape(pluginSlug).replace(/\//g, '\\/')
                );

                // Fallback: search all deactivate links and match by href
                var allDeactivateLinks = document.querySelectorAll('.deactivate a');
                var deactivateLink = null;

                allDeactivateLinks.forEach(function(link) {
                    if (link.href && link.href.indexOf('bulk-media-downloader') !== -1) {
                        deactivateLink = link;
                    }
                });

                if (!deactivateLink) return;

                // Save the original deactivation URL so we can use it later
                deactivateUrl = deactivateLink.href;

                // Intercept the click — show our modal instead of deactivating
                deactivateLink.addEventListener('click', function(e) {
                    e.preventDefault();
                    document.getElementById('bmd-deactivation-modal').style.display = 'block';
                });

                // "Leave a Review" button — opens WP.org in a new tab, then
                // deactivates the plugin after a short delay so the tab has
                // time to open
                document.getElementById('bmd-review-btn').addEventListener('click', function() {
                    // The link already opens in a new tab via target="_blank"
                    // Wait a moment, then deactivate
                    setTimeout(function() {
                        window.location.href = deactivateUrl;
                    }, 500);
                });

                // "Deactivate" button — show a thank-you message briefly,
                // then proceed with deactivation
                document.getElementById('bmd-proceed-btn').addEventListener('click', function() {
                    // Hide the buttons, show thank-you message
                    document.querySelector('.bmd-modal-buttons').style.display = 'none';
                    document.querySelector('.bmd-modal-message').style.display = 'none';
                    document.getElementById('bmd-thankyou').style.display = 'block';

                    // Wait 1.5 seconds so they can read the thank-you, then go
                    setTimeout(function() {
                        window.location.href = deactivateUrl;
                    }, 1500);
                });

                // Clicking the overlay (dark background) also closes the modal
                // without deactivating — in case they changed their mind
                document.querySelector('.bmd-modal-overlay').addEventListener('click', function() {
                    document.getElementById('bmd-deactivation-modal').style.display = 'none';
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Handle the download request from the browser.
     *
     * It grabs the selected files, zips them up, and streams the ZIP
     * back to the browser. Single files get sent directly without zipping.
     */
    public function handle_download() {

        // Make sure the request is legit (nonce check prevents CSRF attacks)
        if (!check_ajax_referer('bmd_download_nonce', '_wpnonce', false)) {
            wp_die(
                esc_html__('Security check failed.', 'bulk-media-downloader'),
                esc_html__('Error', 'bulk-media-downloader'),
                ['response' => 403]
            );
        }

        // Only people who can upload files should be able to download them
        if (!current_user_can('upload_files')) {
            wp_die(
                esc_html__('Permission denied.', 'bulk-media-downloader'),
                esc_html__('Error', 'bulk-media-downloader'),
                ['response' => 403]
            );
        }

        // Parse the comma-separated list of attachment IDs from the form
        $raw_ids = isset($_POST['ids']) ? sanitize_text_field(wp_unslash($_POST['ids'])) : '';
        if (empty($raw_ids)) {
            wp_die(
                esc_html__('No media IDs provided.', 'bulk-media-downloader'),
                esc_html__('Error', 'bulk-media-downloader'),
                ['response' => 400]
            );
        }

        // Convert to an array of integers and remove any zeros/garbage
        $ids = array_filter(array_map('intval', explode(',', $raw_ids)));
        if (empty($ids)) {
            wp_die(
                esc_html__('Invalid media IDs.', 'bulk-media-downloader'),
                esc_html__('Error', 'bulk-media-downloader'),
                ['response' => 400]
            );
        }

        // If they only selected one file, just send it directly —
        // no need to create a whole ZIP for a single file
        if (count($ids) === 1) {
            $file_path = get_attached_file($ids[0]);
            if ($file_path && file_exists($file_path)) {
                $filename = basename($file_path);
                $mime     = mime_content_type($file_path) ?: 'application/octet-stream';

                nocache_headers();
                header('Content-Type: ' . $mime);
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
                exit;
            }
        }

        // Multiple files — we need to ZIP them together.
        // ZipArchive is a PHP extension that most hosts have enabled
        if (!class_exists('ZipArchive')) {
            wp_die(
                esc_html__(
                    'The ZipArchive PHP extension is required but not available on your server. Ask your hosting provider to enable it.',
                    'bulk-media-downloader'
                ),
                esc_html__('Error', 'bulk-media-downloader'),
                ['response' => 500]
            );
        }

        // Create the ZIP in the uploads folder (we'll delete it right after sending)
        $upload_dir = wp_upload_dir();
        $zip_name   = 'media-download-' . gmdate('Y-m-d-His') . '.zip';
        $zip_path   = trailingslashit($upload_dir['basedir']) . $zip_name;

        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            wp_die(
                esc_html__('Could not create ZIP file.', 'bulk-media-downloader'),
                esc_html__('Error', 'bulk-media-downloader'),
                ['response' => 500]
            );
        }

        $added      = 0;
        $used_names = [];

        foreach ($ids as $id) {
            $file_path = get_attached_file($id);

            // Skip if the file doesn't exist on disk (maybe it was deleted manually)
            if (!$file_path || !file_exists($file_path)) {
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

        // If none of the files were found on disk, clean up and show an error
        if ($added === 0) {
            @unlink($zip_path);
            wp_die(
                esc_html__('None of the selected media files could be found on disk.', 'bulk-media-downloader'),
                esc_html__('Error', 'bulk-media-downloader'),
                ['response' => 404]
            );
        }

        // Send the ZIP to the browser as a download
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_name . '"');
        header('Content-Length: ' . filesize($zip_path));

        readfile($zip_path);

        // Clean up — delete the temp ZIP file so it doesn't pile up on the server
        @unlink($zip_path);
        exit;
    }
}

// Fire it up
new Bulk_Media_Downloader();
