<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Nahwa_OM_Gateway extends WC_Payment_Gateway {

	public function __construct() {

		$this->id                 = 'nahwa_orange_money';
		$this->icon               = plugins_url( '../assets/images/orange-logo.png', __FILE__ );
		$this->has_fields         = false;
		$this->method_title       = 'Orange Money (Nahwa)';
		$this->method_description = 'Paiement direct via l\'API Orange Money, sans intermédiaire.';

		$this->init_form_fields();
		$this->init_settings();

		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title'   => 'Activer/Désactiver',
				'type'    => 'checkbox',
				'label'   => 'Activer le paiement par Orange Money',
				'default' => 'no',
			),
			'title' => array(
				'title'       => 'Titre',
				'type'        => 'text',
				'description' => 'Nom affiché au client pendant la commande.',
				'default'     => 'Orange Money',
				'desc_tip'    => true,
			),
			'description' => array(
				'title'       => 'Description',
				'type'        => 'textarea',
				'description' => 'Texte affiché sous le nom, pour expliquer le paiement au client.',
				'default'     => 'Payez avec votre compte Orange Money.',
			),
			'test_mode' => array(
				'title'       => 'Mode test',
				'type'        => 'checkbox',
				'label'       => 'Activer le mode test (sandbox Orange)',
				'description' => 'À cocher tant que ton compte réel n\'est pas encore validé.',
				'default'     => 'yes',
			),
			'client_id' => array(
				'title'       => 'Client ID',
				'type'        => 'text',
				'description' => 'Fourni par Orange lors de la création de ton application sur le portail API.',
			),
			'client_secret' => array(
				'title'       => 'Client Secret',
				'type'        => 'password',
				'description' => 'Fourni par Orange avec le Client ID. Ne le partage jamais.',
			),
			'merchant_key' => array(
				'title'       => 'Merchant Key',
				'type'        => 'password',
				'description' => 'Clé marchande fournie par Orange, spécifique à ton compte Orange Money Business.',
			),
		);
	}

	public function process_payment( $order_id ) {

		$order = wc_get_order( $order_id );

		$client_id     = $this->get_option( 'client_id' );
		$client_secret = $this->get_option( 'client_secret' );
		$merchant_key  = $this->get_option( 'merchant_key' );

		if ( empty( $client_id ) || empty( $client_secret ) || empty( $merchant_key ) ) {
			wc_add_notice( 'Le paiement Orange Money n\'est pas encore configuré. Contactez la boutique.', 'error' );
			return array( 'result' => 'failure' );
		}

		$test_mode = ( 'yes' === $this->get_option( 'test_mode' ) );
		$api       = new Nahwa_OM_Api( $client_id, $client_secret, $merchant_key, $test_mode );

		// L'URL que Orange appellera automatiquement pour confirmer le paiement.
		// On y ajoute l'order_id en paramètre : ce n'est pas une donnée secrète,
		// la vraie sécurité vient de la vérification du notif_token + du rappel API.
		$notif_url = add_query_arg( 'order_id', $order_id, rest_url( 'nahwa-om/v1/webhook' ) );

		$result = $api->create_webpayment(
			$order_id,
			$order->get_total(),
			$this->get_return_url( $order ),
			wc_get_checkout_url(),
			$notif_url
		);

		if ( is_wp_error( $result ) ) {
			wc_add_notice( 'Erreur Orange Money : ' . $result->get_error_message(), 'error' );
			$order->add_order_note( 'Erreur Orange Money (initiation) : ' . $result->get_error_message() );
			return array( 'result' => 'failure' );
		}

		// On stocke le notif_token et le pay_token MAINTENANT, avant la redirection.
		// C'est ce notif_token qui permettra de vérifier l'authenticité du webhook.
		$order->update_meta_data( '_nahwa_om_notif_token', $result['notif_token'] );
		$order->update_meta_data( '_nahwa_om_pay_token', $result['pay_token'] );
		$order->update_status( 'on-hold', 'En attente du paiement Orange Money.' );
		$order->save();

		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $result['payment_url'],
		);
	}
}
