<?php
if (!defined('ABSPATH')) exit;

final class WCMD_License {
    const OPTION    = 'wcmd_license_simple';
    const GROUP     = 'wcmd_license_group';
    const PAGE_SLUG = 'wcmd-license';
    const SALT      = 'wcmd$2025-simple-b64';

    private static $inst = null;
    public static function instance(){ return self::$inst ?: self::$inst = new self(); }

    private function __construct(){
        add_action('admin_init', [$this,'settings_api_init']);
    }

    public function settings_api_init(){
        register_setting( self::GROUP, self::OPTION, [
            'type'              => 'array',
            'sanitize_callback' => [$this,'sanitize_option'],
            'default'           => ['key'=>'','status'=>'inactive']
        ]);
    }

    public function sanitize_option($input){
        $posted = isset($input['key']) ? (string)$input['key'] : '';
        $norm   = $this->normalize_key($posted);
        $expect = $this->expected_key_for_site();
        $ok     = ($norm && hash_equals($expect, $norm));
        
        add_filter('wp_redirect', function($location) use ($ok) {
            return $ok ? admin_url('admin.php?page=wcmd-main') : admin_url('admin.php?page=wcmd-license&status=invalid');
        });

        return ['key' => $norm, 'status' => $ok ? 'active' : 'inactive'];
    }

    public function render_page(){
        if (!current_user_can('manage_woocommerce')) return;

        $opt      = get_option(self::OPTION, ['key'=>'','status'=>'inactive']);
        $savedKey = (string)($opt['key'] ?? '');
        $active   = ($opt['status'] ?? '') === 'active';
        $domain   = $this->host_domain();
        $invalid  = isset($_GET['status']) && $_GET['status'] === 'invalid'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $icon_check = '<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 0 1 0 1.4l-7.5 7.5a1 1 0 0 1-1.4 0l-3.5-3.5a1 1 0 1 1 1.4-1.4l2.8 2.8 6.8-6.8a1 1 0 0 1 1.4 0Z" clip-rule="evenodd"/></svg>';
        $icon_dot   = '<svg viewBox="0 0 20 20" fill="currentColor"><circle cx="10" cy="10" r="5"/></svg>';
        $icon_ext   = '<svg viewBox="0 0 20 20" fill="currentColor" style="width:13px;height:13px;margin-left:4px;vertical-align:-2px;"><path d="M11 3a1 1 0 1 0 0 2h2.586L7.293 11.293a1 1 0 1 0 1.414 1.414L15 6.414V9a1 1 0 1 0 2 0V4a1 1 0 0 0-1-1h-5Z"/><path d="M5 5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-3a1 1 0 1 0-2 0v3H5V7h3a1 1 0 0 0 0-2H5Z"/></svg>';
        ?>
        <div class="wrap wcmd-wrap">
            <div class="wcmd-header">
                <h1>About & License</h1>
            </div>

            <div class="wcmd-license-page">

                <!-- License card -->
                <div class="wcmd-card" style="margin-bottom:24px;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:6px;">
                        <h2 class="wcmd-section-title" style="margin:0;">License</h2>
                        <span class="status-pill <?php echo $active ? 'active' : 'inactive'; ?>">
                            <?php echo $active ? $icon_check . 'Active' : $icon_dot . 'Inactive'; ?>
                        </span>
                    </div>

                    <?php if ($active): ?>
                        <p class="wcmd-section-desc">This plugin is unlocked and working on this site.</p>
                        <p class="wcmd-license-domain">Licensed for <strong><?php echo esc_html($domain); ?></strong></p>
                    <?php else: ?>
                        <p class="wcmd-section-desc">Enter your license key below to unlock the plugin's tracking and recovery features on this site.</p>
                        <p class="wcmd-license-domain">This key must be issued for <strong><?php echo esc_html($domain); ?></strong></p>
                        <?php if ($invalid): ?>
                            <div style="background:#fee2e2; color:#b91c1c; padding:10px 14px; border-radius:8px; border:1px solid #fecaca; font-size:13px; margin-top:12px;">
                                That key isn't valid for this site. Double-check you copied it correctly, or contact support below.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <form method="post" action="options.php" style="margin-top:18px;">
                        <?php settings_fields(self::GROUP); ?>
                        <div class="wcmd-input-group" style="margin-bottom:16px;">
                            <label for="wcmd_license_key">License key</label>
                            <input name="<?php echo esc_attr(self::OPTION); ?>[key]" id="wcmd_license_key" type="text" class="regular-text" placeholder="AAAA-BBBB" value="<?php echo esc_attr($savedKey); ?>" style="width:100%; padding:10px;" />
                        </div>
                        <button type="submit" class="button button-primary wcmd-btn"><?php echo $active ? 'Update Key' : 'Activate License'; ?></button>
                    </form>
                </div>

                <!-- Plugin info card -->
                <div class="wcmd-card">
                    <div class="wcmd-plugin-identity">
                        <div class="wcmd-plugin-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="2"/><path d="M12 2v4M12 18v4M4.9 4.9l2.8 2.8M16.3 16.3l2.8 2.8M2 12h4M18 12h4M4.9 19.1l2.8-2.8M16.3 7.7l2.8-2.8"/></svg>
                        </div>
                        <div>
                            <div style="font-weight:700; font-size:16px; color:#0f172a;">Server-Side Tracking & Enrichment</div>
                            <div style="font-size:13px; color:#64748b;">by Alif Mahmud</div>
                        </div>
                    </div>

                    <div class="wcmd-info-row">
                        <span class="wcmd-info-label">Version</span>
                        <span class="wcmd-info-value"><?php echo esc_html( defined('WCMD_VERSION') ? WCMD_VERSION : '' ); ?></span>
                    </div>
                    <div class="wcmd-info-row">
                        <span class="wcmd-info-label">Website</span>
                        <span class="wcmd-info-value"><a href="https://alifmahmud.com/" target="_blank" rel="noopener">alifmahmud.com<?php echo $icon_ext; ?></a></span>
                    </div>
                    <div class="wcmd-info-row">
                        <span class="wcmd-info-label">Support</span>
                        <span class="wcmd-info-value"><a href="mailto:info@alifmahmud.com">info@alifmahmud.com</a></span>
                    </div>

                    <p style="margin:20px 0 0; font-size:12px; color:#94a3b8;">&copy; <?php echo esc_html( date('Y') ); ?> Alif Mahmud. All rights reserved.</p>
                </div>

            </div>
        </div>
        <?php
    }

    public function is_valid(): bool {
        $o = get_option(self::OPTION, []);
        return isset($o['status']) && $o['status'] === 'active';
    }

    private function host_domain(): string {
        $host = parse_url(home_url(), PHP_URL_HOST);
        $host = strtolower((string)$host);
        if (strpos($host, 'www.') === 0) $host = substr($host, 4);
        return $host;
    }

    private function normalize_key(string $key): string {
        $k = strtoupper(preg_replace('/[^A-Z0-9]/', '', $key));
        if ($k === '') return '';
        $k = str_pad($k, 8, '0', STR_PAD_LEFT);
        return substr($k,0,4).'-'.substr($k,4,4);
    }

    private function expected_key_for_site(): string {
        $payload = strrev($this->host_domain()) . '|' . self::SALT;
        $crc     = sprintf('%u', crc32($payload));
        $b36     = strtoupper(base_convert($crc, 10, 36));
        $b36     = str_pad($b36, 8, '0', STR_PAD_LEFT);
        return substr($b36,0,4).'-'.substr($b36,4,4);
    }
}