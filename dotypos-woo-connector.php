<?php
/**
 * Plugin Name: Dotypos ↔ WooCommerce Connector
 * Description: Stable integration between WooCommerce and Dotypos (Dotykačka) API v2. Handles connector v2 OAuth (refresh token), token refresh, order push to POS, diagnostics and logs.
 * Version: 0.4.5-packaging-aggregated
 * Author: Mammarosa (custom)
 */

if (!defined('ABSPATH')) exit;

final class Dotypos_Woo_Connector {
    const OPT_KEY = 'dwco_options';
    const LOG_OPT_KEY = 'dwco_logs';
    const CALLBACK_PATH = '/dotypos-auth/';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_filter('cron_schedules', [__CLASS__, 'add_cron_schedules']);
        add_action('admin_init', [__CLASS__, 'admin_init']);
        add_action('template_redirect', [__CLASS__, 'maybe_handle_callback']);
        add_action('init', [__CLASS__, 'register_product_meta_ui']);
        add_action('add_meta_boxes', [__CLASS__, 'add_order_metabox']);
        add_action('save_post_product', [__CLASS__, 'save_product_meta'], 10, 2);

        // Push orders to Dotypos when order is created / payment is COD.
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'maybe_push_order_on_checkout'], 20, 3);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'maybe_push_order_on_processing'], 20, 2);
        add_action('dwco_sync_event', [__CLASS__, 'run_scheduled_sync']);
        add_action('dwco_retry_push_order', [__CLASS__, 'retry_push_order'], 10, 2);
        add_action('dwco_daily_report_cron_check', [__CLASS__, 'maybe_send_scheduled_daily_report']);
    }

    public static function defaults(): array {
        return [
            'client_id' => '',
            'client_secret' => '',
            'cloud_id' => '',
            'branch_id' => '',
            'refresh_token' => '',
            'state' => 'mammarosa_wp_001',
            'enabled' => 'yes',
            'push_on_status' => 'checkout', // checkout | processing
            'debug_log' => 'yes',
            'delivery_city_product_id' => '',
            'delivery_km_product_id' => '',
            'delivery_city_price' => '5',
            'delivery_km_rate' => '2',
            'default_addons_customization_id' => '',
            'addons_debug_meta' => 'no',
            'sync_enabled' => 'no',
            'sync_interval_minutes' => '10',
            'sync_overwrite_prices' => 'yes',
            'sync_overwrite_names' => 'yes',
            'sync_import_hidden' => 'no',
            'sync_exclude_delivery_products' => 'yes',
            'etag_products' => '',
            'etag_categories' => '',
            // Daily report SMS
            'daily_report_enabled'               => 'no',
            'daily_report_phone'                 => '',
            'daily_report_smsapi_token'          => '',
            'daily_report_sms_sender'            => '',
            'daily_report_card_payment_method_id'=> '900000002',
            'daily_report_pizza_category_id'     => '1871188158721371',
            'daily_report_branch_sala_id'        => '146005859',
            'daily_report_branch_ogrod_id'       => '150149839',
        ];
    }

    public static function get_options(): array {
        $opts = get_option(self::OPT_KEY, []);
        return array_merge(self::defaults(), is_array($opts) ? $opts : []);
    }

    public static function update_options(array $new): void {
        $opts = self::get_options();
        $merged = array_merge($opts, $new);
        update_option(self::OPT_KEY, $merged, false);
    }

    public static function log(string $level, string $message, array $context = []): void {
        $opts = self::get_options();
        if (($opts['debug_log'] ?? 'yes') !== 'yes' && $level === 'debug') return;

        $logs = get_option(self::LOG_OPT_KEY, []);
        if (!is_array($logs)) $logs = [];

        $entry = [
            'ts' => gmdate('c'),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
        array_unshift($logs, $entry);
        // Keep last 500 entries
        $logs = array_slice($logs, 0, 500);
        update_option(self::LOG_OPT_KEY, $logs, false);
    }

    public static function admin_menu() {
        add_menu_page(
            'Dotypos ↔ Woo',
            'Dotypos ↔ Woo',
            'manage_options',
            'dwco',
            [__CLASS__, 'render_admin_page'],
            'dashicons-cart',
            56
        );
    }

    public static function admin_init() {
        register_setting('dwco', self::OPT_KEY, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_options'],
            'default' => self::defaults(),
        ]);

        add_settings_section('dwco_main', 'Połączenie Dotypos', function () {
            echo '<p>Wtyczka sama ogarnia tokeny (Connector v2 → Refresh Token → Access Token) i wysyła zamówienia WooCommerce jako rachunek do Dotykački.</p>';
        }, 'dwco');

        add_settings_field('client_id', 'client_id', [__CLASS__, 'field_text'], 'dwco', 'dwco_main', ['key' => 'client_id', 'placeholder' => 'np. restaurant_online_orders']);
        add_settings_field('client_secret', 'client_secret', [__CLASS__, 'field_password'], 'dwco', 'dwco_main', ['key' => 'client_secret', 'placeholder' => '••••••••']);
        add_settings_field('cloud_id', 'cloudId', [__CLASS__, 'field_text'], 'dwco', 'dwco_main', ['key' => 'cloud_id', 'placeholder' => 'np. 398610248']);
        add_settings_field('branch_id', 'branchId', [__CLASS__, 'field_text'], 'dwco', 'dwco_main', ['key' => 'branch_id', 'placeholder' => 'np. 146005859']);

        add_settings_field('delivery_city_product_id', 'Dotypos productId: DOWÓZ Kościerzyna (stałe)', [__CLASS__, 'field_text'], 'dwco', 'dwco_main', ['key' => 'delivery_city_product_id', 'placeholder' => 'np. 1879633354521263']);
        add_settings_field('delivery_city_price', 'Cena dowozu (Kościerzyna) w Woo', [__CLASS__, 'field_text'], 'dwco', 'dwco_main', ['key' => 'delivery_city_price', 'placeholder' => 'np. 5']);
        add_settings_field('delivery_km_product_id', 'Dotypos productId: DOWÓZ (1 km)', [__CLASS__, 'field_text'], 'dwco', 'dwco_main', ['key' => 'delivery_km_product_id', 'placeholder' => 'np. 1912446998642827 (jeśli masz taki produkt)']);
        add_settings_field('delivery_km_rate', 'Stawka za 1 km w Woo', [__CLASS__, 'field_text'], 'dwco', 'dwco_main', ['key' => 'delivery_km_rate', 'placeholder' => 'np. 2']);
        add_settings_field('default_addons_customization_id', 'Dotypos productCustomizationId: DODATKI (toppingi) – przypnij pod pizzę', [__CLASS__, 'field_text'], 'dwco', 'dwco_main', ['key' => 'default_addons_customization_id', 'placeholder' => 'np. 1872311111111111']);
        add_settings_field('addons_debug_meta', 'Debug: dopisz meta pozycji do notatki zamówienia (pomocne do dodatków)', [__CLASS__, 'field_yesno'], 'dwco', 'dwco_main', ['key' => 'addons_debug_meta']);
        add_settings_field('state', 'state', [__CLASS__, 'field_text'], 'dwco', 'dwco_main', ['key' => 'state', 'placeholder' => 'np. mammarosa_wp_001']);
        add_settings_field('refresh_token', 'Refresh token', [__CLASS__, 'field_password'], 'dwco', 'dwco_main', ['key' => 'refresh_token', 'placeholder' => 'uzupełni się po połączeniu']);
        add_settings_field('enabled', 'Włącz integrację', [__CLASS__, 'field_yesno'], 'dwco', 'dwco_main', ['key' => 'enabled']);
        add_settings_field('push_on_status', 'Kiedy wysyłać zamówienie do Dotypos', [__CLASS__, 'field_select'], 'dwco', 'dwco_main', [
            'key' => 'push_on_status',
            'options' => [
                'checkout' => 'Od razu po złożeniu zamówienia (checkout)',
                'processing' => 'Gdy status zmieni się na Processing',
            ]
        ]);
        add_settings_field('debug_log', 'Logowanie diagnostyczne', [__CLASS__, 'field_yesno'], 'dwco', 'dwco_main', ['key' => 'debug_log']);

        add_settings_field('sync_enabled', 'Auto-sync menu (WP-Cron)', [__CLASS__, 'field_yesno'], 'dwco', 'dwco_main', ['key' => 'sync_enabled']);
        add_settings_field('sync_interval_minutes', 'Interwał sync (min)', [__CLASS__, 'field_text'], 'dwco', 'dwco_main', ['key' => 'sync_interval_minutes', 'placeholder' => 'np. 10']);
        add_settings_field('sync_overwrite_prices', 'Nadpisuj ceny z Dotykački', [__CLASS__, 'field_yesno'], 'dwco', 'dwco_main', ['key' => 'sync_overwrite_prices']);
        add_settings_field('sync_overwrite_names', 'Nadpisuj nazwy z Dotykački', [__CLASS__, 'field_yesno'], 'dwco', 'dwco_main', ['key' => 'sync_overwrite_names']);
        add_settings_field('sync_import_hidden', 'Importuj też pozycje ukryte (display=false)', [__CLASS__, 'field_yesno'], 'dwco', 'dwco_main', ['key' => 'sync_import_hidden']);
        add_settings_field('sync_exclude_delivery_products', 'Nie importuj produktów DOWÓZ', [__CLASS__, 'field_yesno'], 'dwco', 'dwco_main', ['key' => 'sync_exclude_delivery_products']);

        // Daily report settings section
        add_settings_section('dwco_daily_report', 'Raport dzienny SMS', function () {
            echo '<p>Automatyczny raport dzienny ze sprzedaży (SALA + OGRÓD) wysyłany przez SMSAPI. Harmonogram: pn–czw, nd o 22:40; pt–sb o 23:40.</p>';
        }, 'dwco');

        add_settings_field('daily_report_enabled', 'Włącz raport SMS', [__CLASS__, 'field_yesno'], 'dwco', 'dwco_daily_report', ['key' => 'daily_report_enabled']);
        add_settings_field('daily_report_phone', 'Numer telefonu', [__CLASS__, 'field_text'], 'dwco', 'dwco_daily_report', ['key' => 'daily_report_phone', 'placeholder' => 'np. 48500000000']);
        add_settings_field('daily_report_smsapi_token', 'SMSAPI token', [__CLASS__, 'field_password'], 'dwco', 'dwco_daily_report', ['key' => 'daily_report_smsapi_token', 'placeholder' => '••••••••']);
        add_settings_field('daily_report_sms_sender', 'Nadawca SMS', [__CLASS__, 'field_text'], 'dwco', 'dwco_daily_report', ['key' => 'daily_report_sms_sender', 'placeholder' => 'np. MAMMAROSA']);
        add_settings_field('daily_report_branch_sala_id', 'Branch ID SALA', [__CLASS__, 'field_text'], 'dwco', 'dwco_daily_report', ['key' => 'daily_report_branch_sala_id', 'placeholder' => '146005859']);
        add_settings_field('daily_report_branch_ogrod_id', 'Branch ID OGRÓD', [__CLASS__, 'field_text'], 'dwco', 'dwco_daily_report', ['key' => 'daily_report_branch_ogrod_id', 'placeholder' => '150149839']);
        add_settings_field('daily_report_card_payment_method_id', 'Payment method ID karta', [__CLASS__, 'field_text'], 'dwco', 'dwco_daily_report', ['key' => 'daily_report_card_payment_method_id', 'placeholder' => '900000002']);
        add_settings_field('daily_report_pizza_category_id', 'Category ID PIZZA', [__CLASS__, 'field_text'], 'dwco', 'dwco_daily_report', ['key' => 'daily_report_pizza_category_id', 'placeholder' => '1871188158721371']);

        // Custom actions
        add_action('admin_post_dwco_connect', [__CLASS__, 'handle_admin_connect']);
        add_action('admin_post_dwco_test', [__CLASS__, 'handle_admin_test']);
        add_action('admin_post_dwco_clear_logs', [__CLASS__, 'handle_clear_logs']);
        add_action('admin_post_dwco_import_menu', [__CLASS__, 'handle_import_menu']);
        add_action('admin_post_dwco_sync_now', [__CLASS__, 'handle_sync_now']);
        add_action('admin_post_dwco_list_customizations', [__CLASS__, 'handle_list_customizations']);
        add_action('admin_post_dwco_check_customization_id', [__CLASS__, 'handle_check_customization_id']);
        add_action('admin_post_dwco_test_daily_report', [__CLASS__, 'handle_test_daily_report']);
        add_action('admin_post_dwco_send_last_daily_report_sms', [__CLASS__, 'handle_send_last_daily_report_sms']);
    }

    public static function sanitize_options($input) {
        $out = self::get_options();
        $keys = array_keys(self::defaults());
        foreach ($keys as $k) {
            if (!isset($input[$k])) continue;
            $v = $input[$k];
            if (in_array($k, ['client_secret', 'refresh_token', 'daily_report_smsapi_token'], true)) {
                // allow empty to keep previous
                $v = is_string($v) ? trim($v) : '';
                if ($v === '') continue;
                $out[$k] = $v;
            } else {
                $out[$k] = is_string($v) ? sanitize_text_field($v) : $v;
            }
        }
        return $out;
    }

    public static function field_text($args) {
        $opts = self::get_options();
        $key = $args['key'];
        $val = esc_attr($opts[$key] ?? '');
        $ph = esc_attr($args['placeholder'] ?? '');
        echo "<input type='text' class='regular-text' name='".self::OPT_KEY."[$key]' value='$val' placeholder='$ph' />";
    }
    public static function field_password($args) {
        $opts = self::get_options();
        $key = $args['key'];
        $val = esc_attr($opts[$key] ?? '');
        $ph = esc_attr($args['placeholder'] ?? '');
        echo "<input type='password' class='regular-text' name='".self::OPT_KEY."[$key]' value='$val' placeholder='$ph' autocomplete='new-password' />";
        if ($key === 'refresh_token' && !empty($opts['refresh_token'])) {
            echo '<p class="description">Token zapisany. Mo&#380;esz klikn&#261;&#263; &bdquo;Test po&#322;&#261;czenia&rdquo;.</p>';
        }
    }
    public static function field_yesno($args) {
        $opts = self::get_options();
        $key = $args['key'];
        $val = $opts[$key] ?? 'yes';
        echo "<select name='".self::OPT_KEY."[$key]'><option value='yes' ".selected($val,'yes',false).">Tak</option><option value='no' ".selected($val,'no',false).">Nie</option></select>";
    }
    public static function field_select($args) {
        $opts = self::get_options();
        $key = $args['key'];
        $val = $opts[$key] ?? '';
        echo "<select name='".self::OPT_KEY."[$key]'>";
        foreach (($args['options'] ?? []) as $k=>$label) {
            echo "<option value='".esc_attr($k)."' ".selected($val,$k,false).">".esc_html($label)."</option>";
        }
        echo "</select>";
    }

    public static function render_admin_page() {
        if (!current_user_can('manage_options')) return;
        $opts = self::get_options();
        $callbackUrl = esc_url(home_url(self::CALLBACK_PATH));
        $logs = get_option(self::LOG_OPT_KEY, []);
        if (!is_array($logs)) $logs = [];

        echo "<div class='wrap'><h1>Dotypos ↔ WooCommerce</h1>";

        if (isset($_GET['dwco_msg'])) {
            $msg = sanitize_text_field($_GET['dwco_msg']);
            echo "<div class='notice notice-success'><p>$msg</p></div>";
        }
        if (isset($_GET['dwco_err'])) {
            $msg = sanitize_text_field($_GET['dwco_err']);
            echo "<div class='notice notice-error'><p>$msg</p></div>";
        }

        echo "<h2 class='nav-tab-wrapper'>
            <a class='nav-tab ".(!isset($_GET['tab']) || $_GET['tab']==='settings' ? 'nav-tab-active':'')."' href='".esc_url(admin_url('admin.php?page=dwco&tab=settings'))."'>Ustawienia</a>
            <a class='nav-tab ".(isset($_GET['tab']) && $_GET['tab']==='diagnostics' ? 'nav-tab-active':'')."' href='".esc_url(admin_url('admin.php?page=dwco&tab=diagnostics'))."'>Diagnostyka</a>
            <a class='nav-tab ".(isset($_GET['tab']) && $_GET['tab']==='sync' ? 'nav-tab-active':'')."' href='".esc_url(admin_url('admin.php?page=dwco&tab=sync'))."'>Synchronizacja</a>
            <a class='nav-tab ".(isset($_GET['tab']) && $_GET['tab']==='logs' ? 'nav-tab-active':'')."' href='".esc_url(admin_url('admin.php?page=dwco&tab=logs'))."'>Logi</a>
        </h2>";

        $tab = $_GET['tab'] ?? 'settings';
        $tab = sanitize_text_field($tab);

        if ($tab === 'settings') {
            echo "<p><strong>Callback URL (redirect_uri):</strong> <code>$callbackUrl</code></p>";
            echo "<form method='post' action='options.php'>";
            settings_fields('dwco');
            do_settings_sections('dwco');
            submit_button('Zapisz ustawienia');
            echo "</form>";

            echo "<hr>";
            echo "<h2>Mapowanie dostawy</h2><p>Jeśli chcesz, aby koszt dostawy był widoczny w Dotykačce jako pozycja (produkt), wpisz productId dla <em>dowóz Kościerzyna</em> (stałe 5 zł) i/lub productId dla <em>dowóz 1 km</em> (dla stawki 2 zł/km). Wtyczka doda tę pozycję na podstawie kosztu dostawy w WooCommerce.</p>";
            echo "<hr>";
            echo "<h2>Połącz z Dotypos (Connector v2)</h2>";
            echo "<p>Ten przycisk otworzy stronę Dotypos, gdzie zaakceptujesz integrację. Po akceptacji wrócisz na <code>$callbackUrl</code> z nowym refresh tokenem (zapiszemy go automatycznie).</p>";
            echo "<form method='post' action='".esc_url(admin_url('admin-post.php'))."'>";
            echo "<input type='hidden' name='action' value='dwco_connect' />";
            wp_nonce_field('dwco_connect');
            submit_button('Połącz / Odśwież token', 'secondary');
            echo "</form>";
        }

        if ($tab === 'diagnostics') {
            echo "<h2>Test połączenia</h2>";
            echo "<p>Wykonuje: <code>POST /v2/signin/token</code> i <code>GET /v2/clouds/{cloudId}</code> oraz listę branchy.</p>";
            echo "<form method='post' action='".esc_url(admin_url('admin-post.php'))."'>";
            echo "<input type='hidden' name='action' value='dwco_test' />";
            wp_nonce_field('dwco_test');
            submit_button('Test połączenia', 'primary');
            echo "</form>";

            echo "<hr><h2>Customizations (dodatki pod produktem)</h2>";
            echo '<p>To musi by&#263; <strong>productCustomizationId</strong> (encja Product Customization), kt&#243;rego wymaga <code>pos-actions order/create</code>. Je&#347;li wkleisz z&#322;e ID (np. z UI), POS zwr&#243;ci b&#322;&#261;d 10001 &bdquo;not found&rdquo;.</p>';
            echo "<form method='post' action='".esc_url(admin_url('admin-post.php'))."' style='display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;'>";
            echo "<input type='hidden' name='action' value='dwco_list_customizations' />";
            wp_nonce_field('dwco_list_customizations');
            echo "<div><label>Filtr (opcjonalnie)</label><br><input type='text' name='q' value='' placeholder='np. dodatki, topping' class='regular-text' /></div>";
            submit_button('Pokaż listę customizations (ID + nazwa)', 'secondary', 'submit', false);
            echo "</form>";

            echo "<form method='post' action='".esc_url(admin_url('admin-post.php'))."' style='display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;margin-top:10px;'>";
            echo "<input type='hidden' name='action' value='dwco_check_customization_id' />";
            wp_nonce_field('dwco_check_customization_id');
            echo "<div><label>Sprawdź konkretne ID</label><br><input type='text' name='customization_id' value='' placeholder='np. 1871187871654583' class='regular-text' /></div>";
            submit_button('Sprawdź ID w API', 'secondary', 'submit', false);
            echo "</form>";
            echo "<hr><h2>Ważne</h2><ul style='list-style:disc;padding-left:20px;'>
                <li>Upewnij się, że <code>client_id</code> i <code>client_secret</code> są wpisane.</li>
                <li>Upewnij się, że w Dotypos wybrałeś właściwy cloud (MAMMAROSA) – wtedy callback dostanie <code>cloudid</code>.</li>
                <li>Jeśli zobaczysz błąd o czasie (expired) – to w Connector v2 timestamp musi być aktualny (wtyczka generuje go sama).</li>
            </ul>";

            // Show last fetched customizations (transient)
            $last = get_transient('dwco_last_customizations');
            if (is_array($last)) {
                $endpoint = esc_html($last['_endpoint'] ?? '');
                $data = $last['data'] ?? [];
                $count = is_array($data) ? count($data) : 0;
                echo "<hr><h3>Ostatnio pobrane customizations (ID + nazwa)</h3>";
                if ($endpoint) echo "<p class='description'>Endpoint: <code>$endpoint</code></p>";
                if ($count === 0) {
                    echo "<p>Brak wyników (albo filtr nie znalazł nic).</p>";
                } else {
                    echo "<div style='background:#fff;border:1px solid #ccd0d4;border-radius:8px;padding:12px;max-height:320px;overflow:auto;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \"Liberation Mono\", \"Courier New\", monospace;font-size:12px;'>";
                    $max = min(200, $count);
                    for ($i=0; $i<$max; $i++) {
                        $row = $data[$i];
                        $id = esc_html($row['id'] ?? '');
                        $name = esc_html($row['name'] ?? '');
                        echo $id . " — " . $name . "<br>";
                    }
                    echo "</div>";
                }
            }

            $check = get_transient('dwco_last_customization_check');
            if (is_array($check) && isset($check['cid'])) {
                echo "<hr><h3>Wynik sprawdzenia ID</h3>";
                if (!empty($check['ok'])) {
                    $cid = esc_html($check['cid']);
                    $nm  = esc_html($check['name'] ?? '');
                    $ep  = esc_html($check['endpoint'] ?? '');
                    echo "<p><strong>OK:</strong> ID <code>$cid</code> istnieje w API. Nazwa: <strong>$nm</strong></p>";
                    if ($ep) echo "<p class='description'>Sprawdzone przez: <code>$ep</code></p>";
                } else {
                    $cid = esc_html($check['cid']);
                    echo "<p><strong>NIE:</strong> ID <code>$cid</code> nie istnieje w API dla tego cloudId.</p>";
                }
            }

            // ---- Daily report section ----
            echo "<hr><h2>Raport dzienny Dotykačka</h2>";
            echo "<p>Pobiera raport sprzedaży z obu branchy (SALA + OGRÓD) i buduje podsumowanie.</p>";
            echo "<form method='post' action='".esc_url(admin_url('admin-post.php'))."'>";
            echo "<input type='hidden' name='action' value='dwco_test_daily_report' />";
            wp_nonce_field('dwco_test_daily_report');
            $todayStr = current_time('Y-m-d');
            echo "<div><label for='dwco_report_date'><strong>Data raportu</strong></label><br>";
            echo "<input type='date' id='dwco_report_date' name='report_date' value='".esc_attr($todayStr)."' class='regular-text' style='max-width:180px;' /></div>";
            echo "<br>";
            submit_button('Policz raport testowo', 'primary', 'submit', false);
            echo "</form>";

            // Show last report summary from transient
            $lastSummary = get_transient('dwco_last_daily_report_summary');
            if ($lastSummary) {
                echo "<h3>Ostatni wynik raportu</h3>";
                echo "<pre style='background:#f6f7f7;border:1px solid #ccd0d4;padding:12px;border-radius:4px;font-size:14px;line-height:1.6;'>".esc_html($lastSummary)."</pre>";

                echo "<form method='post' action='".esc_url(admin_url('admin-post.php'))."' style='margin-top:8px;'>";
                echo "<input type='hidden' name='action' value='dwco_send_last_daily_report_sms' />";
                wp_nonce_field('dwco_send_last_daily_report_sms');
                submit_button('Wyślij ostatni raport SMS testowo', 'secondary', 'submit', false);
                echo "</form>";

                $debugJson = get_transient('dwco_last_daily_report_debug');
                if ($debugJson) {
                    echo "<details style='margin-top:16px;'><summary style='cursor:pointer;font-weight:600;'>🔍 Debug: surowy JSON z API (kliknij aby rozwinąć)</summary>";
                    echo "<pre style='background:#fff3cd;border:1px solid #ffc107;padding:12px;border-radius:4px;font-size:11px;line-height:1.4;overflow:auto;max-height:500px;'>".esc_html(json_encode($debugJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))."</pre>";
                    echo "</details>";
                }
            }
        }

        if ($tab === 'sync') {
            $msg = isset($_GET['dwco_msg']) ? sanitize_text_field($_GET['dwco_msg']) : '';
            $err = isset($_GET['dwco_err']) ? sanitize_text_field($_GET['dwco_err']) : '';
            if ($msg) echo "<div class='notice notice-success'><p>".esc_html($msg)."</p></div>";
            if ($err) echo "<div class='notice notice-error'><p>".esc_html($err)."</p></div>";

            echo "<h2>Import i synchronizacja menu (Dotykačka → WooCommerce)</h2>";
            echo "<p>Dotykačka jest źródłem prawdy. Ta sekcja tworzy/aktualizuje produkty i kategorie w WooCommerce oraz ustawia mapowanie <code>Dotypos productId</code>.</p>";

            echo "<div style='display:flex;gap:12px;flex-wrap:wrap;margin:12px 0;'>";
            echo "<form method='post' action='".esc_url(admin_url('admin-post.php'))."'>";
            echo "<input type='hidden' name='action' value='dwco_import_menu' />";
            wp_nonce_field('dwco_import_menu');
            submit_button('Importuj menu (pierwszy raz)', 'primary', 'submit', false);
            echo "</form>";

            echo "<form method='post' action='".esc_url(admin_url('admin-post.php'))."'>";
            echo "<input type='hidden' name='action' value='dwco_sync_now' />";
            wp_nonce_field('dwco_sync_now');
            submit_button('Synchronizuj teraz', 'secondary', 'submit', false);
            echo "</form>";
            echo "</div>";

            echo "<h3>Ustawienia sync</h3>";
            echo "<p>Włącz auto-sync w zakładce <strong>Ustawienia</strong> (WP-Cron) i ustaw interwał.</p>";
            echo "<p class='description'>Uwaga: zdjęcia i opisy zwykle ustawiamy po stronie Woo/Orderable.</p>";
        }

        if ($tab === 'logs') {
            echo "<h2>Logi</h2>";
            echo "<form method='post' action='".esc_url(admin_url('admin-post.php'))."' style='margin-bottom:10px;'>";
            echo "<input type='hidden' name='action' value='dwco_clear_logs' />";
            wp_nonce_field('dwco_clear_logs');
            submit_button('Wyczyść logi', 'delete', 'submit', false);
            echo "</form>";

            echo "<div style='background:#111;color:#eee;padding:12px;border-radius:8px;max-height:520px;overflow:auto;font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \"Liberation Mono\", \"Courier New\", monospace;font-size:12px;'>";
            foreach ($logs as $l) {
                $line = sprintf("[%s] %s: %s %s",
                    $l['ts'] ?? '',
                    strtoupper($l['level'] ?? ''),
                    $l['message'] ?? '',
                    !empty($l['context']) ? json_encode($l['context'], JSON_UNESCAPED_UNICODE) : ''
                );
                echo esc_html($line) . "<br>";
            }
            echo "</div>";
        }

        echo "</div>";
    }

    /**
     * Initiate Connector v2 flow (POST form to admin.dotykacka.cz/client/connect/v2)
     */
    public static function handle_admin_connect() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dwco_connect');

        $opts = self::get_options();
        $clientId = trim($opts['client_id'] ?? '');
        $clientSecret = trim($opts['client_secret'] ?? '');
        $redirectUri = home_url(self::CALLBACK_PATH);
        $state = trim($opts['state'] ?? 'mammarosa_wp_001');
        if ($clientId === '' || $clientSecret === '') {
            wp_redirect(admin_url('admin.php?page=dwco&dwco_err='.rawurlencode('Uzupełnij client_id i client_secret.')));
            exit;
        }

        $timestamp = time(); // unix seconds
        $signature = hash_hmac('sha256', (string)$timestamp, $clientSecret);

        self::log('info', 'Starting connector v2 flow', ['timestamp'=>$timestamp, 'redirect_uri'=>$redirectUri]);

        // Render an auto-submitting POST form (must be in browser)
        $action = 'https://admin.dotykacka.cz/client/connect/v2';
        $fields = [
            'client_id' => $clientId,
            'timestamp' => $timestamp,
            'signature' => $signature,
            'scope' => '*',
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];

        echo "<!doctype html><html><head><meta charset='utf-8'><title>Connecting Dotypos…</title></head><body>";
        echo "<p>Przekierowuję do Dotypos… Jeśli nic się nie dzieje, kliknij przycisk.</p>";
        echo "<form id='dwcoForm' method='POST' action='".esc_url($action)."' accept-charset='utf-8'>";
        foreach ($fields as $name => $value) {
            echo "<input type='hidden' name='".esc_attr($name)."' value='".esc_attr($value)."' />";
        }
        echo "<button type='submit'>Kontynuuj</button>";
        echo "</form><script>document.getElementById('dwcoForm').submit();</script>";
        echo "</body></html>";
        exit;
    }

    /**
     * Callback endpoint: https://your-site/dotypos-auth/?token=...&cloudid=...&state=...
     * Stores refresh token and cloud id.
     */
    public static function maybe_handle_callback() {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
        $expected = self::CALLBACK_PATH;
        // Accept both /dotypos-auth and /dotypos-auth/
        if (!$path) return;
        $norm = rtrim($path, '/') . '/';
        if ($norm !== rtrim($expected,'/') . '/') return;

        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
        $cloudid = isset($_GET['cloudid']) ? sanitize_text_field($_GET['cloudid']) : '';
        $state = isset($_GET['state']) ? sanitize_text_field($_GET['state']) : '';

        if ($token === '' || $cloudid === '') {
            self::log('error', 'Callback hit without token/cloudid', ['qs'=>$_GET]);
            wp_die('Brak token/cloudid w callbacku.');
        }

        $opts = self::get_options();
        if (!empty($opts['state']) && $state !== '' && $state !== $opts['state']) {
            self::log('error', 'State mismatch on callback', ['expected'=>$opts['state'], 'got'=>$state]);
            wp_die('State mismatch (CSRF).');
        }

        self::update_options([
            'refresh_token' => $token,
            'cloud_id' => $cloudid,
        ]);

        self::log('info', 'Stored refresh token from callback', ['cloudid'=>$cloudid]);

        // Redirect to admin settings
        $url = admin_url('admin.php?page=dwco&dwco_msg='.rawurlencode('Połączono. Refresh token zapisany.'));
        wp_safe_redirect($url);
        exit;
    }

    /**
     * Obtain access token using refresh token.
     */
    public static function get_access_token(): string {
        $opts = self::get_options();
        $refresh = trim($opts['refresh_token'] ?? '');
        $cloudId = trim($opts['cloud_id'] ?? '');
        if ($refresh === '' || $cloudId === '') {
            throw new Exception('Brak refresh_token lub cloud_id.');
        }

        $url = 'https://api.dotykacka.cz/v2/signin/token';
        $resp = wp_remote_post($url, [
            'headers' => [
                'Authorization' => 'User ' . $refresh,
                'Content-Type' => 'application/json; charset=utf-8',
            ],
            'body' => wp_json_encode(['_cloudId' => $cloudId]),
            'timeout' => 20,
        ]);
        if (is_wp_error($resp)) {
            self::log('error', 'Access token request failed', ['error'=>$resp->get_error_message()]);
            throw new Exception($resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);

        if ($code >= 300 || empty($data['accessToken'])) {
            self::log('error', 'Access token response invalid', ['http'=>$code, 'body'=>$body]);
            throw new Exception('Nie udało się pobrać access tokena (HTTP '.$code.').');
        }

        // Cache in transient ~55 min
        set_transient('dwco_access_token', $data['accessToken'], 55 * MINUTE_IN_SECONDS);
        return $data['accessToken'];
    }

    public static function api_request(string $method, string $url, $body = null, array $extra_headers = []): array {
        $token = get_transient('dwco_access_token');
        if (!$token) {
            $token = self::get_access_token();
        }

        $args = [
            'method' => $method,
            'timeout' => 25,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json; charset=utf-8',
                'Accept' => 'application/json',
            ],
        ];
        if (!empty($extra_headers)) {
            foreach ($extra_headers as $hk => $hv) {
                $args['headers'][$hk] = $hv;
            }
        }
        if ($body !== null) {
            $args['body'] = is_string($body) ? $body : wp_json_encode($body);
        }

        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            self::log('error', 'API request error', ['url'=>$url, 'error'=>$resp->get_error_message()]);
            throw new Exception($resp->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($resp);
        $raw = wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        // Token could be expired / invalid, retry once
        if ($code === 401 || ($code === 403 && is_array($json) && ($json['reason'] ?? '') === 'INVALID_ACCESS_TOKEN')) {
            delete_transient('dwco_access_token');
            $token = self::get_access_token();
            $args['headers']['Authorization'] = 'Bearer ' . $token;
            $resp = wp_remote_request($url, $args);
            $code = wp_remote_retrieve_response_code($resp);
            $raw = wp_remote_retrieve_body($resp);
            $json = json_decode($raw, true);
        }

        return ['http'=>$code, 'raw'=>$raw, 'json'=>$json, 'headers'=>wp_remote_retrieve_headers($resp)];
    }


    /**
     * Try to fetch Product Customizations list from API. Dotypos naming differs across versions,
     * so we probe a few possible endpoints and return the first that works.
     */
    private static function fetch_product_customizations(string $cloudId, string $q = ''): array {
        $q = trim($q);
        $candidates = [
            "https://api.dotykacka.cz/v2/clouds/$cloudId/product-customizations?limit=200&page=1",
            "https://api.dotykacka.cz/v2/clouds/$cloudId/productCustomizations?limit=200&page=1",
            "https://api.dotykacka.cz/v2/clouds/$cloudId/product_customizations?limit=200&page=1",
            "https://api.dotykacka.cz/v2/clouds/$cloudId/product-customization?limit=200&page=1",
            "https://api.dotykacka.cz/v2/clouds/$cloudId/productCustomizations?perPage=200&page=1",
        ];

        foreach ($candidates as $url) {
            $resp = self::api_request('GET', $url);
            if ($resp['http'] >= 300) continue;

            $json = $resp['json'];
            // Many Dotypos list endpoints return {data:[...]}.
            $rows = [];
            if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
                $rows = $json['data'];
            } elseif (is_array($json) && isset($json['items']) && is_array($json['items'])) {
                $rows = $json['items'];
            } elseif (is_array($json) && isset($json[0])) {
                $rows = $json;
            }
            if (!is_array($rows) || count($rows) === 0) continue;

            // Normalize
            $out = [];
            foreach ($rows as $r) {
                if (!is_array($r)) continue;
                $id = $r['id'] ?? ($r['_id'] ?? null);
                $name = $r['name'] ?? ($r['title'] ?? ($r['label'] ?? ''));
                if ($id === null) continue;
                $id = (string)$id;
                if ($q !== '') {
                    $hay = mb_strtolower((string)$name);
                    if (mb_strpos($hay, mb_strtolower($q)) === false) continue;
                }
                $out[] = ['id'=>$id, 'name'=>(string)$name];
            }
            if (count($out) === 0) {
                // still may be valid endpoint but filter removed all; return empty with endpoint info
                return ['_endpoint'=>$url, '_etag'=>($resp['headers']['etag'] ?? ''), 'data'=>[]];
            }
            return ['_endpoint'=>$url, '_etag'=>($resp['headers']['etag'] ?? ''), 'data'=>$out];
        }

        throw new Exception('Nie udało się pobrać listy customizations z API (żaden endpoint nie zadziałał).');
    }

    private static function flatten_items_customizations(array $items): array {
        $flat = [];
        foreach ($items as $it) {
            if (!is_array($it)) continue;
            $base = $it;
            $customs = $base['customizations'] ?? null;
            unset($base['customizations']);
            $flat[] = $base;
            if (is_array($customs)) {
                foreach ($customs as $c) {
                    if (!is_array($c)) continue;
                    $pid = $c['product-id'] ?? null;
                    if (!$pid) continue;
                    $q = $c['qty'] ?? 1;
                    $row = ['id'=>(int)$pid, 'qty'=>(float)$q];
                    $flat[] = $row;
                }
            }
        }
        return $flat;
    }

    // ===== RETRY (Register is closed: code 3001) =====

    private static function is_register_closed_error(array $resp): bool {
        $raw  = (string)($resp['raw'] ?? '');
        $json = $resp['json'] ?? null;

        // Some API responses return {"code":3001,...}
        if (is_array($json) && (int)($json['code'] ?? 0) === 3001) return true;

        // Sometimes message text contains it
        if ($raw !== '' && stripos($raw, 'Register is closed') !== false) return true;
        if ($raw !== '' && stripos($raw, '"code":3001') !== false) return true;

        return false;
    }

    private static function schedule_retry_window_1145_1215(int $order_id): void {
        // Prefer Action Scheduler (WooCommerce ships it)
        if (!function_exists('as_schedule_single_action') || !function_exists('as_next_scheduled_action')) {
            self::log('error', 'Action Scheduler not available - cannot schedule retry', ['order_id'=>$order_id]);
            return;
        }

        $tz  = wp_timezone();
        $now = new DateTime('now', $tz);

        // Retry slots (local site time)
        $slots = ['11:45', '11:55', '12:05', '12:15'];

        foreach ($slots as $slot) {
            $dt = new DateTime($now->format('Y-m-d') . ' ' . $slot . ':00', $tz);

            // Only schedule future times
            if ($dt <= $now) continue;

            $hook = 'dwco_retry_push_order';
            $args = [$order_id, $slot];

            if (!as_next_scheduled_action($hook, $args, 'dwco')) {
                as_schedule_single_action($dt->getTimestamp(), $hook, $args, 'dwco');
            }
        }
    }

    private static function mark_pending_and_schedule_retry(WC_Order $order): void {
        $order_id = (int)$order->get_id();

        if ((string)$order->get_meta('_dwco_dotypos_pending', true) !== '1') {
            $order->update_meta_data('_dwco_dotypos_pending', '1');
            $order->save();
            $order->add_order_note('Dotypos: kasa zamknięta (code 3001). Zaplanowano retry 11:45–12:15.');
        }

        self::schedule_retry_window_1145_1215($order_id);
    }

    public static function retry_push_order($order_id, $attempt_time = ''): void {
        $order = wc_get_order($order_id);
        if (!$order) return;

        // Retry only if previously marked pending
        if ((string)$order->get_meta('_dwco_dotypos_pending', true) !== '1') return;

        try {
            // Run push WITHOUT scheduling again (we already have slots)
            self::push_order_to_dotypos_internal((int)$order_id, false);

            // Success: clear pending
            $order->delete_meta_data('_dwco_dotypos_pending');
            $order->delete_meta_data('_dwco_dotypos_needs_attention');
            $order->save();
            $order->add_order_note('Dotypos: retry OK o '.$attempt_time);

        } catch (Exception $e) {
            $msg = $e->getMessage();

            // If still "register closed", keep waiting; on last slot mark attention
            if (stripos($msg, 'Register is closed') !== false || stripos($msg, '"code":3001') !== false) {
                if ((string)$attempt_time === '12:15') {
                    $order->update_meta_data('_dwco_dotypos_needs_attention', '1');
                    $order->save();
                    $order->add_order_note('Dotypos: retry zakończone (11:45–12:15). Kasa nadal zamknięta – sprawdź POS.');
                }
                return;
            }

            // Any other error: mark attention
            $order->update_meta_data('_dwco_dotypos_needs_attention', '1');
            $order->save();
            $order->add_order_note('Dotypos: retry przerwane – inny błąd: '.$msg);
        }
    }


    public static function handle_admin_test() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dwco_test');

        try {
            $opts = self::get_options();
            $cloudId = trim($opts['cloud_id'] ?? '');
            if ($cloudId === '') throw new Exception('Brak cloud_id.');
            $access = self::get_access_token();
            self::log('info', 'Access token OK', ['len'=>strlen($access)]);

            $cloudUrl = "https://api.dotykacka.cz/v2/clouds/$cloudId";
            $cloud = self::api_request('GET', $cloudUrl);
            self::log('info', 'GET cloud', ['http'=>$cloud['http'], 'name'=>($cloud['json']['name'] ?? null)]);

            $branchesUrl = "https://api.dotykacka.cz/v2/clouds/$cloudId/branches?perPage=50";
            $branches = self::api_request('GET', $branchesUrl);

            $branchList = [];

            if (!empty($branches['json']['data']) && is_array($branches['json']['data'])) {
                foreach ($branches['json']['data'] as $branch) {
                    $branchList[] = [
                        'id' => $branch['id'] ?? null,
                        'name' => $branch['name'] ?? null,
                        'raw' => $branch,
                    ];
                }
            }

            self::log('info', 'GET branches', [
                'http' => $branches['http'],
                'count' => ($branches['json']['totalItemsCount'] ?? null),
                'branches' => $branchList,
            ]);

            wp_redirect(admin_url('admin.php?page=dwco&tab=diagnostics&dwco_msg='.rawurlencode('Test OK. Zobacz logi.')));
            exit;
        } catch (Exception $e) {
            self::log('error', 'Test failed', ['ex'=>$e->getMessage()]);
            wp_redirect(admin_url('admin.php?page=dwco&tab=diagnostics&dwco_err='.rawurlencode('Test nieudany: '.$e->getMessage())));
            exit;
        }
    }


    public static function handle_list_customizations() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dwco_list_customizations');

        try {
            $opts = self::get_options();
            $cloudId = trim($opts['cloud_id'] ?? '');
            if ($cloudId === '') throw new Exception('Brak cloud_id.');

            $q = isset($_POST['q']) ? sanitize_text_field($_POST['q']) : '';
            $res = self::fetch_product_customizations($cloudId, $q);

            // show up to 200 results in transient for diagnostics tab
            set_transient('dwco_last_customizations', $res, 10 * MINUTE_IN_SECONDS);

            $count = is_array($res['data'] ?? null) ? count($res['data']) : 0;
            self::log('info', 'Fetched product customizations', ['endpoint'=>$res['_endpoint'] ?? null, 'count'=>$count, 'filter'=>$q]);

            wp_redirect(admin_url('admin.php?page=dwco&tab=diagnostics&dwco_msg=' . rawurlencode('Pobrano customizations: '.$count.'. Zobacz poniżej w Diagnostyce.')));
            exit;
        } catch (Exception $e) {
            self::log('error', 'List customizations failed', ['ex'=>$e->getMessage()]);
            wp_redirect(admin_url('admin.php?page=dwco&tab=diagnostics&dwco_err=' . rawurlencode('Błąd: '.$e->getMessage())));
            exit;
        }
    }

    public static function handle_check_customization_id() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dwco_check_customization_id');

        try {
            $opts = self::get_options();
            $cloudId = trim($opts['cloud_id'] ?? '');
            if ($cloudId === '') throw new Exception('Brak cloud_id.');

            $cid = isset($_POST['customization_id']) ? sanitize_text_field($_POST['customization_id']) : '';
            $cid = trim($cid);
            if ($cid === '' || !ctype_digit($cid)) throw new Exception('Podaj poprawne customization ID (same cyfry).');

            // Try a few possible "single entity" endpoints
            $urls = [
                "https://api.dotykacka.cz/v2/clouds/$cloudId/product-customizations/$cid",
                "https://api.dotykacka.cz/v2/clouds/$cloudId/productCustomizations/$cid",
                "https://api.dotykacka.cz/v2/clouds/$cloudId/product_customizations/$cid",
                // fallback: list+filter
                "LIST",
            ];

            $found = null;
            $endpointUsed = null;

            foreach ($urls as $u) {
                if ($u === 'LIST') {
                    $res = self::fetch_product_customizations($cloudId, '');
                    foreach (($res['data'] ?? []) as $r) {
                        if (($r['id'] ?? '') === $cid) { $found = $r; $endpointUsed = $res['_endpoint'].' (list)'; break 2; }
                    }
                    continue;
                }
                $resp = self::api_request('GET', $u);
                if ($resp['http'] >= 300) continue;
                $json = $resp['json'];
                if (is_array($json)) {
                    $id = (string)($json['id'] ?? ($json['_id'] ?? ''));
                    if ($id === $cid) {
                        $found = ['id'=>$cid, 'name'=>(string)($json['name'] ?? ($json['title'] ?? ''))];
                        $endpointUsed = $u;
                        break;
                    }
                }
            }

            if ($found) {
                set_transient('dwco_last_customization_check', ['ok'=>true, 'cid'=>$cid, 'name'=>$found['name'] ?? '', 'endpoint'=>$endpointUsed], 10*MINUTE_IN_SECONDS);
                wp_redirect(admin_url('admin.php?page=dwco&tab=diagnostics&dwco_msg=' . rawurlencode('ID istnieje w API: '.$cid.' ('.$found['name'].')')));
                exit;
            }

            set_transient('dwco_last_customization_check', ['ok'=>false, 'cid'=>$cid], 10*MINUTE_IN_SECONDS);
            wp_redirect(admin_url('admin.php?page=dwco&tab=diagnostics&dwco_err=' . rawurlencode('ID '.$cid.' NIE istnieje w API (dla tego cloudId).')));
            exit;

        } catch (Exception $e) {
            self::log('error', 'Check customization id failed', ['ex'=>$e->getMessage()]);
            wp_redirect(admin_url('admin.php?page=dwco&tab=diagnostics&dwco_err=' . rawurlencode('Błąd: '.$e->getMessage())));
            exit;
        }
    }


    public static function handle_clear_logs() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dwco_clear_logs');
        update_option(self::LOG_OPT_KEY, [], false);
        wp_redirect(admin_url('admin.php?page=dwco&tab=logs&dwco_msg='.rawurlencode('Logi wyczyszczone.')));
        exit;
    }

    /**
     * Product field: dotypos_product_id
     */
    public static function register_product_meta_ui() {
        // nothing here; meta boxes are below
    }

    public static function save_product_meta($post_id, $post) {
        if (!isset($_POST['dwco_product_nonce']) || !wp_verify_nonce($_POST['dwco_product_nonce'], 'dwco_product_meta')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $pid = isset($_POST['dwco_dotypos_product_id']) ? sanitize_text_field($_POST['dwco_dotypos_product_id']) : '';
        if ($pid !== '') {
            update_post_meta($post_id, '_dwco_dotypos_product_id', $pid);
        } else {
            delete_post_meta($post_id, '_dwco_dotypos_product_id');
        }

        $cid = isset($_POST['dwco_dotypos_customization_id']) ? sanitize_text_field($_POST['dwco_dotypos_customization_id']) : '';
        if ($cid !== '') {
            update_post_meta($post_id, '_dwco_dotypos_customization_id', $cid);
        } else {
            delete_post_meta($post_id, '_dwco_dotypos_customization_id');
        }

        // Checkbox: product is an add-on
        $is_addon = isset($_POST['dwco_is_addon']) && $_POST['dwco_is_addon'] == '1' ? '1' : '';
        if ($is_addon !== '') {
            update_post_meta($post_id, '_dwco_is_addon', '1');
        } else {
            delete_post_meta($post_id, '_dwco_is_addon');
        }
    }

    public static function add_order_metabox() {
        add_meta_box('dwco_order_meta', 'Dotypos (integracja)', [__CLASS__, 'render_order_metabox'], 'shop_order', 'side', 'default');
        add_meta_box('dwco_product_meta', 'Dotypos (productId)', [__CLASS__, 'render_product_metabox'], 'product', 'side', 'default');
    }

    public static function render_product_metabox($post) {
        $val = get_post_meta($post->ID, '_dwco_dotypos_product_id', true);
        wp_nonce_field('dwco_product_meta', 'dwco_product_nonce');
        echo "<p><label>Dotypos productId</label><br>";
        echo "<input type='text' name='dwco_dotypos_product_id' value='".esc_attr($val)."' placeholder='np. 1872293799928755' class='widefat' /></p>";

        $cust = get_post_meta($post->ID, '_dwco_dotypos_customization_id', true);
        echo "<p><label>Dotypos productCustomizationId (dla dodatków)</label><br>";
        echo "<input type='text' name='dwco_dotypos_customization_id' value='".esc_attr($cust)."' placeholder='np. 1872311111111111' class='widefat' /></p>";

        $is_addon = get_post_meta($post->ID, '_dwco_is_addon', true);
        echo "<p><label><input type='checkbox' name='dwco_is_addon' value='1' ".checked($is_addon, '1', false)." /> Traktuj ten produkt jako DODATEK (przypnij pod poprzedni produkt w zamówieniu)</label></p>";

        echo "<p class='description'>productId mapuje produkt Woo → produkt w Dotypos. Jeśli ten produkt ma w Woo dodatki (toppingi), wpisz productCustomizationId, pod które mają trafić dodatki w POS. Jeśli puste – użyje ustawienia globalnego z panelu wtyczki.</p>";
    }


    /**
     * Read Dotypos productCustomizationId from WC product meta.
     * Used to attach Woo add-ons (toppings) as Dotypos item customizations.
     */
    private static function get_dotypos_customization_id_from_product($product): string {
        if (!$product) return '';
        $ids_to_check = [];
        $wc_id = (int)$product->get_id();
        $ids_to_check[] = $wc_id;
        if (method_exists($product, 'is_type') && $product->is_type('variation') && method_exists($product, 'get_parent_id')) {
            $parent = (int)$product->get_parent_id();
            if ($parent > 0) $ids_to_check[] = $parent;
        }
        $keys = [
            '_dwco_dotypos_customization_id',
            'dotypos_customization_id',
            'dotypos_customization.id',
        ];
        foreach ($ids_to_check as $pid) {
            foreach ($keys as $k) {
                $v = get_post_meta($pid, $k, true);
                if (is_string($v)) $v = trim($v);
                if ($v !== '' && ctype_digit((string)$v)) return (string)$v;
            }
        }
        return '';
    }

    /**
     * Detect if a Woo order item should be treated as an add-on (topping).
     * Orderable (or other add-on plugins) may use different meta keys, so we support:
     * - explicit line-item meta (_mm_is_addon == 1)
     * - explicit product meta (_dwco_is_addon == 1) that you can tick in product edit screen
     * - heuristic: any line-item meta key containing "addon" with truthy value
     */
    private static function is_addon_item($item, $product): bool {
        try {
            // 1) explicit line-item meta (legacy from earlier implementation)
            if ((int)$item->get_meta('_mm_is_addon', true) === 1) return true;

            // 2) explicit product checkbox
            if ($product) {
                $pid = (int)$product->get_id();
                if ((string)get_post_meta($pid, '_dwco_is_addon', true) === '1') return true;
                // variation -> parent
                if (method_exists($product, 'is_type') && $product->is_type('variation') && method_exists($product, 'get_parent_id')) {
                    $parent = (int)$product->get_parent_id();
                    if ($parent > 0 && (string)get_post_meta($parent, '_dwco_is_addon', true) === '1') return true;
                }
            }

            // 3) heuristic: scan meta keys
            $meta_data = $item->get_meta_data();
            foreach ($meta_data as $md) {
                $key = method_exists($md, 'get_data') ? ($md->get_data()['key'] ?? '') : '';
                $val = method_exists($md, 'get_data') ? ($md->get_data()['value'] ?? '') : '';
                if (!is_string($key)) continue;
                if (stripos($key, 'addon') !== false) {
                    if (is_scalar($val) && (string)$val !== '' && (string)$val !== '0') return true;
                    if (is_array($val) && !empty($val)) return true;
                }
            }
        } catch (Exception $e) {
            // ignore
        }
        return false;
    }

    private static function get_dotypos_product_id_from_product($product): string {
        if (!$product) return '';
        $ids_to_check = [];

        // Variation fallback -> parent
        $wc_id = (int)$product->get_id();
        $ids_to_check[] = $wc_id;

        if (method_exists($product, 'is_type') && $product->is_type('variation') && method_exists($product, 'get_parent_id')) {
            $parent = (int)$product->get_parent_id();
            if ($parent > 0) $ids_to_check[] = $parent;
        }

        $keys = [
            '_dwco_dotypos_product_id', // our meta key
            'dotypos_product_id',       // common custom field
            'dotypos_product.id',       // seen in some setups
            'dotypos_product_id ',      // accidental trailing space
        ];

        foreach ($ids_to_check as $pid) {
            foreach ($keys as $k) {
                $v = get_post_meta($pid, $k, true);
                if (is_string($v)) $v = trim($v);
                if (!empty($v) && preg_match('/^\d+$/', (string)$v)) {
                    return (string)$v;
                }
            }
        }
        return '';
    }

    public static function render_order_metabox($post) {
        $order = wc_get_order($post->ID);
        if (!$order) return;
        $dotOrderId = $order->get_meta('_dwco_dotypos_order_id');
        $dotOrderNumber = $order->get_meta('_dwco_dotypos_order_number');
        echo "<p><strong>Dotypos orderId:</strong> ".esc_html($dotOrderId ?: '—')."</p>";
        echo "<p><strong>Dotypos order-number:</strong> ".esc_html($dotOrderNumber ?: '—')."</p>";
        echo "<p class='description'>Jeśli zamówienie nie dotarło do POS, sprawdź Logi w menu Dotypos ↔ Woo.</p>";
    }

    /**
     * On checkout: push to Dotypos if enabled and configured.
     */
    public static function maybe_push_order_on_checkout($order_id, $posted_data, $order) {
        $opts = self::get_options();
        if (($opts['enabled'] ?? 'yes') !== 'yes') return;
        if (($opts['push_on_status'] ?? 'checkout') !== 'checkout') return;

        try {
            self::push_order_to_dotypos($order_id);
        } catch (Exception $e) {
            self::log('error', 'Push on checkout failed', ['order_id'=>$order_id, 'ex'=>$e->getMessage()]);
            if ($order instanceof WC_Order) {
                $order->add_order_note('Dotypos: błąd wysyłki zamówienia: '.$e->getMessage());
            }
        }
    }

    /**
     * On status change to processing: push to Dotypos if configured.
     * Fires also on manual status change in admin.
     */
    public static function maybe_push_order_on_processing($order_id, $order = null) {
        $opts = self::get_options();

        if (($opts['enabled'] ?? 'yes') !== 'yes') {
            self::log('debug', 'Processing trigger skipped: integration disabled', ['order_id' => $order_id]);
            return;
        }

        if (($opts['push_on_status'] ?? 'checkout') !== 'processing') {
            self::log('debug', 'Processing trigger skipped: push_on_status not processing', [
                'order_id' => $order_id,
                'push_on_status' => ($opts['push_on_status'] ?? 'checkout'),
            ]);
            return;
        }

        if (!$order instanceof WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            self::log('error', 'Processing trigger: order not found', ['order_id' => $order_id]);
            return;
        }

        try {
            self::log('info', 'Processing trigger fired - pushing to Dotypos', [
                'order_id' => $order_id,
                'status'   => $order->get_status(),
            ]);
            // Na tym etapie pozwalamy na push do POS tylko przy zmianie statusu z panelu admina.
            if (!is_admin()) {
                self::log('debug', 'Processing trigger skipped: not admin context', [
                    'order_id' => $order_id,
                    'payment_method' => $order->get_payment_method(),
                    'status' => $order->get_status(),
                ]);
                return;
            }
            self::push_order_to_dotypos($order_id);
        } catch (Exception $e) {
            self::log('error', 'Push on processing failed', [
                'order_id' => $order_id,
                'ex' => $e->getMessage(),
            ]);

            if ($order instanceof WC_Order) {
                $order->add_order_note('Dotypos: błąd wysyłki po zmianie statusu na processing: ' . $e->getMessage());
            }
        }
    }

    public static function push_order_to_dotypos($order_id): void {
        self::push_order_to_dotypos_internal((int)$order_id, true);
    }

    private static function push_order_to_dotypos_internal(int $order_id, bool $allow_schedule_retry = true): void {
        $opts = self::get_options();
        $cloudId = trim($opts['cloud_id'] ?? '');
        $branchId = trim($opts['branch_id'] ?? '');
        if ($cloudId === '' || $branchId === '') throw new Exception('Brak cloud_id / branch_id w ustawieniach wtyczki.');

        $order = wc_get_order($order_id);
        if (!$order) throw new Exception('Nie znaleziono zamówienia WooCommerce.');

        // Prevent duplicates
        if ($order->get_meta('_dwco_dotypos_order_id')) {
            self::log('debug', 'Order already pushed, skipping', ['order_id'=>$order_id]);
            return;
        }

        $items = [];

        $defaultCustomizationId = trim($opts['default_addons_customization_id'] ?? '');
        $currentParentIndex = null;
        $currentCustomizationId = '';
        $wcItemToParentIndex = [];
        $parentCustomizationByIndex = [];

        // Optional debug: dump order line-item meta keys to order note
        if (($opts['addons_debug_meta'] ?? 'no') === 'yes') {
            $dump = [];
            foreach ($order->get_items() as $iid => $it) {
                $p = $it->get_product();
                $name = $p ? $p->get_name() : $it->get_name();
                $row = ['item'=>$name, 'qty'=>$it->get_quantity(), 'meta'=>[]];
                foreach ($it->get_meta_data() as $md) {
                    $d = $md->get_data();
                    $k = $d['key'] ?? '';
                    $v = $d['value'] ?? '';
                    if (is_array($v) || is_object($v)) $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
                    $row['meta'][(string)$k] = (string)$v;
                }
                $dump[] = $row;
            }
            $order->add_order_note("Dotypos DEBUG – meta pozycji (line items):\n" . wp_json_encode($dump, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        // Packaging aggregation (opakowania): collect as separate items instead of customizations
        $packagingById = [];

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product) continue;

            $isAddon = self::is_addon_item($item, $product);
            $dotProductId = self::get_dotypos_product_id_from_product($product);
            if (!$dotProductId) {
                $dotProductId = (string)$item->get_meta('dotypos_product_id', true)
                             ?: (string)$item->get_meta('dotypos_product.id', true);
            }
            if (!$dotProductId) continue;

            $qty = (float)$item->get_quantity();
            if ($qty <= 0) $qty = 1;

            // Woo line total is total for the line (qty * price)
            $line_total = (float)$item->get_total();
            $unit_total = ($qty > 0) ? ($line_total / $qty) : $line_total;

            // If this is packaging (opakowanie/karton), aggregate and skip attaching as customization
            if ($isAddon) {
                $srcKey = mb_strtolower((string)$item->get_meta('_mm_addon_source', true));
                $pname  = mb_strtolower((string)$product->get_name());
                if (strpos($srcKey, 'opak') !== false || strpos($srcKey, 'karton') !== false || strpos($pname, 'opak') !== false || strpos($pname, 'karton') !== false) {
                    $pid = (string)$dotProductId;
                    $packagingById[$pid] = ($packagingById[$pid] ?? 0) + (int)round($qty);
                    continue;
                }
            }

            if (!$isAddon) {
                // Parent item (pizza / danie)
                $currentCustomizationId = self::get_dotypos_customization_id_from_product($product);
                if ($currentCustomizationId === '') $currentCustomizationId = $defaultCustomizationId;

                $items[] = [
                    'id' => (int)$dotProductId,
                    'qty' => $qty,
                ];

                $currentParentIndex = count($items) - 1;
                $wcItemToParentIndex[(string)$item_id] = $currentParentIndex;
                $parentCustomizationByIndex[$currentParentIndex] = $currentCustomizationId;
            } else {
                // Add-on: przypinamy jako customization do właściwego dania (parent)
                $desiredParentIndex = null;
                $desiredCustomizationId = '';

                $parentItemId = (string)$item->get_meta('_mm_parent_item_id', true);
                if ($parentItemId === '') $parentItemId = (string)$item->get_meta('_mm_parent_line_item_id', true);

                if ($parentItemId !== '' && isset($wcItemToParentIndex[$parentItemId])) {
                    $desiredParentIndex = $wcItemToParentIndex[$parentItemId];
                    $desiredCustomizationId = (string)($parentCustomizationByIndex[$desiredParentIndex] ?? '');
                } else {
                    // Fallback: ostatnia pozycja nie-addon
                    $desiredParentIndex = $currentParentIndex;
                    $desiredCustomizationId = $currentCustomizationId;
                }

                if ($desiredParentIndex !== null && $desiredCustomizationId !== '' && ctype_digit((string)$desiredCustomizationId)) {
                    if (!isset($items[$desiredParentIndex]['customizations'])) $items[$desiredParentIndex]['customizations'] = [];

                    $cust = [
                        'product-customization-id' => (int)$desiredCustomizationId,
                        'product-id' => (int)$dotProductId,
                    ];

                    if ($qty > 0) $cust['qty'] = (int)round($qty);

                    if (abs($unit_total) > 0.00001) {
                        $cust['manual-price'] = $unit_total;
                    }

                    $items[$desiredParentIndex]['customizations'][] = $cust;
                } else {
                    // Brak customizations – wrzucamy jako osobny produkt (fallback)
                    $items[] = [
                        'id' => (int)$dotProductId,
                        'qty' => $qty,
                    ];
                }
            }
        }

        // Append aggregated packaging items (as separate products)
        if (!empty($packagingById)) {
            foreach ($packagingById as $pidStr => $pqty) {
                $pqty = (int)$pqty;
                if ($pqty < 1) continue;
                if (ctype_digit((string)$pidStr)) {
                    $items[] = [
                        'id'  => (int)$pidStr,
                        'qty' => $pqty,
                    ];
                }
            }
        }

        // Determine delivery / pickup (based on shipping method label)
        $shipLabel = '';
        $shippingTotal = (float)$order->get_shipping_total();
        foreach ($order->get_items('shipping') as $ship) {
            $shipLabel = $ship->get_name();
            break;
        }
        $isPickup = (stripos($shipLabel, 'odb') !== false || stripos($shipLabel, 'pickup') !== false || stripos($shipLabel, 'osob') !== false);

        // Add delivery as a Dotypos product item (so POS total matches Woo total)
        if (!$isPickup && $shippingTotal > 0.00001) {
            $cityPid = trim($opts['delivery_city_product_id'] ?? '');
            $kmPid   = trim($opts['delivery_km_product_id'] ?? '');
            $cityPrice = (float)str_replace(',', '.', ($opts['delivery_city_price'] ?? '5'));
            $kmRate = (float)str_replace(',', '.', ($opts['delivery_km_rate'] ?? '2'));

            // Case 1: exactly city flat price (e.g. 5 zł)
            if ($cityPid !== '' && abs($shippingTotal - $cityPrice) < 0.01) {
                $items[] = ['id' => (int)$cityPid, 'qty' => 1];
            } else if ($kmPid !== '' && $kmRate > 0) {
                // Case 2: price is per km, infer km count from Woo shipping total
                $km = $shippingTotal / $kmRate;
                $kmQty = (int)round($km);
                if ($kmQty < 1) $kmQty = 1;
                $items[] = ['id' => (int)$kmPid, 'qty' => $kmQty];
                if (abs($km - $kmQty) > 0.05) {
                    self::log('info', 'Shipping km inferred non-integer', ['shippingTotal'=>$shippingTotal, 'kmRate'=>$kmRate, 'km'=>$km, 'kmQty'=>$kmQty]);
                }
            } else {
                self::log('error', 'Shipping present but no delivery productId configured', ['shippingTotal'=>$shippingTotal, 'shipLabel'=>$shipLabel]);
                $order->add_order_note('Dotypos: UWAGA – koszt dostawy ('.$shippingTotal.' zł) nie został dodany jako produkt w POS (brak mapowania productId).');
            }
        }

        // NOTE (info dla obsługi / POS)
        $noteLines = [];
        $noteLines[] = ($isPickup ? '📦 ODBIÓR' : '🚚 DOSTAWA');
        $noteLines[] = 'Woo: #' . $order->get_order_number();
        $fullName = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if ($fullName !== '') {
            $noteLines[] = 'Klient: ' . $fullName;
        }

        $orderableDate = trim((string) $order->get_meta('orderable_order_date', true));
        $orderableTime = trim((string) $order->get_meta('orderable_order_time', true));

        $whenLabel = '';
        if ($orderableDate !== '' || $orderableTime !== '') {
            $whenLabel = trim($orderableDate . ' ' . $orderableTime);

            if ($orderableTime !== '' && preg_match('/^\d{1,2}:\d{2}$/', $orderableTime)) {
                $tz = wp_timezone();

                $dt = DateTime::createFromFormat('d/m/Y H:i', $orderableDate . ' ' . $orderableTime, $tz);
                if ($dt instanceof DateTime) {
                    $dtEnd = clone $dt;

                    $minutes = $isPickup ? 30 : 60;
                    $dtEnd->modify('+' . $minutes . ' minutes');

                    $start = $dt->format('H:i');
                    $end   = $dtEnd->format('H:i');

                    if (!$isPickup) {
                        $whenLabel = trim($orderableDate . ' ' . $start . '–' . $end);
                    }
                }
            }

            $noteLines[] = ($isPickup ? 'Odbiór:' : 'Dostawa:') . ' ' . $whenLabel;
        }

        $paymentTitle = $order->get_payment_method_title();
        if ($paymentTitle) {
            $noteLines[] = 'Płatność: ' . $paymentTitle;
        }

        if ($shippingTotal > 0.00001) {
            $noteLines[] = 'Dostawa (Woo): ' . wc_format_localized_price($shippingTotal) . ' zł';
        }
        if ($shipLabel !== '') {
            $noteLines[] = 'Metoda: ' . $shipLabel;
        }

        $noteLines[] = 'Tel: ' . ($order->get_billing_phone() ?: '-');

        if (!$isPickup) {
            $noteLines[] = 'Adres: ' . trim(
                $order->get_shipping_address_1() . ' ' .
                $order->get_shipping_address_2() . ', ' .
                $order->get_shipping_postcode() . ' ' .
                $order->get_shipping_city()
            );
        }

        $customerNote = trim($order->get_customer_note());
        if ($customerNote !== '') $noteLines[] = 'Uwagi: ' . $customerNote;

        $note = implode(' | ', $noteLines);

        $externalId = 'woo-' . $order->get_id();

        $payload = [
            'action'      => 'order/create',
            'external-id' => $externalId,
            'note'        => $note,
            'items'       => $items,
            'take-away'   => true,
        ];

        $url = "https://api.dotykacka.cz/v2/clouds/$cloudId/branches/$branchId/pos-actions";
        self::log('info', 'Sending order to Dotypos', ['order_id'=>$order_id, 'url'=>$url, 'external_id'=>$externalId]);

        // DEBUG
        if (isset($order) && $order instanceof WC_Order) {
            $prettyItems = json_encode($payload['items'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            $order->add_order_note("Dotypos DEBUG – items wysyłane do API:\n" . $prettyItems);
        }

        $resp = self::api_request('POST', $url, $payload, [
            'Idempotency-Key' => 'dwco-order-' . (int)$order->get_id(),
        ]);

        // If Dotypos rejects a customization id (code 10001 "not found"), retry once WITHOUT customizations,
        // so online orders never get blocked.
        $raw = $resp['raw'] ?? '';
        $json = $resp['json'];

        $needsRetry = false;
        if ($resp['http'] >= 300) {
            $needsRetry = (is_string($raw) && stripos($raw, 'Customization with id') !== false && stripos($raw, 'not found') !== false);
        } elseif (!is_array($json) || ($json['code'] ?? null) !== 0) {
            $needsRetry = (is_string($raw) && stripos($raw, 'Customization with id') !== false && stripos($raw, 'not found') !== false);
        }

        if ($needsRetry) {
            $order->add_order_note('Dotypos: UWAGA – customization ID nie znalezione w API. Wysyłam zamówienie ponownie bez customizations (dodatki jako osobne pozycje), żeby nie blokować zamówień online.');
            $payload2 = $payload;
            $payload2['items'] = self::flatten_items_customizations($payload['items']);
            $resp = self::api_request('POST', $url, $payload2);
            $raw = $resp['raw'] ?? '';
            $json = $resp['json'];
        }

        if ($resp['http'] >= 300) {
            if ($allow_schedule_retry && self::is_register_closed_error($resp)) {
                self::log('warn', 'Register closed - scheduling retry', ['order_id'=>$order_id, 'http'=>$resp['http'], 'raw'=>$raw]);
                self::mark_pending_and_schedule_retry($order);
                return;
            }
            if (self::is_register_closed_error($resp)) {
                throw new Exception('Register is closed (code 3001).');
            }
            throw new Exception('Dotypos API HTTP '.$resp['http'].' | '.$raw);
        }

        if (!is_array($json) || (($json['code'] ?? null) !== 0)) {
            if ($allow_schedule_retry && self::is_register_closed_error($resp)) {
                self::log('warn', 'Register closed - scheduling retry', ['order_id'=>$order_id, 'raw'=>$raw]);
                self::mark_pending_and_schedule_retry($order);
                return;
            }
            if (self::is_register_closed_error($resp)) {
                throw new Exception('Register is closed (code 3001).');
            }
            throw new Exception('Dotypos response error: '.$raw);
        }

        $dotOrderId = $json['order']['id'] ?? null;
        $dotOrderNumber = $json['order']['order-number'] ?? null;

        // Force TAKE-AWAY flag on POS by marking all items as takeaway
        if (!empty($dotOrderId)) {
            $orderItemsFromResp = [];
            if (isset($json['order']) && is_array($json['order']) && isset($json['order']['items']) && is_array($json['order']['items'])) {
                $orderItemsFromResp = $json['order']['items'];
            } elseif (isset($json['items']) && is_array($json['items'])) {
                $orderItemsFromResp = $json['items'];
            }

            $takeawayChanges = [];
            foreach ($orderItemsFromResp as $oi) {
                if (is_array($oi) && isset($oi['id'])) {
                    $takeawayChanges[] = [
                        'order-item-id' => (int)$oi['id'],
                        'take-away' => true,
                    ];
                }
            }

            if (!empty($takeawayChanges)) {
                try {
                    $payloadTakeaway = [
                        'action' => 'order/set-item-takeaway',
                        'order-id' => (int)$dotOrderId,
                        'take-away-changes' => $takeawayChanges,
                    ];
                    $respTw = self::api_request('POST', $url, $payloadTakeaway);
                    self::log('debug', 'Takeaway flag attempt result', ['order_id'=>$order_id, 'dotypos_order_id'=>$dotOrderId, 'raw'=>($respTw['raw'] ?? '')]);
                } catch (Exception $e) {
                    self::log('warn', 'Takeaway flag call failed (ignored)', ['order_id'=>$order_id, 'err'=>$e->getMessage()]);
                }
            } else {
                self::log('debug', 'Takeaway flag skipped - no order item IDs in response', ['order_id'=>$order_id, 'dotypos_order_id'=>$dotOrderId]);
            }
        }
        if ($dotOrderId) $order->update_meta_data('_dwco_dotypos_order_id', (string)$dotOrderId);
        if ($dotOrderNumber) $order->update_meta_data('_dwco_dotypos_order_number', (string)$dotOrderNumber);
        $order->save();

        $order->add_order_note('Dotypos: wysłano do POS. order-number: '.($dotOrderNumber ?: '—'));
        self::log('info', 'Order pushed OK', ['order_id'=>$order_id, 'dotypos_order_id'=>$dotOrderId, 'dotypos_order_number'=>$dotOrderNumber]);
    }

    // ---------- SYNC / IMPORT ----------

    public static function add_cron_schedules($schedules) {
        foreach ([5,10,15,20,30,60,120] as $m) {
            $key = 'dwco_' . $m . 'min';
            if (!isset($schedules[$key])) {
                $schedules[$key] = [
                    'interval' => $m * 60,
                    'display'  => 'DWCO every ' . $m . ' minutes',
                ];
            }
        }
        return $schedules;
    }

    public static function ensure_cron_schedule() {
        $opts = self::get_options();
        $enabled = ($opts['sync_enabled'] ?? 'no') === 'yes';
        $minutes = (int)($opts['sync_interval_minutes'] ?? 10);
        if ($minutes < 5) $minutes = 5;
        if ($minutes > 120) $minutes = 120;

        $hook = 'dwco_sync_event';
        $next = wp_next_scheduled($hook);

        if ($enabled) {
            if ($next) wp_clear_scheduled_hook($hook);
            wp_schedule_event(time() + 120, 'dwco_' . $minutes . 'min', $hook);
        } else {
            if ($next) wp_clear_scheduled_hook($hook);
        }
    }

    public static function run_scheduled_sync() {
        $opts = self::get_options();
        if (($opts['sync_enabled'] ?? 'no') !== 'yes') return;
        try {
            $res = self::sync_menu(false);
            self::log('info', 'Scheduled sync OK', ['summary'=>$res['summary']]);
        } catch (Exception $e) {
            self::log('error', 'Scheduled sync failed', ['ex'=>$e->getMessage()]);
        }
    }

    public static function handle_import_menu() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dwco_import_menu');
        try {
            $res = self::sync_menu(true);
            wp_redirect(admin_url('admin.php?page=dwco&tab=sync&dwco_msg=' . rawurlencode($res['summary'])));
            exit;
        } catch (Exception $e) {
            wp_redirect(admin_url('admin.php?page=dwco&tab=sync&dwco_err=' . rawurlencode($e->getMessage())));
            exit;
        }
    }

    public static function handle_sync_now() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dwco_sync_now');
        try {
            $res = self::sync_menu(false);
            wp_redirect(admin_url('admin.php?page=dwco&tab=sync&dwco_msg=' . rawurlencode($res['summary'])));
            exit;
        } catch (Exception $e) {
            wp_redirect(admin_url('admin.php?page=dwco&tab=sync&dwco_err=' . rawurlencode($e->getMessage())));
            exit;
        }
    }

    private static function sync_menu(bool $is_import): array {
        if (!class_exists('WC_Product_Simple')) throw new Exception('WooCommerce nie jest aktywny.');
        $opts = self::get_options();
        $cloudId = trim($opts['cloud_id'] ?? '');
        if ($cloudId === '') throw new Exception('Brak cloudId w ustawieniach.');

        $cats = self::fetch_all_entities($cloudId, 'categories', $is_import ? false : true, 'etag_categories', 'dwco_cached_categories');
        $catMap = self::upsert_categories_to_woo($cats);

        $products = self::fetch_all_entities($cloudId, 'products', $is_import ? false : true, 'etag_products', 'dwco_cached_products');
        $stats = self::upsert_products_to_woo($products, $catMap);

        self::ensure_cron_schedule();

        $summary = sprintf(
            "OK. Kategorie: %d | Produkty: dodane %d, zaktualizowane %d, pominięte %d",
            is_array($cats) ? count($cats) : 0,
            $stats['created'],
            $stats['updated'],
            $stats['skipped']
        );
        return ['summary'=>$summary] + $stats;
    }

    private static function fetch_all_entities(string $cloudId, string $entity, bool $use_etag, string $etag_opt_key, string $cache_opt_key): array {
        $opts = self::get_options();
        $headers = [];
        if ($use_etag && !empty($opts[$etag_opt_key])) {
            $headers['If-None-Match'] = $opts[$etag_opt_key];
        }

        $page = 1;
        $limit = 100;
        $all = [];
        $firstResp = null;

        while (true) {
            $url = "https://api.dotykacka.cz/v2/clouds/$cloudId/$entity?limit=$limit&page=$page";
            $resp = self::api_request('GET', $url, null, $headers);
            if ($page === 1) $firstResp = $resp;

            if ($resp['http'] === 304) {
                $cached = get_option($cache_opt_key, []);
                return is_array($cached) ? $cached : [];
            }
            if ($resp['http'] >= 300) {
                throw new Exception("GET $entity HTTP ".$resp['http']." | ".$resp['raw']);
            }

            $json = $resp['json'];
            $data = (is_array($json) && isset($json['data']) && is_array($json['data'])) ? $json['data'] : [];
            $all = array_merge($all, $data);

            $lastPage = isset($json['lastPage']) ? (int)$json['lastPage'] : 1;
            if ($page >= $lastPage) break;
            $page++;
            $headers = []; // only on first page
        }

        // store ETag from first response
        if ($firstResp && isset($firstResp['headers'])) {
            $h = $firstResp['headers'];
            $etag = '';
            if (is_array($h)) {
                if (isset($h['etag'])) $etag = is_array($h['etag']) ? $h['etag'][0] : $h['etag'];
                if (!$etag && isset($h['ETag'])) $etag = is_array($h['ETag']) ? $h['ETag'][0] : $h['ETag'];
            } elseif (is_object($h) && method_exists($h, 'get')) {
                $etag = $h->get('etag');
            }
            if ($etag) self::update_options([$etag_opt_key => $etag]);
        }

        update_option($cache_opt_key, $all, false);
        return $all;
    }

    private static function upsert_categories_to_woo(array $cats): array {
        $map = [];
        foreach ($cats as $c) {
            if (!is_array($c)) continue;
            if (!empty($c['deleted'])) continue;
            if (isset($c['display']) && !$c['display']) continue;

            $cid = (int)($c['id'] ?? 0);
            if ($cid <= 0) continue;
            $name = (string)($c['name'] ?? ('Kategoria '.$cid));

            $existing = get_terms([
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'meta_query' => [[
                    'key' => 'dwco_dotypos_category_id',
                    'value' => (string)$cid,
                    'compare' => '='
                ]]
            ]);

            if (!empty($existing) && !is_wp_error($existing)) {
                $term_id = $existing[0]->term_id;
                wp_update_term($term_id, 'product_cat', ['name'=>$name]);
            } else {
                $res = wp_insert_term($name, 'product_cat');
                if (is_wp_error($res)) continue;
                $term_id = (int)$res['term_id'];
                update_term_meta($term_id, 'dwco_dotypos_category_id', (string)$cid);
            }
            $map[$cid] = $term_id;
        }
        return $map;
    }

    private static function find_product_by_dotypos_id(int $dotId): int {
        $q = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'meta_query' => [[
                'key' => '_dwco_dotypos_product_id',
                'value' => (string)$dotId,
                'compare' => '='
            ]],
            'fields' => 'ids',
        ]);
        return !empty($q->posts) ? (int)$q->posts[0] : 0;
    }

    private static function upsert_products_to_woo(array $products, array $catMap): array {
        $opts = self::get_options();
        $overwritePrices = ($opts['sync_overwrite_prices'] ?? 'yes') === 'yes';
        $overwriteNames  = ($opts['sync_overwrite_names'] ?? 'yes') === 'yes';
        $importHidden    = ($opts['sync_import_hidden'] ?? 'no') === 'yes';
        $excludeDelivery = ($opts['sync_exclude_delivery_products'] ?? 'yes') === 'yes';

        $deliveryCityPid = (int)trim($opts['delivery_city_product_id'] ?? '');
        $deliveryKmPid   = (int)trim($opts['delivery_km_product_id'] ?? '');

        $created=0; $updated=0; $skipped=0;

        foreach ($products as $p) {
            if (!is_array($p)) { $skipped++; continue; }
            if (!empty($p['deleted'])) { $skipped++; continue; }
            if (!$importHidden && isset($p['display']) && !$p['display']) { $skipped++; continue; }

            $pid = (int)($p['id'] ?? 0);
            if ($pid <= 0) { $skipped++; continue; }

            if ($excludeDelivery) {
                if ($pid === $deliveryCityPid || $pid === $deliveryKmPid) { $skipped++; continue; }
                $nm = (string)($p['name'] ?? '');
                if (stripos($nm, 'dowóz') !== false || stripos($nm, 'dowoz') !== false) { $skipped++; continue; }
            }

            $name = (string)($p['name'] ?? ('Produkt '.$pid));
            $price = null;
            if (isset($p['priceWithVat'])) $price = (float)$p['priceWithVat'];
            elseif (isset($p['priceWithoutVat'], $p['vat'])) $price = (float)$p['priceWithoutVat'] * (float)$p['vat'];
            else $price = 0.0;

            $post_id = self::find_product_by_dotypos_id($pid);

            if ($post_id <= 0) {
                $product = new WC_Product_Simple();
                $product->set_name($name);
                $product->set_status('publish');
                $product->set_catalog_visibility('visible');
                $product->set_regular_price(wc_format_decimal($price));
                $post_id = $product->save();
                if ($post_id) {
                    update_post_meta($post_id, '_dwco_dotypos_product_id', (string)$pid);
                    update_post_meta($post_id, '_dwco_dotypos_synced_at', time());
                    $created++;
                } else {
                    $skipped++;
                    continue;
                }
            } else {
                $product = wc_get_product($post_id);
                if (!$product) { $skipped++; continue; }
                $changed = false;

                if ($overwriteNames && $product->get_name() !== $name) {
                    $product->set_name($name);
                    $changed = true;
                }
                if ($overwritePrices) {
                    $cur = (float)$product->get_regular_price();
                    if (abs($cur - (float)$price) > 0.0001) {
                        $product->set_regular_price(wc_format_decimal($price));
                        $changed = true;
                    }
                }

                if ($changed) {
                    $product->save();
                    update_post_meta($post_id, '_dwco_dotypos_synced_at', time());
                    $updated++;
                } else {
                    $skipped++;
                }
            }

            $catId = (int)($p['_categoryId'] ?? 0);
            if ($catId > 0 && isset($catMap[$catId])) {
                wp_set_object_terms($post_id, [(int)$catMap[$catId]], 'product_cat', false);
            }
        }

        return ['created'=>$created, 'updated'=>$updated, 'skipped'=>$skipped];
    }

    // ========== DAILY REPORT ==========

    public static function handle_test_daily_report(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dwco_test_daily_report');

        try {
            $dateFrom = isset($_POST['report_date']) ? sanitize_text_field($_POST['report_date']) : '';
            if ($dateFrom === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
                $dateFrom = current_time('Y-m-d');
            }

            $tz = wp_timezone();
            $dtFrom = new DateTime($dateFrom, $tz);
            $dtTo = clone $dtFrom;
            $dtTo->modify('+1 day');
            $dateTo = $dtTo->format('Y-m-d');

            $opts = self::get_options();
            $cloudId = trim($opts['cloud_id'] ?? '');
            if ($cloudId === '') throw new Exception('Brak cloud_id.');

            $branchSalaId  = trim($opts['daily_report_branch_sala_id'] ?? '146005859');
            $branchOgrodId = trim($opts['daily_report_branch_ogrod_id'] ?? '150149839');

            $urlSala  = "https://api.dotykacka.cz/v2/clouds/{$cloudId}/branches/{$branchSalaId}/sales-report?dateFrom={$dateFrom}&dateTo={$dateTo}";
            $urlOgrod = "https://api.dotykacka.cz/v2/clouds/{$cloudId}/branches/{$branchOgrodId}/sales-report?dateFrom={$dateFrom}&dateTo={$dateTo}";

            $salaResp  = self::api_request('GET', $urlSala);
            $ogrodResp = self::api_request('GET', $urlOgrod);

            if ($salaResp['http'] >= 300)  throw new Exception('Błąd raportu SALA: HTTP '.$salaResp['http'].' | '.$salaResp['raw']);
            if ($ogrodResp['http'] >= 300) throw new Exception('Błąd raportu OGRÓD: HTTP '.$ogrodResp['http'].' | '.$ogrodResp['raw']);

            $salaJson  = is_array($salaResp['json'])  ? $salaResp['json']  : [];
            $ogrodJson = is_array($ogrodResp['json']) ? $ogrodResp['json'] : [];

            $summary = self::build_daily_report_summary($dateFrom, $salaJson, $ogrodJson);

            self::log('info', 'DAILY REPORT SUMMARY', [
                'dateFrom'  => $dateFrom,
                'dateTo'    => $dateTo,
                'summary'   => $summary,
                'salaJson'  => $salaJson,
                'ogrodJson' => $ogrodJson,
            ]);

            set_transient('dwco_last_daily_report_summary', $summary, 10 * MINUTE_IN_SECONDS);
            set_transient('dwco_last_daily_report_debug', ['sala' => $salaJson, 'ogrod' => $ogrodJson], 10 * MINUTE_IN_SECONDS);

            wp_redirect(admin_url('admin.php?page=dwco&tab=diagnostics&dwco_msg='.rawurlencode('Raport wygenerowany. Zobacz poniżej.')));
            exit;
        } catch (Exception $e) {
            self::log('error', 'Daily report test failed', ['ex' => $e->getMessage()]);
            wp_redirect(admin_url('admin.php?page=dwco&tab=diagnostics&dwco_err='.rawurlencode('Błąd raportu: '.$e->getMessage())));
            exit;
        }
    }

    public static function handle_send_last_daily_report_sms(): void {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dwco_send_last_daily_report_sms');

        try {
            $summary = get_transient('dwco_last_daily_report_summary');
            if (!$summary) throw new Exception('Brak ostatniego raportu. Najpierw wygeneruj raport przyciskiem "Policz raport testowo".');

            $result = self::send_smsapi_sms((string)$summary);

            self::log('info', 'DAILY REPORT SMS SENT', [
                'http' => $result['http'],
                'raw'  => $result['raw'],
                'json' => $result['json'],
            ]);

            wp_redirect(admin_url('admin.php?page=dwco&tab=diagnostics&dwco_msg='.rawurlencode('SMS wysłany. HTTP: '.$result['http'])));
            exit;
        } catch (Exception $e) {
            self::log('error', 'Daily report SMS failed', ['ex' => $e->getMessage()]);
            wp_redirect(admin_url('admin.php?page=dwco&tab=diagnostics&dwco_err='.rawurlencode('Błąd SMS: '.$e->getMessage())));
            exit;
        }
    }

    private static function mm_to_float($value): float {
        if ($value === null || $value === '') return 0.0;
        return (float)str_replace(',', '.', (string)$value);
    }

    private static function mm_money(float $amount): string {
        return number_format($amount, 2, ',', '') . ' zł';
    }

    private static function mm_qty($qty): string {
        $f = (float)$qty;
        if ($f == (int)$f) return (string)(int)$f;
        return number_format($f, 1, ',', '');
    }

    private static function build_daily_report_summary(string $date, array $salaJson, array $ogrodJson): string {
        $opts = self::get_options();
        $cardMethodId    = (string)($opts['daily_report_card_payment_method_id'] ?? '900000002');
        $pizzaCategoryId = (string)($opts['daily_report_pizza_category_id'] ?? '1871188158721371');

        // Branch totals
        $salaTotal  = self::mm_to_float($salaJson['moneyTransactionInfo']['saleValue']  ?? 0);
        $ogrodTotal = self::mm_to_float($ogrodJson['moneyTransactionInfo']['saleValue'] ?? 0);
        $total = $salaTotal + $ogrodTotal;

        // S = SALA employees without /WYNOS, W = SALA employees with /WYNOS
        $s = 0.0;
        $w = 0.0;
        $employeeSales = $salaJson['employeeSales'] ?? [];
        if (is_array($employeeSales)) {
            foreach ($employeeSales as $emp) {
                if (!is_array($emp)) continue;
                $name   = (string)($emp['name'] ?? '');
                $amount = self::mm_to_float(
                    $emp['saleValue'] ?? ($emp['totalValue'] ?? ($emp['revenue'] ?? ($emp['total'] ?? 0)))
                );
                if (stripos($name, '/WYNOS') !== false) {
                    $w += $amount;
                } else {
                    $s += $amount;
                }
            }
        }

        $o = $ogrodTotal;

        // T = card payments from both branches
        $t = 0.0;
        foreach ([$salaJson, $ogrodJson] as $reportJson) {
            $paymentTypes = $reportJson['revenue']['paymentTypeInfo'] ?? [];
            if (!is_array($paymentTypes)) continue;
            foreach ($paymentTypes as $pt) {
                if (!is_array($pt)) continue;
                $typeId = (string)($pt['typeId'] ?? '');
                if ($typeId === $cardMethodId) {
                    $t += self::mm_to_float(
                        $pt['totalValue'] ?? ($pt['saleValue'] ?? ($pt['value'] ?? 0))
                    );
                }
            }
        }

        // PIZZA = count of products in pizza category from both branches
        $pizzaCount = 0.0;
        foreach ([$salaJson, $ogrodJson] as $reportJson) {
            $productSales = $reportJson['productSales'] ?? [];
            if (!is_array($productSales)) continue;
            foreach ($productSales as $prod) {
                if (!is_array($prod)) continue;
                $catId = (string)($prod['categoryId'] ?? '');
                if ($catId === $pizzaCategoryId) {
                    $pizzaCount += self::mm_to_float($prod['count'] ?? 0);
                }
            }
        }

        $dateFormatted = (new DateTime($date, wp_timezone()))->format('d.m.Y');

        return implode("\n", [
            "MM - {$dateFormatted}",
            "Utarg: " . self::mm_money($total),
            "S: " . self::mm_money($s),
            "O: " . self::mm_money($o),
            "W: " . self::mm_money($w),
            "T: " . self::mm_money($t),
            "PIZZA: " . self::mm_qty($pizzaCount) . " szt.",
        ]);
    }

    private static function send_smsapi_sms(string $message): array {
        $opts   = self::get_options();
        $token  = trim($opts['daily_report_smsapi_token'] ?? '');
        $phone  = trim($opts['daily_report_phone'] ?? '');
        $sender = trim($opts['daily_report_sms_sender'] ?? '');

        if ($token === '') throw new Exception('Brak SMSAPI token w ustawieniach.');
        if ($phone === '') throw new Exception('Brak numeru telefonu w ustawieniach.');

        $params = [
            'to'      => $phone,
            'message' => $message,
            'format'  => 'json',
        ];
        if ($sender !== '') {
            $params['from'] = $sender;
        }

        $resp = wp_remote_post('https://api.smsapi.pl/sms.do', [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'    => $params,
            'timeout' => 20,
        ]);

        if (is_wp_error($resp)) {
            throw new Exception('SMSAPI request failed: ' . $resp->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);

        return ['http' => $code, 'raw' => $raw, 'json' => $json];
    }

    public static function maybe_send_scheduled_daily_report(): void {
        $opts = self::get_options();
        if (($opts['daily_report_enabled'] ?? 'no') !== 'yes') return;

        $tz     = wp_timezone();
        $now    = new DateTime('now', $tz);
        $dow    = (int)$now->format('N'); // 1=Mon … 7=Sun
        $hour   = (int)$now->format('H');
        $minute = (int)$now->format('i');

        // Mon–Thu + Sun at 22:40–22:44; Fri–Sat at 23:40–23:44
        $weekdaySlot = in_array($dow, [1,2,3,4,7], true) && $hour === 22 && $minute >= 40 && $minute <= 44;
        $weekendSlot = in_array($dow, [5,6], true)       && $hour === 23 && $minute >= 40 && $minute <= 44;

        if (!$weekdaySlot && !$weekendSlot) return;

        $dateFrom = $now->format('Y-m-d');

        // Guard against double send on same day
        $guardKey = 'dwco_daily_report_sent_' . $dateFrom;
        if (get_option($guardKey)) return;

        try {
            $cloudId = trim($opts['cloud_id'] ?? '');
            if ($cloudId === '') throw new Exception('Brak cloud_id.');

            $branchSalaId  = trim($opts['daily_report_branch_sala_id'] ?? '146005859');
            $branchOgrodId = trim($opts['daily_report_branch_ogrod_id'] ?? '150149839');

            $dtTo   = clone $now;
            $dtTo->modify('+1 day');
            $dateTo = $dtTo->format('Y-m-d');

            $urlSala  = "https://api.dotykacka.cz/v2/clouds/{$cloudId}/branches/{$branchSalaId}/sales-report?dateFrom={$dateFrom}&dateTo={$dateTo}";
            $urlOgrod = "https://api.dotykacka.cz/v2/clouds/{$cloudId}/branches/{$branchOgrodId}/sales-report?dateFrom={$dateFrom}&dateTo={$dateTo}";

            $salaResp  = self::api_request('GET', $urlSala);
            $ogrodResp = self::api_request('GET', $urlOgrod);

            $salaJson  = is_array($salaResp['json'])  ? $salaResp['json']  : [];
            $ogrodJson = is_array($ogrodResp['json']) ? $ogrodResp['json'] : [];

            $summary = self::build_daily_report_summary($dateFrom, $salaJson, $ogrodJson);

            set_transient('dwco_last_daily_report_summary', $summary, 60 * MINUTE_IN_SECONDS);

            $smsResult = self::send_smsapi_sms($summary);

            update_option($guardKey, current_time('mysql'), false);

            self::log('info', 'DAILY REPORT SCHEDULED SENT', [
                'dateFrom' => $dateFrom,
                'summary'  => $summary,
                'sms_http' => $smsResult['http'],
            ]);
        } catch (Exception $e) {
            self::log('error', 'Scheduled daily report failed', ['ex' => $e->getMessage()]);
        }
    }

}

register_activation_hook(__FILE__, function () {
    if (class_exists('Dotypos_Woo_Connector')) {
        Dotypos_Woo_Connector::ensure_cron_schedule();
        if (!wp_next_scheduled('dwco_daily_report_cron_check')) {
            wp_schedule_event(time() + 60, 'dwco_5min', 'dwco_daily_report_cron_check');
        }
    }
});
register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('dwco_sync_event');
    wp_clear_scheduled_hook('dwco_daily_report_cron_check');
});

Dotypos_Woo_Connector::init();
