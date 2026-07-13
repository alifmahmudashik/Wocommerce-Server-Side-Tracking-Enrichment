<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WCMD_Capture_Settings {
    private static $inst = null;
    public static function instance() {
        return self::$inst ?: self::$inst = new self();
    }
    private function __construct() {
        // Front-end capture (URL params saved to localStorage and injected at checkout)
        add_action( 'wp_enqueue_scripts', [$this, 'enqueue_frontend_capture_js'] );

        // Capture at checkout
        add_action( 'woocommerce_checkout_create_order', [$this, 'attach_meta_to_order'], 10, 1 );

        // Default Not tracked ❌
        add_action( 'woocommerce_new_order', [$this, 'mark_not_tracked_on_create'], 10, 2 );

        // Order admin box
        add_action( 'add_meta_boxes', [$this, 'add_order_metabox'] );
        add_action( 'add_meta_boxes_woocommerce_page_wc-orders', [$this, 'add_order_metabox_hpos'] );

        // Tracked/Untracked column on the Orders list (legacy CPT + HPOS)
        add_filter( 'manage_edit-shop_order_columns',              [$this, 'add_tracking_column'] );
        add_filter( 'manage_woocommerce_page_wc-orders_columns',   [$this, 'add_tracking_column'] );
        add_action( 'manage_shop_order_posts_custom_column',              [$this, 'render_tracking_column_legacy'], 10, 2 );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column',    [$this, 'render_tracking_column_hpos'], 10, 2 );

        // REST enrichment
        add_filter( 'woocommerce_rest_prepare_shop_order_object', [$this, 'rest_enrich_order'], 10, 3 );
    }

    /* ===== Front-end capture JS ===== */
    public function enqueue_frontend_capture_js() {
        $o = WCMD_Utils::get_options();
        if ( empty( $o['enabled'] ) ) return;

        $param_keys = WCMD_Utils::lines_to_keys( $o['urlparam_keys'] );

        wp_register_script( 'wc-md-capture', '', [], '1.0', true );
        $js = "(function(){ try { var KEYS = ".wp_json_encode($param_keys)."; var LS_KEY = 'wc_md_url_params'; var TTL_DAYS = 90; function parseQuery(){ var out = {}; var q = window.location.search || ''; if(!q || q.length < 2) return out; q.substring(1).split('&').forEach(function(part){ if(!part) return; var kv = part.split('='); var k = decodeURIComponent(kv[0]||'').trim(); if(!k) return; var v = decodeURIComponent((kv[1]||'').replace(/\\+/g,' ')); if(KEYS.indexOf(k) !== -1 && v !== '') out[k] = v; }); return out; } function readLS(){ try{ var raw = localStorage.getItem(LS_KEY); if(!raw) return {data:{},ts:0}; return JSON.parse(raw); }catch(e){ return {data:{},ts:0}; } } function saveLS(data){ var payload = {data:data, ts:Date.now(), ttl: TTL_DAYS*24*60*60*1000}; localStorage.setItem(LS_KEY, JSON.stringify(payload)); } function merge(a,b){ var c={}; Object.keys(a||{}).forEach(function(k){c[k]=a[k];}); Object.keys(b||{}).forEach(function(k){c[k]=b[k];}); return c; } var found = parseQuery(); if(Object.keys(found).length){ var cur = readLS(); saveLS( merge(cur.data, found) ); } function onReady(fn){ if(document.readyState!='loading'){fn();}else{document.addEventListener('DOMContentLoaded',fn);} } onReady(function(){ if(!document.body) return; var isCheckout = document.body.classList.contains('woocommerce-checkout') || document.querySelector('form.checkout'); if(!isCheckout) return; var bag = readLS(); if(bag && bag.ts && bag.ttl && (Date.now() - bag.ts) > bag.ttl){ bag = {data:{}}; } var params = (bag && bag.data) ? bag.data : {}; if(!params || !Object.keys(params).length) return; var form = document.querySelector('form.checkout'); if(!form) return; Object.keys(params).forEach(function(k){ var v = params[k]; if(typeof v !== 'string' || !v) return; var input = document.createElement('input'); input.type = 'hidden'; input.name = k; input.value = v; form.appendChild(input); }); }); } catch(e){} })();";
        wp_add_inline_script( 'wc-md-capture', $js );
        wp_enqueue_script( 'wc-md-capture' );
    }

    /* ===== Capture on checkout (server-side) ===== */
    public function attach_meta_to_order( $order ) {
        $o = WCMD_Utils::get_options();
        if ( empty($o['enabled']) ) return;

        $cookie_keys = WCMD_Utils::lines_to_keys( $o['cookie_keys'] );
        $param_keys  = WCMD_Utils::lines_to_keys( $o['urlparam_keys'] );

        $data = [ 'common_cookies'=>[], 'url_parameters'=>[] ];

        foreach ( $cookie_keys as $key ) {
            if ( isset($_COOKIE[$key]) && $_COOKIE[$key] !== '' ) {
                $data['common_cookies'][$key] = sanitize_text_field( wp_unslash($_COOKIE[$key]) );
            }
        }

        // GA4's session cookie is named per-property (e.g. _ga_ABC1234XYZ), so
        // it can never be listed by exact name above — capture it automatically
        // whenever present, since it's required to send a session_id to GA4.
        foreach ( $_COOKIE as $cookie_name => $cookie_value ) {
            if ( isset($data['common_cookies'][$cookie_name]) ) continue;
            if ( $cookie_value === '' || ! preg_match('/^_ga_[A-Za-z0-9]+$/', $cookie_name) ) continue;
            $data['common_cookies'][$cookie_name] = sanitize_text_field( wp_unslash($cookie_value) );
        }

        foreach ( $param_keys as $key ) {
            if ( isset($_REQUEST[$key]) && $_REQUEST[$key] !== '' ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $data['url_parameters'][$key] = sanitize_text_field( wp_unslash($_REQUEST[$key]) );
            }
        }

        if ( ! empty($o['store_user_agent']) && ! empty($_SERVER['HTTP_USER_AGENT']) ) {
            $data['user_agent'] = sanitize_text_field( wp_unslash($_SERVER['HTTP_USER_AGENT']) );
        }
        if ( ! empty($o['store_ip']) ) {
            $data['ip_address'] = sanitize_text_field( WCMD_Utils::client_ip() );
        }

        if ( $data['common_cookies'] || $data['url_parameters'] || !empty($data['user_agent']) || !empty($data['ip_address']) ) {
            $order->update_meta_data( WCMD_Utils::META_KEY, $data );
        }

        // Default Not tracked ❌
        if ( '' === $order->get_meta( WCMD_Utils::META_TRACKED, true ) ) {
            $order->update_meta_data( WCMD_Utils::META_TRACKED, '0' );
        }
    }

    /** Also ensure default Not tracked on creation */
    public function mark_not_tracked_on_create( $order_id, $order ) {
        if ( ! $order instanceof \WC_Order ) return;
        if ( '' === $order->get_meta( WCMD_Utils::META_TRACKED, true ) ) {
            $order->update_meta_data( WCMD_Utils::META_TRACKED, '0' );
            $order->save();
        }
    }

    /* ===== Order meta box ===== */
    public function add_order_metabox() {
        add_meta_box( 'wc_md_box', 'Marketing Data', [$this,'render_order_metabox'], 'shop_order', 'side', 'default' );
    }
    public function add_order_metabox_hpos() {
        add_meta_box( 'wc_md_box_hpos', 'Marketing Data', [$this,'render_order_metabox_hpos_cb'], 'woocommerce_page_wc-orders', 'side', 'default' );
    }
    public function render_order_metabox( $post ) {
        $order_id = isset($post->ID) ? (int)$post->ID : 0;
        $this->render_marketing_data_box( $order_id );
    }
    public function render_order_metabox_hpos_cb() {
        $order_id = isset($_GET['id']) ? absint($_GET['id']) : 0; // phpcs:ignore
        $this->render_marketing_data_box( $order_id );
    }
    private function render_marketing_data_box( $order_id ) {
        if ( ! $order_id ) { echo '<p><em>No order ID.</em></p>'; return; }
        $order = wc_get_order( $order_id );
        if ( ! $order ) { echo '<p><em>Order not found.</em></p>'; return; }

        $data       = $order->get_meta( WCMD_Utils::META_KEY );
        $tracked    = $order->get_meta( WCMD_Utils::META_TRACKED );
        $tracked_at = $order->get_meta( WCMD_Utils::META_TRACKED_AT );
        $track_src  = $order->get_meta( WCMD_Utils::META_TRACK_SRC );
        $track_notes= $order->get_meta( WCMD_Utils::META_TRACK_NOTES );
        $dc_sent    = $order->get_meta( WCMD_Utils::META_DC_SENT ) ?: $order->get_meta( WCMD_Utils::LEGACY_META_WH_SENT );
        $ga4_sent   = $order->get_meta( WCMD_Utils::META_GA4_SENT ) ?: $order->get_meta( WCMD_Utils::LEGACY_META_STAPE_SENT );

        $cookies = is_array($data) ? ($data['common_cookies'] ?? ($data['cookies'] ?? [])) : [];
        $params  = is_array($data) ? ($data['url_parameters'] ?? ($data['params'] ?? [])) : [];

        echo '<div style="font-size:12px;line-height:1.5">';
        echo '<strong>Tracking Status</strong><br/>';
        if ( $tracked === '1' ) {
            echo '<span style="color:#117a00;font-weight:bold">Tracked ✅</span>';
            if ( $tracked_at ) echo ' <small>(' . esc_html( $tracked_at ) . ' UTC</small>)';
            if ( $track_src ) echo '<br/><small>Source: ' . esc_html( $track_src ) . '</small>';
            if ( $track_notes ) echo '<br/><small>Notes: ' . esc_html( $track_notes ) . '</small>';
        } elseif ( $tracked === '0' ) {
            echo '<span style="color:#a00;font-weight:bold">Not tracked ❌</span>';
        } else {
            echo '<span>—</span> <small>(no tracking state yet)</small>';
        }
        if ( $dc_sent )  echo '<br/><small>Data Client sent: ' . esc_html( $dc_sent ) . ' UTC</small>';
        if ( $ga4_sent ) echo '<br/><small>GA4 sent: ' . esc_html( $ga4_sent ) . ' UTC</small>';
        echo '<hr/>';

        if ( is_array($data) && ! empty( $data ) ) {
            if ( ! empty( $cookies ) ) {
                echo '<strong>Common Cookies</strong><br/>';
                foreach ( $cookies as $k => $v ) {
                    echo esc_html($k) . ': <code style="word-break:break-all">' . esc_html($v) . "</code><br/>";
                }
                echo '<hr/>';
            }
            if ( ! empty( $params ) ) {
                echo '<strong>URL Parameters</strong><br/>';
                foreach ( $params as $k => $v ) {
                    echo esc_html($k) . ': <code style="word-break:break-all">' . esc_html($v) . "</code><br/>";
                }
                echo '<hr/>';
            }
            if ( ! empty( $data['user_agent'] ) ) {
                echo '<strong>User-Agent</strong><br/>';
                echo '<code style="word-break:break-all">' . esc_html($data['user_agent']) . "</code><br/><hr/>";
            }
            if ( ! empty( $data['ip_address'] ) ) {
                echo '<strong>IP Address</strong><br/>';
                echo '<code>' . esc_html($data['ip_address']) . "</code><br/>";
            }
        }
        echo '</div>';
    }

    /* ===== Orders list: Tracking column ===== */
    public function add_tracking_column( $columns ) {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[$key] = $label;
            if ( $key === 'order_status' ) $new['wcmd_tracking'] = 'Tracking';
        }
        if ( ! isset( $new['wcmd_tracking'] ) ) $new['wcmd_tracking'] = 'Tracking';
        return $new;
    }

    /**
     * Legacy (non-HPOS) list table: read the meta directly via get_post_meta()
     * instead of hydrating a full WC_Order object per row (items, totals,
     * addresses, etc.) — this column only needs one meta value.
     */
    public function render_tracking_column_legacy( $column, $post_id ) {
        if ( $column !== 'wcmd_tracking' ) return;
        $this->render_tracking_badge( get_post_meta( $post_id, WCMD_Utils::META_TRACKED, true ) );
    }

    public function render_tracking_column_hpos( $column, $order ) {
        if ( $column !== 'wcmd_tracking' ) return;
        if ( $order instanceof \WC_Order ) $this->render_tracking_badge( $order->get_meta( WCMD_Utils::META_TRACKED ) );
    }

    private function render_tracking_badge( $tracked ) {
        if ( $tracked === '1' ) {
            echo '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#dcfce7;color:#15803d;">Tracked</span>';
        } else {
            echo '<span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:#fee2e2;color:#b91c1c;">Untracked</span>';
        }
    }

    /* ===== REST enrichment ===== */
    public function rest_enrich_order( $response, $order, $request ) {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return $response;

        $md = $order->get_meta( WCMD_Utils::META_KEY );
        if ( is_array($md) ) {
            if ( ! isset($md['common_cookies']) && isset($md['cookies']) ) {
                $md['common_cookies'] = $md['cookies'];
            }
            if ( ! isset($md['url_parameters']) && isset($md['params']) ) {
                $md['url_parameters'] = $md['params'];
            }
        }

        $response->data['marketing_data'] = $md ?: (object)[];
        $response->data['marketing_track'] = [
            'tracked'            => ( $order->get_meta( WCMD_Utils::META_TRACKED ) === '1' ),
            'tracked_at'         => (string) $order->get_meta( WCMD_Utils::META_TRACKED_AT ),
            'source'             => (string) $order->get_meta( WCMD_Utils::META_TRACK_SRC ),
            'notes'              => (string) $order->get_meta( WCMD_Utils::META_TRACK_NOTES ),
            'dataclient_sent_at' => (string) ( $order->get_meta( WCMD_Utils::META_DC_SENT ) ?: $order->get_meta( WCMD_Utils::LEGACY_META_WH_SENT ) ),
            'ga4_sent_at'        => (string) ( $order->get_meta( WCMD_Utils::META_GA4_SENT ) ?: $order->get_meta( WCMD_Utils::LEGACY_META_STAPE_SENT ) ),
        ];
        return $response;
    }
}
