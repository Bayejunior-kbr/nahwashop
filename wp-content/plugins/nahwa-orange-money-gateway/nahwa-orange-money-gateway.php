<?php
/**
 * Plugin Name: Nahwa - Passerelle de paiement Orange Money
 * Description: Intégration directe de l'API Orange Money pour WooCommerce (sans intermédiaire).
 * Version: 1.0.0
 * Author: Nahwa
 * Text Domain: nahwa-orange-money-gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'plugins_loaded', 'nahwa_om_init_gateway' );

function nahwa_om_init_gateway() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		return; // WooCommerce n'est pas actif
	}

	require_once plugin_dir_path( __FILE__ ) . 'includes/class-nahwa-om-api.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-nahwa-om-gateway.php';
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-nahwa-om-webhook.php';

	Nahwa_OM_Webhook::init();
}

add_filter( 'woocommerce_payment_gateways', 'nahwa_om_add_gateway' );

function nahwa_om_add_gateway( $gateways ) {
	$gateways[] = 'Nahwa_OM_Gateway';
	return $gateways;
}
