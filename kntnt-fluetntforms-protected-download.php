<?php

/**
 * @wordpress-plugin
 * Plugin Name:       Kntnt Protected Downloads for Fluent Forms
 * Plugin URI:        https://www.kntnt.com/
 * Description:       Provides a way to send unique onetime download links after filling out a form by Fluent Forms.
 * Version:           1.1.0
 * Author:            Thomas Barregren
 * Author URI:        https://www.kntnt.com/
 * License:           GPL-3.0+
 * License URI:       http://www.gnu.org/licenses/gpl-3.0.txt
 */

/**
 * To use this plugin, add two hidden fields to your form that maps a token to
 * an attachment post id:
 *
 * Admin Field Label: Download Token
 * Default Value: {random_string.}
 * Name Attribute: kntnt_fluentforms_protected_downloads_token
 *
 * Admin Field Label: Media ID
 * Default Value: The Post ID of the attachment containing the asset to be downloaded (e.g. 1139)
 * Name Attribute: kntnt_fluentforms_protected_downloads_media
 *
 * Optionally, add a hidden field with the path that should be used for
 * downloading. Default is /download. The used URL will we path followed by
 * forward slash and the token, e.g. /download/630a184616ba0.
 *
 * Admin Field Label: Download Path
 * Default Value: The desired path (e.g. /assets/e-books)
 * Name Attribute: kntnt_fluentforms_protected_downloads_path
 *
 * Optionally, add a hidden field with the minimum number of hours the download
 * token should be valid. Default is one hour. Download tokens are checked
 * hourly. Any older than the value provided will be made invalid.
 *
 * Admin Field Label: Minimum Lifetime
 * Default Value: The desired minimum lifetime in seconds
 * Name Attribute: kntnt_fluentforms_protected_downloads_expires
 */

namespace Kntnt\FluentForms_Protected_Downloads;

defined('ABSPATH') && new Plugin;

class Plugin {

    public function __construct() {

        add_action('fluentform_before_insert_submission', [$this, 'fluentform_before_insert_submission'], 10, 3);

        add_action('init', [$this, 'intercept_requests']);

        if (!wp_next_scheduled('kntnt_fluentforms_protected_downloads_maintenance')) {
            wp_schedule_event(time(), 'hourly', 'kntnt_fluentforms_protected_downloads_maintenance');
        }

    }

    public function fluentform_before_insert_submission($insertData, $data, $form): void {
        if (($token = $data['kntnt_fluentforms_protected_downloads_token'] ?? null) && ($media = $data['kntnt_fluentforms_protected_downloads_media'] ?? null)) {

            $path = $data['kntnt_fluentforms_protected_downloads_path'] ?? '/download';
            $expires = time() + 3600 * ($data['kntnt_fluentforms_protected_downloads_expires'] ?? 1);

            $maps = get_option('kntnt_fluentforms_protected_downloads_maps', []);
            $maps[$token] = [
                'media' => $media,
                'path' => $path,
                'expires' => $expires,
            ];
            update_option('kntnt_fluentforms_protected_downloads_maps', $maps, false);

            $paths = get_option('kntnt_fluentforms_protected_downloads_paths', []);
            if (!isset($paths[$path]) || $paths[$path] < $expires) {
                $paths[$path] = $expires;
                update_option('kntnt_fluentforms_protected_downloads_paths', $paths, true);
            }

        }
    }

    public function intercept_requests() {
        if (($n = strrpos($_SERVER['REQUEST_URI'], '/')) > 0) {
            $path = substr($_SERVER['REQUEST_URI'], 0, $n);
            $paths = get_option('kntnt_fluentforms_protected_downloads_paths', []);
            if (isset($paths[$path])) {
                $m = false == ($m = strrpos($_SERVER['REQUEST_URI'], '?')) ? null : $m - $n - 1;
                $token = substr($_SERVER['REQUEST_URI'], $n + 1, $m);
                $maps = get_option('kntnt_fluentforms_protected_downloads_maps', []);
                $map = $maps[$token] ?? [];
                if ($map && time() < $map['expires'] && $path == $map['path']) {
                    unset($maps[$token]);
                    update_option('kntnt_fluentforms_protected_downloads_maps', $maps, false);
                    $this->handle_request($map);
                } else {
                    $this->handle_error();
                }
            }
        }
    }

    public function kntnt_fluentforms_protected_downloads_maintenance(): void {

        $now = time();

        $maps = get_option('kntnt_fluentforms_protected_downloads_maps', []);
        foreach ($maps as $token => $map) {
            if ($map['expires'] <= $now) {
                unset($maps[$token]);
            }
        }
        update_option('kntnt_fluentforms_protected_downloads_map', $maps, false);

        $paths = get_option('kntnt_fluentforms_protected_downloads_paths', []);
        foreach ($paths as $path => $expires) {
            if ($expires <= $now) {
                unset($paths[$path]);
            }
        }
        update_option('kntnt_fluentforms_protected_downloads_paths', $paths, false);

    }

    private function handle_request($map): void {
        $media = get_attached_file($map['media']);
        if (file_exists($media)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($media) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($media));
            readfile($media);
            exit;
        }
    }

    private function handle_error(): void {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        get_template_part(404);
        exit;
    }

}
