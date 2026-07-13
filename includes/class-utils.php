<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WCMD_Utils {
    const OPTION_KEY       = 'wc_order_marketing_data_opts';
    const SCHEMA_VER_KEY   = 'wcmd_options_schema_version';
    const INSTALL_TIME_KEY = 'wcmd_install_timestamp';
    const CAPABILITY       = 'manage_woocommerce';

    const CURRENT_SCHEMA_VERSION = 5;

    // Capture / tracking-confirmation meta (unchanged)
    const META_KEY         = '_marketing_data';
    const META_TRACKED     = '_marketing_tracked';
    const META_TRACKED_AT  = '_marketing_tracked_at';
    const META_TRACK_SRC   = '_marketing_track_source';
    const META_TRACK_NOTES = '_marketing_track_notes';

    // Per-destination "sent" meta (new)
    const META_DC_SENT     = '_wcmd_dataclient_sent_at';
    const META_GA4_SENT    = '_wcmd_ga4_sent_at';
    const META_FB_SENT     = '_wcmd_facebook_sent_at';

    // Legacy meta, read-only fallback so upgraded sites don't resend
    const LEGACY_META_WH_SENT    = '_marketing_wh_sent_at';
    const LEGACY_META_STAPE_SENT = '_marketing_stape_sent_at';

    const CRON_HOOK = 'wc_md_recovery_cron_send';

    /**
     * Get the timestamp of when the plugin was installed/updated.
     * If not set, sets it to NOW to prevent tracking historical orders.
     */
    public static function get_install_timestamp() {
        $ts = get_option( self::INSTALL_TIME_KEY );
        if ( empty($ts) ) {
            $ts = time();
            update_option( self::INSTALL_TIME_KEY, $ts );
        }
        return (int) $ts;
    }

    public static function default_options() {
        return [
            // Capture
            'enabled'          => 1,
            'cookie_keys'      => implode("\n", [
                '_ga', '_gid', '_gcl_au', '_gcl_aw', '_gcl_dc',
                '_fbp', '_fbc', '_ttp', '_uetsid', '_uetvid',
            ]),
            'urlparam_keys'    => implode("\n", [
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                'gclid', 'dclid', 'wbraid', 'gbraid', 'fbclid', 'ttclid', 'msclkid',
            ]),
            'store_user_agent' => 1,
            'store_ip'         => 1,

            // Integration mode picks how GA4 / Facebook get their data —
            // mutually exclusive. 'sgtm': a single sGTM Server Endpoint
            // (dataclient_enabled/endpoint below) handles everything, GA4
            // included, carrying client_id/session_id directly in its
            // payload. 'direct': GA4 and Facebook are each sent straight to
            // Google's/Meta's real APIs with their own credentials, no sGTM
            // container involved.
            'integration_mode' => 'sgtm', // 'sgtm' | 'direct'

            // sGTM mode's one and only destination.
            'dataclient_enabled'  => 0,
            'dataclient_endpoint' => '',
            'skip_if_tracked'     => 1,

            // GA4 Measurement Protocol — Direct mode only.
            'ga4_enabled'         => 0,
            'ga4_endpoint'        => '', // unused; kept so old sGTM-GA4 values aren't lost on upgrade
            'ga4_measurement_id'  => '',
            'ga4_api_secret'      => '',

            // Facebook Conversions API — Direct mode only.
            'fb_enabled'         => 0,
            'fb_pixel_id'        => '',
            'fb_access_token'    => '',
            'fb_test_event_code' => '', // optional — shows up in Facebook's Test Events tool instead of counting as a real conversion

            // Triggers — Real-Time and Recovery each watch their own status
            // list, independently. Real-Time fires immediately when an order
            // reaches one of its statuses; Recovery Sweep periodically
            // catches orders sitting in one of its statuses that never sent.
            'realtime_statuses' => ['processing'],
            'realtime_enabled'  => 0,
            'realtime_delay'    => 5,

            'recovery_statuses'    => ['processing'],
            'recovery_enabled'     => 0,
            'recovery_schedule'    => 'off',
            'recovery_window_days' => 7,

            // Incoming API
            'webhook_enabled' => 0,
            'webhook_secret'  => '',
        ];
    }

    public static function get_options() {
        $opts = get_option( self::OPTION_KEY, [] );
        return wp_parse_args( $opts, self::default_options() );
    }

    public static function sanitize_options( $input ) {
        $out = self::get_options();

        // Capture
        $out['enabled']          = empty($input['enabled']) ? 0 : 1;
        $out['cookie_keys']      = isset($input['cookie_keys']) ? wp_kses_post($input['cookie_keys']) : '';
        $out['urlparam_keys']    = isset($input['urlparam_keys']) ? wp_kses_post($input['urlparam_keys']) : '';
        $out['store_user_agent'] = empty($input['store_user_agent']) ? 0 : 1;
        $out['store_ip']         = empty($input['store_ip']) ? 0 : 1;

        // Destinations
        $out['dataclient_enabled']  = empty($input['dataclient_enabled']) ? 0 : 1;
        $out['dataclient_endpoint'] = isset($input['dataclient_endpoint']) ? esc_url_raw(trim($input['dataclient_endpoint'])) : '';
        $out['skip_if_tracked']     = empty($input['skip_if_tracked']) ? 0 : 1;

        $mode = isset($input['integration_mode']) ? $input['integration_mode'] : 'sgtm';
        $out['integration_mode'] = in_array($mode, ['sgtm','direct'], true) ? $mode : 'sgtm';

        $out['ga4_enabled']        = empty($input['ga4_enabled']) ? 0 : 1;
        // ga4_endpoint no longer has a form field (sGTM mode uses the single
        // sGTM Server Endpoint instead) — preserve whatever was last saved
        // rather than blanking it out just because the form doesn't submit it.
        $out['ga4_measurement_id'] = isset($input['ga4_measurement_id']) ? sanitize_text_field(trim($input['ga4_measurement_id'])) : '';
        $out['ga4_api_secret']     = isset($input['ga4_api_secret']) ? sanitize_text_field(trim($input['ga4_api_secret'])) : '';

        $out['fb_enabled']         = empty($input['fb_enabled']) ? 0 : 1;
        $out['fb_pixel_id']        = isset($input['fb_pixel_id']) ? sanitize_text_field(trim($input['fb_pixel_id'])) : '';
        $out['fb_access_token']    = isset($input['fb_access_token']) ? sanitize_text_field(trim($input['fb_access_token'])) : '';
        $out['fb_test_event_code'] = isset($input['fb_test_event_code']) ? sanitize_text_field(trim($input['fb_test_event_code'])) : '';

        // Triggers (independent status list per firing mechanism). An absent
        // checkbox group means the user unchecked every box — save that as a
        // genuinely empty list rather than silently re-selecting "processing".
        // Real-Time treats empty as "never fires" (it's inherently
        // status-driven); Recovery treats empty as "any status counts" (see
        // WCMD_Recovery_Scheduler).
        $out['realtime_statuses'] = ( isset($input['realtime_statuses']) && is_array($input['realtime_statuses']) )
            ? array_map('sanitize_key', $input['realtime_statuses'])
            : [];
        $out['realtime_enabled'] = empty($input['realtime_enabled']) ? 0 : 1;
        $delay = isset($input['realtime_delay']) ? intval($input['realtime_delay']) : 5;
        $out['realtime_delay'] = max(0, min(60, $delay));

        $out['recovery_statuses'] = ( isset($input['recovery_statuses']) && is_array($input['recovery_statuses']) )
            ? array_map('sanitize_key', $input['recovery_statuses'])
            : [];
        $out['recovery_enabled'] = empty($input['recovery_enabled']) ? 0 : 1;
        $valid = ['off','every_1_min','every_15_min','every_30_min','hourly','twicedaily','daily'];
        $req   = isset($input['recovery_schedule']) ? $input['recovery_schedule'] : 'off';
        $out['recovery_schedule'] = in_array($req, $valid, true) ? $req : 'off';
        $days = isset($input['recovery_window_days']) ? intval($input['recovery_window_days']) : 7;
        $out['recovery_window_days'] = max(1, min(90, $days));

        // Incoming API
        $out['webhook_enabled'] = empty($input['webhook_enabled']) ? 0 : 1;
        $out['webhook_secret']  = isset($input['webhook_secret']) ? sanitize_text_field($input['webhook_secret']) : '';

        return $out;
    }

    /**
     * Steps the saved options forward to CURRENT_SCHEMA_VERSION, gated by
     * SCHEMA_VER_KEY so each step only ever runs once.
     */
    public static function migrate_legacy_options() {
        $current = (int) get_option( self::SCHEMA_VER_KEY, 0 );
        if ( $current >= self::CURRENT_SCHEMA_VERSION ) return;

        if ( $current < 2 ) self::migrate_v1_to_v2();
        if ( $current < 3 ) self::migrate_v2_to_v3();
        if ( $current < 4 ) self::migrate_v3_to_v4();
        if ( $current < 5 ) self::migrate_v4_to_v5();

        update_option( self::SCHEMA_VER_KEY, self::CURRENT_SCHEMA_VERSION );
    }

    /** Pre-3.0 Stape host/API-key fetch + wh_, stape_, sched_ keys -> Destinations + split Real-Time/Recovery schema. */
    private static function migrate_v1_to_v2() {
        $old = get_option( self::OPTION_KEY, [] );
        if ( empty($old) || isset($old['dataclient_enabled']) ) return; // not the pre-3.0 shape

        $new = self::default_options();

        foreach ( ['enabled','cookie_keys','urlparam_keys','store_user_agent','store_ip','webhook_enabled','webhook_secret'] as $k ) {
            if ( isset($old[$k]) ) $new[$k] = $old[$k];
        }

        $new['dataclient_enabled']  = ! empty($old['wh_enabled']) ? 1 : 0;
        $new['dataclient_endpoint'] = $old['wh_endpoint'] ?? '';

        $new['ga4_enabled']  = ( ! empty($old['stape_enabled']) || ! empty($old['sched_send_ga4']) ) ? 1 : 0;
        $new['ga4_endpoint'] = $old['stape_sgtm_url'] ?? '';

        $new['skip_if_tracked'] = 1;

        // v2 still had realtime_statuses / send-to flags; migrate_v2_to_v3() collapses those next.
        $new['realtime_enabled']         = ! empty($old['stape_enabled']) ? 1 : 0;
        $new['realtime_statuses']        = ! empty($old['stape_trigger_statuses']) && is_array($old['stape_trigger_statuses']) ? $old['stape_trigger_statuses'] : ['processing'];
        $new['realtime_delay']           = isset($old['stape_fetch_delay']) ? intval($old['stape_fetch_delay']) : 5;
        $new['realtime_send_dataclient'] = ! empty($old['stape_use_data_client']) ? 1 : 0;
        $new['realtime_send_ga4']        = ! empty($old['stape_enabled']) ? 1 : 0;

        $new['recovery_enabled']         = ( isset($old['wh_cron']) && $old['wh_cron'] !== 'off' ) ? 1 : 0;
        $new['recovery_schedule']        = $old['wh_cron'] ?? 'off';
        $new['recovery_window_days']     = isset($old['wh_cron_window_days']) ? intval($old['wh_cron_window_days']) : 7;
        $new['recovery_verify_status']   = ! empty($old['sched_verify_status']) ? 1 : 0;
        $new['recovery_send_dataclient'] = ! empty($old['wh_enabled']) ? 1 : 0;
        $new['recovery_send_ga4']        = ! empty($old['sched_send_ga4']) ? 1 : 0;

        update_option( self::OPTION_KEY, $new );
    }

    /** Combine Real-Time + Recovery into one shared status list; drop the redundant per-trigger destination checkboxes (Destinations enable is now the single switch). */
    private static function migrate_v2_to_v3() {
        $old = get_option( self::OPTION_KEY, [] );
        if ( empty($old) ) return;

        $new = self::default_options();

        foreach ( ['enabled','cookie_keys','urlparam_keys','dataclient_enabled','dataclient_endpoint','ga4_enabled','ga4_endpoint','skip_if_tracked','realtime_enabled','realtime_delay','recovery_enabled','recovery_schedule','recovery_window_days','webhook_enabled','webhook_secret'] as $k ) {
            if ( isset($old[$k]) ) $new[$k] = $old[$k];
        }

        $new['trigger_statuses'] = ! empty($old['realtime_statuses']) && is_array($old['realtime_statuses']) ? $old['realtime_statuses'] : ['processing'];

        // Deliberate default flip: capture IP + User-Agent out of the box.
        $new['store_user_agent'] = 1;
        $new['store_ip']         = 1;

        update_option( self::OPTION_KEY, $new );
    }

    /** Split the shared trigger_statuses list back into independent realtime_statuses / recovery_statuses lists, so each firing mechanism can watch different order statuses. */
    private static function migrate_v3_to_v4() {
        $old = get_option( self::OPTION_KEY, [] );
        if ( empty($old) ) return;

        $new = self::default_options();

        foreach ( ['enabled','cookie_keys','urlparam_keys','store_user_agent','store_ip','dataclient_enabled','dataclient_endpoint','ga4_enabled','ga4_endpoint','skip_if_tracked','realtime_enabled','realtime_delay','recovery_enabled','recovery_schedule','recovery_window_days','webhook_enabled','webhook_secret'] as $k ) {
            if ( isset($old[$k]) ) $new[$k] = $old[$k];
        }

        $shared = ! empty($old['trigger_statuses']) && is_array($old['trigger_statuses']) ? $old['trigger_statuses'] : ['processing'];
        $new['realtime_statuses'] = $shared;
        $new['recovery_statuses'] = $shared;

        update_option( self::OPTION_KEY, $new );
    }

    /** Introduce Direct Integration mode (Facebook CAPI + direct GA4 Measurement Protocol) alongside the existing sGTM path. Every existing install keeps sending exactly as before, on 'sgtm' mode with its saved ga4_endpoint. */
    private static function migrate_v4_to_v5() {
        $old = get_option( self::OPTION_KEY, [] );
        if ( empty($old) ) return;

        $new = self::default_options();

        foreach ( array_keys( $old ) as $k ) {
            if ( array_key_exists( $k, $new ) ) $new[$k] = $old[$k];
        }

        $new['integration_mode'] = 'sgtm';

        update_option( self::OPTION_KEY, $new );
    }

    public static function lines_to_keys( $str ) {
        $keys = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', (string) $str ) ) );
        $keys = array_unique( $keys );
        foreach ( $keys as $i => $k ) {
            if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $k ) ) unset( $keys[$i] );
        }
        return array_values($keys);
    }

    public static function client_ip() {
        foreach ( ['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','HTTP_CLIENT_IP'] as $h ) {
            if ( ! empty($_SERVER[$h]) ) {
                $raw = wp_unslash($_SERVER[$h]);
                $parts = explode(',', $raw);
                $ip = trim($parts[0]);
                if ($ip) return $ip;
            }
        }
        return ! empty($_SERVER['REMOTE_ADDR']) ? wp_unslash($_SERVER['REMOTE_ADDR']) : '';
    }

    public static function parse_ga_cookie_to_cid( $gaCookie ) {
        $gaCookie = (string) $gaCookie;
        if ( preg_match('/^GA\d+\.\d+\.(\d+\.\d+)$/', $gaCookie, $m) ) return $m[1];
        if ( preg_match('/^\d+\.\d+$/', $gaCookie) ) return $gaCookie;
        if ( preg_match('/(\d+\.\d+)$/', $gaCookie, $m) ) return $m[1];
        return '';
    }

    /**
     * The _ga_<container-id> cookie GA4 sets client-side looks like:
     * GS1.1.<session_start_timestamp>.<hit_count>.<is_engaged>.<last_engagement_timestamp>.0.0.0
     * The GA4 Measurement Protocol session_id parameter is that third,
     * dot-separated segment (a Unix timestamp), not a regex-embedded "s123".
     */
    public static function parse_ga_session_id( $gaSessionCookie ) {
        $parts = explode( '.', (string) $gaSessionCookie );
        return ( isset($parts[2]) && ctype_digit($parts[2]) ) ? $parts[2] : '';
    }
}
