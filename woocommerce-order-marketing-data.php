<?php
/**
 * Plugin Name: Wocommerce Server-Side Tracking & Enrichment
 * Description: Captures marketing data and fires purchase events to a Data Client webhook and/or GA4 Measurement Protocol — in real time on order status change, and via a background recovery sweep for anything missed.
 * Version: 3.2.0
 * Author: Alif Mahmud
 * Author URI: https://alifmahmud.com
 * License: GPL-2.0+
 */

if ( ! defined('ABSPATH') ) exit;
if ( ! defined('WCMD_VERSION') ) define('WCMD_VERSION', '3.2.0');
if ( ! defined('WCMD_URL') ) define('WCMD_URL', plugin_dir_url(__FILE__));

/* HPOS compatibility */
add_action('before_woocommerce_init', function () {
    if ( class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/* 1. Load Utilities & License first */
require_once __DIR__ . '/includes/class-utils.php';
require_once __DIR__ . '/includes/license/class-wcmd-license.php';

/* 2. Load Admin UI (Handles Menus) */
require_once __DIR__ . '/includes/class-admin-ui.php';

add_action('plugins_loaded', function () {

    // Migrate pre-3.0 options (wh_*/stape_*/sched_*) into the new Destinations + Triggers schema.
    WCMD_Utils::migrate_legacy_options();

    // Always init License & Admin UI (so the menu always exists)
    WCMD_License::instance();
    WCMD_Admin_UI::instance();

    // Check License Status
    $licensed = WCMD_License::instance()->is_valid();

    if ( ! $licensed ) {
        return; // Stop loading features if unlicensed
    }

    /**
     * 3. Load Functional Classes (Only if Licensed)
     */
    require_once __DIR__ . '/includes/class-capture-settings.php';
    require_once __DIR__ . '/includes/class-webhook-mark-tracked.php';
    require_once __DIR__ . '/includes/class-dispatcher.php';
    require_once __DIR__ . '/includes/class-realtime-trigger.php';
    require_once __DIR__ . '/includes/class-recovery-scheduler.php';

    WCMD_Capture_Settings::instance();
    WCMD_Webhook_Mark_Tracked::instance();
    WCMD_Dispatcher::instance();
    WCMD_Realtime_Trigger::instance();
    WCMD_Recovery_Scheduler::instance();
});

/**
 * Activation/Deactivation hooks
 */
register_activation_hook(__FILE__, function () {
    require_once __DIR__ . '/includes/class-utils.php';
    require_once __DIR__ . '/includes/class-recovery-scheduler.php';
    if ( class_exists('WCMD_Recovery_Scheduler') ) {
        WCMD_Recovery_Scheduler::on_activate();
    }
});
register_deactivation_hook(__FILE__, function () {
    require_once __DIR__ . '/includes/class-utils.php';
    require_once __DIR__ . '/includes/class-recovery-scheduler.php';
    if ( class_exists('WCMD_Recovery_Scheduler') ) {
        WCMD_Recovery_Scheduler::on_deactivate();
    }
});