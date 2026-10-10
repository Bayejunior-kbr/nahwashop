<?php
/**
 * Plugin Name: Nahwa - Passerelle de paiement Wave
 * Description: Intégration directe de l'API Wave Business pour WooCommerce (sans intermédiaire).
 * Version: 0.1.0
 * Author: Nahwa
 * Text Domain: nahwa-wave-gateway
 */

// Sécurité : empêche l'accès direct au fichier
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// On vérifie que WooCommerce est bien actif avant de faire quoi que ce soit
add_action( 'plugins_loaded', 'nahwa_wave_init_gateway' );

function nahwa_wave_init_gateway() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
		// WooCommerce n'est pas actif, on arrête tout proprement
		return;
	}

	// On charge le fichier contenant la classe du moyen de paiement
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-nahwa-wave-gateway.php';

	// On charge et on active l'écoute du webhook de confirmation Wave
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-nahwa-wave-webhook.php';
	Nahwa_Wave_Webhook::init();

}

// On déclare notre gateway auprès de WooCommerce, pour qu'il apparaisse
// dans WooCommerce > Réglages > Paiements
add_filter( 'woocommerce_payment_gateways', 'nahwa_wave_add_gateway' );

function nahwa_wave_add_gateway( $gateways ) {
	$gateways[] = 'Nahwa_Wave_Gateway';
	return $gateways;
}
