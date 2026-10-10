( function () {
    'use strict';

    if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wp || ! window.wp.element ) {
        return;
    }

    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var getSetting = ( window.wc.wcSettings && window.wc.wcSettings.getSetting ) || function ( name, fallback ) { return fallback; };
    var el = window.wp.element.createElement;
    var useState = window.wp.element.useState;
    var useEffect = window.wp.element.useEffect;
    var decodeEntities = ( window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ) || function ( s ) { return s; };
    var __ = ( window.wp.i18n && window.wp.i18n.__ ) || function ( s ) { return s; };
    var sprintf = ( window.wp.i18n && window.wp.i18n.sprintf ) || function ( f ) { return f; };

    var settings = getSetting( 'unitechpay_gateway_data', {} );
    var defaultLabel = __( 'UnitechPay - Wave & Orange Money', 'unitechpay-paiements-mobile-money' );
    var label = decodeEntities( settings.title || '' ) || defaultLabel;
    var description = decodeEntities( settings.description || '' );
    var feePercent = parseFloat( settings.feePercent || 0 );

    // Config pays : [{ code, name, options:[{value,title,desc,icon,extraClass}] }]
    var countries = Array.isArray( settings.countries ) ? settings.countries : [];
    if ( ! countries.length ) {
        countries = [ {
            code: 'SN',
            name: 'Sénégal',
            options: Array.isArray( settings.options ) ? settings.options : [],
        } ];
    }
    var defaultCountry = settings.defaultCountry || countries[ 0 ].code;
    var dialcodes = settings.dialcodes || {};

    function countryByCode( code ) {
        for ( var i = 0; i < countries.length; i++ ) {
            if ( countries[ i ].code === code ) { return countries[ i ]; }
        }
        return countries[ 0 ];
    }

    var OTP_COUNTRIES = [ 'CI', 'BF' ];

    var Content = function ( props ) {
        var eventRegistration = props.eventRegistration || {};
        var emitResponse = props.emitResponse || {};
        var onPaymentSetup = eventRegistration.onPaymentSetup;

        var countryState = useState( defaultCountry );
        var selectedCountry = countryState[ 0 ];
        var setSelectedCountry = countryState[ 1 ];

        var currentOptions = ( countryByCode( selectedCountry ).options ) || [];

        var methodState = useState( currentOptions.length ? currentOptions[ 0 ].value : '' );
        var selectedMethod = methodState[ 0 ];
        var setSelectedMethod = methodState[ 1 ];

        var phoneState = useState( '' );
        var phoneLocal = phoneState[ 0 ];
        var setPhoneLocal = phoneState[ 1 ];

        var otpState = useState( '' );
        var otpValue = otpState[ 0 ];
        var setOtpValue = otpState[ 1 ];

        var needsOtp = ( selectedMethod === 'orange_money' ) && ( selectedCountry === 'CI' || selectedCountry === 'BF' );

        // Quand le pays change, sélectionner la première méthode disponible
        useEffect( function () {
            var opts = ( countryByCode( selectedCountry ).options ) || [];
            var exists = false;
            for ( var i = 0; i < opts.length; i++ ) {
                if ( opts[ i ].value === selectedMethod ) { exists = true; break; }
            }
            if ( ! exists ) {
                setSelectedMethod( opts.length ? opts[ 0 ].value : '' );
            }
            // Mettre à jour le numéro de téléphone
            setPhoneLocal( '' );
            setOtpValue( '' );
        }, [ selectedCountry ] );

        useEffect( function () {
            if ( typeof onPaymentSetup !== 'function' ) {
                return;
            }

            var unsubscribe = onPaymentSetup( function () {
                var responseTypes = emitResponse.responseTypes || {};

                if ( ! selectedMethod ) {
                    return {
                        type: responseTypes.ERROR || 'error',
                        message: __( 'Veuillez sélectionner une méthode de paiement', 'unitechpay-paiements-mobile-money' ),
                    };
                }

                var composedPhone = '';
                if ( selectedCountry !== 'SN' ) {
                    var digits = ( phoneLocal || '' ).replace( /\D/g, '' );
                    if ( ! digits ) {
                        return {
                            type: responseTypes.ERROR || 'error',
                            message: __( 'Veuillez saisir votre numéro Mobile Money', 'unitechpay-paiements-mobile-money' ),
                        };
                    }
                    composedPhone = '+' + ( dialcodes[ selectedCountry ] || '' ) + digits;
                }

                var otpClean = ( otpValue || '' ).replace( /\D/g, '' );
                if ( needsOtp && ! otpClean ) {
                    return {
                        type: responseTypes.ERROR || 'error',
                        message: __( 'Veuillez saisir votre code OTP Orange Money (composez #144*82#).', 'unitechpay-paiements-mobile-money' ),
                    };
                }

                return {
                    type: responseTypes.SUCCESS || 'success',
                    meta: {
                        paymentMethodData: {
                            unitechpay_payment_method: selectedMethod,
                            unitechpay_country: selectedCountry,
                            unitechpay_phone_local: phoneLocal || '',
                            unitechpay_phone: composedPhone,
                            unitechpay_otp: otpClean,
                        },
                    },
                };
            } );

            return function () {
                if ( typeof unsubscribe === 'function' ) {
                    unsubscribe();
                }
            };
        }, [ onPaymentSetup, emitResponse, selectedMethod, selectedCountry, phoneLocal, otpValue, needsOtp ] );

        var children = [];

        if ( description ) {
            children.push(
                el( 'p', { key: 'desc', className: 'unitechpay-blocks-description' }, description )
            );
        }

        // Sélecteur de pays
        var countrySelect = null;
        if ( countries.length > 1 ) {
            countrySelect = el(
                'div',
                { className: 'unitechpay-country-select', style: { marginBottom: '12px' } },
                el( 'label', {
                    htmlFor: 'unitechpay_country',
                    style: { fontWeight: 600, display: 'block', marginBottom: '4px' },
                }, __( 'Pays :', 'unitechpay-paiements-mobile-money' ) ),
                el(
                    'select',
                    {
                        id: 'unitechpay_country',
                        value: selectedCountry,
                        onChange: function ( e ) { 
                            setSelectedCountry( e.target.value ); 
                        },
                        style: { width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '6px' },
                    },
                    countries.map( function ( c ) {
                        return el( 'option', { key: c.code, value: c.code }, decodeEntities( c.name ) );
                    } )
                )
            );
        }

        // Construction des options de paiement
        var grid = el(
            'div',
            { className: 'unitechpay-payment-grid' },
            currentOptions.map( function ( option ) {
                var isChecked = selectedMethod === option.value;
                var inputId = 'unitechpay_method_' + selectedCountry + '_' + option.value;

                return el(
                    'div',
                    {
                        key: option.value,
                        className: 'unitechpay-payment-option ' + ( option.extraClass || '' ),
                    },
                    el( 'input', {
                        type: 'radio',
                        id: inputId,
                        name: 'unitechpay_payment_method',
                        value: option.value,
                        checked: isChecked,
                        onChange: function () {
                            setSelectedMethod( option.value );
                        },
                    } ),
                    el(
                        'label',
                        { htmlFor: inputId },
                        el(
                            'div',
                            { className: 'unitechpay-option-content' },
                            option.icon
                                ? el( 'img', { src: option.icon, alt: option.title, className: 'unitechpay-logo' } )
                                : null,
                            el( 'div', { className: 'unitechpay-option-title' }, option.title ),
                            option.desc
                                ? el( 'div', { className: 'unitechpay-option-desc' }, option.desc )
                                : null
                        )
                    )
                );
            } )
        );

        var feeNode = el(
            'div',
            { className: 'unitechpay-info' },
            el( 'strong', null, __( 'Frais appliqués:', 'unitechpay-paiements-mobile-money' ) ),
            el( 'br', null ),
            feePercent > 0
                ? sprintf( __( '• Vos frais: %s%%', 'unitechpay-paiements-mobile-money' ), feePercent )
                : __( '• Pas de frais supplémentaires', 'unitechpay-paiements-mobile-money' )
        );

        // Champ téléphone (visible pour les autres pays)
        var phoneNode = null;
        if ( selectedCountry !== 'SN' ) {
            phoneNode = el(
                'div',
                { className: 'unitechpay-phone-row', style: { marginTop: '12px' } },
                el( 'label', {
                    htmlFor: 'unitechpay_phone_local',
                    style: { fontWeight: 600, display: 'block', marginBottom: '4px' },
                }, __( 'Votre numéro Mobile Money :', 'unitechpay-paiements-mobile-money' ) ),
                el(
                    'div',
                    { style: { display: 'flex', alignItems: 'stretch' } },
                    el( 'span', {
                        id: 'unitechpay_dialcode',
                        style: {
                            display: 'inline-flex', alignItems: 'center', padding: '0 10px',
                            background: '#f1f1f1', border: '1px solid #ccc', borderRight: 0,
                            borderRadius: '6px 0 0 6px', fontWeight: 600,
                        },
                    }, '+' + ( dialcodes[ selectedCountry ] || '' ) ),
                    el( 'input', {
                        type: 'tel',
                        id: 'unitechpay_phone_local',
                        value: phoneLocal,
                        placeholder: __( 'Numéro sans indicatif', 'unitechpay-paiements-mobile-money' ),
                        onChange: function ( e ) { setPhoneLocal( e.target.value ); },
                        style: { flex: 1, padding: '8px', border: '1px solid #ccc', borderRadius: '0 6px 6px 0' },
                    } )
                ),
                el( 'small', { style: { color: '#666' } },
                    __( 'L\'indicatif est ajouté automatiquement selon le pays.', 'unitechpay-paiements-mobile-money' ) )
            );
        }

        // Champ OTP (Orange Money CI / BF)
        var otpNode = null;
        if ( needsOtp ) {
            otpNode = el(
                'div',
                { className: 'unitechpay-otp-row', style: { marginTop: '12px' } },
                el( 'label', {
                    htmlFor: 'unitechpay_otp',
                    style: { fontWeight: 600, display: 'block', marginBottom: '4px' },
                }, __( 'Code OTP Orange Money :', 'unitechpay-paiements-mobile-money' ) ),
                el( 'input', {
                    type: 'text',
                    id: 'unitechpay_otp',
                    value: otpValue,
                    inputMode: 'numeric',
                    autoComplete: 'one-time-code',
                    placeholder: __( 'Code à 6-8 chiffres', 'unitechpay-paiements-mobile-money' ),
                    onChange: function ( e ) { setOtpValue( e.target.value ); },
                    style: { width: '100%', padding: '8px', border: '1px solid #ccc', borderRadius: '6px' },
                } ),
                el( 'small', { style: { color: '#666', display: 'block', marginTop: '4px' } },
                    __( 'Composez #144*82# sur votre téléphone Orange pour générer votre code OTP, puis saisissez-le ici.', 'unitechpay-paiements-mobile-money' ) )
            );
        }

        children.push(
            el(
                'div',
                { key: 'options', className: 'unitechpay-payment-options' },
                countrySelect,
                el( 'h4', null, __( 'Choisissez votre méthode de paiement:', 'unitechpay-paiements-mobile-money' ) ),
                grid,
                phoneNode,
                otpNode,
                feeNode
            )
        );

        return el( 'div', { className: 'payment_method_unitechpay_gateway' }, children );
    };

    var Label = function ( props ) {
        var components = ( props && props.components ) || {};
        var PaymentMethodLabel = components.PaymentMethodLabel;

        var nodes = [
            PaymentMethodLabel
                ? el( PaymentMethodLabel, { text: label, key: 'label' } )
                : el( 'span', { key: 'label' }, label ),
        ];

        if ( settings.icon ) {
            nodes.push(
                el( 'img', {
                    key: 'icon',
                    src: settings.icon,
                    alt: label,
                    style: { maxHeight: '24px', marginLeft: 'auto' },
                } )
            );
        }

        return el(
            'span',
            {
                style: {
                    width: '100%',
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'space-between',
                },
            },
            nodes
        );
    };

    function hasAnyOption() {
        for ( var i = 0; i < countries.length; i++ ) {
            if ( countries[ i ].options && countries[ i ].options.length ) { return true; }
        }
        return false;
    }

    registerPaymentMethod( {
        name: 'unitechpay_gateway',
        label: el( Label, null ),
        content: el( Content, null ),
        edit: el( Content, null ),
        canMakePayment: function () {
            return settings.licenseValid !== false && hasAnyOption();
        },
        ariaLabel: label,
        supports: {
            features: settings.supports || [ 'products' ],
        },
    } );
} )();