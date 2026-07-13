<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Background sweep that finds orders which never got a purchase event out
 * (no destination "sent" meta) and recovers them via WCMD_Dispatcher. This
 * is the safety net behind the Real-Time Trigger.
 */
final class WCMD_Recovery_Scheduler {
    private static $inst = null;
    public static function instance() { return self::$inst ?: self::$inst = new self(); }

    private function __construct() {
        add_filter( 'cron_schedules', [$this, 'add_custom_schedules'] );
        add_action( 'admin_init',     [$this, 'ensure_cron_schedule'] );
        add_action( WCMD_Utils::CRON_HOOK, [$this, 'run_sweep'] );
    }

    public static function on_activate() {
        $opts = WCMD_Utils::get_options();
        if ( ! empty($opts['recovery_enabled']) && $opts['recovery_schedule'] !== 'off' ) {
            if ( ! wp_next_scheduled( WCMD_Utils::CRON_HOOK ) ) {
                wp_schedule_event( time() + 60, $opts['recovery_schedule'], WCMD_Utils::CRON_HOOK );
            }
        }
    }
    public static function on_deactivate() {
        wp_clear_scheduled_hook( WCMD_Utils::CRON_HOOK );
    }

    public function add_custom_schedules( $schedules ) {
        $schedules['every_1_min']  = [ 'interval' => 60, 'display' => __('Every 1 minute','wc-md') ];
        $schedules['every_15_min'] = [ 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __('Every 15 minutes','wc-md') ];
        $schedules['every_30_min'] = [ 'interval' => 30 * MINUTE_IN_SECONDS, 'display' => __('Every 30 minutes','wc-md') ];
        return $schedules;
    }

    public function ensure_cron_schedule() {
        $o = WCMD_Utils::get_options();
        $recurrence = empty($o['recovery_enabled']) ? 'off' : $o['recovery_schedule'];
        $scheduled  = wp_next_scheduled( WCMD_Utils::CRON_HOOK );

        if ( $recurrence === 'off' ) {
            if ( $scheduled ) wp_clear_scheduled_hook( WCMD_Utils::CRON_HOOK );
            return;
        }

        if ( ! $scheduled || wp_get_schedule( WCMD_Utils::CRON_HOOK ) !== $recurrence ) {
            wp_clear_scheduled_hook( WCMD_Utils::CRON_HOOK );
            wp_schedule_event( time() + 60, $recurrence, WCMD_Utils::CRON_HOOK );
        }
    }

    /** Cron entrypoint. */
    public function run_sweep() {
        $this->process( false );
    }

    /** Overview "Run Recovery Sweep Now" button — single page of 50, ignores the enabled/schedule switch. */
    public function manual_run_now() {
        return $this->process( true );
    }

    private function process( $manual = false ) {
        $o = WCMD_Utils::get_options();

        $destinations = [];
        if ( ! empty($o['dataclient_enabled']) ) $destinations[] = 'dataclient';
        if ( ! empty($o['ga4_enabled']) )        $destinations[] = 'ga4';

        if ( empty($destinations) ) return 0;
        if ( ! $manual && empty($o['recovery_enabled']) ) return 0;

        $window_days = isset($o['recovery_window_days']) ? intval($o['recovery_window_days']) : 7;
        if ( $window_days < 1 ) $window_days = 1;
        $after_date = date( 'Y-m-d\TH:i:s', time() - ( $window_days * DAY_IN_SECONDS ) );

        // Recovery only ever considers orders currently in a Trigger Status —
        // this is the same shared list Real-Time uses, so a "completed
        // purchase" means the same thing everywhere in the plugin.
        $allowed_statuses = $o['trigger_statuses'] ?? ['processing'];

        $args = [
            'limit'        => 50,
            'paged'        => 1,
            'status'       => array_keys( wc_get_order_statuses() ),
            'orderby'      => 'date',
            'order'        => $manual ? 'DESC' : 'ASC',
            'date_created' => '>' . $after_date,
            'meta_query'   => [
                'relation' => 'OR',
                [ 'key' => WCMD_Utils::META_TRACKED, 'compare' => 'NOT EXISTS' ],
                [ 'key' => WCMD_Utils::META_TRACKED, 'value' => '1', 'compare' => '!=' ],
            ],
        ];

        $processed = 0;
        $dispatcher = WCMD_Dispatcher::instance();

        do {
            $orders = wc_get_orders( $args );
            if ( empty($orders) ) break;

            foreach ( $orders as $order ) {
                $needed = array_filter( $destinations, function( $d ) use ( $dispatcher, $order ) {
                    return ! $dispatcher->already_sent( $order, $d );
                } );
                if ( empty($needed) ) continue;

                $clean_status = str_replace( 'wc-', '', $order->get_status() );
                if ( ! in_array( $clean_status, $allowed_statuses, true ) ) continue;

                $result = $dispatcher->dispatch( $order, $needed, $o );
                if ( ! empty($result) && in_array(true, $result, true) ) {
                    $processed++;
                    usleep(150000); // 0.15s pause between outbound requests
                }
            }
            $args['paged']++;
        } while ( ! $manual && count($orders) === (int) $args['limit'] );

        return $processed;
    }
}
