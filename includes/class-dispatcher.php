<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Single place that builds the purchase payload and sends it to the two
 * supported destinations (Data Client webhook, GA4 Measurement Protocol).
 * Both the Real-Time Trigger and the Recovery Sweep call into this class so
 * there is exactly one send implementation and one dedupe rule per
 * destination.
 */
final class WCMD_Dispatcher {
    private static $inst = null;
    public static function instance() { return self::$inst ?: self::$inst = new self(); }
    private function __construct() {}

    private function log( $msg ) {
        if ( class_exists('WC_Logger') ) {
            wc_get_logger()->info( $msg, ['source' => 'wcmd'] );
        }
    }

    /**
     * Send an order to the given destinations ('dataclient', 'ga4'), honoring
     * the global "skip if tracked" guard and per-destination dedupe.
     *
     * @return array Map of destination => bool (true if sent this call).
     */
    public function dispatch( \WC_Order $order, array $destinations, $opts = null, $force = false ) {
        $opts   = $opts ?: WCMD_Utils::get_options();
        $result = [];

        if ( ! $force && ! empty($opts['skip_if_tracked']) && $order->get_meta( WCMD_Utils::META_TRACKED ) === '1' ) {
            $this->log( "Order #{$order->get_id()}: skipped, already confirmed tracked." );
            return $result;
        }

        if ( in_array('dataclient', $destinations, true) ) {
            $result['dataclient'] = $this->send_data_client( $order, $opts, $force );
        }
        if ( in_array('ga4', $destinations, true) ) {
            $result['ga4'] = $this->send_ga4( $order, $opts, $force );
        }
        return $result;
    }

    public function already_sent( \WC_Order $order, $destination ) {
        if ( $destination === 'dataclient' ) {
            return (bool) ( $order->get_meta( WCMD_Utils::META_DC_SENT ) ?: $order->get_meta( WCMD_Utils::LEGACY_META_WH_SENT ) );
        }
        if ( $destination === 'ga4' ) {
            return (bool) ( $order->get_meta( WCMD_Utils::META_GA4_SENT ) ?: $order->get_meta( WCMD_Utils::LEGACY_META_STAPE_SENT ) );
        }
        return false;
    }

    /* ==========================================================================
       DATA CLIENT
       ========================================================================== */
    public function send_data_client( \WC_Order $order, $opts, $force = false ) {
        if ( empty($opts['dataclient_enabled']) || empty($opts['dataclient_endpoint']) ) return false;
        if ( ! $force && $this->already_sent( $order, 'dataclient' ) ) return false;

        $payload = $this->build_payload( $order );

        $resp = wp_remote_post( $opts['dataclient_endpoint'], [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( apply_filters('wcmd_dataclient_payload', $payload, $order) ),
        ] );

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ( $code >= 200 && $code < 400 ) {
            $order->update_meta_data( WCMD_Utils::META_DC_SENT, current_time('c', true) );
            $order->save();
            return true;
        }

        $this->log( "Order #{$order->get_id()}: Data Client send failed (" . ( is_wp_error($resp) ? $resp->get_error_message() : "HTTP $code" ) . ')' );
        return false;
    }

    /* ==========================================================================
       GA4 MEASUREMENT PROTOCOL
       ========================================================================== */
    public function send_ga4( \WC_Order $order, $opts, $force = false ) {
        if ( empty($opts['ga4_enabled']) || empty($opts['ga4_endpoint']) ) return false;
        if ( ! $force && $this->already_sent( $order, 'ga4' ) ) return false;

        $payload = $this->build_payload( $order );
        $mp_body = $this->build_ga4_body( $payload );

        $url = $opts['ga4_endpoint'];
        if ( strpos($url, 'v=2') === false ) $url = add_query_arg( 'v', '2', $url );
        $url = add_query_arg( 'cid', $mp_body['client_id'], $url );

        $resp = wp_remote_post( $url, [
            'timeout' => 15,
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( apply_filters('wcmd_ga4_payload', $mp_body, $order) ),
        ] );

        $code = (int) wp_remote_retrieve_response_code($resp);
        if ( $code >= 200 && $code < 400 ) {
            $order->update_meta_data( WCMD_Utils::META_GA4_SENT, current_time('c', true) );
            $order->save();
            return true;
        }

        $this->log( "Order #{$order->get_id()}: GA4 send failed (" . ( is_wp_error($resp) ? $resp->get_error_message() : "HTTP $code" ) . ')' );
        return false;
    }

    /**
     * Builds a GA4 Measurement Protocol body from the flat local payload:
     * client_id + session_id parsed from the stored _ga / _ga_<id> cookies,
     * ip/user agent overrides, and the purchase event params.
     * https://developers.google.com/analytics/devguides/collection/protocol/ga4
     */
    public function build_ga4_body( array $payload ) {
        $cookies = $payload['common_cookie'] ?? [];

        $client_id = $payload['client_id'] ?: '';
        if ( ! $client_id && ! empty($cookies['_ga']) ) {
            $client_id = WCMD_Utils::parse_ga_cookie_to_cid( $cookies['_ga'] );
        }
        if ( ! $client_id ) $client_id = 'server.' . ( $payload['transaction_id'] ?: wp_generate_uuid4() );

        $session_id = '';
        foreach ( $cookies as $key => $val ) {
            if ( strpos($key, '_ga_') === 0 ) {
                $sid = WCMD_Utils::parse_ga_session_id( $val );
                if ( $sid ) { $session_id = $sid; break; }
            }
        }

        $params = $payload;
        unset( $params['client_id'] );
        if ( $session_id ) $params['session_id'] = $session_id;
        $params['ip_override'] = $payload['ip_override'] ?? '';
        $params['user_agent']  = $payload['user_agent'] ?? '';

        $body = [
            'client_id' => $client_id,
            'events'    => [[ 'name' => 'purchase', 'params' => $params ]],
        ];

        return $body;
    }

    /* ==========================================================================
       PAYLOAD BUILDER (shared by both destinations)
       ========================================================================== */
    public function build_payload( \WC_Order $order ) {
        $items = [];
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof \WC_Order_Item_Product ) continue;
            $product = $item->get_product();
            $cat = '';
            if ( $product ) {
                $terms = get_the_terms( $product->get_id(), 'product_cat' );
                if ( is_array($terms) && !empty($terms) ) $cat = $terms[0]->name;
            }
            $items[] = [
                'item_id'       => (string) ( $product ? $product->get_id() : $item->get_product_id() ),
                'item_name'     => (string) $item->get_name(),
                'item_sku'      => $product ? (string) $product->get_sku() : '',
                'item_category' => $cat,
                'price'         => (float) wc_format_decimal( $order->get_item_total( $item, true, false ), 2 ),
                'quantity'      => (int) $item->get_quantity(),
                'index'         => count($items) + 1,
                'discount'      => 0,
                'item_brand'    => '',
            ];
        }

        $md      = $order->get_meta( WCMD_Utils::META_KEY );
        $cookies = is_array($md) ? ($md['common_cookies'] ?? ($md['cookies'] ?? [])) : [];
        $params  = is_array($md) ? ($md['url_parameters'] ?? ($md['params'] ?? [])) : [];
        $ua      = is_array($md) ? ($md['user_agent'] ?? '') : '';
        $ip      = is_array($md) ? ($md['ip_address'] ?? '') : '';

        $client_id = '';
        if ( ! empty($cookies['_ga']) ) {
            $client_id = WCMD_Utils::parse_ga_cookie_to_cid( $cookies['_ga'] );
        } elseif ( ! empty($params['_ga']) ) {
            $client_id = WCMD_Utils::parse_ga_cookie_to_cid( $params['_ga'] );
        }

        $value          = (float) wc_format_decimal( $order->get_total(), 2 );
        $tax            = (float) wc_format_decimal( $order->get_total_tax(), 2 );
        $shipping       = (float) wc_format_decimal( $order->get_shipping_total(), 2 );
        $discount_total = (float) wc_format_decimal( $order->get_discount_total(), 2 );
        $coupon         = implode( ',', $order->get_coupon_codes() );

        $first_name = (string) $order->get_billing_first_name();
        $last_name  = (string) $order->get_billing_last_name();
        $user_data = [
            'email_address' => (string) $order->get_billing_email(),
            'phone_number'  => (string) $order->get_billing_phone(),
            'first_name'    => $first_name,
            'last_name'     => $last_name,
            'address' => [
                'street'      => trim( (string)$order->get_billing_address_1() . ' ' . (string)$order->get_billing_address_2() ),
                'city'        => (string) $order->get_billing_city(),
                'region'      => (string) $order->get_billing_state(),
                'postal_code' => (string) $order->get_billing_postcode(),
                'country'     => (string) $order->get_billing_country(),
                'first_name'  => $first_name,
                'last_name'   => $last_name,
            ],
        ];

        $gclid  = $cookies['gclid']  ?? $params['gclid']  ?? '';
        $wbraid = $cookies['wbraid'] ?? $params['wbraid'] ?? '';
        $gbraid = $cookies['gbraid'] ?? $params['gbraid'] ?? '';
        $ads = array_filter([ 'gclid' => $gclid, 'wbraid' => $wbraid, 'gbraid' => $gbraid ]);

        $site_url      = home_url();
        $webhook_base  = trailingslashit( home_url( '/webhook' ) );
        $order_id      = (int) $order->get_id();
        $page_location = trailingslashit( $webhook_base . 'order-received/' . $order_id );
        $page_referrer = $order->get_checkout_order_received_url();
        $page_title    = 'Webhook - ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

        $payload = [
            'event'           => 'purchase',
            'event_name'      => 'purchase',
            'timestamp'       => time(),
            'transaction_id'  => (string) $order->get_order_number(),
            'value'           => $value,
            'currency'        => (string) $order->get_currency(),
            'tax'             => $tax,
            'shipping'        => $shipping,
            'discount_amount' => $discount_total,
            'coupon'          => $coupon,
            'items'           => $items,
            'client_id'       => $client_id,
            'common_cookie'   => $cookies,
            'url_parameters'  => $params,
            'ip_override'     => $ip ?: (string) $order->get_customer_ip_address(),
            'user_agent'      => $ua ?: (string) $order->get_customer_user_agent(),
            'site_url'        => $site_url,
            'webhook_base'    => $webhook_base,
            'page_location'   => $page_location,
            'page_referrer'   => $page_referrer,
            'page_title'      => $page_title,
            'user_data'       => $user_data,
            'email_address'   => $user_data['email_address'],
            'phone_number'    => $user_data['phone_number'],
            'first_name'      => $user_data['first_name'],
            'last_name'       => $user_data['last_name'],
            'street'          => $user_data['address']['street'],
            'city'            => $user_data['address']['city'],
            'region'          => $user_data['address']['region'],
            'postal_code'     => $user_data['address']['postal_code'],
            'country'         => $user_data['address']['country'],
        ];
        if ( ! empty($ads) ) $payload['ads'] = $ads;

        return apply_filters( 'wcmd_build_payload', $payload, $order );
    }

    /* ==========================================================================
       TEST HELPERS (Overview page diagnostics)
       ========================================================================== */
    private function fake_payload() {
        $fake_id       = 999999;
        $site_url      = home_url();
        $webhook_base  = trailingslashit( home_url( '/webhook' ) );
        $page_location = trailingslashit( $webhook_base . 'order-received/' . $fake_id );
        $page_referrer = add_query_arg( ['key' => 'wc_order_TEST'], trailingslashit( home_url( '/checkout/order-received/'.$fake_id ) ) );
        $page_title    = 'Webhook - ' . wp_specialchars_decode( get_bloginfo('name'), ENT_QUOTES );

        return [
            'event' => 'purchase', 'event_name' => 'purchase', 'timestamp' => time(),
            'transaction_id' => 'TEST-' . time(), 'value' => 1.23, 'currency' => 'USD',
            'tax' => 0, 'shipping' => 0, 'discount_amount' => 0, 'coupon' => '',
            'items' => [['item_id' => 'SKU-TEST', 'item_name' => 'Test Item', 'item_sku' => 'SKU-TEST', 'item_category' => '', 'price' => 1.23, 'quantity' => 1, 'index' => 1, 'discount' => 0, 'item_brand' => '']],
            'client_id' => '1804441153.1756627112',
            'common_cookie' => [], 'url_parameters' => [],
            'ip_override' => '', 'user_agent' => '',
            'site_url' => $site_url, 'webhook_base' => $webhook_base,
            'page_location' => $page_location, 'page_referrer' => $page_referrer, 'page_title' => $page_title,
            'user_data' => [], 'email_address' => get_option('admin_email'),
            'phone_number' => '', 'first_name' => '', 'last_name' => '',
            'street' => '', 'city' => '', 'region' => '', 'postal_code' => '', 'country' => '',
        ];
    }

    /** Sends a fake purchase to one or both destinations. Returns WP_Error on hard failure, true otherwise. */
    public function send_test( $destination ) {
        $opts = WCMD_Utils::get_options();
        $payload = $this->fake_payload();
        $sent_any = false;
        $error = null;

        if ( ( $destination === 'dataclient' || $destination === 'both' ) && ! empty($opts['dataclient_enabled']) && ! empty($opts['dataclient_endpoint']) ) {
            $resp = wp_remote_post( $opts['dataclient_endpoint'], [
                'timeout' => 15, 'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode( apply_filters('wcmd_dataclient_payload_test', $payload) ),
            ]);
            if ( is_wp_error($resp) ) $error = $resp; else $sent_any = true;
        }

        if ( ( $destination === 'ga4' || $destination === 'both' ) && ! empty($opts['ga4_enabled']) && ! empty($opts['ga4_endpoint']) ) {
            $mp_body = $this->build_ga4_body( $payload );
            $mp_body['events'][0]['params']['debug_mode'] = 'true';
            $url = $opts['ga4_endpoint'];
            if ( strpos($url, 'v=2') === false ) $url = add_query_arg( 'v', '2', $url );
            $url = add_query_arg( 'cid', $mp_body['client_id'], $url );
            $resp = wp_remote_post( $url, [
                'timeout' => 15, 'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode( $mp_body ),
            ]);
            if ( is_wp_error($resp) && ! $sent_any ) $error = $resp; else $sent_any = true;
        }

        if ( ! $sent_any && ! $error ) {
            return new \WP_Error('disabled', 'Enable and configure at least one destination to test.');
        }
        if ( $error ) return $error;
        return true;
    }
}
