jQuery(document).ready(function($) {
    'use strict';
    
    console.log('UnitechPay loaded');
    
    
    function initUnitechPay() {

        handlePaymentMethodVisibility();
        

        handlePaymentOptionInteractions();
        

        handleFormSubmissions();
    }
    
    
    function handlePaymentMethodVisibility() {
        function toggleUnitechPayFields() {
            var $unitechPayGateway = $('.payment_method_unitechpay_gateway');
            var $unitechPayRadio = $('input[name="payment_method"][value="unitechpay_gateway"]');
            
            if ($unitechPayRadio.is(':checked')) {
                $unitechPayGateway.find('.unitechpay-payment-options').show();
            } else {
                $unitechPayGateway.find('.unitechpay-payment-options').hide();
            }
        }


        toggleUnitechPayFields();
        

        $(document.body).on('change', 'input[name="payment_method"]', function() {
            setTimeout(toggleUnitechPayFields, 50);
        });


        $(document.body).on('updated_checkout', function() {
            setTimeout(toggleUnitechPayFields, 100);
        });
    }
    
    
    function handlePaymentOptionInteractions() {

        $(document.body).on('change', 'input[name="unitechpay_payment_method"]', function() {
            var selectedMethod = $(this).val();
            var $selectedOption = $(this).closest('.unitechpay-payment-option');
            

            $('.unitechpay-payment-option').removeClass('selected');
            

            $selectedOption.addClass('selected');
            

            $(document.body).trigger('unitechpay_method_selected', [selectedMethod]);
        });
        

        $(document.body).on('mouseenter', '.unitechpay-payment-option', function() {
            $(this).addClass('hover');
        });
        
        $(document.body).on('mouseleave', '.unitechpay-payment-option', function() {
            $(this).removeClass('hover');
        });
    }
    
    
    function handleFormSubmissions() {

        $(document.body).on('checkout_place_order_unitechpay_gateway', function() {
            var selectedPaymentMethod = $('input[name="unitechpay_payment_method"]:checked').val();
            
            if (!selectedPaymentMethod) {

                $('.woocommerce-notices-wrapper').html(
                    '<div class="woocommerce-error">' + 
                    unitechpay_ajax.strings.select_method_error + 
                    '</div>'
                );
                

                $('html, body').animate({
                    scrollTop: 0
                }, 500);
                
                return false;
            }
            
            return true;
        });
    }
    
    
    function initPaymentStatusChecker() {
        if (typeof unitechpay_payment_data === 'undefined') {
            return;
        }
        
        var checkInterval;
        var maxChecks = 600;
        var checkCount = 0;
        
        function checkPaymentStatus() {
            if (checkCount >= maxChecks) {
                clearInterval(checkInterval);
                showTimeoutMessage();
                return;
            }
            
            $.ajax({
                url: unitechpay_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'unitechpay_check_payment_status',
                    order_id: unitechpay_payment_data.order_id,
                    nonce: unitechpay_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        if (response.data.is_completed) {
                            clearInterval(checkInterval);
                            showPaymentSuccess(response.data.order_url);
                        }
                    }
                },
                error: function() {
                    console.log('Error checking payment status');
                }
            });
            
            checkCount++;
        }
        
        function showPaymentSuccess(orderUrl) {
            $('#unitechpay-status-checking').hide();
            $('#unitechpay-payment-success').show();
            
            setTimeout(function() {
                window.location.href = orderUrl;
            }, 2000);
        }
        
        function showTimeoutMessage() {
            $('#unitechpay-status-checking').html(
                '<p>' + unitechpay_ajax.strings.timeout_message + '</p>'
            );
        }
        

        checkInterval = setInterval(checkPaymentStatus, 3000);
    }
    
    
    var UnitechPayUtils = {
        
        formatAmount: function(amount) {
            return new Intl.NumberFormat('fr-FR', {
                style: 'currency',
                currency: 'XOF',
                minimumFractionDigits: 0
            }).format(amount);
        },
        
        
        showLoading: function($element) {
            $element.addClass('unitechpay-loading');
            $element.prop('disabled', true);
        },
        
        
        hideLoading: function($element) {
            $element.removeClass('unitechpay-loading');
            $element.prop('disabled', false);
        },
        
        
        showSuccess: function(message) {
            $('.woocommerce-notices-wrapper').html(
                '<div class="woocommerce-message">' + message + '</div>'
            );
        },
        
        
        showError: function(message) {
            $('.woocommerce-notices-wrapper').html(
                '<div class="woocommerce-error">' + message + '</div>'
            );
        }
    };
    

    initUnitechPay();
    

    initPaymentStatusChecker();
    

    window.UnitechPayUtils = UnitechPayUtils;
    
});