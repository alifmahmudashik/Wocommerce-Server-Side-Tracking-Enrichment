<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fires purchase data the moment an order reaches one of the configured
 * statuses. Sends to whichever destinations are enabled for Real-Time in
 * Settings, via WCMD_Dispatcher.
 */
final class WCMD_Realtime_Trigger {
    private static $inst = null;
    public static function instance() { return self::$inst ?: self::$inst = new self(); }

    const ASYNC_HOOK = 'wcmd_realtime_async_send';

    private function __construct() {
        add_action( 'woocommerce_order_status_changed', [$this, 'handle_status_change'], 10, 3 );
        add_action( self::ASYNC_HOOK, [$this, 'run_async_send'], 10, 1 );
    }

    public function handle_status_change( $order_id, $old_status, $new_status ) {
        $opts = WCMD_Utils::get_options();
        if ( empty($opts['realtime_enabled']) ) return;

        $allowed = $opts['realtime_statuses'] ?? ['processing'];
        if ( ! in_array( $new_status, $allowed, true ) ) return;

        $this->schedule( $order_id, $opts );
    }

    private function schedule( $order_id, $opts ) {
        if ( wp_next_scheduled( self::ASYNC_HOOK, [$order_id] ) ) return;

        $delay = isset($opts['realtime_delay']) ? intval($opts['realtime_delay']) : 5;
        wp_schedule_single_event( time() + $delay, self::ASYNC_HOOK, [$order_id] );
    }

    public function run_async_send( $order_id ) {
        $this->send( $order_id, false );
    }

    /**
     * Sends to whichever destinations are enabled on the Destinations tab —
     * there's no separate per-trigger destination selection; if a
     * destination is switched on, both Real-Time and Recovery use it.
     *
     * @param bool $force Bypass dedupe/tracked guards (used by the Overview "Test Real-Time Send" button).
     * @return array|\WP_Error Destination => bool map, or WP_Error when forced and nothing could send.
     */
    public function send( $order_id, $force = false ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return $force ? new \WP_Error('no_order', 'Order not found') : [];

        $opts = WCMD_Utils::get_options();
        if ( ! $force && empty($opts['realtime_enabled']) ) return [];

        $result = WCMD_Dispatcher::instance()->dispatch( $order, ['dataclient', 'ga4'], $opts, $force );

        if ( $force && ! in_array( true, $result, true ) ) {
            return new \WP_Error('no_send', 'Nothing was sent — enable at least one destination on the Destinations tab.');
        }
        return $result;
    }
}
