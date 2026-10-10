<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contrairement à Wave, Orange Money n'envoie pas de signature cryptographique
 * classique. La sécurité repose ici sur deux vérifications obligatoires :
 *
 * 1. Le "notif_token" reçu doit correspondre EXACTEMENT à celui qu'on a
 *    nous-mêmes stocké sur la commande avant la redirection du client.
 * 2. Le webhook peut arriver sans le montant ("webhook orphelin") — on ne
 *    lui fait donc jamais confiance seul : on rappelle toujours l'API
 *    Orange (transactionstatus) pour confirmer le vrai statut et le vrai
 *    montant avant de valider quoi que ce soit.
 */
class Nahwa_OM_Webhook {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	public static function register_route() {
		register_rest_route( 'nahwa-om/v1', '/webhook', array(
			'methods'             => array( 'POST', 'GET' ), // Orange peut appeler en GET ou POST selon les cas
			'callback'            => array( __CLASS__, 'handle' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function handle( WP_REST_Request $request ) {

		// L'order_id vient du paramètre qu'on a nous-mêmes ajouté à l'URL
		// du webhook au moment de l'initiation du paiement.
		$order_id = intval( $request->get_param( 'order_id' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			return new WP_REST_Response( array( 'error' => 'Commande introuvable.' ), 404 );
		}

		// Évite de retraiter une commande déjà validée (Orange peut renvoyer
		// le même webhook plusieurs fois).
		if ( $order->is_paid() ) {
			return new WP_REST_Response( array( 'status' => 'déjà traité' ), 200 );
		}

		// Le contenu peut arriver en JSON (POST) ou en paramètres (GET) selon le cas.
		$payload = json_decode( $request->get_body(), true );
		if ( empty( $payload ) ) {
			$payload = $request->get_params();
		}

		$received_token = isset( $payload['notif_token'] ) ? $payload['notif_token'] : '';
		$stored_token    = $order->get_meta( '_nahwa_om_notif_token' );

		// Vérification n°1 : le token doit correspondre exactement à celui
		// qu'on a stocké nous-mêmes avant la redirection. Sans ça, n'importe
		// qui pourrait appeler cette URL et tenter de valider une fausse commande.
		if ( empty( $received_token ) || empty( $stored_token ) || ! hash_equals( $stored_token, $received_token ) ) {
			$order->add_order_note( 'Webhook Orange Money reçu avec un notif_token invalide ou manquant — ignoré.' );
			return new WP_REST_Response( array( 'error' => 'notif_token invalide.' ), 401 );
		}

		// Vérification n°2 (obligatoire, pas optionnelle) : on rappelle l'API
		// Orange pour confirmer le vrai statut, car le webhook seul peut
		// arriver sans le montant et n'est pas suffisant pour valider un paiement.
		$gateway = new Nahwa_OM_Gateway();
		$api     = new Nahwa_OM_Api(
			$gateway->get_option( 'client_id' ),
			$gateway->get_option( 'client_secret' ),
			$gateway->get_option( 'merchant_key' ),
			( 'yes' === $gateway->get_option( 'test_mode' ) )
		);

		$pay_token = $order->get_meta( '_nahwa_om_pay_token' );
		$status    = $api->get_transaction_status( $order_id, $order->get_total(), $pay_token );

		if ( is_wp_error( $status ) ) {
			$order->add_order_note( 'Impossible de vérifier le statut Orange Money : ' . $status->get_error_message() );
			return new WP_REST_Response( array( 'error' => 'Vérification impossible, réessai prévu par Orange.' ), 500 );
		}

		$real_status = isset( $status['status'] ) ? $status['status'] : '';

		if ( 'SUCCESS' === $real_status ) {
			$order->payment_complete();
			$order->add_order_note( 'Paiement Orange Money confirmé (notif_token vérifié + statut API confirmé).' );
		} else {
			$order->add_order_note( 'Webhook Orange Money reçu, mais statut réel non confirmé (' . $real_status . ').' );
		}

		return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
	}
}
