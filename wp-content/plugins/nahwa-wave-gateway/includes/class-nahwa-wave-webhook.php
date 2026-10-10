<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cette classe écoute l'URL : https://tonsite.com/wp-json/nahwa-wave/v1/webhook
 * C'est Wave qui appelle cette adresse automatiquement pour confirmer un paiement.
 * On ne valide JAMAIS une commande simplement parce que le client est revenu
 * sur le site — uniquement quand Wave confirme ici, avec la bonne signature.
 */
class Nahwa_Wave_Webhook {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route( 'nahwa-wave/v1', '/webhook', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true', // Wave n'est pas connecté en tant qu'utilisateur WP
		) );
	}

	public static function handle( WP_REST_Request $request ) {

		$gateway = new Nahwa_Wave_Gateway();
		$secret  = $gateway->get_option( 'webhook_secret' );
		$raw_body = $request->get_body();

		// 1. Vérification de la signature — étape de sécurité indispensable.
		// Sans ça, n'importe qui pourrait appeler cette URL et valider
		// de fausses commandes gratuitement.
		$signature_header = $request->get_header( 'wave-signature' );

		if ( empty( $secret ) || empty( $signature_header ) ) {
			return new WP_REST_Response( array( 'error' => 'Signature manquante ou secret non configuré.' ), 401 );
		}

		$expected_signature = hash_hmac( 'sha256', $raw_body, $secret );

		if ( ! hash_equals( $expected_signature, $signature_header ) ) {
			return new WP_REST_Response( array( 'error' => 'Signature invalide.' ), 401 );
		}

		// 2. La signature est valide, on peut faire confiance au contenu.
		$data = json_decode( $raw_body, true );

		if ( empty( $data['client_reference'] ) || empty( $data['type'] ) ) {
			return new WP_REST_Response( array( 'error' => 'Données incomplètes.' ), 400 );
		}

		$order_id = intval( $data['client_reference'] );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_REST_Response( array( 'error' => 'Commande introuvable.' ), 404 );
		}

		// 3. On évite de traiter deux fois la même confirmation
		// (Wave peut renvoyer le même webhook plusieurs fois par sécurité).
		if ( $order->is_paid() ) {
			return new WP_REST_Response( array( 'status' => 'déjà traité' ), 200 );
		}

		// 4. On valide la commande uniquement si Wave confirme un paiement réussi.
		if ( 'checkout.session.completed' === $data['type'] ) {
			$order->payment_complete();
			$order->add_order_note( 'Paiement confirmé par Wave (webhook vérifié).' );
		}

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}
}
