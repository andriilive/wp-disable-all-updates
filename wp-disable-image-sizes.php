<?php
/**
 * Plugin Name:  Wp Disable All updates
 * Plugin URI:   https://github.com/andriilive/wp-disable-all-updates
 * Description:  disables all WordPress updates
 * Version:      0.1.0
 * Author:       DigitalAndy
 * Author URI:   https://github.com/andriilive
 * License:      MIT License
 *
 * @package   Wp_Disable_All_Updates
 */


// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class.
 *
 * @since 1.0.0
 *
 * @package Disable_All_WP_Updates
 * @author  Thomas Griffin
 */
class Disable_All_WP_Updates
{

    /**
     * Holds the current WP version.
     *
     * @since 1.0.0
     *
     * @var bool|int
     */
    public $version = false;

    /**
     * Holds all the registered themes.
     *
     * @since 1.0.0
     *
     * @var array
     */
    public $themes = array();

    /**
     * Holds all the registered plugins.
     *
     * @since 1.0.0
     *
     * @var array
     */
    public $plugins = array();

    /**
     * Initializes the plugin.
     *
     * @since 1.0.0
     *
     * @global int $wp_version The current WP version.
     */
    public function __construct()
    {
        // Set WP version.
        global $wp_version;
        $this->wp_version = $wp_version;

        // Possibly define constants to prevent automatic updates.
        if (! defined('AUTOMATIC_UPDATER_DISABLED')) {
            define('AUTOMATIC_UPDATER_DISABLED', true);
        }

        if (! defined('WP_AUTO_UPDATE_CORE')) {
            define('WP_AUTO_UPDATE_CORE', false);
        }

        // Remove hooks and cron checks.
        add_action('init', array( $this, 'init' ));
        add_action('admin_init', array( $this, 'admin_init' ));
        remove_action('init', 'wp_schedule_update_checks');

        // Disable plugins from hooking into plugins_api.
        remove_all_filters('plugins_api');

        // Further disable theme update checks.
        add_filter('pre_site_transient_update_themes', array( $this, 'pre_update_themes' ));
        add_filter('site_transient_update_themes', array( $this, 'update_themes' ));
        add_filter('transient_update_themes', array( $this, 'update_themes' ));

        // Further disable plugin update checks.
        add_filter('pre_site_transient_update_plugins', array( $this, 'pre_update_plugins' ));
        add_filter('site_transient_update_plugins', array( $this, 'update_plugins' ));
        add_filter('transient_update_plugins', array( $this, 'update_plugins' ));

        // Further disable core update checks.
        add_filter('pre_site_transient_update_core', array( $this, 'pre_update_core' ));
        add_filter('site_transient_update_core', array( $this, 'update_core' ));

        // Disable even other external updates related to core.
        add_filter('auto_update_translation', '__return_false');
        add_filter('automatic_updater_disabled', '__return_true');
        add_filter('allow_minor_auto_core_updates', '__return_false');
        add_filter('allow_major_auto_core_updates', '__return_false');
        add_filter('allow_dev_auto_core_updates', '__return_false');
        add_filter('auto_update_core', '__return_false');
        add_filter('wp_auto_update_core', '__return_false');
        add_filter('auto_update_plugin', '__return_false');
        add_filter('auto_update_theme', '__return_false');
        add_filter('auto_core_update_send_email', '__return_false');
        add_filter('automatic_updates_send_debug_email ', '__return_false');
        add_filter('send_core_update_notification_email', '__return_false');
        add_filter('automatic_updates_is_vcs_checkout', '__return_true');

        // Remove bulk action for updating themes and plugins.
        add_filter('bulk_actions-plugins', array( $this, 'remove_bulk_actions' ));
        add_filter('bulk_actions-themes', array( $this, 'remove_bulk_actions' ));
        add_filter('bulk_actions-plugins-network', array( $this, 'remove_bulk_actions' ));
        add_filter('bulk_actions-themes-network', array( $this, 'remove_bulk_actions' ));

        // Prevent user actions.
        add_filter('map_meta_cap', array( $this, 'block_update_caps' ), 10, 2);

        // Prevent site health check returning errors for feature.
        add_filter('site_status_tests', array( $this, 'remove_auto_update_health_check' ));
    }

    /**
     * Remove any global update checks.
     *
     * @since 1.0.0
     */
    public function init()
    {
        remove_action('init', 'wp_version_check');
        add_filter('pre_option_update_core', '__return_null');
        remove_all_filters('plugins_api');
    }

    /**
     * Remove admin update checks and block cron checks.
     *
     * @since 1.0.0
     */
    public function admin_init()
    {
        // Remove updates page.
        remove_submenu_page('index.php', 'update-core.php');

        // Disable plugin API checks.
        remove_all_filters('plugins_api');

        // Disable theme checks.
        remove_action('load-update-core.php', 'wp_update_themes');
        remove_action('load-themes.php', 'wp_update_themes');
        remove_action('load-update.php', 'wp_update_themes');
        remove_action('wp_update_themes', 'wp_update_themes');
        remove_action('admin_init', '_maybe_update_themes');
        wp_clear_scheduled_hook('wp_update_themes');

        // Disable plugin checks.
        remove_action('load-update-core.php', 'wp_update_plugins');
        remove_action('load-plugins.php', 'wp_update_plugins');
        remove_action('load-update.php', 'wp_update_plugins');
        remove_action('admin_init', '_maybe_update_plugins');
        remove_action('wp_update_plugins', 'wp_update_plugins');
        wp_clear_scheduled_hook('wp_update_plugins');

        // Disable any other update/cron checks.
        remove_action('wp_version_check', 'wp_version_check');
        remove_action('admin_init', '_maybe_update_core');
        remove_action('wp_maybe_auto_update', 'wp_maybe_auto_update');
        remove_action('admin_init', 'wp_maybe_auto_update');
        remove_action('admin_init', 'wp_auto_update_core');
        wp_clear_scheduled_hook('wp_version_check');
        wp_clear_scheduled_hook('wp_maybe_auto_update');

        // Hide nag messages.
        remove_action('admin_notices', 'update_nag', 3);
        remove_action('network_admin_notices', 'update_nag', 3);
        remove_action('admin_notices', 'maintenance_nag');
        remove_action('network_admin_notices', 'maintenance_nag');
    }

    /**
     * Block users from taking certain actions.
     *
     * Adds `do_not_allow` magic keyword to block users from taking update
     * related actions.
     *
     * @since 1.1.0
     *
     * @param string[] $caps Required primitive caps for the capability been checked.
     * @param string   $cap  The capability been checked.
     * @return string[] Modified array of primitive caps.
     */
    function block_update_caps($caps, $cap)
    {
        $update_caps = array(
            'update_plugins',
            'delete_plugins',
            'install_plugins',
            'upload_plugins',

            'update_themes',
            'delete_themes',
            'install_themes',
            'upload_themes',

            'update_languages',
            'install_languages',

            'update_core',
            'update_php',
        );

        if (in_array($cap, $update_caps, true)) {
            $caps[] = 'do_not_allow';
        }

        return $caps;
    }

    /**
     * Remove auto-update test from site health check.
     *
     * This removes auto-update related failures from the site health check page as
     * it's not a bug but a feature.
     *
     * @param array $tests {
     *     An associative array, where the `$test_type` is either `direct` or
     *     `async`, to declare if the test should run via Ajax calls after page load.
     *
     *     @type array $identifier {
     *         `$identifier` should be a unique identifier for the test that should run.
     *         Plugins and themes are encouraged to prefix test identifiers with their slug
     *         to avoid any collisions between tests.
     *
     *         @type string   $label             A friendly label for your test to identify it by.
     *         @type mixed    $test              A callable to perform a direct test, or a string AJAX action
     *                                           to be called to perform an async test.
     *         @type boolean  $has_rest          Optional. Denote if `$test` has a REST API endpoint.
     *         @type boolean  $skip_cron         Whether to skip this test when running as cron.
     *         @type callable $async_direct_test A manner of directly calling the test marked as asynchronous,
     *                                           as the scheduled event can not authenticate, and endpoints
     *                                           may require authentication.
     *     }
     * }
     * @return array Modified array of tests.
     */
    function remove_auto_update_health_check($tests)
    {
        unset($tests['async']['background_updates'], $tests['direct']['plugin_theme_auto_updates']);
        return $tests;
    }

    /**
     * Remove theme update data from the update transient.
     *
     * @since 1.0.0
     */
    public function pre_update_themes()
    {
        // Get all registered themes.
        $this->themes = get_transient('dawpu_themes');
        if (false === $this->themes) {
            foreach (wp_get_themes() as $theme) {
                $this->themes[ $theme->get_stylesheet() ] = $theme->get('Version');
            }

            set_transient('dawpu_themes', $this->themes, DAY_IN_SECONDS);
        }

        // Return an empty object to prevent extra checks.
        return (object) array(
            'last_checked'    => time(),
            'updates'         => array(),
            'version_checked' => $this->wp_version,
            'checked'         => $this->themes,
        );
    }

    /**
     * Remove theme update data from the update transient.
     *
     * @since 1.0.0
     */
    public function update_themes()
    {
        return array();
    }

    /**
     * Remove plugin update data from the update transient.
     *
     * @since 1.0.0
     */
    public function pre_update_plugins()
    {
        // Get all registered plugins.
        $this->plugins = get_transient('dawpu_plugins');
        if (false === $this->plugins) {
            if (! function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            foreach (get_plugins() as $file => $plugin) {
                $this->plugins[ $file ] = $plugin['Version'];
            }

            set_transient('dawpu_plugins', $this->plugins, DAY_IN_SECONDS);
        }

        // Return an empty object to prevent extra checks.
        return (object) array(
            'last_checked'    => time(),
            'updates'         => array(),
            'version_checked' => $this->wp_version,
            'checked'         => $this->plugins,
        );
    }

    /**
     * Remove plugin update data from the update transient.
     *
     * @since 1.0.0
     */
    public function update_plugins()
    {
        return array();
    }

    /**
     * Remove core update data from the update transient.
     *
     * @since 1.0.0
     */
    public function pre_update_core()
    {
        // Return an empty object to prevent extra checks.
        return (object) array(
            'last_checked'    => time(),
            'updates'         => array(),
            'version_checked' => $this->wp_version,
        );
    }

    /**
     * Remove core update data from the update transient.
     *
     * @since 1.0.0
     */
    public function update_core()
    {
        return array();
    }

    /**
     * Removes update bulk actions.
     *
     * @since 1.0.0
     *
     * @param array $actions Bulk actions.
     */
    public function remove_bulk_actions($actions)
    {
        if (isset($actions['update-selected'])) {
            unset($actions['update-selected']);
        }

        if (isset($actions['update'])) {
            unset($actions['update']);
        }

        if (isset($actions['upgrade'])) {
            unset($actions['upgrade']);
        }

        return $actions;
    }
}

// Initialize the plugin.
$GLOBALS['disable_all_wp_updates'] = new Disable_All_WP_Updates();
