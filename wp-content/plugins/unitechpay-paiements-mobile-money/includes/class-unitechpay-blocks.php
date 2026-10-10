<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class UnitechPay_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'unitechpay_gateway';

    private $gateway = null;

    public function initialize() {
        $this->settings = get_option( 'woocommerce_unitechpay_gateway_settings', array() );

        $gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : array();
        if ( isset( $gateways[ $this->name ] ) ) {
            $this->gateway = $gateways[ $this->name ];
        }
    }

    public function is_active() {
        if ( get_option( 'unitechpay_license_status', 'invalid' ) !== 'valid' ) {
            return false;
        }

        if ( $this->gateway ) {
            return $this->gateway->is_available();
        }

        return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
    }

    public function get_payment_method_script_handles() {
        wp_register_style(
            'unitechpay-blocks-css',
            UNITECHPAY_PLUGIN_URL . 'assets/css/unitechpay.css',
            array(),
            UNITECHPAY_VERSION
        );
        wp_enqueue_style( 'unitechpay-blocks-css' );

        $handle = 'unitechpay-blocks';
        wp_register_script(
            $handle,
            UNITECHPAY_PLUGIN_URL . 'assets/js/blocks.js',
            array( 'wc-blocks-registry', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
            UNITECHPAY_VERSION,
            true
        );

        if ( function_exists( 'wp_set_script_translations' ) ) {
            wp_set_script_translations( $handle, 'unitechpay-paiements-mobile-money' );
        }

        return array( $handle );
    }

    public function get_payment_method_data() {
        $countries_config = $this->get_countries_config();
        $default_country  = ! empty( $countries_config ) ? $countries_config[0]['code'] : 'SN';
        
        return array(
            'title'          => $this->get_setting( 'title' ) ? $this->get_setting( 'title' ) : __( 'UnitechPay - Wave & Orange Money', 'unitechpay-paiements-mobile-money' ),
            'description'    => $this->get_setting( 'description' ) ? $this->get_setting( 'description' ) : '',
            'icon'           => UNITECHPAY_PLUGIN_URL . 'assets/unitechpay-logo.png',
            'supports'       => $this->get_supported_features(),
            'options'        => array(),
            'countries'      => $countries_config,
            'defaultCountry' => $default_country,
            'dialcodes'      => $this->get_dialcodes(),
            'feePercent'     => (float) $this->get_setting( 'merchant_fee_percent' ),
            'licenseValid'   => ( get_option( 'unitechpay_license_status', 'invalid' ) === 'valid' ),
        );
    }

    /**
     * Config complète des pays pour le Block Checkout.
     */
    private function get_countries_config() {
        $config = array();

        // --- Sénégal ---
        $senegal_enabled   = function_exists( 'unitechpay_senegal_enabled' ) ? unitechpay_senegal_enabled() : true;
        $wave_configured   = isset( $this->settings['merchant_wave_number'] ) && trim( (string) $this->settings['merchant_wave_number'] ) !== '';
        $orange_configured = isset( $this->settings['merchant_orange_number'] ) && trim( (string) $this->settings['merchant_orange_number'] ) !== '';

        if ( $senegal_enabled && ( $wave_configured || $orange_configured ) ) {
            $sn_options = array();

            if ( $wave_configured ) {
                $sn_options[] = array(
                    'value'      => 'wave',
                    'title'      => __( 'Wave', 'unitechpay-paiements-mobile-money' ),
                    'desc'       => __( 'Paiement mobile', 'unitechpay-paiements-mobile-money' ),
                    'icon'       => UNITECHPAY_PLUGIN_URL . 'assets/wave-logo.png',
                    'extraClass' => 'wave',
                );
            }

            if ( $orange_configured ) {
                $sn_options[] = array(
                    'value'      => 'orange_qr',
                    'title'      => __( 'QR Code', 'unitechpay-paiements-mobile-money' ),
                    'desc'       => '',
                    'icon'       => UNITECHPAY_PLUGIN_URL . 'assets/qr-code-icon.png',
                    'extraClass' => 'orange',
                );
                $sn_options[] = array(
                    'value'      => 'orange_maxit',
                    'title'      => __( 'Max It', 'unitechpay-paiements-mobile-money' ),
                    'desc'       => __( 'Application mobile', 'unitechpay-paiements-mobile-money' ),
                    'icon'       => UNITECHPAY_PLUGIN_URL . 'assets/maxit-logo.png',
                    'extraClass' => 'orange',
                );
            }

            if ( ! empty( $sn_options ) ) {
                $config[] = array(
                    'code'    => 'SN',
                    'name'    => __( 'Sénégal', 'unitechpay-paiements-mobile-money' ),
                    'options' => $sn_options,
                );
            }
        }

        // --- Autres pays (Bictorys) ---
        if ( function_exists( 'unitechpay_bictorys_enabled_countries' ) ) {
            $enabled_countries = unitechpay_bictorys_enabled_countries();
            foreach ( $enabled_countries as $code ) {
                $options = array();
                foreach ( unitechpay_bictorys_methods( $code ) as $ptype => $info ) {
                    $icon = ! empty( $info['icon'] ) ? UNITECHPAY_PLUGIN_URL . 'assets/' . $info['icon'] : '';
                    // Vérifier que le fichier existe, sinon utiliser un fallback
                    if ( $icon && ! file_exists( str_replace( UNITECHPAY_PLUGIN_URL, UNITECHPAY_PLUGIN_PATH, $icon ) ) ) {
                        $icon = UNITECHPAY_PLUGIN_URL . 'assets/wave-logo.png';
                    }
                    $options[] = array(
                        'value'      => $ptype,
                        'title'      => $info['label'],
                        'desc'       => '',
                        'icon'       => $icon,
                        'extraClass' => 'bictorys',
                    );
                }
                if ( ! empty( $options ) ) {
                    $country_name = unitechpay_bictorys_countries();
                    $config[] = array(
                        'code'    => $code,
                        'name'    => isset( $country_name[ $code ]['name'] ) ? $country_name[ $code ]['name'] : $code,
                        'options' => $options,
                    );
                }
            }
        }

        return $config;
    }

    /** Indicatifs téléphoniques. */
    private function get_dialcodes() {
        $dial = array( 'SN' => function_exists( 'unitechpay_country_dialcode' ) ? unitechpay_country_dialcode( 'SN' ) : '221' );
        if ( function_exists( 'unitechpay_bictorys_enabled_countries' ) ) {
            foreach ( unitechpay_bictorys_enabled_countries() as $code ) {
                $dial[ $code ] = unitechpay_country_dialcode( $code );
            }
        }
        return $dial;
    }

    public function get_supported_features() {
        if ( $this->gateway && is_array( $this->gateway->supports ) ) {
            return array_values( $this->gateway->supports );
        }

        return array( 'products' );
    }
}