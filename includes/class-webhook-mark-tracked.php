<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WCMD_Webhook_Mark_Tracked {
    private static $inst = null;
    public static function instance() {
        return self::$inst ?: self::$inst = new self();
    }
    private function __construct() {
        add_action( 'rest_api_init', [$this, 'register_rest_routes'] );
    }

    public function register_rest_routes() {
        register_rest_route( 'wc-marketing/v1', '/track', [
            'methods'             => ['POST','GET'],
            'permission_callback' => '__return_true',
            'callback'            => [$this, 'webhook_track_callback'],
        ] );
    }

    public function webhook_track_callback( \WP_REST_Request $req ) {
        $opts = WCMD_Utils::get_options();
        if ( empty($opts['webhook_enabled']) ) {
            return new \WP_Error( 'disabled', 'Webhook disabled', ['status'=>403] );
        }

        $secret   = (string)($opts['webhook_secret'] ?? '');
        $provided = (string)($req->get_header('X-WCMD-Secret') ?: $req->get_param('secret'));
        if ( ! $secret || ! hash_equals( $secret, $provided ) ) {
            return new \WP_Error( 'badsecret', 'Invalid secret', ['status'=>403] );
        }

        $json    = $req->get_json_params() ?: [];
        $orderId = absint( $req->get_param('order_id') ?: ($json['order_id'] ?? 0) );
        if ( ! $orderId ) return new \WP_Error( 'noorder', 'order_id missing', ['status'=>400] );

        $order = wc_get_order( $orderId );
        if ( ! $order ) return new \WP_Error( 'notfound', 'Order not found', ['status'=>404] );

        $tracked = $req->get_param('tracked');
        if ( $tracked === null ) $tracked = $json['tracked'] ?? true;
        $tracked = (string)$tracked === '0' ? false : (bool)$tracked;

        $source = sanitize_text_field( $req->get_param('source') ?? ($json['source'] ?? 'gtm-ss') );
        $notes  = sanitize_text_field( $req->get_param('notes')  ?? ($json['notes']  ?? '') );

        $order->update_meta_data( WCMD_Utils::META_TRACKED, $tracked ? '1' : '0' );
        $order->update_meta_data( WCMD_Utils::META_TRACKED_AT, current_time( 'c', true ) );
        $order->update_meta_data( WCMD_Utils::META_TRACK_SRC, $source );
        if ( $notes ) $order->update_meta_data( WCMD_Utils::META_TRACK_NOTES, $notes );
        $order->save();

        return rest_ensure_response([
            'ok'      => true,
            'order_id'=> $orderId,
            'tracked' => $tracked,
            'source'  => $source,
        ]);
    }
}
