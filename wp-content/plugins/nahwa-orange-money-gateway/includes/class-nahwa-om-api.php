<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orange Money fonctionne différemment de Wave :
 * - Authentification OAuth2 (Client ID + Client Secret → jeton temporaire)
 * - Le webhook ("notif_url") peut arriver sans le montant, donc on ne lui
 *   fait jamais confiance seul : on rappelle toujours l'API pour vérifier
 *   le vrai statut de la transaction avant de valider une commande.
 *
 * NOTE IMPORTANTE : les noms exacts de champs/chemins ci-dessous sont basés
 * sur la documentation et des intégrations existantes trouvées publiquement.
 * À confirmer/ajuster avec la vraie documentation une fois l'accès obtenu
 * (portail API Orange Sénégal) — surtout le segment de pays dans l'URL.
 */
class Nahwa_OM_Api {

	private $client_id;
	private $client_secret;
	private $merchant_key;
	private $test_mode;

	public function __construct( $client_id, $client_secret, $merchant_key, $test_mode = true ) {
		$this->client_id     = $client_id;
		$this->client_secret = $client_secret;
		$this->merchant_key  = $merchant_key;
		$this->test_mode     = $test_mode;
	}

	/**
	 * Construit le chemin de base selon le mode (test ou réel).
	 * "dev" est l'environnement sandbox utilisé par plusieurs intégrations
	 * Orange Money Web Payment avant le passage en production.
	 */
	private function get_base_path() {
		return $this->test_mode
			? 'https://api.orange.com/orange-money-webpay/dev/v1'
			: 'https://api.orange.com/orange-money-webpay/sn/v1';
	}

	/**
	 * Étape 1 : on échange Client ID + Client Secret contre un jeton OAuth2.
	 * On met ce jeton en cache quelques minutes pour éviter de le redemander
	 * à chaque commande (il reste valable un certain temps chez Orange).
	 */
	public function get_access_token() {

		$cache_key = 'nahwa_om_token_' . md5( $this->client_id );
		$cached    = get_transient( $cache_key );

		if ( $cached ) {
			return $cached;
		}

		$response = wp_remote_post( 'https://api.orange.com/oauth/v3/token', array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->client_id . ':' . $this->client_secret ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => array( 'grant_type' => 'client_credentials' ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'om_token_error', $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['access_token'] ) ) {
			$message = isset( $body['error_description'] ) ? $body['error_description'] : 'Jeton OAuth2 introuvable dans la réponse.';
			return new WP_Error( 'om_token_error', $message );
		}

		// On garde le jeton un peu moins longtemps que sa vraie durée de vie, par sécurité
		$expires_in = isset( $body['expires_in'] ) ? intval( $body['expires_in'] ) - 60 : 3000;
		set_transient( $cache_key, $body['access_token'], max( $expires_in, 60 ) );

		return $body['access_token'];
	}

	/**
	 * Étape 2 : on crée la session de paiement chez Orange.
	 * Orange renvoie, entre autres, le "notif_token" qu'on doit stocker
	 * NOUS-MÊMES avant la redirection — c'est ce token qui servira à
	 * vérifier le webhook plus tard.
	 */
	public function create_webpayment( $order_id, $amount, $return_url, $cancel_url, $notif_url ) {

		$token = $this->get_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$payload = array(
			'merchant_key' => $this->merchant_key,
			'currency'     => 'XOF',
			'order_id'     => (string) $order_id, // Orange limite ce champ à 24 caractères
			'amount'       => (string) intval( $amount ),
			'return_url'   => $return_url,
			'cancel_url'   => $cancel_url,
			'notif_url'    => $notif_url,
			'lang'         => 'fr',
			'reference'    => 'Nahwa',
		);

		$response = wp_remote_post( $this->get_base_path() . '/webpayment', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'om_webpayment_error', $response->get_error_message() );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $body['payment_url'] ) || empty( $body['notif_token'] ) ) {
			$message = isset( $body['message'] ) ? $body['message'] : 'Réponse Orange incomplète (payment_url ou notif_token manquant).';
			return new WP_Error( 'om_webpayment_error', $message );
		}

		return $body; // contient payment_url, pay_token, notif_token
	}

	/**
	 * Étape 3 (vérification active) : on ne fait jamais confiance au webhook
	 * seul. On rappelle cet endpoint pour confirmer le vrai statut avant de
	 * valider la commande.
	 */
	public function get_transaction_status( $order_id, $amount, $pay_token ) {

		$token = $this->get_access_token();

		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = add_query_arg( array(
			'order_id'  => $order_id,
			'amount'    => intval( $amount ),
			'pay_token' => $pay_token,
		), $this->get_base_path() . '/transactionstatus' );

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'om_status_error', $response->get_error_message() );
		}

		return json_decode( wp_remote_retrieve_body( $response ), true );
	}
}
