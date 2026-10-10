<?php
/*
Plugin Name: UnitechPay - Solution de Paiement Mobile Money
Description: Passerelle de paiement sécurisée prenant en charge Wave, Orange Money, MTN MoMo, Moov Money et T-Money selon le pays. Compatible WooCommerce et Block Checkout.
Version: 1.0.6.3
Author: Unitech Group
Author URI: https://unitech.sn
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
Text Domain: unitechpay-paiements-mobile-money
*/

if (!defined('ABSPATH')) {
    exit; }

define('UNITECHPAY_VERSION', '1.0.6.3');
define('UNITECHPAY_PLUGIN_URL', plugin_dir_url(__FILE__));
define('UNITECHPAY_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('UNITECHPAY_PLUGIN_FILE', __FILE__);
define('UNITECHPAY_API_BASE', 'https://pay.unitech.sn/wooplugin.php');
define('UNITECHPAY_LICENCE_API', 'https://pay.unitech.sn/licence.php?action=valider');
// Endpoint central pour les AUTRES pays
define('UNITECHPAY_BICTORYS_API', 'https://pay.unitech.sn/bictorys/wooplugin.php');

function unitechpay_bictorys_countries() {
    return array(
        'CI' => array('name' => "Côte d'Ivoire", 'currency' => 'XOF', 'commission' => 4.6),
        'TG' => array('name' => 'Togo',          'currency' => 'XOF', 'commission' => 4.8),
        'BF' => array('name' => 'Burkina Faso',  'currency' => 'XOF', 'commission' => 6.2),
        'BJ' => array('name' => 'Bénin',         'currency' => 'XOF', 'commission' => 4.0),
    );
}

/**
 * Moyens de paiement mobile money par pays .
 */
function unitechpay_bictorys_methods($country) {
    $map = array(
        'CI' => array(
            'wave_money'   => array('label' => 'Wave',         'icon' => 'wave-logo.png'),
            'orange_money' => array('label' => 'Orange Money', 'icon' => 'methods/orange_money.svg'),
            'mtn_money'    => array('label' => 'MTN MoMo',      'icon' => 'methods/mtn_money.svg'),
            'moov'         => array('label' => 'Moov Money',    'icon' => 'methods/moov.svg'),
        ),
        'TG' => array(
            'togocell'     => array('label' => 'T-Money',       'icon' => 'methods/togocell.svg'),
            'moov'         => array('label' => 'Moov Money',    'icon' => 'methods/moov.svg'),
        ),
        'BF' => array(
            'wave_money'   => array('label' => 'Wave',         'icon' => 'wave-logo.png'),
            'orange_money' => array('label' => 'Orange Money', 'icon' => 'methods/orange_money.svg'),
            'moov'         => array('label' => 'Moov Money',    'icon' => 'methods/moov.svg'),
        ),
        'BJ' => array(
            'mtn_money'    => array('label' => 'MTN MoMo',      'icon' => 'methods/mtn_money.svg'),
            'moov'         => array('label' => 'Moov Money',    'icon' => 'methods/moov.svg'),
        ),
    );
    $country = strtoupper((string) $country);
    return isset($map[$country]) ? $map[$country] : array();
}

function unitechpay_bictorys_commission($country) {
    $c = unitechpay_bictorys_countries();
    $country = strtoupper((string) $country);
    return isset($c[$country]) ? floatval($c[$country]['commission']) : 5.0;
}

/** Indicatif téléphonique par pays. */
function unitechpay_country_dialcode($code) {
    $map = array('SN' => '221', 'CI' => '225', 'TG' => '228', 'BF' => '226', 'BJ' => '229', 'ML' => '223');
    $code = strtoupper((string) $code);
    return isset($map[$code]) ? $map[$code] : '';
}
function unitechpay_needs_otp($country, $method) {
    return ($method === 'orange_money') && in_array(strtoupper((string) $country), array('CI', 'BF'), true);
}

function unitechpay_sync_method_logos() {
    $sources = array(
        'orange_money' => 'https://pay.bictorys.com/payment/v1/assets/orange_money-3cc1f465.svg',
        'mtn_money'    => 'https://pay.bictorys.com/payment/v1/assets/mtn_money-02eabc46.svg',
        'moov'         => 'https://pay.bictorys.com/payment/v1/assets/moov-36df913e.svg',
        'mobicash'     => 'https://pay.bictorys.com/payment/v1/assets/mobicash-5802a140.svg',
        'togocell'     => 'https://pay.bictorys.com/payment/v1/assets/togocell-fe62c49e.svg',
    );
    $dir = UNITECHPAY_PLUGIN_PATH . 'assets/methods/';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    foreach ($sources as $key => $url) {
        $response = wp_remote_get($url, array('timeout' => 20));
        if (is_wp_error($response)) {
            continue;
        }
        if (wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            if (stripos($body, '<svg') !== false) {
                @file_put_contents($dir . $key . '.svg', $body);
            }
        }
    }
}

function unitechpay_maybe_sync_method_logos() {
    if (get_transient('unitechpay_logos_synced')) {
        return;
    }
    unitechpay_sync_method_logos();
    set_transient('unitechpay_logos_synced', 1, WEEK_IN_SECONDS);
}
add_action('admin_init', 'unitechpay_maybe_sync_method_logos');

function unitechpay_bictorys_enabled_countries() {
    $settings = get_option('woocommerce_unitechpay_gateway_settings', array());
    $all = array_keys(unitechpay_bictorys_countries());
    // Jamais enregistré => tous activés par défaut
    if (!array_key_exists('bictorys_countries', $settings) || $settings['bictorys_countries'] === '') {
        return $all;
    }
    $enabled = (array) $settings['bictorys_countries'];
    return array_values(array_intersect($all, $enabled));
}

/**
 * Récupère la clé de licence de façon fiable.
 * Elle est enregistrée dans les réglages de la passerelle
 * (woocommerce_unitechpay_gateway_settings['license_key']) ; l'option
 * autonome unitechpay_license_key sert de miroir/cache.
 */
function unitechpay_get_license_key() {
    $key = get_option('unitechpay_license_key', '');
    if (empty($key)) {
        $settings = get_option('woocommerce_unitechpay_gateway_settings', array());
        if (!empty($settings['license_key'])) {
            $key = $settings['license_key'];
        }
    }
    return trim((string) $key);
}

function unitechpay_json_from_body($body) {
    if (!is_string($body) || $body === '') {
        return null;
    }
    // Retire un éventuel BOM UTF-8.
    $body = preg_replace('/^\xEF\xBB\xBF/', '', $body);
    $data = json_decode(trim($body), true);
    if (is_array($data)) {
        return $data;
    }
    // Repli : extraire le premier bloc {...} du corps.
    if (preg_match('/\{.*\}/s', $body, $m)) {
        $data = json_decode($m[0], true);
        if (is_array($data)) {
            return $data;
        }
    }
    return null;
}

/** Le Sénégal (Wave / Orange Money) est-il activé ? (par défaut : oui). */
function unitechpay_senegal_enabled() {
    $settings = get_option('woocommerce_unitechpay_gateway_settings', array());
    if (!array_key_exists('enable_senegal', $settings)) {
        return true; // par défaut activé
    }
    return $settings['enable_senegal'] === 'yes';
}

/* ============================================================
 *  TABLEAU DE BORD ADMIN UNITECHPAY
 * ============================================================ */
add_action('admin_menu', 'unitechpay_register_admin_dashboard');
function unitechpay_register_admin_dashboard() {
    add_menu_page(
        'UnitechPay',
        'UnitechPay',
        'manage_woocommerce',
        'unitechpay-dashboard',
        'unitechpay_render_admin_dashboard',
        'dashicons-money-alt',
        56
    );
}

/** Agrège les statistiques des commandes payées via UnitechPay. */
function unitechpay_collect_stats(&$recent) {
    $recent = array();
    $stats = array(
        'total' => 0, 'reussi' => 0, 'attente' => 0, 'echec' => 0,
        'revenue' => 0.0, 'commission' => 0.0, 'payout' => 0.0,
        'payout_done' => 0, 'payout_pending' => 0,
        'by_country' => array(), 'by_method' => array(),
    );

    if (!function_exists('wc_get_orders')) {
        return $stats;
    }

    $order_ids = wc_get_orders(array(
        'payment_method' => 'unitechpay_gateway',
        'limit'          => -1,
        'return'         => 'ids',
        'orderby'        => 'date',
        'order'          => 'DESC',
    ));

    foreach ($order_ids as $oid) {
        $order = wc_get_order($oid);
        if (!$order) continue;

        $status = $order->get_status();
        $total  = (float) $order->get_total();
        $stats['total']++;

        if (in_array($status, array('completed', 'processing'), true)) {
            $stats['reussi']++;
            $stats['revenue'] += $total;
        } elseif (in_array($status, array('pending', 'on-hold'), true)) {
            $stats['attente']++;
        } else { // failed, cancelled, refunded...
            $stats['echec']++;
        }

        $commission = (float) get_post_meta($oid, '_unitechpay_commission', true);
        $payout     = (float) get_post_meta($oid, '_unitechpay_payout_amount', true);
        $payout_ok  = get_post_meta($oid, '_unitechpay_payout_completed', true);
        if ($commission) $stats['commission'] += $commission;
        if ($payout_ok) {
            $stats['payout_done']++;
            if ($payout) $stats['payout'] += $payout;
        } elseif (in_array($status, array('completed', 'processing'), true)) {
            $stats['payout_pending']++;
        }

        $country = get_post_meta($oid, '_unitechpay_country', true) ?: 'SN';
        $method  = get_post_meta($oid, '_unitechpay_payment_method', true) ?: '—';
        $stats['by_country'][$country] = ($stats['by_country'][$country] ?? 0) + 1;
        $stats['by_method'][$method]   = ($stats['by_method'][$method] ?? 0) + 1;

        if (count($recent) < 30) {
            $recent[] = array(
                'id'         => $oid,
                'number'     => $order->get_order_number(),
                'date'       => $order->get_date_created() ? $order->get_date_created()->date('d/m/Y H:i') : '',
                'customer'   => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'country'    => $country,
                'method'     => $method,
                'total'      => $total,
                'commission' => $commission,
                'payout'     => $payout,
                'payout_ok'  => (bool) $payout_ok,
                'status'     => $status,
                'edit_url'   => $order->get_edit_order_url(),
            );
        }
    }
    return $stats;
}

function unitechpay_render_admin_dashboard() {
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Accès refusé', 'unitechpay-paiements-mobile-money'));
    }

    $recent = array();
    $s = unitechpay_collect_stats($recent);

    $status_labels = array(
        'completed'  => array('Terminée', '#d4edda', '#155724'),
        'processing' => array('En cours', '#cce5ff', '#004085'),
        'pending'    => array('En attente', '#fff3cd', '#856404'),
        'on-hold'    => array('En pause', '#fff3cd', '#856404'),
        'failed'     => array('Échec', '#f8d7da', '#721c24'),
        'cancelled'  => array('Annulée', '#f8d7da', '#721c24'),
        'refunded'   => array('Remboursée', '#e2e3e5', '#383d41'),
    );

    echo '<div class="wrap unitechpay-dash">';
    echo '<style>
        .unitechpay-dash{max-width:1300px}
        .up-hero{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;border-radius:16px;padding:26px 30px;margin:16px 0 24px;box-shadow:0 8px 24px rgba(102,126,234,.25)}
        .up-hero h1{color:#fff;margin:0 0 4px;font-size:1.7em}
        .up-hero p{margin:0;opacity:.9}
        .up-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:24px}
        .up-card{background:#fff;border-radius:14px;padding:20px 22px;box-shadow:0 3px 12px rgba(0,0,0,.06);border:1px solid #f0f0f4}
        .up-card .lbl{color:#8a8a99;font-size:.8em;text-transform:uppercase;letter-spacing:.5px;font-weight:600;margin-bottom:8px}
        .up-card .val{font-size:2em;font-weight:800;color:#2c3e50;line-height:1}
        .up-card .sub{color:#a0a0ad;font-size:.82em;margin-top:6px}
        .up-card.green{background:linear-gradient(135deg,#27ae60,#2ecc71);color:#fff}
        .up-card.green .lbl,.up-card.green .val,.up-card.green .sub{color:#fff}
        .up-card.amber{background:linear-gradient(135deg,#f1c40f,#e67e22);color:#2c3e50}
        .up-card.amber .lbl,.up-card.amber .val,.up-card.amber .sub{color:#2c3e50}
        .up-card.red{background:linear-gradient(135deg,#e74c3c,#c0392b);color:#fff}
        .up-card.red .lbl,.up-card.red .val,.up-card.red .sub{color:#fff}
        .up-section{background:#fff;border-radius:14px;padding:20px 22px;box-shadow:0 3px 12px rgba(0,0,0,.06);margin-bottom:22px;border:1px solid #f0f0f4}
        .up-section h2{margin:0 0 14px;font-size:1.15em;color:#2c3e50}
        .up-table{width:100%;border-collapse:collapse}
        .up-table th{text-align:left;padding:11px 12px;background:#f8f9fb;color:#2c3e50;font-size:.82em;border-bottom:2px solid #eef0f5}
        .up-table td{padding:11px 12px;border-bottom:1px solid #f4f4f8;font-size:.88em;color:#2c3e50}
        .up-table tr:hover td{background:#fafbff}
        .up-badge{padding:3px 10px;border-radius:20px;font-size:.78em;font-weight:700;display:inline-block}
        .up-pill{padding:2px 9px;border-radius:12px;font-size:.78em;font-weight:600;background:#eef0ff;color:#4c51bf}
        .up-ok{color:#27ae60;font-weight:700}
        .up-wait{color:#e67e22;font-weight:700}
        .up-mini{display:flex;gap:10px;flex-wrap:wrap}
        .up-mini span{background:#f2f3f8;border-radius:20px;padding:5px 12px;font-size:.82em;color:#4a4a5a}
        .up-empty{text-align:center;color:#999;padding:30px}
    </style>';

    echo '<div class="up-hero"><h1>💳 UnitechPay — Tableau de bord</h1><p>Statistiques des paiements et retraits (payouts) — Wave, Orange Money, MTN, Moov, Carte</p></div>';

    // Cartes principales
    echo '<div class="up-cards">';
    echo '<div class="up-card"><div class="lbl">Transactions</div><div class="val">' . number_format($s['total'], 0, ',', ' ') . '</div><div class="sub">Total UnitechPay</div></div>';
    echo '<div class="up-card green"><div class="lbl">Réussies</div><div class="val">' . number_format($s['reussi'], 0, ',', ' ') . '</div><div class="sub">Terminées / en cours</div></div>';
    echo '<div class="up-card amber"><div class="lbl">En attente</div><div class="val">' . number_format($s['attente'], 0, ',', ' ') . '</div><div class="sub">Non confirmées</div></div>';
    echo '<div class="up-card red"><div class="lbl">Échecs</div><div class="val">' . number_format($s['echec'], 0, ',', ' ') . '</div><div class="sub">Annulées / échouées</div></div>';
    echo '</div>';

    // Cartes financières
    echo '<div class="up-cards">';
    echo '<div class="up-card"><div class="lbl">Chiffre d\'affaires</div><div class="val">' . number_format($s['revenue'], 0, ',', ' ') . '<small style="font-size:.45em"> FCFA</small></div><div class="sub">Paiements réussis</div></div>';
    echo '<div class="up-card"><div class="lbl">Commissions</div><div class="val">' . number_format($s['commission'], 0, ',', ' ') . '<small style="font-size:.45em"> FCFA</small></div><div class="sub">Total UnitechPay</div></div>';
    echo '<div class="up-card"><div class="lbl">Retraits (payouts)</div><div class="val">' . number_format($s['payout'], 0, ',', ' ') . '<small style="font-size:.45em"> FCFA</small></div><div class="sub">' . (int) $s['payout_done'] . ' payout(s) effectué(s)</div></div>';
    echo '<div class="up-card"><div class="lbl">Payouts en attente</div><div class="val">' . number_format($s['payout_pending'], 0, ',', ' ') . '</div><div class="sub">À débourser</div></div>';
    echo '</div>';

    // Répartitions
    echo '<div class="up-section"><h2>Répartition</h2><div class="up-mini">';
    foreach ($s['by_country'] as $c => $n) { echo '<span>🌍 ' . esc_html($c) . ' : <strong>' . (int) $n . '</strong></span>'; }
    foreach ($s['by_method'] as $m => $n)  { echo '<span>⚡ ' . esc_html(strtoupper($m)) . ' : <strong>' . (int) $n . '</strong></span>'; }
    if (empty($s['by_country'])) { echo '<span>Aucune donnée pour le moment.</span>'; }
    echo '</div></div>';

    // Transactions récentes
    echo '<div class="up-section"><h2>Transactions récentes</h2>';
    if (empty($recent)) {
        echo '<div class="up-empty">Aucune transaction UnitechPay pour le moment.</div>';
    } else {
        echo '<table class="up-table"><thead><tr>';
        echo '<th>Commande</th><th>Date</th><th>Client</th><th>Pays</th><th>Méthode</th><th>Montant</th><th>Commission</th><th>Payout</th><th>Statut</th>';
        echo '</tr></thead><tbody>';
        foreach ($recent as $r) {
            $sl = $status_labels[$r['status']] ?? array(ucfirst($r['status']), '#eee', '#555');
            echo '<tr>';
            echo '<td><a href="' . esc_url($r['edit_url']) . '">#' . esc_html($r['number']) . '</a></td>';
            echo '<td>' . esc_html($r['date']) . '</td>';
            echo '<td>' . esc_html($r['customer'] ?: '—') . '</td>';
            echo '<td><span class="up-pill">' . esc_html($r['country']) . '</span></td>';
            echo '<td>' . esc_html(strtoupper($r['method'])) . '</td>';
            echo '<td><strong>' . number_format($r['total'], 0, ',', ' ') . ' F</strong></td>';
            echo '<td>' . ($r['commission'] ? number_format($r['commission'], 0, ',', ' ') . ' F' : '—') . '</td>';
            echo '<td>' . ($r['payout'] ? number_format($r['payout'], 0, ',', ' ') . ' F ' . ($r['payout_ok'] ? '<span class="up-ok">✓</span>' : '<span class="up-wait">⏳</span>') : ($r['payout_ok'] ? '<span class="up-ok">✓</span>' : '—')) . '</td>';
            echo '<td><span class="up-badge" style="background:' . esc_attr($sl[1]) . ';color:' . esc_attr($sl[2]) . '">' . esc_html($sl[0]) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    echo '<p style="color:#999;font-size:.85em">UnitechPay v' . esc_html(UNITECHPAY_VERSION) . ' — Les payouts « autres pays » sont exécutés automatiquement via Bictorys après confirmation du paiement.</p>';
    echo '</div>';
}

add_action('before_woocommerce_init', 'unitechpay_declare_compatibility');
function unitechpay_declare_compatibility() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', UNITECHPAY_PLUGIN_FILE, true);
    }
}

add_action('woocommerce_blocks_loaded', 'unitechpay_register_blocks_support');
function unitechpay_register_blocks_support() {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    require_once UNITECHPAY_PLUGIN_PATH . 'includes/class-unitechpay-blocks.php';

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function ($payment_method_registry) {
            $payment_method_registry->register(new UnitechPay_Blocks_Support());
        }
    );
}

add_action('plugins_loaded', 'unitechpay_check_woocommerce');
function unitechpay_check_woocommerce() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', 'unitechpay_woocommerce_missing_notice');
        return;
    }
}

function unitechpay_woocommerce_missing_notice() {
    echo '<div class="error"><p><strong>UnitechPay</strong> ' . esc_html__('nécessite WooCommerce pour fonctionner. Veuillez installer et activer WooCommerce.', 'unitechpay-paiements-mobile-money') . '</p></div>';
}

add_action('admin_init', 'unitechpay_check_license_on_admin');
function unitechpay_check_license_on_admin() {
    $license_key = unitechpay_get_license_key();
    $license_status = get_option('unitechpay_license_status', 'invalid');

        if (!empty($license_key) && $license_status !== 'valid') {
        unitechpay_validate_license_now($license_key);
    }
}

/**
 * Appel bas niveau au serveur de licences, robuste.
 * - User-Agent explicite + HTTP/1.1 (certains WAF bloquent l'UA WordPress par défaut).
 * - Repli sans vérif SSL (bundle CA obsolète), puis repli cURL direct.
 * - Enregistre un diagnostic MINIMAL et NON sensible (code HTTP + erreur réseau
 *   uniquement — jamais l'URL, la clé, ni le corps de la réponse).
 * Retourne array('data'=>?array, 'error'=>string, 'code'=>int).
 */
function unitechpay_remote_license_check($license_key) {
    $license_key = trim((string) $license_key);
    $url = add_query_arg(array(
        'action'      => 'valider',
        'licence_key' => $license_key,
        'site_url'    => get_site_url(),
    ), UNITECHPAY_LICENCE_API);

    $args = array(
        'timeout'     => 30,
        'redirection' => 5,
        'httpversion' => '1.1',
        'user-agent'  => 'UnitechPay/' . UNITECHPAY_VERSION,
        'headers'     => array('Accept' => 'application/json'),
    );

    $response = wp_remote_get($url, $args);
    if (is_wp_error($response)) {
        $args2 = $args;
        $args2['sslverify'] = false;
        $response = wp_remote_get($url, $args2);
    }

    $err = ''; $code = 0; $body = '';
    if (is_wp_error($response)) {
        $err = $response->get_error_message();
        // Dernier repli : cURL direct (contourne parfois l'API HTTP de WP).
        $curl = unitechpay_curl_license($url);
        if ($curl !== null) {
            $code = $curl['code'];
            $body = $curl['body'];
            if ($curl['error'] !== '') { $err = $curl['error']; }
            elseif ($body !== '')      { $err = ''; }
        }
    } else {
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
    }

    $data = unitechpay_json_from_body($body);

    // Diagnostic minimal et non sensible : uniquement le nécessaire pour savoir
    // qu'un appel a échoué (jamais d'URL, de clé ni de contenu de réponse).
    update_option('unitechpay_license_debug', array(
        'time'     => current_time('mysql'),
        'code'     => $code,
        'error'    => $err,
        'had_json' => is_array($data),
    ), false);

    return array('data' => $data, 'error' => $err, 'code' => $code);
}

/** Repli cURL direct. Retourne array('body','error','code') ou null si cURL absent. */
function unitechpay_curl_license($url) {
    if (!function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HTTPHEADER     => array('Accept: application/json'),
        CURLOPT_USERAGENT      => 'UnitechPay/' . UNITECHPAY_VERSION,
    ));
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array('body' => (string) $body, 'error' => (string) $err, 'code' => $code);
}

/**
 * Valide la licence auprès du serveur.
 * IMPORTANT : en cas d'échec réseau (host qui bloque les requêtes sortantes,
 * certificat SSL obsolète, WAF, timeout...), on NE rétrograde PAS une licence
 * déjà valide. On ne passe en 'invalid' QUE si le serveur répond explicitement
 * success=false.
 */
function unitechpay_validate_license_now($license_key) {
    $license_key = trim((string) $license_key);
    if ($license_key === '') {
        update_option('unitechpay_license_status', 'invalid');
        return false;
    }

    $r    = unitechpay_remote_license_check($license_key);
    $data = $r['data'];

    if (is_array($data) && isset($data['success'])) {
        update_option('unitechpay_license_status', $data['success'] ? 'valid' : 'invalid');
        update_option('unitechpay_license_data', $data);
        if ($data['success']) {
            update_option('unitechpay_license_key', $license_key);
        }
        return (bool) $data['success'];
    }

    // Réponse illisible / serveur injoignable : on garde l'état courant.
    return get_option('unitechpay_license_status', 'invalid') === 'valid';
}


add_action('admin_init', 'unitechpay_check_license_periodically');
function unitechpay_check_license_periodically() {
    $license_key = unitechpay_get_license_key();
    $license_status = get_option('unitechpay_license_status', 'invalid');
    $last_check = get_option('unitechpay_last_license_check', 0);
    
        if ($license_status === 'valid' && time() - $last_check > 86400 && !empty($license_key)) {
        unitechpay_validate_license_now($license_key);
        update_option('unitechpay_last_license_check', time());
    }
}

add_action('admin_notices', 'unitechpay_license_notice');
function unitechpay_license_notice() {
    $license_status = get_option('unitechpay_license_status', 'invalid');
    $license_key = unitechpay_get_license_key();
    $screen = get_current_screen();
    
    if ($license_status === 'valid') {
        return;
    }
    
    if ($screen && $screen->id === 'woocommerce_page_wc-settings' && isset($_GET['section']) && $_GET['section'] === 'unitechpay_gateway') {
        return;
    }
    
    if (empty($license_key)) {
        echo '<div class="notice notice-warning is-dismissible">';
        echo '<p><strong>UnitechPay :</strong> Veuillez entrer votre clé de licence pour activer le plugin. <a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=unitechpay_gateway') . '">Configurer maintenant</a></p>';
        echo '</div>';
    } elseif ($license_status !== 'valid') {
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p><strong>UnitechPay :</strong> Votre licence n\'est pas valide. Veuillez vérifier votre clé de licence ou <a href="https://pay.unitech.sn/licence.php" target="_blank">acheter une licence</a>.</p>';
        echo '</div>';
    }
}

add_filter('woocommerce_available_payment_gateways', 'unitechpay_disable_if_license_invalid');
function unitechpay_disable_if_license_invalid($gateways) {
    $license_status = get_option('unitechpay_license_status', 'invalid');
    
    if ($license_status !== 'valid' && isset($gateways['unitechpay_gateway'])) {
        unset($gateways['unitechpay_gateway']);
    }
    
    return $gateways;
}

/**
 * Tant que la licence n'est pas valide, on force enabled = 'no' dans les
 * réglages de la passerelle. Ainsi l'interrupteur « Activé » de la liste
 * WooCommerce → Paiements ne reste jamais allumé sans licence valide.
 * Les autres réglages (numéros Wave / Orange, etc.) restent intacts.
 */
function unitechpay_enforce_license_gate() {
    if (get_option('unitechpay_license_status', 'invalid') === 'valid') {
        return;
    }
    $settings = get_option('woocommerce_unitechpay_gateway_settings', array());
    if (is_array($settings) && !empty($settings) && (!isset($settings['enabled']) || $settings['enabled'] !== 'no')) {
        $settings['enabled'] = 'no';
        update_option('woocommerce_unitechpay_gateway_settings', $settings);
    }
}
add_action('admin_init', 'unitechpay_enforce_license_gate');
add_action('init', 'unitechpay_enforce_license_gate');

add_filter('woocommerce_payment_gateways', 'unitechpay_add_gateway_class');
function unitechpay_add_gateway_class($gateways) {
    if (class_exists('WooCommerce') && !in_array('UnitechPay_Gateway', $gateways)) {
        $gateways[] = 'UnitechPay_Gateway';
    }
    return $gateways;
}

add_action('wp_enqueue_scripts', 'unitechpay_frontend_scripts');
function unitechpay_frontend_scripts() {
    if (is_checkout() || is_account_page()) {
        wp_enqueue_style('unitechpay-styles', UNITECHPAY_PLUGIN_URL . 'assets/css/unitechpay.css', array(), UNITECHPAY_VERSION);
        wp_enqueue_style('unitechpay-fontawesome', UNITECHPAY_PLUGIN_URL . 'assets/css/fontawesome.min.css', array(), UNITECHPAY_VERSION);
        
        wp_enqueue_script('unitechpay-script', UNITECHPAY_PLUGIN_URL . 'assets/js/unitechpay.js', array('jquery'), UNITECHPAY_VERSION, true);
        wp_localize_script('unitechpay-script', 'unitechpay_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('unitechpay_nonce')
        ));
    }
}

add_action('init', 'unitechpay_register_endpoints');
add_action('parse_request', 'unitechpay_process_requests');

function unitechpay_register_endpoints() {
        add_rewrite_rule('^unitechpay-wave-webhook/?$', 'index.php?unitechpay_wave_webhook=1', 'top');
    add_rewrite_tag('%unitechpay_wave_webhook%', '([0-9]+)');
    
        add_rewrite_rule('^unitechpay-orange-webhook/?$', 'index.php?unitechpay_orange_webhook=1', 'top');
    add_rewrite_tag('%unitechpay_orange_webhook%', '([0-9]+)');

        add_rewrite_rule('^unitechpay-bictorys-webhook/?$', 'index.php?unitechpay_bictorys_webhook=1', 'top');
    add_rewrite_tag('%unitechpay_bictorys_webhook%', '([0-9]+)');
    
        add_rewrite_rule('^unitechpay-payment/([^/]+)/?$', 'index.php?unitechpay_payment=1&order_key=$matches[1]', 'top');
    add_rewrite_tag('%unitechpay_payment%', '([0-9]+)');
    add_rewrite_tag('%order_key%', '([^&]+)');

        add_rewrite_rule('^unitechpay-bictorys-pay/([^/]+)/?$', 'index.php?unitechpay_bictorys_pay=1&order_key=$matches[1]', 'top');
    add_rewrite_tag('%unitechpay_bictorys_pay%', '([0-9]+)');
}

function unitechpay_process_requests() {
    global $wp;
    
    if (isset($wp->query_vars['unitechpay_wave_webhook'])) {
        unitechpay_process_wave_webhook();
        exit;
    }
    
    if (isset($wp->query_vars['unitechpay_orange_webhook'])) {
        unitechpay_process_orange_webhook();
        exit;
    }

    if (isset($wp->query_vars['unitechpay_bictorys_webhook'])) {
        unitechpay_process_central_webhook('bictorys');
        exit;
    }
    
    if (isset($wp->query_vars['unitechpay_payment'])) {
        unitechpay_display_payment_page();
        exit;
    }

    if (isset($wp->query_vars['unitechpay_bictorys_pay'])) {
        unitechpay_display_bictorys_payment_page();
        exit;
    }
}

add_action('wp_ajax_unitechpay_check_payment_status', 'unitechpay_ajax_check_payment_status');
add_action('wp_ajax_nopriv_unitechpay_check_payment_status', 'unitechpay_ajax_check_payment_status');
function unitechpay_ajax_check_payment_status() {
    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'unitechpay_nonce')) {
        wp_send_json_error(array('message' => esc_html__('Échec de la vérification de sécurité', 'unitechpay-paiements-mobile-money')));
    }
    
    if (!isset($_POST['order_id'])) {
        wp_send_json_error(array('message' => esc_html__('ID de commande manquant', 'unitechpay-paiements-mobile-money')));
    }
    
    $order_id = intval($_POST['order_id']);
    if ($order_id <= 0) {
        wp_send_json_error(array('message' => esc_html__('ID de commande invalide', 'unitechpay-paiements-mobile-money')));
    }
    
    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(array('message' => esc_html__('Commande introuvable', 'unitechpay-paiements-mobile-money')));
    }
    
    $status = $order->get_status();
    $payout_completed = get_post_meta($order_id, '_unitechpay_payout_completed', true);
    $transaction_id = get_post_meta($order_id, '_unitechpay_transaction_id', true);
    
    wp_cache_delete($order_id, 'posts');
    wp_cache_delete($order_id, 'post_meta');
    clean_post_cache($order_id);
    
    $order = wc_get_order($order_id);
    $status = $order->get_status();
    
    $is_completed = false;
    $redirect_url = $order->get_checkout_order_received_url();
    
    if (in_array($status, array('completed', 'processing')) || $payout_completed || $transaction_id) {
        $is_completed = true;
        update_post_meta($order_id, '_unitechpay_status_checked', current_time('timestamp'));
    }
    
    wp_send_json_success(array(
        'status' => sanitize_text_field($status),
        'order_url' => esc_url_raw($redirect_url),
        'is_completed' => $is_completed,
        'payout_completed' => $payout_completed ? true : false,
        'transaction_id' => $transaction_id ? sanitize_text_field($transaction_id) : '',
        'timestamp' => current_time('mysql'),
        'force_redirect' => $is_completed
    ));
}

function unitechpay_process_wave_webhook() {
    unitechpay_process_central_webhook('wave');
}

function unitechpay_process_orange_webhook() {
    unitechpay_process_central_webhook('orange');
}

function unitechpay_process_central_webhook($provider) {
    $provider = sanitize_text_field($provider);
    
    if (!in_array($provider, array('wave', 'orange', 'bictorys'))) {
        http_response_code(400);
        wp_send_json_error('Fournisseur invalide');
        exit;
    }
    
    $webhook_body = file_get_contents('php://input');
    if (empty($webhook_body)) {
        http_response_code(400);
        wp_send_json_error('Données de webhook vides');
        exit;
    }
    
    $webhook_data = json_decode($webhook_body, true);
    error_log('UnitechPay Webhook (' . $provider . '): ' . $webhook_body);
    
    if (!$webhook_data || !is_array($webhook_data)) {
        http_response_code(400);
        wp_send_json_error('Données de webhook invalides');
        exit;
    }
    
    if (isset($webhook_data['order_id']) && isset($webhook_data['status'])) {
        $order_id = intval($webhook_data['order_id']);
        $status = sanitize_text_field($webhook_data['status']);
        $transaction_id = isset($webhook_data['transaction_id']) ? sanitize_text_field($webhook_data['transaction_id']) : '';
        $payout_amount = isset($webhook_data['payout_amount']) ? floatval($webhook_data['payout_amount']) : 0;
        $commission = isset($webhook_data['commission']) ? floatval($webhook_data['commission']) : 0;
        
        if ($order_id <= 0 || !in_array($status, array('completed', 'failed', 'pending'))) {
            http_response_code(400);
            wp_send_json_error('Données invalides');
            exit;
        }
        
        $order = wc_get_order($order_id);
        
        if (!$order || $order->get_payment_method() !== 'unitechpay_gateway') {
            error_log('UnitechPay: Commande invalide pour le webhook: ' . $order_id);
            http_response_code(404);
            exit;
        }
        
        if ($status === 'completed') {
            error_log('UnitechPay: Traitement paiement réussi pour commande ' . $order_id);
            
            update_post_meta($order_id, '_unitechpay_payout_completed', current_time('timestamp'));
            update_post_meta($order_id, '_unitechpay_commission', $commission);
            update_post_meta($order_id, '_unitechpay_payout_amount', $payout_amount);
            
            if ($transaction_id) {
                update_post_meta($order_id, '_unitechpay_transaction_id', sanitize_text_field($transaction_id));
            }
            
            if (!$order->is_paid()) {
                $order->payment_complete($transaction_id);
            }
            
            $order->update_status('completed', sprintf(
                esc_html__('Paiement %s réussi via UnitechPay', 'unitechpay-paiements-mobile-money'), 
                ucfirst($provider)
            ));
            
            $order->add_order_note(
                sprintf(
                    esc_html__('Paiement UnitechPay réussi : %s FCFA transférés (Commission : %s FCFA) - Transaction: %s', 'unitechpay-paiements-mobile-money'),
                    number_format($payout_amount, 0, ',', ' '),
                    number_format($commission, 0, ',', ' '),
                    $transaction_id
                )
            );
            
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }
            
            wp_cache_flush();
            wp_cache_delete($order_id, 'posts');
            wp_cache_delete($order_id, 'post_meta');
            clean_post_cache($order_id);
            
            update_post_meta($order_id, '_unitechpay_webhook_processed', current_time('timestamp'));
            
        } elseif ($status === 'failed') {
            $order->update_status('failed', sprintf(
                esc_html__('Paiement %s échoué via UnitechPay', 'unitechpay-paiements-mobile-money'), 
                ucfirst($provider)
            ));
            error_log('UnitechPay: Paiement échoué pour la commande ' . $order_id);
        }
    }
    
    http_response_code(200);
    wp_send_json_success('Webhook traité');
    exit;
}

function unitechpay_display_payment_page() {
    global $wp;
    $order_key = isset($wp->query_vars['order_key']) ? sanitize_text_field($wp->query_vars['order_key']) : '';
    
    if (empty($order_key)) {
        wp_die(esc_html__('Clé de commande manquante', 'unitechpay-paiements-mobile-money'));
    }
    
    $order_id = wc_get_order_id_by_order_key($order_key);
    $order = wc_get_order($order_id);
    
    if (!$order || $order->get_payment_method() !== 'unitechpay_gateway') {
        wp_die(esc_html__('Commande introuvable', 'unitechpay-paiements-mobile-money'));
    }
    
    wp_cache_delete($order_id, 'posts');
    clean_post_cache($order_id);
    $order = wc_get_order($order_id);
    
    $current_status = $order->get_status();
    $payout_completed = get_post_meta($order_id, '_unitechpay_payout_completed', true);
    
    if (in_array($current_status, array('completed', 'processing')) || $payout_completed) {
        wp_redirect($order->get_checkout_order_received_url());
        exit;
    }
    
    $payment_method = get_post_meta($order_id, '_unitechpay_payment_method', true);
    $qr_code = get_post_meta($order_id, '_unitechpay_qr_code', true);
    
    $is_mobile = wp_is_mobile();
    
    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php esc_html_e('Paiement UnitechPay', 'unitechpay-paiements-mobile-money'); ?> - <?php bloginfo('name'); ?></title>
        <?php wp_head(); ?>
        <style>
            .unitechpay-payment-page {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
                font-family: 'Arial', sans-serif;
            }
            
            .unitechpay-payment-container {
                max-width: 600px;
                margin: 0 auto;
                background: white;
                border-radius: 15px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.2);
                padding: 30px;
                text-align: center;
                animation: slideIn 0.5s ease-out;
            }
            
            @keyframes slideIn {
                from { opacity: 0; transform: translateY(30px); }
                to { opacity: 1; transform: translateY(0); }
            }
            
            .unitechpay-payment-header {
                margin-bottom: 30px;
            }
            
            .unitechpay-payment-header h1 {
                color: #2c3e50;
                margin-bottom: 10px;
                font-size: 24px;
                font-weight: 700;
            }
            
            .unitechpay-order-info {
                color: #7f8c8d;
                font-size: 16px;
                background: #f8f9fa;
                padding: 15px;
                border-radius: 8px;
                margin: 15px 0;
            }
            
            .unitechpay-qr-section {
                margin: 30px 0;
                padding: 25px;
                background: linear-gradient(45deg, #f8f9fa, #e9ecef);
                border-radius: 15px;
                border: 2px dashed #6c757d;
                position: relative;
            }
            
            .unitechpay-qr-section::before {
                content: "📱";
                position: absolute;
                top: -15px;
                right: -15px;
                font-size: 30px;
                background: white;
                padding: 10px;
                border-radius: 50%;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            
            .unitechpay-qr-image {
                max-width: 280px;
                width: 100%;
                height: auto;
                padding: 20px;
                background: white;
                border-radius: 12px;
                box-shadow: 0 4px 20px rgba(0,0,0,0.15);
                margin: 0 auto;
                display: block;
                transition: transform 0.3s ease;
            }
            
            .unitechpay-qr-image:hover {
                transform: scale(1.05);
            }
            
            .unitechpay-instructions {
                text-align: left;
                background: #f8f9fa;
                padding: 25px;
                border-radius: 12px;
                margin: 25px 0;
                border-left: 4px solid #007cba;
            }
            
            .unitechpay-instructions h3 {
                color: #2c3e50;
                margin-top: 0;
                font-size: 18px;
                border-bottom: 2px solid #eee;
                padding-bottom: 10px;
                margin-bottom: 15px;
            }
            
            .unitechpay-instructions ol {
                padding-left: 20px;
            }
            
            .unitechpay-instructions li {
                margin-bottom: 12px;
                color: #34495e;
                line-height: 1.5;
            }
            
            .unitechpay-mobile-notice {
                background: linear-gradient(45deg, #ff6b6b, #ee5a52);
                color: white;
                padding: 20px;
                border-radius: 10px;
                margin: 20px 0;
                font-weight: bold;
                animation: pulse 2s infinite;
            }
            
            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.02); }
                100% { transform: scale(1); }
            }
            
            .unitechpay-status-check {
                margin-top: 30px;
                background: #f8f9fa;
                padding: 25px;
                border-radius: 12px;
            }
            
            .unitechpay-spinner {
                width: 60px;
                height: 60px;
                border: 6px solid #f3f3f3;
                border-top: 6px solid #007cba;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin: 0 auto 20px;
            }
            
            .unitechpay-status-check p {
                color: #495057;
                font-size: 16px;
                margin: 10px 0;
            }
            
            .unitechpay-success {
                color: #28a745;
                font-size: 20px;
                margin-top: 20px;
                background: #d4edda;
                padding: 20px;
                border-radius: 10px;
                border: 1px solid #c3e6cb;
            }
            
            .unitechpay-success i {
                font-size: 60px;
                margin-bottom: 15px;
                display: block;
                animation: bounce 0.5s ease-in-out;
            }
            
            @keyframes bounce {
                0%, 20%, 60%, 100% { transform: translateY(0); }
                40% { transform: translateY(-20px); }
                80% { transform: translateY(-10px); }
            }
            
            .unitechpay-error {
                color: #dc3545;
                font-size: 16px;
                margin-top: 20px;
                background: #f8d7da;
                padding: 20px;
                border-radius: 10px;
                border: 1px solid #f5c6cb;
            }
            
            .unitechpay-refresh-btn {
                background: linear-gradient(45deg, #007cba, #0056b3);
                color: white;
                padding: 12px 25px;
                border: none;
                border-radius: 25px;
                cursor: pointer;
                margin-top: 15px;
                font-size: 16px;
                transition: all 0.3s ease;
            }
            
            .unitechpay-refresh-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,123,186,0.3);
            }
            
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            @media (max-width: 480px) {
                .unitechpay-payment-container {
                    padding: 20px;
                    margin: 10px;
                }
                
                .unitechpay-qr-image {
                    max-width: 250px;
                }
                
                .unitechpay-payment-header h1 {
                    font-size: 20px;
                }
            }
        </style>
    </head>
    <body>
    
    <div class="unitechpay-payment-page">
        <div class="unitechpay-payment-container">
            <img src="<?php echo esc_url(UNITECHPAY_PLUGIN_URL . 'assets/unitechpay-logo.png'); ?>" alt="UnitechPay" style="max-width: 200px; margin: 0 auto 20px; display: block;">
            
            <div class="unitechpay-payment-header">
                <h1><?php esc_html_e('Finalisez votre paiement', 'unitechpay-paiements-mobile-money'); ?></h1>
                <div class="unitechpay-order-info">
                    <?php 
                    printf(
                        esc_html__('Commande #%s - Montant: %s', 'unitechpay-paiements-mobile-money'), 
                        esc_html($order->get_order_number()), 
                        wp_kses_post(wc_price($order->get_total()))
                    ); 
                    ?>
                </div>
            </div>
            
            <?php if ($is_mobile && ($payment_method === 'orange_qr' || $payment_method === 'orange_maxit')): ?>
            <div class="unitechpay-mobile-notice">
                <strong>📱 <?php esc_html_e('IMPORTANT - Utilisateur Mobile', 'unitechpay-paiements-mobile-money'); ?></strong><br>
                <?php esc_html_e('Après avoir payé dans votre app, revenez sur cette page. Elle se mettra à jour automatiquement.', 'unitechpay-paiements-mobile-money'); ?>
            </div>
            <?php endif; ?>
            
            <div class="unitechpay-payment-content">
                <?php if ($payment_method === 'orange_qr' && !empty($qr_code)): ?>
                    <div class="unitechpay-qr-section">
                        <h3><?php esc_html_e('Scannez ce QR Code avec Max It', 'unitechpay-paiements-mobile-money'); ?></h3>
                        <img src="data:image/png;base64,<?php echo esc_attr($qr_code); ?>" alt="<?php esc_attr_e('QR Code Max It', 'unitechpay-paiements-mobile-money'); ?>" class="unitechpay-qr-image">
                    </div>
                    
                    <div class="unitechpay-instructions">
                        <h3><?php esc_html_e('Instructions de paiement', 'unitechpay-paiements-mobile-money'); ?></h3>
                        <ol>
                            <li><strong><?php esc_html_e('Ouvrez votre application Max It', 'unitechpay-paiements-mobile-money'); ?></strong></li>
                            <li><?php esc_html_e('Allez dans la section "Payer avec QR Code"', 'unitechpay-paiements-mobile-money'); ?></li>
                            <li><?php esc_html_e('Scannez le code QR ci-dessus', 'unitechpay-paiements-mobile-money'); ?></li>
                            <li><?php esc_html_e('Confirmez le paiement dans votre application', 'unitechpay-paiements-mobile-money'); ?></li>
                            <li><strong><?php esc_html_e('IMPORTANT : Revenez sur cette page après paiement', 'unitechpay-paiements-mobile-money'); ?></strong></li>
                            <li><?php esc_html_e('Vous serez redirigé automatiquement vers la confirmation', 'unitechpay-paiements-mobile-money'); ?></li>
                        </ol>
                    </div>
                    
                <?php else: ?>
                    <div class="unitechpay-instructions">
                        <h3><?php esc_html_e('Traitement du paiement', 'unitechpay-paiements-mobile-money'); ?></h3>
                        <p><?php esc_html_e('Votre paiement est en cours de traitement. Cette page se mettra à jour automatiquement.', 'unitechpay-paiements-mobile-money'); ?></p>
                    </div>
                <?php endif; ?>
                
                <div class="unitechpay-status-check">
                    <div id="unitechpay-status-checking">
                        <div class="unitechpay-spinner"></div>
                        <p><strong><?php esc_html_e('Vérification du statut du paiement...', 'unitechpay-paiements-mobile-money'); ?></strong></p>
                        <p id="unitechpay-attempts" style="font-size: 14px; color: #6c757d;"></p>
                        <p id="unitechpay-next-check" style="font-size: 12px; color: #adb5bd;"></p>
                    </div>
                    <div class="unitechpay-success" id="unitechpay-payment-success" style="display: none;">
                        <i>✅</i>
                        <p><strong><?php esc_html_e('Paiement réussi !', 'unitechpay-paiements-mobile-money'); ?></strong></p>
                        <p><?php esc_html_e('Redirection vers votre confirmation de commande...', 'unitechpay-paiements-mobile-money'); ?></p>
                    </div>
                    <div class="unitechpay-error" id="unitechpay-payment-error" style="display: none;">
                        <p><strong><?php esc_html_e('Vérification en cours...', 'unitechpay-paiements-mobile-money'); ?></strong></p>
                        <p><?php esc_html_e('Si vous avez payé, la page se mettra à jour sous peu. Sinon, actualisez pour recommencer.', 'unitechpay-paiements-mobile-money'); ?></p>
                        <button onclick="location.reload()" class="unitechpay-refresh-btn">
                            <?php esc_html_e('Actualiser la page', 'unitechpay-paiements-mobile-money'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script type="text/javascript">
    jQuery(function($) {
        var checkAttempts = 0;
        var maxAttempts = 180;
        var checkInterval;
        var isMobile = <?php echo wp_is_mobile() ? 'true' : 'false'; ?>;
        
        function updateAttemptsDisplay() {
            checkAttempts++;
            $('#unitechpay-attempts').text('Tentative ' + checkAttempts + '/' + maxAttempts);
            var nextCheck = new Date(Date.now() + 3000);
            $('#unitechpay-next-check').text('Prochaine vérification: ' + nextCheck.toLocaleTimeString());
        }
        
        function checkPaymentStatus() {
            updateAttemptsDisplay();
            
            $.ajax({
                url: "<?php echo esc_url(admin_url('admin-ajax.php')); ?>",
                type: "POST",
                timeout: 15000,
                data: {
                    action: "unitechpay_check_payment_status",
                    order_id: <?php echo intval($order_id); ?>,
                    nonce: "<?php echo esc_js(wp_create_nonce('unitechpay_nonce')); ?>",
                    is_mobile: isMobile ? 1 : 0
                },
                success: function(response) {
                    if (response.success && response.data) {
                        if (response.data.is_completed || response.data.force_redirect || 
                            response.data.payout_completed || response.data.transaction_id) {
                            
                            clearInterval(checkInterval);
                            $("#unitechpay-status-checking").hide();
                            $("#unitechpay-payment-success").show();
                            
                            setTimeout(function() {
                                window.location.href = response.data.order_url;
                            }, 1500);
                            
                            return;
                        }
                        
                        if (checkAttempts >= maxAttempts) {
                            clearInterval(checkInterval);
                            $("#unitechpay-status-checking").hide();
                            $("#unitechpay-payment-error").show();
                        }
                    } else {
                        if (checkAttempts >= maxAttempts) {
                            clearInterval(checkInterval);
                            $("#unitechpay-status-checking").hide();
                            $("#unitechpay-payment-error").show();
                        }
                    }
                },
                error: function(xhr, status, error) {
                    if (checkAttempts >= maxAttempts) {
                        clearInterval(checkInterval);
                        $("#unitechpay-status-checking").hide();
                        $("#unitechpay-payment-error").show();
                    }
                }
            });
        }
        
        checkInterval = setInterval(checkPaymentStatus, 3000);
        checkPaymentStatus();
        
        var hidden = "hidden";
        var visibilityChange;
        if (typeof document.hidden !== "undefined") {
            hidden = "hidden";
            visibilityChange = "visibilitychange";
        }
        
        function handleVisibilityChange() {
            if (!document[hidden]) {
                setTimeout(checkPaymentStatus, 1000);
            }
        }
        
        if (typeof document.addEventListener !== "undefined" && typeof document[hidden] !== "undefined") {
            document.addEventListener(visibilityChange, handleVisibilityChange, false);
        }
    });
    </script>
    
    <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}

add_action('plugins_loaded', 'unitechpay_init_gateway_class');
function unitechpay_display_bictorys_payment_page() {
    global $wp;
    $order_key = isset($wp->query_vars['order_key']) ? sanitize_text_field($wp->query_vars['order_key']) : '';
    if (empty($order_key)) {
        wp_die(esc_html__('Clé de commande manquante', 'unitechpay-paiements-mobile-money'));
    }

    $order_id = wc_get_order_id_by_order_key($order_key);
    $order = wc_get_order($order_id);
    if (!$order || $order->get_payment_method() !== 'unitechpay_gateway') {
        wp_die(esc_html__('Commande introuvable', 'unitechpay-paiements-mobile-money'));
    }

    wp_cache_delete($order_id, 'posts');
    clean_post_cache($order_id);
    $order = wc_get_order($order_id);

    $current_status = $order->get_status();
    $payout_completed = get_post_meta($order_id, '_unitechpay_payout_completed', true);
    if (in_array($current_status, array('completed', 'processing')) || $payout_completed) {
        wp_redirect($order->get_checkout_order_received_url());
        exit;
    }

    $message   = get_post_meta($order_id, '_unitechpay_bictorys_message', true);
    $method    = get_post_meta($order_id, '_unitechpay_payment_method', true);
    $total     = get_post_meta($order_id, '_unitechpay_total_with_fees', true);
    $total     = $total !== '' ? $total : $order->get_total();

    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo('charset'); ?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title><?php esc_html_e('Paiement Mobile Money', 'unitechpay-paiements-mobile-money'); ?></title>
        <?php wp_head(); ?>
        <style>
            body.unitechpay-bictorys-page { background: linear-gradient(135deg,#667eea 0%,#764ba2 100%); min-height:100vh; margin:0; font-family:Arial,sans-serif; }
            .up-card { max-width:440px; margin:40px auto; background:#fff; border-radius:18px; padding:30px; box-shadow:0 12px 45px rgba(0,0,0,.25); text-align:center; }
            .up-card h1 { font-size:21px; margin:0 0 4px; color:#222; }
            .up-sub { color:#888; font-size:13px; margin:0 0 14px; }
            .up-amount { font-size:28px; font-weight:800; color:#764ba2; margin:6px 0 18px; }
            .up-msg { background:#f5f3ff; border:1px solid #ddd6fe; color:#4c1d95; border-radius:12px; padding:16px; font-size:15px; line-height:1.5; margin:8px 0; font-weight:600; }
            .up-hint { color:#666; font-size:13px; margin-top:14px; }
            .up-status { margin-top:20px; font-weight:600; color:#555; display:flex; align-items:center; justify-content:center; gap:8px; }
            .up-spin { display:inline-block; width:18px; height:18px; border:3px solid #eee; border-top-color:#764ba2; border-radius:50%; animation:upspin 1s linear infinite; }
            @keyframes upspin { to { transform:rotate(360deg); } }
        </style>
    </head>
    <body <?php body_class('unitechpay-bictorys-page'); ?>>
        <div class="up-card">
            <h1><?php esc_html_e('Finalisez votre paiement', 'unitechpay-paiements-mobile-money'); ?></h1>
            <p class="up-sub"><?php echo esc_html(get_bloginfo('name')); ?></p>
            <div class="up-amount"><?php echo esc_html(number_format((float)$total, 0, ',', ' ')); ?> FCFA</div>

            <?php if ($message): ?>
                <div class="up-msg"><?php echo esc_html($message); ?></div>
            <?php else: ?>
                <p class="up-hint"><?php esc_html_e('Une demande de confirmation a été envoyée sur votre téléphone. Veuillez la valider pour finaliser le paiement.', 'unitechpay-paiements-mobile-money'); ?></p>
            <?php endif; ?>

            <div class="up-status"><span class="up-spin"></span><?php esc_html_e('En attente de confirmation du paiement...', 'unitechpay-paiements-mobile-money'); ?></div>
        </div>

        <script>
        (function(){
            var ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
            var nonce   = <?php echo wp_json_encode(wp_create_nonce('unitechpay_nonce')); ?>;
            var orderId = <?php echo (int) $order_id; ?>;
            var received = <?php echo wp_json_encode($order->get_checkout_order_received_url()); ?>;
            function check(){
                var body = new URLSearchParams();
                body.append('action','unitechpay_check_payment_status');
                body.append('nonce', nonce);
                body.append('order_id', orderId);
                fetch(ajaxUrl, { method:'POST', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if (res && res.success && res.data && (res.data.is_completed || res.data.force_redirect)) {
                            window.location.href = res.data.order_url || received;
                        }
                    })
                    .catch(function(){});
            }
            setInterval(check, 4000);
            check();
        })();
        </script>
        <?php wp_footer(); ?>
    </body>
    </html>
    <?php
}

function unitechpay_init_gateway_class() {
    if (!class_exists('WooCommerce') || !class_exists('WC_Payment_Gateway')) {
        return;
    }
    class UnitechPay_Gateway extends WC_Payment_Gateway {
        protected $merchant_wave_number;
        protected $merchant_orange_number;
        protected $merchant_fee_percent;
        protected $commission_rate;
        protected $license_key;
        protected $license_status;
        protected $bictorys_payout_number;
        protected $bictorys_payout_operator;
        protected $bictorys_payout_country;
        
        public function __construct() {
            $this->id = 'unitechpay_gateway';
            $this->icon = esc_url(UNITECHPAY_PLUGIN_URL . 'assets/unitechpay-logo.png');
            $this->has_fields = true;
            $this->method_title = esc_html__('UnitechPay', 'unitechpay-paiements-mobile-money');
            $this->method_description = esc_html__('Paiements Wave et Orange Money avec gestion automatique des commissions. Licence à vie - 5000 FCFA', 'unitechpay-paiements-mobile-money');
            
            $this->supports = array('products');
            
            $this->init_form_fields();
            $this->init_settings();
            
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->merchant_wave_number = $this->get_option('merchant_wave_number');
            $this->merchant_orange_number = $this->get_option('merchant_orange_number');
            $this->merchant_fee_percent = $this->get_option('merchant_fee_percent', '0');
            $this->commission_rate = $this->get_option('commission_rate', '3');
            $this->license_key = $this->get_option('license_key', '');
            $this->license_status = get_option('unitechpay_license_status', 'invalid');
            $this->bictorys_payout_number   = $this->get_option('bictorys_payout_number', '');
            $this->bictorys_payout_operator = $this->get_option('bictorys_payout_operator', 'wave_money');
            $this->bictorys_payout_country  = $this->get_option('bictorys_payout_country', 'CI');
            
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'validate_license'));
            // Après validation : si la licence n'est pas valide, forcer enabled=no.
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'enforce_license_gate_on_save'), 99);
        }

        /**
         * Juste après l'enregistrement des réglages : si la licence n'est pas
         * valide, on désactive la passerelle (enabled=no) tout en conservant
         * les numéros et autres champs déjà saisis.
         */
        public function enforce_license_gate_on_save() {
            if (get_option('unitechpay_license_status', 'invalid') === 'valid') {
                return;
            }
            $settings = get_option('woocommerce_unitechpay_gateway_settings', array());
            if (!is_array($settings)) {
                $settings = array();
            }
            $settings['enabled'] = 'no';
            update_option('woocommerce_unitechpay_gateway_settings', $settings);
            // Refléter sur l'instance courante.
            $this->enabled = 'no';
            if (is_array($this->settings)) {
                $this->settings['enabled'] = 'no';
            }
        }

        /**
         * La passerelle n'est disponible que si la licence est valide.
         * Garantit qu'un plugin « activé » mais sans licence valide n'apparaît
         * jamais au checkout (classique ET Block Checkout via is_active()).
         */
        public function is_available() {
            if (get_option('unitechpay_license_status', 'invalid') !== 'valid') {
                return false;
            }
            return parent::is_available();
        }
        
        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => esc_html__('Activer/Désactiver', 'unitechpay-paiements-mobile-money'),
                    'label' => esc_html__('Activer UnitechPay', 'unitechpay-paiements-mobile-money'),
                    'type' => 'checkbox',
                    'default' => 'no',
                ),
                'license_key' => array(
                    'title' => esc_html__('Clé de licence', 'unitechpay-paiements-mobile-money'),
                    'type' => 'text',
                    'description' => sprintf(
                        esc_html__('Entrez votre clé de licence à vie. %sAcheter une licence (5000 FCFA)%s', 'unitechpay-paiements-mobile-money'),
                        '<a href="https://pay.unitech.sn/licence.php" target="_blank">',
                        '</a>'
                    ),
                    'desc_tip' => false,
                    'default' => '',
                    'custom_attributes' => array(
                        'required' => 'required'
                    )
                ),
                'title' => array(
                    'title' => esc_html__('Titre', 'unitechpay-paiements-mobile-money'),
                    'type' => 'text',
                    'default' => esc_html__('UnitechPay - Wave & Orange Money', 'unitechpay-paiements-mobile-money'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => esc_html__('Description', 'unitechpay-paiements-mobile-money'),
                    'type' => 'textarea',
                    'default' => esc_html__('Paiement sécurisé avec Wave, Orange Money, MTN MoMo, Moov Money, T-Money selon le pays.', 'unitechpay-paiements-mobile-money'),
                ),
                'merchant_wave_number' => array(
                    'title' => esc_html__('Votre numéro Wave', 'unitechpay-paiements-mobile-money'),
                    'type' => 'text',
                    'description' => esc_html__('Le numéro Wave où vous souhaitez recevoir vos paiements (format: 7XXXXXXXX)', 'unitechpay-paiements-mobile-money'),
                    'desc_tip' => true,
                    'placeholder' => '7XXXXXXXX',
                ),
                'merchant_orange_number' => array(
                    'title' => esc_html__('Votre numéro Orange Money', 'unitechpay-paiements-mobile-money'),
                    'type' => 'text',
                    'description' => esc_html__('Le numéro Orange Money où vous souhaitez recevoir vos paiements (format: 7XXXXXXXX)', 'unitechpay-paiements-mobile-money'),
                    'desc_tip' => true,
                    'placeholder' => '7XXXXXXXX',
                ),
                'merchant_fee_percent' => array(
                    'title' => esc_html__('Vos frais supplémentaires (%)', 'unitechpay-paiements-mobile-money'),
                    'type' => 'number',
                    'default' => '0',
                    'description' => esc_html__('Pourcentage de frais que vous souhaitez ajouter au prix (0 = pas de frais). UnitechPay déduira toujours 3% du montant final.', 'unitechpay-paiements-mobile-money'),
                    'desc_tip' => true,
                    'custom_attributes' => array(
                        'min' => '0',
                        'max' => '20',
                        'step' => '0.1'
                    ),
                ),
                'commission_rate' => array(
                    'title' => esc_html__('Commission UnitechPay (%)', 'unitechpay-paiements-mobile-money'),
                    'type' => 'number',
                    'default' => '3',
                    'description' => esc_html__('Commission UnitechPay Sénégal (fixée à 3%)', 'unitechpay-paiements-mobile-money'),
                    'desc_tip' => true,
                    'custom_attributes' => array(
                        'readonly' => 'readonly'
                    ),
                ),

                // ===== SÉNÉGAL =====
                'enable_senegal' => array(
                    'title' => esc_html__('Activer le Sénégal', 'unitechpay-paiements-mobile-money'),
                    'type' => 'checkbox',
                    'label' => esc_html__('Proposer Wave / Orange Money (Sénégal) au checkout', 'unitechpay-paiements-mobile-money'),
                    'default' => 'yes',
                    'description' => esc_html__('Décochez pour désactiver le Sénégal et ne proposer que les autres pays.', 'unitechpay-paiements-mobile-money'),
                    'desc_tip' => true,
                ),

                // ===== AUTRES PAYS (via Bictorys) =====
                'bictorys_section' => array(
                    'title' => esc_html__('— Autres pays —', 'unitechpay-paiements-mobile-money'),
                    'type' => 'title',
                    'description' => esc_html__('Chaque pays peut être activé ou désactivé librement. Le taux exact dépend de l\'opérateur choisi par le client et de votre opérateur de réception.', 'unitechpay-paiements-mobile-money'),
                ),
                'bictorys_countries' => array(
                    'title' => esc_html__('Pays activés', 'unitechpay-paiements-mobile-money'),
                    'type' => 'multiselect',
                    'class' => 'wc-enhanced-select',
                    'default' => array('CI', 'TG', 'BF', 'BJ'),
                    'options' => array(
                        'CI' => esc_html__("Côte d'Ivoire (dès 4,1%)", 'unitechpay-paiements-mobile-money'),
                        'TG' => esc_html__('Togo (dès 4,6%)', 'unitechpay-paiements-mobile-money'),
                        'BF' => esc_html__('Burkina Faso (dès 5,6%)', 'unitechpay-paiements-mobile-money'),
                        'BJ' => esc_html__('Bénin (dès 3,8%)', 'unitechpay-paiements-mobile-money'),
                    ),
                    'description' => esc_html__('Par défaut, tous les pays sont activés. Retirez un pays pour le désactiver au checkout.', 'unitechpay-paiements-mobile-money'),
                    'desc_tip' => true,
                ),
                'bictorys_payout_country' => array(
                    'title' => esc_html__('Pays de réception', 'unitechpay-paiements-mobile-money'),
                    'type' => 'select',
                    'default' => 'CI',
                    'description' => esc_html__('Pays du numéro sur lequel vous recevez les fonds encaissés dans les autres pays. Vous pouvez choisir le Sénégal pour être reversé sur votre numéro Wave / Orange Money sénégalais.', 'unitechpay-paiements-mobile-money'),
                    'desc_tip' => true,
                    'options' => array(
                        'SN' => 'Sénégal',
                        'CI' => "Côte d'Ivoire",
                        'TG' => 'Togo',
                        'BF' => 'Burkina Faso',
                        'BJ' => 'Bénin',
                    ),
                ),
                'bictorys_payout_operator' => array(
                    'title' => esc_html__('Opérateur de réception', 'unitechpay-paiements-mobile-money'),
                    'type' => 'select',
                    'default' => 'wave_money',
                    'description' => esc_html__('Seuls les opérateurs disponibles dans le pays choisi sont proposés.', 'unitechpay-paiements-mobile-money'),
                    'desc_tip' => true,
                    'options' => array(
                        'wave_money'   => 'Wave',
                        'orange_money' => 'Orange Money',
                        'mtn_money'    => 'MTN MoMo',
                        'moov'         => 'Moov Money',
                    ),
                ),
                'bictorys_payout_number' => array(
                    'title' => esc_html__('Numéro de réception (retrait)', 'unitechpay-paiements-mobile-money'),
                    'type' => 'text',
                    'description' => esc_html__('Numéro mobile money où vous recevez l\'argent des autres pays. Format international, ex: +2250700000000', 'unitechpay-paiements-mobile-money'),
                    'desc_tip' => true,
                    'placeholder' => '+2250700000000',
                ),
            );
        }
        
        public function validate_license() {
            $license_key = trim((string) $this->get_option('license_key'));

            // Pas de clé saisie => licence invalide (mais les numéros/réglages
            // viennent quand même d'être enregistrés par process_admin_options).
            if ($license_key === '') {
                update_option('unitechpay_license_status', 'invalid');
                update_option('unitechpay_license_key', '');
                WC_Admin_Settings::add_error(__('❌ Aucune clé de licence saisie. Vos numéros ont été enregistrés, mais le plugin ne sera actif qu\'après validation de la licence.', 'unitechpay-paiements-mobile-money'));
                return false;
            }

            $r    = unitechpay_remote_license_check($license_key);
            $data = $r['data'];

            if (is_array($data) && isset($data['success'])) {
                update_option('unitechpay_license_status', $data['success'] ? 'valid' : 'invalid');
                update_option('unitechpay_license_data', $data);
                update_option('unitechpay_license_key', $license_key);

                if ($data['success']) {
                    WC_Admin_Settings::add_message(__('✅ Licence UnitechPay valide ! Plugin activé.', 'unitechpay-paiements-mobile-money'));
                } else {
                    $srv = isset($data['error']) ? ' (' . esc_html($data['error']) . ')' : '';
                    WC_Admin_Settings::add_error(__('❌ Licence UnitechPay invalide. Veuillez vérifier votre clé.', 'unitechpay-paiements-mobile-money') . $srv);
                }
                return (bool) $data['success'];
            }

            // Réponse illisible / serveur injoignable : on garde l'état courant
            // plutôt que de casser une licence déjà valide. Aucun message affiché.
            update_option('unitechpay_license_key', $license_key);
            $current = get_option('unitechpay_license_status', 'invalid');
            return $current === 'valid';
        }
        
        public function payment_fields() {
                        if ($this->license_status !== 'valid') {
                echo '<div class="woocommerce-error">';
                echo '<p>' . esc_html__('⚠️ Licence UnitechPay invalide ou manquante.', 'unitechpay-paiements-mobile-money') . '</p>';
                echo '<p><a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=unitechpay_gateway') . '" class="button">' . esc_html__('Configurer la licence', 'unitechpay-paiements-mobile-money') . '</a> ';
                echo '<a href="https://pay.unitech.sn/licence.php" target="_blank" class="button">' . esc_html__('Acheter une licence (5000 FCFA)', 'unitechpay-paiements-mobile-money') . '</a></p>';
                echo '</div>';
                return;
            }
            
            if ($description = $this->get_description()) {
                echo wp_kses_post(wpautop(wptexturize($description)));
            }
            
            $wave_configured   = trim((string) $this->merchant_wave_number) !== '';
            $orange_configured = trim((string) $this->merchant_orange_number) !== '';

            $enabled_bictorys   = unitechpay_bictorys_enabled_countries();
            $bictorys_countries = unitechpay_bictorys_countries();
            $senegal_enabled    = unitechpay_senegal_enabled();
            // Le Sénégal s'affiche s'il est activé ET qu'au moins un numéro de
            // réception (Wave ou Orange) est renseigné — c'est le numéro sur
            // lequel le marchand est payé.
            $sn_available       = $senegal_enabled && ($wave_configured || $orange_configured);

            if (!$sn_available && empty($enabled_bictorys)) {
                echo '<div class="woocommerce-error">' . esc_html__('Aucun pays activé. Veuillez contacter l\'administrateur.', 'unitechpay-paiements-mobile-money') . '</div>';
                return;
            }

            // Pays par défaut (première option affichée)
            $default_country = $sn_available ? 'SN' : (count($enabled_bictorys) ? $enabled_bictorys[0] : '');

            echo '<div class="unitechpay-payment-options">';

            // --- Sélecteur de pays ---
            echo '<div class="unitechpay-country-select" style="margin-bottom:12px;">';
            echo '<label for="unitechpay_country" style="font-weight:600;display:block;margin-bottom:4px;">' . esc_html__('Pays :', 'unitechpay-paiements-mobile-money') . '</label>';
            $sn_dialcodes = array('SN' => unitechpay_country_dialcode('SN'));
            foreach ($enabled_bictorys as $cc) {
                $sn_dialcodes[$cc] = unitechpay_country_dialcode($cc);
            }
            echo '<select id="unitechpay_country" name="unitechpay_country" data-dial="' . esc_attr(wp_json_encode($sn_dialcodes)) . '" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;">';
            if ($sn_available) {
                echo '<option value="SN"' . ($default_country === 'SN' ? ' selected' : '') . '>' . esc_html__('Sénégal', 'unitechpay-paiements-mobile-money') . '</option>';
            }
            foreach ($enabled_bictorys as $code) {
                echo '<option value="' . esc_attr($code) . '"' . ($default_country === $code ? ' selected' : '') . '>' . esc_html($bictorys_countries[$code]['name']) . '</option>';
            }
            echo '</select>';
            echo '</div>';

            echo '<h4>' . esc_html__('Choisissez votre méthode de paiement:', 'unitechpay-paiements-mobile-money') . '</h4>';
            echo '<div class="unitechpay-payment-grid">';

            if ($sn_available && $wave_configured) {
                echo '<div class="unitechpay-payment-option wave" data-country="SN">';
                echo '<input type="radio" id="unitechpay_method_wave" name="unitechpay_payment_method" value="wave"' . ($default_country === 'SN' ? ' checked' : '') . '>';
                echo '<label for="unitechpay_method_wave">';
                echo '<div class="unitechpay-option-content">';
                echo '<img src="' . esc_url(UNITECHPAY_PLUGIN_URL . 'assets/wave-logo.png') . '" alt="Wave" class="unitechpay-logo">';
                echo '<div class="unitechpay-option-title">' . esc_html__('Wave', 'unitechpay-paiements-mobile-money') . '</div>';
                echo '<div class="unitechpay-option-desc">' . esc_html__('Paiement mobile', 'unitechpay-paiements-mobile-money') . '</div>';
                echo '</div></label></div>';
            }

            if ($sn_available && $orange_configured) {
                echo '<div class="unitechpay-payment-option orange" data-country="SN">';
                echo '<input type="radio" id="unitechpay_method_orange_qr" name="unitechpay_payment_method" value="orange_qr"' . ($default_country === 'SN' && !$wave_configured ? ' checked' : '') . '>';
                echo '<label for="unitechpay_method_orange_qr">';
                echo '<div class="unitechpay-option-content">';
                echo '<img src="' . esc_url(UNITECHPAY_PLUGIN_URL . 'assets/qr-code-icon.png') . '" alt="QR Code" class="unitechpay-logo">';
                echo '<div class="unitechpay-option-title">' . esc_html__('QR Code', 'unitechpay-paiements-mobile-money') . '</div>';
                echo '</div></label></div>';

                echo '<div class="unitechpay-payment-option orange" data-country="SN">';
                echo '<input type="radio" id="unitechpay_method_orange_maxit" name="unitechpay_payment_method" value="orange_maxit">';
                echo '<label for="unitechpay_method_orange_maxit">';
                echo '<div class="unitechpay-option-content">';
                echo '<img src="' . esc_url(UNITECHPAY_PLUGIN_URL . 'assets/maxit-logo.png') . '" alt="Max It" class="unitechpay-logo">';
                echo '<div class="unitechpay-option-title">' . esc_html__('Max It', 'unitechpay-paiements-mobile-money') . '</div>';
                echo '<div class="unitechpay-option-desc">' . esc_html__('Application mobile', 'unitechpay-paiements-mobile-money') . '</div>';
                echo '</div></label></div>';
            }

            // === Méthodes des autres pays, masquées par défaut ===
            foreach ($enabled_bictorys as $code) {
                foreach (unitechpay_bictorys_methods($code) as $ptype => $info) {
                    $uid = 'unitechpay_bictorys_' . esc_attr($code) . '_' . esc_attr($ptype);
                    echo '<div class="unitechpay-payment-option bictorys" data-country="' . esc_attr($code) . '" style="display:none;">';
                    echo '<input type="radio" id="' . esc_attr($uid) . '" name="unitechpay_payment_method" value="' . esc_attr($ptype) . '">';
                    echo '<label for="' . esc_attr($uid) . '">';
                    echo '<div class="unitechpay-option-content">';
                    if (!empty($info['icon'])) {
                        echo '<img src="' . esc_url(UNITECHPAY_PLUGIN_URL . 'assets/' . $info['icon']) . '" alt="' . esc_attr($info['label']) . '" class="unitechpay-logo">';
                    }
                    echo '<div class="unitechpay-option-title">' . esc_html($info['label']) . '</div>';
                    echo '</div></label></div>';
                }
            }

            echo '</div>'; // .unitechpay-payment-grid

            // Champ numéro Mobile Money (autres pays) : indicatif automatique
            echo '<div class="unitechpay-phone-row" style="display:none;margin-top:12px;">';
            echo '<label for="unitechpay_phone_local" style="font-weight:600;display:block;margin-bottom:4px;">' . esc_html__('Votre numéro Mobile Money :', 'unitechpay-paiements-mobile-money') . '</label>';
            echo '<div style="display:flex;align-items:stretch;">';
            echo '<span id="unitechpay_dialcode" style="display:inline-flex;align-items:center;padding:0 10px;background:#f1f1f1;border:1px solid #ccc;border-right:0;border-radius:6px 0 0 6px;font-weight:600;">+—</span>';
            echo '<input type="tel" id="unitechpay_phone_local" name="unitechpay_phone_local" placeholder="' . esc_attr__('Numéro sans indicatif', 'unitechpay-paiements-mobile-money') . '" style="flex:1;padding:8px;border:1px solid #ccc;border-radius:0 6px 6px 0;" autocomplete="tel-national">';
            echo '</div>';
            echo '<small style="color:#666;">' . esc_html__('L\'indicatif est ajouté automatiquement selon le pays : saisissez uniquement le numéro.', 'unitechpay-paiements-mobile-money') . '</small>';
            echo '</div>';

            // Champ OTP (Orange Money CI / BF) : le client génère l'OTP via #144*82#
            echo '<div class="unitechpay-otp-row" style="display:none;margin-top:12px;">';
            echo '<label for="unitechpay_otp" style="font-weight:600;display:block;margin-bottom:4px;">' . esc_html__('Code OTP Orange Money :', 'unitechpay-paiements-mobile-money') . '</label>';
            echo '<input type="text" id="unitechpay_otp" name="unitechpay_otp" inputmode="numeric" autocomplete="one-time-code" placeholder="' . esc_attr__('Code à 6-8 chiffres', 'unitechpay-paiements-mobile-money') . '" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;">';
            echo '<small style="color:#666;display:block;margin-top:4px;">' . esc_html__('Composez #144*82# sur votre téléphone Orange pour générer votre code OTP, puis saisissez-le ici.', 'unitechpay-paiements-mobile-money') . '</small>';
            echo '</div>';

            // Table des indicatifs (Sénégal + pays activés)
            // (Les indicatifs sont exposés via l'attribut data-dial du <select>.)
            // La logique méthodes/indicatif/OTP est centralisée dans le script
            // du pied de page (unitechpay_payment_scripts) pour rester fiable
            // après chaque rafraîchissement AJAX du checkout (updated_checkout).
            ?>
            <?php

            echo '<div class="unitechpay-info">';
            echo '<strong>' . esc_html__('Frais appliqués:', 'unitechpay-paiements-mobile-money') . '</strong><br>';
            
            if (!empty($this->merchant_fee_percent) && $this->merchant_fee_percent > 0) {
                echo sprintf(esc_html__('• Vos frais: %s%%', 'unitechpay-paiements-mobile-money'), esc_html($this->merchant_fee_percent)) . '<br>';
            } else {
                echo esc_html__('• Pas de frais supplémentaires', 'unitechpay-paiements-mobile-money') . '<br>';
            }
            echo '</div>';
            echo '</div>';
        }
        
        public function process_payment($order_id) {
                        if ($this->license_status !== 'valid') {
                wc_add_notice(esc_html__('❌ Licence UnitechPay invalide. Veuillez contacter l\'administrateur.', 'unitechpay-paiements-mobile-money'), 'error');
                return array('result' => 'failure');
            }
            
            $order = wc_get_order($order_id);
            
            if (!isset($_POST['unitechpay_payment_method'])) {
                wc_add_notice(esc_html__('Veuillez sélectionner une méthode de paiement', 'unitechpay-paiements-mobile-money'), 'error');
                return array('result' => 'failure');
            }
            
            $payment_method = sanitize_text_field($_POST['unitechpay_payment_method']);
            $country = isset($_POST['unitechpay_country']) ? sanitize_text_field(wp_unslash($_POST['unitechpay_country'])) : 'SN';
            $country = strtoupper(preg_replace('/[^A-Za-z]/', '', $country));

            // ===== SÉNÉGAL : comportement existant (INCHANGÉ, commission 3%) =====
            if ($country === 'SN') {
                if (!unitechpay_senegal_enabled()) {
                    wc_add_notice(esc_html__('Le Sénégal n\'est pas disponible.', 'unitechpay-paiements-mobile-money'), 'error');
                    return array('result' => 'failure');
                }

                $allowed_methods = array('wave', 'orange_qr', 'orange_maxit');

                if (!in_array($payment_method, $allowed_methods)) {
                    wc_add_notice(esc_html__('Méthode de paiement invalide', 'unitechpay-paiements-mobile-money'), 'error');
                    return array('result' => 'failure');
                }

                $reference = 'unitechpay_' . $order_id . '_' . uniqid('', true);
                update_post_meta($order_id, '_unitechpay_reference', sanitize_text_field($reference));
                update_post_meta($order_id, '_unitechpay_payment_method', sanitize_text_field($payment_method));

                switch ($payment_method) {
                    case 'wave':
                        return $this->process_wave_payment($order_id, $reference);
                    case 'orange_qr':
                        return $this->process_orange_qr_payment($order_id, $reference);
                    case 'orange_maxit':
                        return $this->process_orange_maxit_payment($order_id, $reference);
                    default:
                        wc_add_notice(esc_html__('Méthode de paiement invalide', 'unitechpay-paiements-mobile-money'), 'error');
                        return array('result' => 'failure');
                }
            }

            // ===== AUTRES PAYS =====
            if (!in_array($country, unitechpay_bictorys_enabled_countries(), true)) {
                wc_add_notice(esc_html__('Ce pays n\'est pas disponible.', 'unitechpay-paiements-mobile-money'), 'error');
                return array('result' => 'failure');
            }

            $country_methods = unitechpay_bictorys_methods($country);
            if (!isset($country_methods[$payment_method])) {
                wc_add_notice(esc_html__('Méthode de paiement invalide pour ce pays.', 'unitechpay-paiements-mobile-money'), 'error');
                return array('result' => 'failure');
            }

            // paymentReference DOIT commencer par l'order_id
            $reference = $order_id . '_' . uniqid('', true);
            update_post_meta($order_id, '_unitechpay_reference', sanitize_text_field($reference));
            update_post_meta($order_id, '_unitechpay_payment_method', sanitize_text_field($payment_method));
            update_post_meta($order_id, '_unitechpay_country', sanitize_text_field($country));

            $phone_local = isset($_POST['unitechpay_phone_local']) ? sanitize_text_field(wp_unslash($_POST['unitechpay_phone_local'])) : '';
            $otp = isset($_POST['unitechpay_otp']) ? preg_replace('/\D/', '', wp_unslash($_POST['unitechpay_otp'])) : '';

            // OTP obligatoire pour Orange Money CI / BF
            if (unitechpay_needs_otp($country, $payment_method) && $otp === '') {
                wc_add_notice(esc_html__('Veuillez saisir votre code OTP Orange Money (composez #144*82#).', 'unitechpay-paiements-mobile-money'), 'error');
                return array('result' => 'failure');
            }

            return $this->process_bictorys_payment($order_id, $reference, $country, $payment_method, $phone_local, $otp);
        }
        
        private function process_wave_payment($order_id, $reference) {
            $order = wc_get_order($order_id);
            
            $base_amount = $order->get_total();
            $merchant_fee_rate = !empty($this->merchant_fee_percent) ? floatval($this->merchant_fee_percent) / 100 : 0;
            $amount_with_fees = ceil($base_amount * (1 + $merchant_fee_rate));
            
            $payment_data = array(
                'action' => 'create_wave_payment',
                'order_id' => intval($order_id),
                'base_amount' => floatval($base_amount),
                'merchant_fee_percent' => floatval($this->merchant_fee_percent),
                'amount' => floatval($amount_with_fees),
                'reference' => sanitize_text_field($reference),
                'success_url' => esc_url_raw($order->get_checkout_order_received_url()),
                'cancel_url' => esc_url_raw($order->get_cancel_order_url()),
                'webhook_url' => esc_url_raw(home_url('unitechpay-wave-webhook')),
                'site_url' => esc_url_raw(get_site_url()),
                'merchant_wave_number' => sanitize_text_field($this->merchant_wave_number),
                'license_key' => $this->license_key
            );
            
            $response = wp_remote_post(UNITECHPAY_API_BASE, array(
                'method' => 'POST',
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($payment_data),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                wc_add_notice(esc_html__('Erreur de connexion au service de paiement', 'unitechpay-paiements-mobile-money'), 'error');
                return array('result' => 'failure');
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);
            
            if (isset($response_data['success']) && $response_data['success'] && isset($response_data['payment_url'])) {
                $order->update_status('pending', esc_html__('En attente du paiement Wave via UnitechPay', 'unitechpay-paiements-mobile-money'));
                
                update_post_meta($order_id, '_unitechpay_base_amount', floatval($base_amount));
                update_post_meta($order_id, '_unitechpay_merchant_fee_percent', floatval($this->merchant_fee_percent));
                update_post_meta($order_id, '_unitechpay_total_with_fees', floatval($amount_with_fees));
                
                return array(
                    'result' => 'success',
                    'redirect' => esc_url_raw($response_data['payment_url'])
                );
            } else {
                $error_message = isset($response_data['message']) ? sanitize_text_field($response_data['message']) : esc_html__('Erreur lors de la création du paiement', 'unitechpay-paiements-mobile-money');
                wc_add_notice($error_message, 'error');
                return array('result' => 'failure');
            }
        }
        
        private function process_orange_qr_payment($order_id, $reference) {
            $order = wc_get_order($order_id);
            $order_key = $order->get_order_key();
            
            $base_amount = $order->get_total();
            $merchant_fee_rate = !empty($this->merchant_fee_percent) ? floatval($this->merchant_fee_percent) / 100 : 0;
            $amount_with_fees = ceil($base_amount * (1 + $merchant_fee_rate));
            
            $payment_data = array(
                'action' => 'create_orange_qr',
                'order_id' => intval($order_id),
                'base_amount' => floatval($base_amount),
                'merchant_fee_percent' => floatval($this->merchant_fee_percent),
                'amount' => floatval($amount_with_fees),
                'reference' => sanitize_text_field($reference),
                'callback_success' => esc_url_raw(home_url('unitechpay-payment/' . $order_key)),
                'callback_cancel' => esc_url_raw($order->get_cancel_order_url()),
                'webhook_url' => esc_url_raw(home_url('unitechpay-orange-webhook')),
                'site_url' => esc_url_raw(get_site_url()),
                'merchant_orange_number' => sanitize_text_field($this->merchant_orange_number),
                'license_key' => $this->license_key
            );
            
            $response = wp_remote_post(UNITECHPAY_API_BASE, array(
                'method' => 'POST',
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($payment_data),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                wc_add_notice(esc_html__('Erreur de connexion au service de paiement', 'unitechpay-paiements-mobile-money'), 'error');
                return array('result' => 'failure');
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);
            
            if (isset($response_data['success']) && $response_data['success'] && isset($response_data['qr_code'])) {
                update_post_meta($order_id, '_unitechpay_qr_code', sanitize_text_field($response_data['qr_code']));
                update_post_meta($order_id, '_unitechpay_base_amount', floatval($base_amount));
                update_post_meta($order_id, '_unitechpay_merchant_fee_percent', floatval($this->merchant_fee_percent));
                update_post_meta($order_id, '_unitechpay_total_with_fees', floatval($amount_with_fees));
                
                $order->update_status('pending', esc_html__('En attente du paiement Orange Money QR via UnitechPay', 'unitechpay-paiements-mobile-money'));
                
                return array(
                    'result' => 'success',
                    'redirect' => esc_url_raw(home_url('unitechpay-payment/' . $order_key))
                );
            } else {
                $error_message = isset($response_data['message']) ? sanitize_text_field($response_data['message']) : esc_html__('Erreur lors de la génération du QR Code', 'unitechpay-paiements-mobile-money');
                wc_add_notice($error_message, 'error');
                return array('result' => 'failure');
            }
        }
        
        private function process_orange_maxit_payment($order_id, $reference) {
            $order = wc_get_order($order_id);
            $order_key = $order->get_order_key();
            
            $base_amount = $order->get_total();
            $merchant_fee_rate = !empty($this->merchant_fee_percent) ? floatval($this->merchant_fee_percent) / 100 : 0;
            $amount_with_fees = ceil($base_amount * (1 + $merchant_fee_rate));
            
            $payment_data = array(
                'action' => 'create_orange_maxit',
                'order_id' => intval($order_id),
                'base_amount' => floatval($base_amount),
                'merchant_fee_percent' => floatval($this->merchant_fee_percent),
                'amount' => floatval($amount_with_fees),
                'reference' => sanitize_text_field($reference),
                'callback_success' => esc_url_raw(home_url('unitechpay-payment/' . $order_key)),
                'callback_cancel' => esc_url_raw($order->get_cancel_order_url()),
                'webhook_url' => esc_url_raw(home_url('unitechpay-orange-webhook')),
                'site_url' => esc_url_raw(get_site_url()),
                'merchant_orange_number' => sanitize_text_field($this->merchant_orange_number),
                'license_key' => $this->license_key
            );
            
            $response = wp_remote_post(UNITECHPAY_API_BASE, array(
                'method' => 'POST',
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($payment_data),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                wc_add_notice(esc_html__('Erreur de connexion au service de paiement', 'unitechpay-paiements-mobile-money'), 'error');
                return array('result' => 'failure');
            }
            
            $response_body = wp_remote_retrieve_body($response);
            $response_data = json_decode($response_body, true);
            
            if (isset($response_data['success']) && $response_data['success'] && isset($response_data['deep_link'])) {
                update_post_meta($order_id, '_unitechpay_base_amount', floatval($base_amount));
                update_post_meta($order_id, '_unitechpay_merchant_fee_percent', floatval($this->merchant_fee_percent));
                update_post_meta($order_id, '_unitechpay_total_with_fees', floatval($amount_with_fees));
                
                $order->update_status('pending', esc_html__('En attente du paiement Orange Money Max It via UnitechPay', 'unitechpay-paiements-mobile-money'));
                
                return array(
                    'result' => 'success',
                    'redirect' => esc_url_raw($response_data['deep_link'])
                );
            } else {
                $error_message = isset($response_data['message']) ? sanitize_text_field($response_data['message']) : esc_html__('Erreur lors de la création du lien Max It', 'unitechpay-paiements-mobile-money');
                wc_add_notice($error_message, 'error');
                return array('result' => 'failure');
            }
        }
        private function process_bictorys_payment($order_id, $reference, $country, $method, $phone_local = '', $otp = '') {
            $order = wc_get_order($order_id);

            $type = sanitize_text_field($method); 

            $base_amount       = $order->get_total();
            $merchant_fee_rate = !empty($this->merchant_fee_percent) ? floatval($this->merchant_fee_percent) / 100 : 0;
            $amount_with_fees  = ceil($base_amount * (1 + $merchant_fee_rate));

            $countries = unitechpay_bictorys_countries();
            $currency  = isset($countries[$country]['currency']) ? $countries[$country]['currency'] : 'XOF';

            // Numéro Mobile Money du client : indicatif ajouté automatiquement
            $dial   = unitechpay_country_dialcode($country);
            $digits = preg_replace('/\D/', '', (string) $phone_local);
            $customer_phone = ($digits !== '') ? ('+' . $dial . $digits) : $order->get_billing_phone();
            update_post_meta($order_id, '_unitechpay_customer_phone', sanitize_text_field($customer_phone));

            $payment_data = array(
                'action'          => 'create_bictorys_payment',
                'order_id'        => intval($order_id),
                'country'         => sanitize_text_field($country),
                'method'          => sanitize_text_field($type), // payment_type Bictorys
                'amount'          => floatval($amount_with_fees),
                'currency'        => sanitize_text_field($currency),
                'reference'       => sanitize_text_field($reference),
                'phone'           => sanitize_text_field($customer_phone),
                'otp'             => sanitize_text_field($otp),
                'success_url'     => esc_url_raw($order->get_checkout_order_received_url()),
                'cancel_url'      => esc_url_raw($order->get_cancel_order_url()),
                'webhook_url'     => esc_url_raw(home_url('unitechpay-bictorys-webhook')),
                'site_url'        => esc_url_raw(get_site_url()),
                'license_key'     => $this->license_key,
                'payout_number'   => sanitize_text_field($this->bictorys_payout_number),
                'payout_operator' => sanitize_text_field($this->bictorys_payout_operator),
                'payout_country'  => sanitize_text_field($this->bictorys_payout_country),
                'payout_name'     => sanitize_text_field(get_bloginfo('name')),
                'customer'        => array(
                    'name'  => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                    'phone' => $customer_phone,
                    'email' => $order->get_billing_email(),
                    'city'  => $order->get_billing_city(),
                ),
            );

            $response = wp_remote_post(UNITECHPAY_BICTORYS_API, array(
                'method'  => 'POST',
                'headers' => array('Content-Type' => 'application/json'),
                'body'    => wp_json_encode($payment_data),
                'timeout' => 45,
            ));

            if (is_wp_error($response)) {
                wc_add_notice(esc_html__('Erreur de connexion au service de paiement', 'unitechpay-paiements-mobile-money'), 'error');
                return array('result' => 'failure');
            }

            $response_data = json_decode(wp_remote_retrieve_body($response), true);

            if (isset($response_data['success']) && $response_data['success']) {
                update_post_meta($order_id, '_unitechpay_base_amount', floatval($base_amount));
                update_post_meta($order_id, '_unitechpay_total_with_fees', floatval($amount_with_fees));
                update_post_meta($order_id, '_unitechpay_bictorys_txid', isset($response_data['transactionId']) ? sanitize_text_field($response_data['transactionId']) : '');

                $order->update_status('pending', sprintf(
                    esc_html__('En attente du paiement %1$s (%2$s) via UnitechPay', 'unitechpay-paiements-mobile-money'),
                    sanitize_text_field($method), sanitize_text_field($country)
                ));

                $link        = !empty($response_data['link']) ? $response_data['link'] : '';
                $redirect_url= !empty($response_data['redirect_url']) ? $response_data['redirect_url'] : '';
                $payment_url = !empty($response_data['payment_url']) ? $response_data['payment_url'] : '';
                $message     = !empty($response_data['message']) ? $response_data['message'] : '';

                // Mémoriser le message éventuel pour la page d'attente
                if ($message !== '') { update_post_meta($order_id, '_unitechpay_bictorys_message', sanitize_text_field($message)); }

                $wait_page = esc_url_raw(home_url('unitechpay-bictorys-pay/' . $order->get_order_key()));

                // 1) S'il y a un lien de redirection propre à la méthode -> on redirige
                if ($link !== '') {
                    return array('result' => 'success', 'redirect' => esc_url_raw($link));
                }
                // 2) Sinon, s'il y a un message (push / USSD) -> page "En attente de confirmation"
                if ($message !== '') {
                    return array('result' => 'success', 'redirect' => $wait_page);
                }
                // 3) Sinon, repli sur redirectUrl / payment_url si présent
                $url = $redirect_url ?: $payment_url;
                if ($url !== '') {
                    return array('result' => 'success', 'redirect' => esc_url_raw($url));
                }
                // 4) Rien d'exploitable -> page d'attente générique
                return array('result' => 'success', 'redirect' => $wait_page);
            }

            $error_message = isset($response_data['message']) ? sanitize_text_field($response_data['message']) : esc_html__('Erreur lors de la création du paiement', 'unitechpay-paiements-mobile-money');
            wc_add_notice($error_message, 'error');
            return array('result' => 'failure');
        }

        public function admin_options() {
            $license_status = get_option('unitechpay_license_status', 'invalid');
            $license_data = get_option('unitechpay_license_data', []);
            ?>
            <h2><?php esc_html_e('UnitechPay - Configuration', 'unitechpay-paiements-mobile-money'); ?></h2>
            
            <div style="background: <?php echo $license_status === 'valid' ? '#d4edda' : '#fff3cd'; ?>; border: 1px solid <?php echo $license_status === 'valid' ? '#c3e6cb' : '#ffeeba'; ?>; padding: 20px; margin: 20px 0; border-radius: 5px;">
                <h3 style="margin-top: 0; color: <?php echo $license_status === 'valid' ? '#155724' : '#856404'; ?>;">
                    <?php if ($license_status === 'valid'): ?>
                        <i class="fas fa-check-circle" style="color: #28a745;"></i> 
                        <?php esc_html_e('✅ Licence valide - Plugin activé', 'unitechpay-paiements-mobile-money'); ?>
                    <?php else: ?>
                        <i class="fas fa-exclamation-triangle" style="color: #856404;"></i> 
                        <?php esc_html_e('⚠️ Licence requise - 5000 FCFA (frais d\'adhésion unique)', 'unitechpay-paiements-mobile-money'); ?>
                    <?php endif; ?>
                </h3>
                
                <?php if ($license_status === 'valid'): ?>
                    <p><strong>Statut :</strong> <span style="color: #28a745;">Licence active</span></p>
                    <?php if (!empty($license_data['licence']['sites_autorises'])): ?>
                        <p><strong>Sites autorisés :</strong> <?php echo implode(', ', $license_data['licence']['sites_autorises']); ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p><strong>Statut :</strong> <span style="color: #dc3545;">Licence invalide ou manquante</span></p>
                    <p>
                        <a href="https://pay.unitech.sn/licence.php" target="_blank" class="button button-primary">
                            <?php esc_html_e('Acheter une licence à vie (5000 FCFA)', 'unitechpay-paiements-mobile-money'); ?>
                        </a>
                    </p>
                <?php endif; ?>
            </div>
            
            <div style="background: #f9f9f9; border: 1px solid #ccc; padding: 15px; margin: 15px 0; border-radius: 5px;">
                <h3><?php esc_html_e('Fonctionnement', 'unitechpay-paiements-mobile-money'); ?></h3>
                <ol>
                    <li><?php esc_html_e('Vos clients paient via Wave ou Orange Money', 'unitechpay-paiements-mobile-money'); ?></li>
                    <li><?php printf(esc_html__('UnitechPay déduit automatiquement %s%% de commission', 'unitechpay-paiements-mobile-money'), esc_html($this->commission_rate)); ?></li>
                    <li><?php esc_html_e('Le montant restant est envoyé sur votre numéro correspondant', 'unitechpay-paiements-mobile-money'); ?></li>
                </ol>
                <p><strong><?php esc_html_e('Note :', 'unitechpay-paiements-mobile-money'); ?></strong> <?php esc_html_e('Ce plugin nécessite une licence à vie de 5000 FCFA (frais d\'adhésion unique).', 'unitechpay-paiements-mobile-money'); ?></p>
            </div>
            
            <table class="form-table">
                <?php $this->generate_settings_html(); ?>
            </table>

            <script>
            /* Opérateurs de réception disponibles selon le pays choisi */
            jQuery(function($){
                var payoutOps = {
                    'SN': ['wave_money','orange_money'],
                    'CI': ['wave_money','orange_money','mtn_money','moov'],
                    'TG': ['moov'],
                    'BF': ['orange_money','wave_money','moov'],
                    'BJ': ['moov','mtn_money']
                };
                var $c = $('#woocommerce_unitechpay_gateway_bictorys_payout_country');
                var $o = $('#woocommerce_unitechpay_gateway_bictorys_payout_operator');
                if (!$c.length || !$o.length) { return; }
                var all = [];
                $o.find('option').each(function(){ all.push({ v: $(this).val(), t: $(this).text() }); });
                function refresh(){
                    var allowed = payoutOps[$c.val()] || all.map(function(o){ return o.v; });
                    var cur = $o.val();
                    $o.empty();
                    all.forEach(function(o){
                        if (allowed.indexOf(o.v) !== -1) { $o.append($('<option>').val(o.v).text(o.t)); }
                    });
                    $o.val(allowed.indexOf(cur) !== -1 ? cur : allowed[0]);
                }
                $c.on('change', refresh);
                refresh();

                /* Préremplissage de l'indicatif du numéro de réception (retrait)
                   selon le pays de réception choisi. Non destructif : on ne
                   touche pas à un numéro déjà saisi au chargement. */
                var dialByCountry = { 'SN':'221','CI':'225','TG':'228','BF':'226','BJ':'229' };
                var $num = $('#woocommerce_unitechpay_gateway_bictorys_payout_number');
                function prefillDial(initial){
                    if (!$num.length) { return; }
                    var d = dialByCountry[$c.val()] || '';
                    var v = ($num.val() || '').trim();
                    if (v === '') { $num.val('+' + d); return; }
                    if (initial) { return; } // ne pas écraser un numéro existant au chargement
                    // Changement de pays : remplacer l'ancien indicatif par le nouveau
                    for (var k in dialByCountry) {
                        var p = '+' + dialByCountry[k];
                        if (v === p) { $num.val('+' + d); return; }
                        if (v.indexOf(p) === 0) { $num.val('+' + d + v.slice(p.length)); return; }
                    }
                }
                $c.on('change', function(){ prefillDial(false); });
                prefillDial(true);
            });
            </script>
            <?php
        }
    }
}

add_action('wp_footer', 'unitechpay_payment_scripts');
function unitechpay_payment_scripts() {
    if (!is_checkout()) return;

    wp_add_inline_script('unitechpay-script', '
        jQuery(function($) {
            var OTP_COUNTRIES = ["CI","BF"];

            function q(sel, root){ return (root||document).querySelector(sel); }
            function qa(sel, root){ return Array.prototype.slice.call((root||document).querySelectorAll(sel)); }

            function selectedMethod(){
                var r = q(".unitechpay-payment-grid input[type=radio]:checked");
                return r ? r.value : "";
            }

            function refreshOtp(){
                var sel = q("#unitechpay_country");
                var otpRow = q(".unitechpay-otp-row");
                if (!sel || !otpRow) return;
                var need = (selectedMethod() === "orange_money") && OTP_COUNTRIES.indexOf(sel.value) !== -1;
                otpRow.style.display = need ? "" : "none";
            }

            function refreshMethods(){
                var sel = q("#unitechpay_country");
                if (!sel) return;
                var dial = {};
                try { dial = JSON.parse(sel.getAttribute("data-dial") || "{}"); } catch(e){ dial = {}; }
                var c = sel.value;
                var phoneRow = q(".unitechpay-phone-row");
                var dcSpan   = q("#unitechpay_dialcode");
                var opts = qa(".unitechpay-payment-grid .unitechpay-payment-option");
                var firstVisible = null;
                opts.forEach(function(o){
                    var show = (o.getAttribute("data-country") === c);
                    o.style.display = show ? "" : "none";
                    var r = o.querySelector("input[type=radio]");
                    if (r){ if(!show){ r.checked = false; } else if(!firstVisible){ firstVisible = r; } }
                });
                if (firstVisible && !q(".unitechpay-payment-grid input[type=radio]:checked")) {
                    firstVisible.checked = true;
                }
                if (phoneRow){
                    if (c === "SN"){ phoneRow.style.display = "none"; }
                    else { phoneRow.style.display = ""; if (dcSpan){ dcSpan.textContent = "+" + (dial[c] || ""); } }
                }
                refreshOtp();
            }

            function showHideUnitechPayFields() {
                var $gw = $(".payment_method_unitechpay_gateway");
                var $radio = $("input[name=\'payment_method\'][value=\'unitechpay_gateway\']");
                if ($radio.is(":checked")) {
                    $gw.find(".unitechpay-payment-options").show();
                    refreshMethods();
                } else {
                    $gw.find(".unitechpay-payment-options").hide();
                }
            }

            // Délégation : survit aux rafraîchissements AJAX du checkout.
            $(document.body).on("change", "#unitechpay_country", refreshMethods);
            $(document.body).on("change", ".unitechpay-payment-grid input[type=radio]", refreshOtp);
            $(document.body).on("change", "input[name=\'payment_method\']", function(){
                setTimeout(showHideUnitechPayFields, 50);
            });
            $(document.body).on("updated_checkout", function(){
                setTimeout(showHideUnitechPayFields, 60);
            });
            $(document).ready(function(){
                setTimeout(showHideUnitechPayFields, 80);
            });
        });
    ');
}

register_activation_hook(__FILE__, 'unitechpay_create_files');
function unitechpay_create_files() {
    $upload_dir = wp_upload_dir();
    $unitechpay_dir = $upload_dir['basedir'] . '/unitechpay';
    
    if (!file_exists($unitechpay_dir)) {
        wp_mkdir_p($unitechpay_dir);
    }
    
    $assets_dir = UNITECHPAY_PLUGIN_PATH . 'assets';
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
    }
    
    $css_dir = $assets_dir . '/css';
    if (!file_exists($css_dir)) {
        wp_mkdir_p($css_dir);
    }
    
    $js_dir = $assets_dir . '/js';
    if (!file_exists($js_dir)) {
        wp_mkdir_p($js_dir);
    }
    
    $css_file = $css_dir . '/unitechpay.css';
    if (!file_exists($css_file)) {
        $css_content = '.payment_method_unitechpay_gateway .unitechpay-payment-options {
    background: #ffffff !important;
    border: 1px solid #e0e0e0 !important;
    border-radius: 8px !important;
    padding: 20px !important;
    margin: 15px 0 !important;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1) !important;
}

.unitechpay-payment-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin: 15px 0;
}

.unitechpay-payment-option {
    border: 2px solid #e0e0e0;
    border-radius: 8px;
    overflow: hidden;
    transition: all 0.3s ease;
}

.unitechpay-payment-option:hover {
    border-color: #007cba;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

.unitechpay-payment-option input[type="radio"] {
    display: none;
}

.unitechpay-payment-option input[type="radio"]:checked + label {
    background-color: #f0f8ff;
    border-color: #007cba;
}

.unitechpay-payment-option label {
    display: block;
    padding: 20px;
    cursor: pointer;
    margin: 0;
    background: #fff;
    transition: all 0.3s ease;
}

.unitechpay-option-content {
    text-align: center;
}

.unitechpay-logo {
    max-width: 60px;
    max-height: 60px;
    margin-bottom: 10px;
}

.unitechpay-option-title {
    font-weight: bold;
    font-size: 16px;
    margin-bottom: 5px;
}

.unitechpay-option-desc {
    color: #666;
    font-size: 14px;
}

.unitechpay-info {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 15px;
    margin-top: 15px;
    font-size: 14px;
}

.license-notice {
    background: #fff3cd;
    border: 1px solid #ffeeba;
    color: #856404;
    padding: 15px;
    border-radius: 5px;
    margin: 15px 0;
}

.license-notice a {
    color: #533f03;
    font-weight: bold;
}

@media (max-width: 768px) {
    .unitechpay-payment-grid {
        grid-template-columns: 1fr;
    }
}';
        file_put_contents($css_file, $css_content);
    }
    
    $js_file = $js_dir . '/unitechpay.js';
    if (!file_exists($js_file)) {
        $js_content = 'jQuery(document).ready(function($) {
    console.log("UnitechPay loaded");
    
    $(".unitechpay-payment-option input[type=radio]").on("change", function() {
        $(".unitechpay-payment-option").removeClass("selected");
        $(this).closest(".unitechpay-payment-option").addClass("selected");
    });
});';
        file_put_contents($js_file, $js_content);
    }
    
    flush_rewrite_rules();
}

add_action('woocommerce_admin_order_data_after_billing_address', 'unitechpay_display_payment_info');
function unitechpay_display_payment_info($order) {
    if ($order->get_payment_method() === 'unitechpay_gateway') {
        $payment_method = get_post_meta($order->get_id(), '_unitechpay_payment_method', true);
        $base_amount = get_post_meta($order->get_id(), '_unitechpay_base_amount', true);
        $merchant_fee_percent = get_post_meta($order->get_id(), '_unitechpay_merchant_fee_percent', true);
        $merchant_received_amount = get_post_meta($order->get_id(), '_unitechpay_payout_amount', true);
        
        echo '<h3>' . esc_html__('Informations UnitechPay', 'unitechpay-paiements-mobile-money') . '</h3>';
        
        if ($payment_method) {
            $methods = array(
                'wave' => 'Wave',
                'orange_qr' => 'QR Code',
                'orange_maxit' => 'Max It'
            );
            echo '<p><strong>' . esc_html__('Méthode:', 'unitechpay-paiements-mobile-money') . '</strong> ' . esc_html($methods[$payment_method] ?? $payment_method) . '</p>';
        }
        
        if ($base_amount) {
            echo '<p><strong>' . esc_html__('Montant de base:', 'unitechpay-paiements-mobile-money') . '</strong> ' . wp_kses_post(wc_price($base_amount)) . '</p>';
        }
        
        if ($merchant_fee_percent && $merchant_fee_percent > 0) {
            echo '<p><strong>' . esc_html__('Vos frais appliqués:', 'unitechpay-paiements-mobile-money') . '</strong> ' . esc_html($merchant_fee_percent) . '%</p>';
        }
        
        if ($merchant_received_amount) {
            echo '<p><strong>' . esc_html__('Montant que vous avez reçu:', 'unitechpay-paiements-mobile-money') . '</strong> <span style="color: #2ea500; font-weight: bold;">' . wp_kses_post(wc_price($merchant_received_amount)) . '</span></p>';
        }
    }
}

add_action('woocommerce_checkout_process', 'unitechpay_validate_fields');
function unitechpay_validate_fields() {
    if (isset($_POST['payment_method']) && $_POST['payment_method'] === 'unitechpay_gateway') {
        if (!isset($_POST['woocommerce-process-checkout-nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woocommerce-process-checkout-nonce'])), 'woocommerce-process_checkout')) {
            wc_add_notice(esc_html__('Échec de la vérification de sécurité', 'unitechpay-paiements-mobile-money'), 'error');
            return;
        }
        
        if (!isset($_POST['unitechpay_payment_method'])) {
            wc_add_notice(esc_html__('Veuillez sélectionner une méthode de paiement UnitechPay', 'unitechpay-paiements-mobile-money'), 'error');
            return;
        }
        
        $payment_method = sanitize_text_field($_POST['unitechpay_payment_method']);
        $country = isset($_POST['unitechpay_country']) ? strtoupper(preg_replace('/[^A-Za-z]/', '', sanitize_text_field(wp_unslash($_POST['unitechpay_country'])))) : 'SN';

        if ($country === 'SN') {
            $allowed_methods = array('wave', 'orange_qr', 'orange_maxit');
        } else {
            $allowed_methods = array_keys(unitechpay_bictorys_methods($country));
        }

        if (empty($payment_method) || !in_array($payment_method, $allowed_methods, true)) {
            wc_add_notice(esc_html__('Veuillez sélectionner une méthode de paiement UnitechPay valide', 'unitechpay-paiements-mobile-money'), 'error');
        }
    }
}

register_activation_hook(__FILE__, 'unitechpay_flush_rewrite_rules_on_activation');
function unitechpay_flush_rewrite_rules_on_activation() {
    unitechpay_register_endpoints();
    flush_rewrite_rules();
    delete_transient('unitechpay_logos_synced');
    if (function_exists('unitechpay_sync_method_logos')) {
        unitechpay_sync_method_logos();
    }
}

register_deactivation_hook(__FILE__, 'unitechpay_flush_rewrite_rules_on_deactivation');
function unitechpay_flush_rewrite_rules_on_deactivation() {
    flush_rewrite_rules();
}

add_action('init', 'unitechpay_register_order_status');
function unitechpay_register_order_status() {
    register_post_status('wc-pending-payout', array(
        'label' => esc_html__('En attente de transfert', 'unitechpay-paiements-mobile-money'),
        'public' => true,
        'exclude_from_search' => false,
        'show_in_admin_all_list' => true,
        'show_in_admin_status_list' => true,
        'label_count' => _n_noop('En attente de transfert <span class="count">(%s)</span>', 'En attente de transfert <span class="count">(%s)</span>', 'unitechpay-paiements-mobile-money')
    ));
}

add_filter('wc_order_statuses', 'unitechpay_add_order_status');
function unitechpay_add_order_status($order_statuses) {
    $order_statuses['wc-pending-payout'] = esc_html__('En attente de transfert', 'unitechpay-paiements-mobile-money');
    return $order_statuses;
}

add_filter('manage_edit-shop_order_columns', 'unitechpay_add_payout_column');
function unitechpay_add_payout_column($columns) {
    $columns['unitechpay_payout'] = esc_html__('Transfert UnitechPay', 'unitechpay-paiements-mobile-money');
    return $columns;
}

add_action('manage_shop_order_posts_custom_column', 'unitechpay_show_payout_column');
function unitechpay_show_payout_column($column) {
    global $post;
    
    if ($column === 'unitechpay_payout') {
        $order = wc_get_order($post->ID);
        
        if ($order->get_payment_method() === 'unitechpay_gateway') {
            $payout_completed = get_post_meta($order->get_id(), '_unitechpay_payout_completed', true);
            $payout_amount = get_post_meta($order->get_id(), '_unitechpay_payout_amount', true);
            
            if ($payout_completed) {
                echo '<span class="dashicons dashicons-yes" style="color: #46b450;"></span> ';
                echo esc_html__('Transféré: ', 'unitechpay-paiements-mobile-money') . wp_kses_post(wc_price($payout_amount));
            } else {
                echo '<span class="dashicons dashicons-clock" style="color: #ffb900;"></span> ';
                echo esc_html__('En attente', 'unitechpay-paiements-mobile-money');
            }
        } else {
            echo '—';
        }
    }
}

add_filter('bulk_actions-edit-shop_order', 'unitechpay_add_bulk_actions');
function unitechpay_add_bulk_actions($actions) {
    $actions['unitechpay_process_payouts'] = esc_html__('Traiter les transferts UnitechPay', 'unitechpay-paiements-mobile-money');
    return $actions;
}

add_filter('handle_bulk_actions-edit-shop_order', 'unitechpay_handle_bulk_actions', 10, 3);
function unitechpay_handle_bulk_actions($redirect_to, $action, $post_ids) {
    if ($action === 'unitechpay_process_payouts') {
        $processed = 0;
        
        foreach ($post_ids as $post_id) {
            $order = wc_get_order($post_id);
            
            if ($order->get_payment_method() === 'unitechpay_gateway' && !get_post_meta($post_id, '_unitechpay_payout_completed', true)) {
                $processed++;
            }
        }
        
        $redirect_to = add_query_arg(array(
            'unitechpay_payouts_processed' => $processed,
            'bulk_action' => 'unitechpay_process_payouts'
        ), $redirect_to);
    }
    
    return $redirect_to;
}

add_action('admin_notices', 'unitechpay_bulk_action_notices');
function unitechpay_bulk_action_notices() {
    if (!empty($_REQUEST['bulk_action']) && $_REQUEST['bulk_action'] === 'unitechpay_process_payouts') {
        $count = isset($_REQUEST['unitechpay_payouts_processed']) ? intval($_REQUEST['unitechpay_payouts_processed']) : 0;
        
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p>' . sprintf(esc_html__('%d transferts UnitechPay traités.', 'unitechpay-paiements-mobile-money'), $count) . '</p>';
        echo '</div>';
    }
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'unitechpay_add_settings_link');
function unitechpay_add_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=unitechpay_gateway') . '">' . esc_html__('Paramètres', 'unitechpay-paiements-mobile-money') . '</a>';
    array_unshift($links, $settings_link);
    
        if (get_option('unitechpay_license_status', 'invalid') !== 'valid') {
        $purchase_link = '<a href="https://pay.unitech.sn/licence.php" target="_blank" style="color: #46b450; font-weight: bold;">' . esc_html__('Acheter licence (5000 FCFA)', 'unitechpay-paiements-mobile-money') . '</a>';
        array_push($links, $purchase_link);
    }
    
    return $links;
}

add_filter('plugin_row_meta', 'unitechpay_add_plugin_meta', 10, 2);
function unitechpay_add_plugin_meta($links, $file) {
    if (plugin_basename(__FILE__) === $file) {
        $links[] = '<a href="https://pay.unitech.sn/documentation" target="_blank">' . esc_html__('Documentation', 'unitechpay-paiements-mobile-money') . '</a>';
        $links[] = '<a href="https://unitech.sn/contact" target="_blank">' . esc_html__('Support', 'unitechpay-paiements-mobile-money') . '</a>';
        $links[] = '<a href="https://pay.unitech.sn/licence.php" target="_blank" style="color: #46b450; font-weight: bold;">' . esc_html__('Licence à vie 5000 FCFA', 'unitechpay-paiements-mobile-money') . '</a>';
    }
    return $links;
}

add_filter('plugin_row_meta', 'unitechpay_add_license_status', 10, 4);
function unitechpay_add_license_status($plugin_meta, $plugin_file, $plugin_data, $status) {
    if ($plugin_file === plugin_basename(__FILE__)) {
        $license_status = get_option('unitechpay_license_status', 'invalid');
        $status_text = $license_status === 'valid' ? '✅ Licence active' : '⚠️ Licence requise';
        $status_color = $license_status === 'valid' ? '#46b450' : '#dc3232';
        
        $plugin_meta[] = '<span style="color: ' . $status_color . '; font-weight: bold;">' . $status_text . '</span>';
    }
    return $plugin_meta;
}
?>