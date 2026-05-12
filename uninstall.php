<?php
/**
 * ZipMyMedia — Uninstall script
 *
 * This file runs only when the plugin is being deleted from the Plugins screen
 * (not on regular deactivation). It cleans up anything the plugin may have
 * left behind on the server.
 *
 * This plugin doesn't store options in the database or create custom tables,
 * so there's nothing to clean from there. But just in case any temp ZIP files
 * got stuck in the uploads folder (e.g. a download was interrupted mid-stream),
 * we sweep them up here.
 *
 * @package ZipMyMedia
 */

// Make sure WordPress called this file — direct access is blocked
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Sweep up any leftover ZIP files from interrupted downloads.
 * Matches both the legacy "media-download-*.zip" name and the new
 * "zipmym-<token>-media-download-*.zip" name.
 */
function zipmym_uninstall_cleanup_site() {
    $upload_dir = wp_upload_dir();
    if (empty($upload_dir['basedir'])) {
        return;
    }
    $basedir = trailingslashit($upload_dir['basedir']);

    foreach (['media-download-*.zip', 'zipmym-*-media-download-*.zip'] as $pattern) {
        $leftover = glob($basedir . $pattern);
        if (!is_array($leftover)) {
            continue;
        }
        foreach ($leftover as $file) {
            if (file_exists($file)) {
                wp_delete_file($file);
            }
        }
    }
}

// On multisite, iterate every blog so we clean each site's uploads.
if (is_multisite()) {
    $site_ids = get_sites(['fields' => 'ids', 'number' => 0]);
    foreach ($site_ids as $site_id) {
        switch_to_blog($site_id);
        zipmym_uninstall_cleanup_site();
        restore_current_blog();
    }
} else {
    zipmym_uninstall_cleanup_site();
}
