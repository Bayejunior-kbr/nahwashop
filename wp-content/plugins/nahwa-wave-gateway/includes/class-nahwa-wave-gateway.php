<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nahwa_Wave_Gateway extends WC_Payment_Gateway {

	public function __construct() {

		$this->id                 = 'nahwa_wave';
		$this->icon               = plugins_url( '../assets/images/wave-logo.png', __FILE__ );
		$this->has_fields         = false;
		$this->method_title       = 'Wave (Nahwa)';
		$this->method_description = 'Paiement direct via Wave Business, sans intermédiaire.';

		// Charge les réglages (titre, description, clé API...) déjà enregistrés
		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		// Sauvegarde des réglages depuis l'admin WooCommerce
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	// Les champs de réglage qu'on verra dans WooCommerce > Paiements > Wave
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => 'Activer/Désactiver',
				'type'    => 'checkbox',
				'label'   => 'Activer le paiement par Wave',
				'default' => 'no',
			),
			'title' => array(
				'title'       => 'Titre',
				'type'        => 'text',
				'description' => 'Nom affiché au client pendant la commande.',
				'default'     => 'Wave',
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => 'Description',
				'type'        => 'textarea',
				'description' => 'Texte affiché sous le nom, pour expliquer le paiement au client.',
				'default'     => 'Payez rapidement et en toute sécurité avec Wave.',
			),
			'test_mode' => array(
				'title'       => 'Mode test',
				'type'        => 'checkbox',
				'label'       => 'Activer le mode test (sandbox Wave)',
				'description' => 'À cocher tant que ton compte Wave Business réel n\'est pas encore validé.',
				'default'     => 'yes',
			),
			'api_key_test' => array(
				'title'       => 'Clé API Wave (test)',
				'type'        => 'text',
				'description' => 'Clé API du compte sandbox Wave, pour les essais avant le vrai lancement.',
			),
			'api_key_live' => array(
				'title'       => 'Clé API Wave (réelle)',
				'type'        => 'password',
				'description' => 'Ta vraie clé API Wave Business, une fois ton compte validé. Ne la partage jamais.',
			),
			'webhook_secret' => array(
				'title'       => 'Secret webhook',
				'type'        => 'password',
				'description' => 'Donné par Wave lors de la création du webhook dans ton Wave Business Portal. Sert à vérifier que la confirmation vient bien de Wave.',
			),
		);
	}

	// Renvoie la bonne clé selon le mode (test ou réel)
	public function get_api_key() {
		if ( 'yes' === $this->get_option( 'test_mode' ) ) {
			return $this->get_option( 'api_key_test' );
		}
		return $this->get_option( 'api_key_live' );
	}

	/**
	 * Appelée par WooCommerce quand le client clique "Commander".
	 * On crée une session de paiement chez Wave, puis on redirige le client.
	 */
	public function process_payment( $order_id ) {

		$order   = wc_get_order( $order_id );
		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			wc_add_notice( 'Le paiement Wave n\'est pas encore configuré (clé API manquante). Contactez la boutique.', 'error' );
			return array( 'result' => 'failure' );
		}

		// URL que Wave appellera une fois le paiement terminé (succès ou annulation)
		$success_url = $this->get_return_url( $order );
		$error_url   = wc_get_checkout_url();

		$payload = array(
			'amount'            => (string) intval( $order->get_total() ), // XOF n'a pas de centimes
			'currency'          => 'XOF',
			'success_url'       => $success_url,
			'error_url'         => $error_url,
			'client_reference'  => (string) $order_id, // pour retrouver la commande au moment du webhook
		);

		$response = wp_remote_post( 'https://api.wave.com/v1/checkout/sessions', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 30,
		) );

		// Si la requête échoue techniquement (pas de réponse de Wave)
		if ( is_wp_error( $response ) ) {
			wc_add_notice( 'Impossible de contacter Wave pour le moment. Réessayez.', 'error' );
			$order->add_order_note( 'Erreur Wave (connexion) : ' . $response->get_error_message() );
			return array( 'result' => 'failure' );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Si Wave renvoie une erreur (clé invalide, montant incorrect, etc.)
		if ( empty( $body['wave_launch_url'] ) ) {
			$message = isset( $body['message'] ) ? $body['message'] : 'Erreur inconnue de Wave.';
			wc_add_notice( 'Erreur Wave : ' . $message, 'error' );
			$order->add_order_note( 'Erreur Wave (API) : ' . $message );
			return array( 'result' => 'failure' );
		}

		// On garde une trace de la session Wave sur la commande
		$order->update_meta_data( '_nahwa_wave_session_id', $body['id'] );
		$order->update_status( 'on-hold', 'En attente du paiement Wave.' );
		$order->save();

		// On vide le panier et on redirige le client vers la page de paiement Wave
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $body['wave_launch_url'],
		);
	}
}
