<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class WCMD_Admin_UI {
    private static $inst = null;
    public static function instance() { return self::$inst ?: self::$inst = new self(); }

    /** Pending admin-notice HTML from handle_actions(), rendered by render_own_notice(). */
    private $notice_html = '';

    private function __construct() {
        add_action( 'admin_menu',           [$this, 'register_menus'] );
        add_action( 'admin_init',           [$this, 'register_settings'] );
        add_action( 'admin_enqueue_scripts',[$this, 'enqueue_assets'] );
        add_action( 'admin_init',           [$this, 'handle_actions'] );

        // Other plugins' promo/nag notices (e.g. Elementor) render inside our
        // page header and break its layout. On our own screens, strip every
        // notice and only show ours back.
        add_action( 'in_admin_header', [$this, 'suppress_foreign_notices'], PHP_INT_MAX );
    }

    public function suppress_foreign_notices() {
        $screen = get_current_screen();
        if ( ! $screen || strpos( $screen->id, 'wcmd' ) === false ) return;

        remove_all_actions( 'admin_notices' );
        remove_all_actions( 'all_admin_notices' );
        add_action( 'admin_notices', [$this, 'render_own_notice'] );
    }

    public function render_own_notice() {
        if ( $this->notice_html ) echo $this->notice_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_html() pieces at the call site
    }

    public function enqueue_assets($hook) {
        if ( strpos($hook, 'wcmd') !== false ) {
            wp_enqueue_style( 'wcmd-admin', WCMD_URL . 'assets/css/admin-styles.css', [], WCMD_VERSION );
            if ( strpos($hook, 'wcmd-config') !== false ) {
                $js = "document.addEventListener('DOMContentLoaded', function(){const tabs=document.querySelectorAll('.wcmd-nav-item');const contents=document.querySelectorAll('.wcmd-tab-content');if(tabs.length){tabs.forEach(tab=>{tab.addEventListener('click',function(e){e.preventDefault();tabs.forEach(t=>t.classList.remove('active'));contents.forEach(c=>c.classList.remove('active'));this.classList.add('active');const target=this.getAttribute('data-tab');document.getElementById(target).classList.add('active');const url=new URL(window.location);url.searchParams.set('tab',target);window.history.pushState({},'',url);});});const urlParams=new URLSearchParams(window.location.search);const activeTab=urlParams.get('tab');if(activeTab){const targetTab=document.querySelector(`.wcmd-nav-item[data-tab='`+activeTab+`']`);if(targetTab)targetTab.click();}}});";
                wp_add_inline_script('common', $js);
            }
        }
    }

    public function register_menus() {
        $licensed = WCMD_License::instance()->is_valid();
        $cap      = WCMD_Utils::CAPABILITY;
        $main_slug= 'wcmd-main';

        add_menu_page( 'Server Tracking', 'Server Tracking', $cap, $main_slug, $licensed ? [$this, 'render_overview_page'] : [WCMD_License::instance(), 'render_page'], 'dashicons-networking', 56 );

        if ( $licensed ) {
            add_submenu_page($main_slug, 'Overview', 'Overview', $cap, $main_slug, [$this, 'render_overview_page']);
            add_submenu_page($main_slug, 'Settings', 'Settings', $cap, 'wcmd-config', [$this, 'render_config_page']);
            add_submenu_page($main_slug, 'About & License', 'About & License', $cap, 'wcmd-license', [WCMD_License::instance(), 'render_page']);
        } else {
            add_submenu_page($main_slug, 'License', 'License', $cap, 'wcmd-license', [WCMD_License::instance(), 'render_page']);
            remove_submenu_page($main_slug, $main_slug);
        }
    }

    public function register_settings() {
        register_setting( WCMD_Utils::OPTION_KEY, WCMD_Utils::OPTION_KEY, ['WCMD_Utils','sanitize_options'] );
    }

    /* ==========================================================================
       PAGE: OVERVIEW
       ========================================================================== */
    public function render_overview_page() {
        $total_orders = (int) wp_count_posts('shop_order')->publish;
        if ( class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
            global $wpdb;
            $total_orders = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE status = 'wc-completed' OR status = 'wc-processing'");
        }

        $recent_orders = wc_get_orders(['limit'=>7, 'orderby'=>'date', 'order'=>'DESC']);
        $tracked_count = 0;
        $untracked_count = 0;
        foreach($recent_orders as $o) {
            if($o->get_meta(WCMD_Utils::META_TRACKED) === '1') {
                $tracked_count++;
            } else {
                $untracked_count++;
            }
        }
        ?>
        <div class="wrap wcmd-wrap">
            <div class="wcmd-header">
                <h1>Server Tracking Overview <span class="wcmd-badge">v<?php echo WCMD_VERSION; ?></span></h1>
                <div><a href="<?php echo admin_url('admin.php?page=wcmd-config'); ?>" class="button wcmd-btn button-secondary">Settings</a></div>
            </div>

            <div class="wcmd-grid">
                <div class="wcmd-card">
                    <div class="wcmd-stat-label">📦 Total Orders</div>
                    <div class="wcmd-stat-value"><?php echo number_format_i18n($total_orders); ?></div>
                    <div class="wcmd-stat-desc">Processed orders</div>
                </div>

                <div class="wcmd-card">
                    <div class="wcmd-stat-label">⚠️ Untracked Orders</div>
                    <div class="wcmd-stat-value <?php echo $untracked_count > 0 ? 'text-red' : 'text-green'; ?>">
                        <?php echo $untracked_count; ?> <span style="font-size:14px;color:#94a3b8">/ 7 recent</span>
                    </div>
                    <div class="wcmd-stat-desc">
                        <?php echo $untracked_count > 0 ? "Recovery Sweep will pick these up" : 'All recent orders tracked'; ?>
                    </div>
                </div>

                <div class="wcmd-card">
                    <div class="wcmd-stat-label">🎯 Tracking Rate</div>
                    <div class="wcmd-stat-value text-green"><?php echo $tracked_count; ?> <span style="font-size:14px;color:#94a3b8">/ 7 recent</span></div>
                    <div class="wcmd-stat-desc">Confirmed as tracked</div>
                </div>

                <div class="wcmd-card wcmd-table-card">
                    <div class="wcmd-section-title">Recent Activity</div>
                    <table class="wcmd-table">
                        <thead>
                            <tr>
                                <th>📦 Order</th>
                                <th>📊 Status</th>
                                <th>💰 Total</th>
                                <th>✅ Tracked</th>
                                <th>🔗 Data Client</th>
                                <th>📈 GA4</th>
                                <th>📅 Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($recent_orders)): ?><tr><td colspan="7">No orders found.</td></tr><?php else: foreach($recent_orders as $order):
                                $dc_sent  = $order->get_meta(WCMD_Utils::META_DC_SENT)  ?: $order->get_meta(WCMD_Utils::LEGACY_META_WH_SENT);
                                $ga4_sent = $order->get_meta(WCMD_Utils::META_GA4_SENT) ?: $order->get_meta(WCMD_Utils::LEGACY_META_STAPE_SENT);
                            ?>
                                <tr>
                                    <td><a href="<?php echo $order->get_edit_order_url(); ?>">#<?php echo $order->get_order_number(); ?></a></td>
                                    <td><?php echo ucfirst($order->get_status()); ?></td>
                                    <td><?php echo $order->get_formatted_order_total(); ?></td>
                                    <td><?php echo ($order->get_meta(WCMD_Utils::META_TRACKED)==='1') ? '<span class="wcmd-status-pill status-yes">Yes</span>' : '<span class="wcmd-status-pill status-no">No</span>'; ?></td>
                                    <td><?php echo $dc_sent ? '<span class="dashicons dashicons-yes text-blue"></span>' : '—'; ?></td>
                                    <td><?php echo $ga4_sent ? '<span class="dashicons dashicons-yes text-green"></span>' : '—'; ?></td>
                                    <td><?php echo $order->get_date_created()->date_i18n('M j, H:i'); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="wcmd-card">
                <div class="wcmd-section-title">Test & Recover</div>
                <p class="wcmd-section-desc">Use these to check things are working, or to manually push out purchase data without waiting.</p>

                <div class="wcmd-test-row">
                    <form method="post" class="wcmd-test-form">
                        <?php wp_nonce_field('wc_md_test_realtime_nonce'); ?>
                        <input type="number" name="test_order_id" placeholder="Order ID" style="width:100px;" required />
                        <input type="hidden" name="wcmd_do" value="test_realtime_send" />
                        <button type="submit" class="button wcmd-btn button-secondary">Send this order now</button>
                    </form>
                    <p class="description">Sends one real order right away, ignoring its status and whether it was already sent — useful for checking your destinations actually receive data.</p>
                </div>

                <div class="wcmd-test-row">
                    <form method="post" class="wcmd-test-form">
                        <?php wp_nonce_field('wc_md_test_payload_nonce'); ?>
                        <input type="hidden" name="wcmd_do" value="send_test_payload" />
                        <button type="submit" class="button wcmd-btn button-secondary">Send a sample purchase</button>
                    </form>
                    <p class="description">Sends made-up test data (not a real order) to your enabled destinations, so you can see it show up without needing a real sale.</p>
                </div>

                <div class="wcmd-test-row">
                    <form method="post" class="wcmd-test-form">
                        <?php wp_nonce_field('wc_md_run_recovery_nonce'); ?>
                        <input type="hidden" name="wcmd_do" value="run_recovery_now" />
                        <button type="submit" class="button wcmd-btn button-primary wcmd-btn-danger">Recover missed orders now</button>
                    </form>
                    <p class="description">Runs Recovery Sweep immediately instead of waiting for its schedule (up to 50 orders per click).</p>
                </div>
            </div>
        </div>
        <?php
    }

    /* ==========================================================================
       PAGE: SETTINGS
       ========================================================================== */
    public function render_config_page() {
        $opts = WCMD_Utils::get_options();
        $statuses = wc_get_order_statuses();

        // Cached for an hour — avoids a blocking network round-trip to wp-cron.php on every Settings page view.
        $cron_msg = '';
        if ( ! empty($opts['recovery_enabled']) && $opts['recovery_schedule'] !== 'off' ) {
            $cron_ok = get_transient( 'wcmd_cron_loopback_ok' );
            if ( false === $cron_ok ) {
                $loopback = wp_remote_post( site_url('/wp-cron.php'), ['timeout'=>3, 'blocking'=>true] );
                $cron_ok  = ! ( is_wp_error($loopback) || wp_remote_retrieve_response_code($loopback) >= 400 );
                set_transient( 'wcmd_cron_loopback_ok', $cron_ok, HOUR_IN_SECONDS );
            }
            if ( ! $cron_ok ) {
                $cron_msg = '<div style="background:#fee2e2; color:#b91c1c; padding:12px; border-radius:8px; margin-bottom:20px; border:1px solid #fecaca; font-size:13px;">
                    <strong>Heads up:</strong> WordPress couldn\'t reach its own scheduler just now. If your site gets little traffic, "Check back and catch anything missed" may run late — ask your host to set up a real server cron for reliable timing.
                </div>';
            }
        }

        $opt_key = WCMD_Utils::OPTION_KEY;
        ?>
        <div class="wrap wcmd-wrap">
            <div class="wcmd-header">
                <h1>Settings</h1>
                <a href="<?php echo admin_url('admin.php?page=wcmd-main'); ?>" class="button wcmd-btn">&larr; Back to Overview</a>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields( $opt_key ); ?>

                <div class="wcmd-config-container">
                    <div class="wcmd-sidebar">
                        <button type="button" class="wcmd-nav-item active" data-tab="tab-capture">📸 Data Capture</button>
                        <button type="button" class="wcmd-nav-item" data-tab="tab-destinations">🎯 Destinations</button>
                        <button type="button" class="wcmd-nav-item" data-tab="tab-triggers">⚡ Triggers</button>
                        <button type="button" class="wcmd-nav-item" data-tab="tab-incoming">🔗 Incoming API</button>
                    </div>

                    <div class="wcmd-content-area">

                        <!-- ============ DATA CAPTURE ============ -->
                        <div id="tab-capture" class="wcmd-tab-content wcmd-card active">
                            <h2 class="wcmd-section-title">Data Capture</h2>
                            <p class="wcmd-section-desc">Every order saves the tracking info the plugin can see at checkout — which ad or campaign the customer came from, and their cookies from Google, Meta, TikTok, etc. This is what gets sent out later.</p>
                            <div class="wcmd-input-group"><label><input type="checkbox" name="<?php echo $opt_key; ?>[enabled]" value="1" <?php checked($opts['enabled'],1); ?> /> Turn on data capture</label></div>
                            <div class="wcmd-input-group"><label>Cookies to save</label><textarea name="<?php echo $opt_key; ?>[cookie_keys]" rows="5"><?php echo esc_textarea($opts['cookie_keys']); ?></textarea><span class="description">One cookie name per line. These are the small tracking cookies set by ad platforms (e.g. <code>_ga</code> for Google Analytics, <code>_fbp</code> for Meta) that this plugin will read and save.</span></div>
                            <div class="wcmd-input-group"><label>Link parameters to save</label><textarea name="<?php echo $opt_key; ?>[urlparam_keys]" rows="5"><?php echo esc_textarea($opts['urlparam_keys']); ?></textarea><span class="description">One parameter per line. These are the tags that show up in a link when someone clicks an ad (e.g. <code>gclid</code> from Google Ads, <code>utm_source</code> from a campaign link).</span></div>
                            <div class="wcmd-input-group"><label><input type="checkbox" name="<?php echo $opt_key; ?>[store_user_agent]" value="1" <?php checked($opts['store_user_agent'],1); ?> /> Save the customer's browser/device info</label><label><input type="checkbox" name="<?php echo $opt_key; ?>[store_ip]" value="1" <?php checked($opts['store_ip'],1); ?> /> Save the customer's IP address</label></div>
                        </div>

                        <!-- ============ DESTINATIONS ============ -->
                        <div id="tab-destinations" class="wcmd-tab-content wcmd-card">
                            <h2 class="wcmd-section-title">Destinations</h2>
                            <p class="wcmd-section-desc">Turn on the places you want purchase data sent to. That's the only switch each one needs — the Triggers tab decides <em>when</em> to send, and automatically uses whatever you turn on here.</p>

                            <div class="wcmd-input-group" style="border:1px solid #dbeafe; background:#eff6ff; padding:15px; border-radius:8px;">
                                <label style="color:#1e40af; font-size:15px;">
                                    <input type="checkbox" name="<?php echo $opt_key; ?>[dataclient_enabled]" value="1" <?php checked($opts['dataclient_enabled'],1); ?> />
                                    <strong>Data Client Webhook</strong>
                                </label>
                                <p class="description" style="margin-top:5px;">Sends the full order details to any web address you choose. Use this for a custom server-side GTM Data Client, a CRM, or any system other than GA4.</p>
                                <div class="wcmd-input-group" style="margin-top:10px;">
                                    <label>Web address to send to</label>
                                    <input type="url" name="<?php echo $opt_key; ?>[dataclient_endpoint]" value="<?php echo esc_attr($opts['dataclient_endpoint']); ?>" placeholder="https://your-sgtm.example.com/data-client" style="width:100%;" />
                                </div>
                            </div>

                            <div class="wcmd-input-group" style="border:1px solid #dcfce7; background:#f0fdf4; padding:15px; border-radius:8px; margin-top:15px;">
                                <label style="color:#15803d; font-size:15px;">
                                    <input type="checkbox" name="<?php echo $opt_key; ?>[ga4_enabled]" value="1" <?php checked($opts['ga4_enabled'],1); ?> />
                                    <strong>GA4 (Google Analytics 4)</strong>
                                </label>
                                <p class="description" style="margin-top:5px;">Sends a purchase event to Google Analytics 4, built from the visitor info this plugin already saved (the customer's ad-click IDs, cookies, IP, etc.) — no extra lookup step.</p>
                                <div class="wcmd-input-group" style="margin-top:10px;">
                                    <label>Web address to send to</label>
                                    <input type="url" name="<?php echo $opt_key; ?>[ga4_endpoint]" value="<?php echo esc_attr($opts['ga4_endpoint']); ?>" placeholder="https://your-sgtm.example.com/ga4-client" style="width:100%;" />
                                    <span class="description">Your server-side GTM container's GA4 endpoint.</span>
                                </div>
                            </div>

                            <hr style="border:0; border-top:1px solid #e2e8f0; margin:20px 0;">
                            <div class="wcmd-input-group">
                                <label><input type="checkbox" name="<?php echo $opt_key; ?>[skip_if_tracked]" value="1" <?php checked($opts['skip_if_tracked'],1); ?> /> <strong>Don't send it again if it's already confirmed</strong></label>
                                <p class="description">Applies to both destinations above. If the Incoming API (see that tab) already told this plugin an order's purchase was received elsewhere, skip it here — so you never count the same sale twice.</p>
                            </div>
                        </div>

                        <!-- ============ TRIGGERS (combined Real-Time + Recovery) ============ -->
                        <div id="tab-triggers" class="wcmd-tab-content wcmd-card">
                            <h2 class="wcmd-section-title">Triggers</h2>
                            <p class="wcmd-section-desc">This decides <em>when</em> purchase data goes out to the destinations you turned on. You can use just one method below, or both together so nothing slips through — Recovery Sweep only ever picks up what Real-Time missed, so the same order is never sent twice.</p>

                            <?php if ( empty($opts['dataclient_enabled']) && empty($opts['ga4_enabled']) ): ?>
                                <div style="background:#fff7ed; color:#9a3412; padding:12px; border-radius:8px; margin-bottom:20px; border:1px solid #ffedd5; font-size:13px;">
                                    You haven't turned on a destination yet. Visit the <strong>Destinations</strong> tab and enable Data Client and/or GA4 first, or nothing below will actually send.
                                </div>
                            <?php endif; ?>

                            <div class="wcmd-input-group">
                                <label>Which order status counts as "sold"?</label>
                                <p class="description" style="margin-top:0;">Tick the status (or statuses) that mean a real, paid order — not a cart that was abandoned or a refund. Both methods below use this same list.</p>
                                <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; max-width:500px; background:#f8fafc; padding:10px; border:1px solid #e2e8f0; border-radius:6px;">
                                    <?php
                                    $saved_statuses = $opts['trigger_statuses'] ?? ['processing'];
                                    foreach($statuses as $slug => $label):
                                        $clean_slug = str_replace('wc-', '', $slug);
                                    ?>
                                        <label style="font-weight:normal; font-size:13px; margin:0;"><input type="checkbox" name="<?php echo $opt_key; ?>[trigger_statuses][]" value="<?php echo esc_attr($clean_slug); ?>" <?php checked(in_array($clean_slug, $saved_statuses)); ?> /> <?php echo esc_html($label); ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <hr style="border:0; border-top:1px solid #e2e8f0; margin:20px 0;">

                            <div class="wcmd-input-group" style="border:1px solid #e2e8f0; padding:15px; border-radius:8px;">
                                <label style="font-size:15px;"><input type="checkbox" name="<?php echo $opt_key; ?>[realtime_enabled]" value="1" <?php checked($opts['realtime_enabled'],1); ?> /> <strong>⚡ Send right away</strong></label>
                                <p class="description" style="margin:6px 0 0;">The moment an order reaches one of the statuses above, send it — no waiting.</p>
                                <div class="wcmd-input-group" style="margin-top:10px;">
                                    <label>Wait a few seconds first (optional)</label>
                                    <input type="number" min="0" max="60" name="<?php echo $opt_key; ?>[realtime_delay]" value="<?php echo esc_attr($opts['realtime_delay']); ?>" style="width:100px;" />
                                    <span class="description">Delays sending by this many seconds. Only useful if you need other scripts on your site to finish first — leave the default if you're not sure.</span>
                                </div>
                            </div>

                            <div class="wcmd-input-group" style="border:1px solid #e2e8f0; padding:15px; border-radius:8px; margin-top:15px;">
                                <label style="font-size:15px;"><input type="checkbox" name="<?php echo $opt_key; ?>[recovery_enabled]" value="1" <?php checked($opts['recovery_enabled'],1); ?> /> <strong>🔄 Check back and catch anything missed</strong></label>
                                <p class="description" style="margin:6px 0 0;">Every so often, look back over recent orders for any still in one of the statuses above that never got sent — and send those now. This is your safety net if "Send right away" is off, misses an order, or the site was briefly down.</p>

                                <?php echo $cron_msg; ?>
                                <div class="wcmd-input-group" style="margin-top:10px;">
                                    <label>How often to check</label>
                                    <select name="<?php echo $opt_key; ?>[recovery_schedule]">
                                        <option value="off" <?php selected($opts['recovery_schedule'],'off'); ?>>Off</option>
                                        <option value="every_1_min" <?php selected($opts['recovery_schedule'],'every_1_min'); ?>>Every 1 minute</option>
                                        <option value="every_15_min" <?php selected($opts['recovery_schedule'],'every_15_min'); ?>>Every 15 minutes</option>
                                        <option value="every_30_min" <?php selected($opts['recovery_schedule'],'every_30_min'); ?>>Every 30 minutes</option>
                                        <option value="hourly" <?php selected($opts['recovery_schedule'],'hourly'); ?>>Every hour</option>
                                        <option value="twicedaily" <?php selected($opts['recovery_schedule'],'twicedaily'); ?>>Twice a day</option>
                                        <option value="daily" <?php selected($opts['recovery_schedule'],'daily'); ?>>Once a day</option>
                                    </select>
                                </div>
                                <div class="wcmd-input-group">
                                    <label>How far back to look</label>
                                    <input type="number" min="1" max="90" name="<?php echo $opt_key; ?>[recovery_window_days]" value="<?php echo esc_attr($opts['recovery_window_days']); ?>" style="width:100px;" /> <span class="description" style="display:inline;">days</span>
                                    <span class="description">Only checks orders placed within this many days — older orders are left alone.</span>
                                </div>
                            </div>
                        </div>

                        <!-- ============ INCOMING API ============ -->
                        <div id="tab-incoming" class="wcmd-tab-content wcmd-card">
                            <h2 class="wcmd-section-title">Incoming API</h2>
                            <p class="wcmd-section-desc">This is a web address other systems can call to tell this plugin "this order's purchase was received." For example, your server-side GTM container can confirm it got the event, and that flips the order to Tracked — which then powers the "don't send it again if it's already confirmed" option on the Destinations tab. You only need this if you're connecting an outside system; most stores can leave it off.</p>
                            <div class="wcmd-input-group"><label><input type="checkbox" name="<?php echo $opt_key; ?>[webhook_enabled]" value="1" <?php checked($opts['webhook_enabled'],1); ?> /> Allow outside systems to mark orders as tracked</label></div>
                            <div class="wcmd-input-group"><label>Shared secret</label><input type="text" name="<?php echo $opt_key; ?>[webhook_secret]" value="<?php echo esc_attr($opts['webhook_secret']); ?>" /><span class="description">A password only you and the calling system know, so random requests on the internet can't mark your orders as tracked.</span></div>
                            <p style="background:#f1f5f9;padding:10px;border-radius:6px;font-size:12px">Web address to call: <code><?php echo esc_url(rest_url('wc-marketing/v1/track')); ?></code></p>
                        </div>

                        <div style="margin-top:20px;text-align:right"><?php submit_button('Save Changes', 'primary wcmd-btn', 'submit', false); ?></div>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    public function handle_actions() {
        if ( ! current_user_can( WCMD_Utils::CAPABILITY ) || empty($_POST['wcmd_do']) ) return;

        if ( $_POST['wcmd_do'] === 'test_realtime_send' && check_admin_referer('wc_md_test_realtime_nonce') ) {
            $order_id = absint( $_POST['test_order_id'] );
            if ( class_exists('WCMD_Realtime_Trigger') ) {
                $res = WCMD_Realtime_Trigger::instance()->send( $order_id, true );
                if ( is_wp_error($res) ) {
                    $this->notice_html = '<div class="notice notice-error"><p>Couldn\'t send: '.esc_html($res->get_error_message()).'</p></div>';
                } else {
                    $this->notice_html = '<div class="notice notice-success"><p>Sent! Reached: '.esc_html(implode(', ', array_keys(array_filter($res)))).'</p></div>';
                }
            }
        }

        if ( $_POST['wcmd_do'] === 'send_test_payload' && check_admin_referer('wc_md_test_payload_nonce') ) {
            if ( class_exists('WCMD_Dispatcher') ) {
                $res = WCMD_Dispatcher::instance()->send_test('both');
                if ( is_wp_error($res) ) {
                    $this->notice_html = '<div class="notice notice-error"><p>Couldn\'t send: '.esc_html($res->get_error_message()).'</p></div>';
                } else {
                    $this->notice_html = '<div class="notice notice-success"><p>Sample purchase sent — check your destination for it.</p></div>';
                }
            }
        }

        if ( $_POST['wcmd_do'] === 'run_recovery_now' && check_admin_referer('wc_md_run_recovery_nonce') ) {
            $count = WCMD_Recovery_Scheduler::instance()->manual_run_now();
            $this->notice_html = '<div class="notice notice-success"><p>Done — sent '.intval($count).' orders (up to 50 per click). Click again if you still see untracked orders.</p></div>';
        }
    }
}
