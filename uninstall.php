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
 * @package BulkMediaDownloader
 */

// Make sure WordPress called this file — direct access is blocked
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Sweep up any leftover ZIP files from interrupted downloads
$upload_dir = wp_upload_dir();
$pattern    = trailingslashit($upload_dir['basedir']) . 'media-download-*.zip';
$leftover   = glob($pattern);

if (is_array($leftover)) {
    foreach ($leftover as $file) {
        @unlink($file);
    }
}
