<?php
/*
Plugin Name: Verofy Payment Gateway
Description: VEROFY your brand our technology.
Version: 1.6.3
Author: Verofy Payment Geteway
*/
?>
<?php
if (!defined('ABSPATH')) {
    exit;
}

add_filter('woocommerce_payment_gateways', function ($gateways) {
    $gateways[] = 'WC_Gateway_Verofy';
    return $gateways;
});

add_action('plugins_loaded', 'init_verofy_class');

function init_verofy_class()
{
    class WC_Gateway_Verofy extends WC_Payment_Gateway
    {

        const GATEWAY_URL = 'https://gateway.verofy.com';
        const GATEWAY_URL_TEST = 'https://test-gateway.verofy.com';
        const STYLE_URL = 'https://cloudfront.posinabox.eu/creditcall_gateway_branding_ecommerce/css/style_whitelabel.css';
        const STYLE_URL_TEST = 'https://cloudfront.posinabox.eu/creditcall_gateway_branding_ecommerce/css/style_test.css';

        protected $ekashuSellerId = '';
        protected $ekashuHashCode = '';
        protected $ekashuSellerKey = '';
        protected $ekashuFailureUrl = '';
        protected $ekashuSuccessUrl = '';
        protected $sandbox = true;
        protected $paymentDebug = false;
        protected $callbackName;
        protected $callbackSuccessUrl;
        protected $callbackFailureUrl;
        protected $redirectUrl;
        protected $myAccountUrl;
        protected $domain;
        protected $log = false;

        protected $availableCurrency = [
            'GBP',
            'EUR',
            'USD',
        ];

        public function __construct()
        {
            $this->id = 'verofygw';
            $this->icon = plugins_url('/assets/img/logo.png', __FILE__);
            $this->has_fields = true;
            $this->method_title = 'Verofy';
            $this->method_description = 'VEROFY your brand our technology';
            $this->domain = get_home_url();
            $this->callbackName = strtolower(get_class($this));
            $this->callbackSuccessUrl = add_query_arg('wc-api', $this->callbackName . '_callback_success_url', home_url('/'));
            $this->callbackFailureUrl = add_query_arg('wc-api', $this->callbackName . '_callback_failure_url', home_url('/'));
            $this->redirectUrl = add_query_arg('wc-api', $this->callbackName . '_payment_redirect', home_url('/'));
            $this->myAccountUrl = $this->domain . '/my-account/orders/';

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->sandbox = $this->get_option('sandbox');
            $this->ekashuFailureUrl = $this->get_option('ekashuFailureUrl');
            $this->ekashuSuccessUrl = $this->get_option('ekashuSuccessUrl');
            $this->ekashuSellerKey = $this->get_option('ekashuSellerKey');
            $this->ekashuSellerId = $this->get_option('ekashuSellerId');
            $this->ekashuHashCode = $this->get_option('ekashuHashCode');
            $this->paymentDebug = $this->get_option('paymentDebug');

            $this->checkSettings();

            // Save settings
            if (is_admin()) {
                add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            }

            // Register a webhook
            add_action('woocommerce_api_' . $this->callbackName . '_callback_success_url', [$this, 'handle_callback_success']);

            // Register a webhook
            add_action('woocommerce_api_' . $this->callbackName . '_callback_failure_url', [$this, 'handle_callback_failure']);

            // Register a webhook
            add_action('woocommerce_api_' . $this->callbackName . '_payment_redirect', [$this, 'payment_redirect']);
        }

        function process_payment($orderId)
        {
            try {
                $order = wc_get_order($orderId);

                if (!in_array($order->get_currency(), $this->availableCurrency)) {
                    throw new Exception('Invalid currency ' . $order->get_currency() . ' for this payment method. The correct currency is ' . implode(',', $this->availableCurrency) . '.');
                }

                // Mark as on-hold (we're awaiting the cheque)
                //$order->update_status('on-hold', 'Awaiting payment');

                // Remove cart
                WC()->cart->empty_cart();

                // Redirect to payment redirect hook
                return array(
                    'result' => 'success',
                    'redirect' => $this->redirectUrl . '&order=' . $orderId,
                );
            } catch (Exception $e) {
                wc_add_notice(__('Payment error:', 'woothemes') . $e->getMessage(), 'error');
                return ['result' => 'failure'];
            }
        }

        function _logging_payment($data, $source, $method = null){

            if($this->paymentDebug == 'yes'){

                $logger = wc_get_logger();

                if($method == 'alert'){
                    $logger->alert(wc_print_r($data, true), ['source' => $source]);
                } else {
                    $logger->info(wc_print_r($data, true), ['source' => $source]);
                }

            }

        }

        function handle_callback_success()
        {

            // Get the order by ID
            $ekashu_reference = isset($_POST['ekashu_reference']) ? sanitize_text_field($_POST['ekashu_reference']) : '';

            // Support for: Sequential Order Numbers extension
            // -- Obtain the order number
            // -- Otherwise we get the regular number if the extension doesn't exist
            if (function_exists( 'wc_sequential_order_numbers') && is_plugin_active( 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php')) {
                $order_id = wc_sequential_order_numbers()->find_order_by_order_number( $ekashu_reference );
                $order = wc_get_order($order_id);

                $this->_logging_payment([
                    'original_order_id' => $order ? $order->get_id() : null,
                    'sequential_order_id' => $ekashu_reference,
                    'sandbox' => $this->sandbox,
                    'post-data' => $_POST,
                ], 'success-payment-sequential-report');

            } else {
                $order = wc_get_order($ekashu_reference);
            }

            $this->_logging_payment([
                'order' => $order ? $order->get_id() : null,
                'ekashu-hash-code' => $this->ekashuHashCode,
                'sandbox' => $this->sandbox,
                'post-data' => $_POST
            ], 'success-payment-received');

            // Hashcode result validation
            if(!empty($this->ekashuHashCode)){

                $post_data = null;
                foreach ($_POST as $name => $value) {

                    if (preg_match('/^ekashu_/', $name) == 1){
                        $post_data .= urlencode($name).'='.urlencode($value);
                        $post_data .= '&';
                    }

                }

                if($this->sandbox == 'yes'){
                    $url = 'https://test.ekashu.com/validate_hash_code.php';
                } else {
                    $url = 'https://live.ekashu.com/validate_hash_code.php';
                }

                $post_data = substr($post_data, 0, -1);
                $curl = curl_init();
                curl_setopt($curl, CURLOPT_URL, $url);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_POSTFIELDS, $post_data);
                curl_setopt($curl, CURLOPT_HEADER, true);
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_exec($curl);
                $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);

                $this->_logging_payment([
                    'order' => $order ? $order->get_id() : null,
                    'validation-result' => $http_code,
                    'validation-post-data' => $post_data
                ], 'success-order-hashcode-validation');

                if ($http_code != 200){
                    echo 'code=1&message=Error';
                    exit();
                }

            }

            // Order validation
            try {

                $ekashu_auth_result = isset($_POST['ekashu_auth_result']) ? sanitize_text_field($_POST['ekashu_auth_result']) : '';
                $ekashu_auth_code = isset($_POST['ekashu_auth_code']) ? sanitize_text_field($_POST['ekashu_auth_code']) : '';
                $ekashu_transaction_id = isset($_POST['ekashu_transaction_id']) ? sanitize_text_field($_POST['ekashu_transaction_id']) : '';

                if (empty($ekashu_auth_result) || empty($ekashu_reference) || empty($ekashu_transaction_id) || empty($ekashu_auth_code) || !$order || !$this->checkOrder($order, $_POST)) {
                    throw new Exception('Error: data - handle_callback_success (either empty or invalid)');
                }

                $current_status = $order->get_status();

                if($current_status == 'pending'){

                    wc_reduce_stock_levels($order);

                    $order->update_status('complete', 'Payment successfully completed');
                    $order->payment_complete();
                    $order->add_order_note(sprintf(__('Payment of %1$s was captured - Auth ID: %2$s, Transaction ID: %3$s', 'woocommerce'), $order->get_total(), $ekashu_auth_code, $ekashu_transaction_id));

                }

                $this->_logging_payment([
                    'order' => $order ? $order->get_id() : null,
                    'note' => 'True',
                    'exception' => null
                ], 'success-order-processing');

                echo 'code=0&message=OK';
                exit();

            } catch (Exception $exception) {

                $this->_logging_payment([
                    'order' => $order ? $order->get_id() : null,
                    'note' => 'False',
                    'exception' => $exception->getMessage()
                ], 'success-order-processing', 'alert');

                echo 'code=1&message=Error';
                exit();
            }
        }

        function handle_callback_failure()
        {

            // Get the order by ID
            $ekashu_reference = isset($_POST['ekashu_reference']) ? sanitize_text_field($_POST['ekashu_reference']) : '';

            // Support for: Sequential Order Numbers extension
            // -- Obtain the order number
            // -- Otherwise we get the regular number if the extension doesn't exist
            if (function_exists( 'wc_sequential_order_numbers') && is_plugin_active( 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php')) {
                $order_id = wc_sequential_order_numbers()->find_order_by_order_number( $ekashu_reference );
                $order = wc_get_order($order_id);

                $this->_logging_payment([
                    'original_order_id' => $order ? $order->get_id() : null,
                    'sequential_order_id' => $ekashu_reference,
                    'post-data' => $_POST
                ], 'failure-payment-sequential-report');

            } else {
                $order = wc_get_order($ekashu_reference);
            }

            $this->_logging_payment([
                'order' => $order ? $order->get_id() : null,
                'post-data' => $_POST
            ], 'failure-payment-received');

            try {

                $ekashu_auth_result = isset($_POST['ekashu_auth_result']) ? sanitize_text_field($_POST['ekashu_auth_result']) : '';
                $ekashu_auth_code = isset($_POST['ekashu_auth_code']) ? sanitize_text_field($_POST['ekashu_auth_code']) : '';
                $ekashu_transaction_id = isset($_POST['ekashu_transaction_id']) ? sanitize_text_field($_POST['ekashu_transaction_id']) : '';

                if (empty($ekashu_auth_result) || empty($ekashu_reference) || empty($ekashu_transaction_id) || empty($ekashu_auth_code) || !$order || !$this->checkOrder($order, $_POST)) {
                    throw new Exception('Error: data - handle_callback_failure (either empty or invalid)');
                }

                $order->update_status('cancelled', 'Payment failed');
                $order->add_order_note(sprintf(__('Payment could not captured - Auth ID: %1$s, Status: %2$s', 'woocommerce'), $ekashu_auth_code, $ekashu_auth_result));

                $this->_logging_payment([
                    'order' => $order ? $order->get_id() : null,
                    'exception' => 'Payment failed'
                ], 'failure-order-processing');

                echo 'code=0&message=OK';
                exit();

            } catch (Exception $exception) {

                $this->_logging_payment([
                    'order' => $order ? $order->get_id() : null,
                    'exception' => $exception->getMessage()
                ], 'failure-order-processing', 'alert');

                echo 'code=1&message=Error';
                exit();

            }
        }

        function payment_redirect()
        {

            $orderId = isset($_GET['order']) ? $_GET['order'] : null;

            if ($orderId === null || !$order = wc_get_order($orderId)) {
                throw new Exception('Invalid order.');
            }

            $sellerId = $this->ekashuSellerId;
            $sellerKey = $this->ekashuSellerKey;
            $actionUrl = '';
            $hashCode = '';

            if ($_SERVER['REQUEST_METHOD'] === 'POST') {

                $actionUrl = ($this->sandbox == 'yes' ? self::GATEWAY_URL_TEST : self::GATEWAY_URL);

                if(!empty($this->ekashuHashCode)){

                    $checkFields = [
                        'ekashu_3d_secure_verify',
                        'ekashu_amount',
                        'ekashu_amount_format',
                        'ekashu_auto_confirm',
                        'ekashu_callback_failure_url',
                        'ekashu_callback_include_post',
                        'ekashu_callback_success_url',
                        'ekashu_card_address_editable',
                        'ekashu_card_address_required',
                        'ekashu_card_address_verify',
                        'ekashu_card_email_address_mandatory',
                        'ekashu_card_phone_number_mandatory',
                        'ekashu_card_title_mandatory',
                        'ekashu_card_zip_code_verify',
                        'ekashu_currency',
                        'ekashu_delivery_address_editable',
                        'ekashu_delivery_address_required',
                        'ekashu_delivery_email_address_mandatory',
                        'ekashu_delivery_phone_number_mandatory',
                        'ekashu_delivery_title_mandatory',
                        'ekashu_description',
                        'ekashu_device',
                        'ekashu_duplicate_check',
                        'ekashu_duplicate_minutes',
                        'ekashu_failure_return_text',
                        'ekashu_failure_url',
                        'ekashu_hash_code_format',
                        'ekashu_hash_code_type',
                        'ekashu_hash_code_version',
                        'ekashu_include_post',
                        'ekashu_invoice_address_editable',
                        'ekashu_invoice_address_required',
                        'ekashu_invoice_email_address_mandatory',
                        'ekashu_invoice_phone_number_mandatory',
                        'ekashu_invoice_title_mandatory',
                        'ekashu_locale',
                        'ekashu_payment_methods',
                        'ekashu_reference',
                        'ekashu_request_type',
                        'ekashu_return_text',
                        'ekashu_seller_address',
                        'ekashu_seller_email_address',
                        'ekashu_seller_id',
                        'ekashu_seller_key',
                        'ekashu_seller_name',
                        'ekashu_shortcut_icon',
                        'ekashu_style_sheet',
                        'ekashu_success_url',
                        'ekashu_title',
                        'ekashu_verification_value_mask',
                        'ekashu_verification_value_verify',
                        'ekashu_viewport',
                    ];

                    $hashcodeInput = [];
                    foreach ($checkFields as $field) {
                        $hashcodeInput[$field] = isset($_POST[$field]) ? $_POST[$field] : null;
                    }

                    ksort($hashcodeInput);

                    $hashCode = null;

                    foreach ($hashcodeInput as $name => $value)
                    {
                        $hashCode .= $value;
                        $hashCode .= '&';
                    }

                    $hashCode = substr($hashCode, 0, -1);
                    $hashCode = base64_encode(hash_hmac('sha256', $hashCode, $this->ekashuHashCode, true))."\n";

                }

            }

            // Support for: Sequential Order Numbers extension
            // -- Sending the order number
            // -- Otherwise we send the regular number if the extension doesn't exist
            if (function_exists( 'wc_sequential_order_numbers') && is_plugin_active( 'woocommerce-sequential-order-numbers/woocommerce-sequential-order-numbers.php')) {
                $order = wc_get_order( $order->get_id() );
                $ekashu_reference = $order->get_order_number();
            } else {
                $ekashu_reference = $order->get_id();
            }

            $this->_logging_payment([
                'ekashu-hash-code' => $this->ekashuHashCode,
                'sandbox' => $this->sandbox,
                'post-data' => [
                        'ekashu_reference' => $ekashu_reference,
                        'function_exists_wc_sequential_order_numbers' => function_exists( 'wc_sequential_order_numbers' ) ? 'Yes' : 'No'
                ],
            ], 'initiate-payment-status');

            $output = '';
            ob_start(); ?>

            <!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN" "http://www.w3.org/TR/html4/loose.dtd">
            <html lang="en">
            <head>
                <meta http-equiv="content-type" content="text/html; charset=utf-8">
                <title>Redirect...</title>
            </head>

            <body>

            <form class="form-horizontal" role="form" action="<?php echo $actionUrl; ?>" method="post" id="payment-form" style="display: none">

                <input type="hidden" name="ekashu_seller_id" value="<?php echo $sellerId; ?>"/>
                <input type="hidden" name="ekashu_seller_key" value="<?php echo $sellerKey; ?>"/>

                <input type="hidden" name="ekashu_amount" value="<?php echo $order->get_total(); ?>" required/>
                <input type="hidden" name="ekashu_currency" value="<?php echo $order->get_currency(); ?>"/>
                <input type="hidden" name="ekashu_reference" value="<?php echo $ekashu_reference; ?>"/>
                <input type="hidden" name="ekashu_auto_confirm" value="true"/>
                <input type="hidden" name="ekashu_duplicate_check" value="error"/>
                <input type="hidden" name="ekashu_card_address_required" value="false"/>
                <input type="hidden" name="ekashu_card_address_verify" value="check"/>
                <input type="hidden" name="ekashu_card_zip_code_verify" value="check"/>
                <input type="hidden" name="ekashu_card_title_mandatory" value="false"/>
                <input type="hidden" name="ekashu_card_email_address_mandatory" value="false"/>
                <input type="hidden" name="ekashu_include_post" value="false"/>

                <input type="hidden" name="ekashu_card_first_name" value="<?php echo $order->get_billing_first_name(); ?>"/>
                <input type="hidden" name="ekashu_card_email_address" value="<?php echo $order->get_billing_email(); ?>"/>
                <input type="hidden" name="ekashu_card_phone_number" value="<?php echo $order->get_billing_phone(); ?>"/>
                <input type="hidden" name="ekashu_card_last_name" value="<?php echo $order->get_billing_last_name(); ?>"/>
                <input type="hidden" name="ekashu_card_address_1" value="<?php echo $order->get_billing_address_1(); ?>"/>
                <input type="hidden" name="ekashu_card_zip_code" value="<?php echo $order->get_billing_postcode(); ?>"/>
                <input type="hidden" name="ekashu_card_state" value="<?php echo WC()->countries->countries[ $order->get_billing_country() ]; ?>"/>
                <input type="hidden" name="ekashu_card_city" value="<?php echo $order->get_billing_city(); ?>"/>
                <input type="hidden" name="ekashu_card_phone_number_type" value="Mobile"/>

                <input type="hidden" name="ekashu_style_sheet" value="<?php echo ($this->sandbox == 'yes' ? self::STYLE_URL_TEST : self::STYLE_URL); ?>"/>
                <input type="hidden" name="ekashu_callback_failure_url" value="<?php echo $this->callbackFailureUrl; ?>"/>
                <input type="hidden" name="ekashu_callback_success_url" value="<?php echo $this->callbackSuccessUrl; ?>"/>
                <input type="hidden" name="ekashu_failure_url" value="<?php echo $this->ekashuFailureUrl; ?>"/>
                <input type="hidden" name="ekashu_success_url" value="<?php echo $this->ekashuSuccessUrl; ?>"/>
                <input type="hidden" name="ekashu_return_url" value="<?php echo $this->myAccountUrl; ?>"/>

                <?php if(!empty($this->ekashuHashCode)){ ?>
                    <input type="hidden" name="ekashu_hash_code" id="ekashu_hash_code" value="<?php echo $hashCode ?>">
                    <input type="hidden" name="ekashu_hash_code_type" id="ekashu_hash_code_type" value="SHA256HMAC">
                    <input type="hidden" name="ekashu_hash_code_version" id="ekashu_hash_code_version" value="2.0.0">
                <?php } ?>

            </form>

            <script type="text/javascript">
                document.getElementById("payment-form").submit();
            </script>

            <?php $output .= ob_get_contents();
            ob_end_clean();
            echo $output;
            exit();
        }

        function init_form_fields()
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'Enable/Disable',
                    'label' => 'Enable Verofy Gateway',
                    'type' => 'checkbox',
                    'description' => '',
                    'default' => 'no'
                ],
                'title' => [
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title which the user sees during checkout.',
                    'default' => 'Card Payments',
                    'desc_tip' => true,
                ],
                'description' => [
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description which the user sees during checkout.',
                    'default' => 'Pay with your card via Verofy payment gateway.',
                ],
                'sandbox' => [
                    'title' => 'Test mode',
                    'label' => 'Enable Test Mode',
                    'type' => 'checkbox',
                    'description' => 'Place the payment gateway in test mode using test API keys.',
                    'default' => 'yes',
                    'desc_tip' => true,
                ],
                'ekashuFailureUrl' => [
                    'title' => 'Failure Url',
                    'type' => 'text',
                    'description' => '',
                    'default' => $this->domain . '/my-account/orders/',
                ],
                'ekashuSuccessUrl' => [
                    'title' => 'Success Url',
                    'type' => 'text',
                    'description' => '',
                    'default' => $this->domain . '/checkout/order-received/',
                ],
                'ekashuSellerKey' => [
                    'title' => 'The seller’s eKashu key',
                    'type' => 'text',
                    'description' => '',
                ],
                'ekashuSellerId' => [
                    'title' => 'The seller’s eKashu ID',
                    'type' => 'text',
                    'description' => '',
                ],
                'ekashuHashCode' => [
                    'title' => 'The seller’s eKashu Hash Key',
                    'type' => 'text',
                    'description' => '',
                ],
                'paymentDebug' => [
                    'title' => 'Payment debug mode',
                    'label' => 'Enable debug/info mode',
                    'type' => 'checkbox',
                    'description' => 'Leave this mode turned on if you want to review payment data in WooCommerce > Status: Logs. It works independently on test mode.',
                    'default' => 'no',
                    'desc_tip' => true,
                ],
            ];
        }

        public function checkSettings()
        {
            if (empty($this->ekashuSellerKey) OR empty($this->ekashuSellerId)) {
                $this->enabled = false;
            }
        }

        protected function checkOrder($order, $data)
        {

            if (!isset($data['ekashu_amount']) || $order->get_total() !== sanitize_text_field($data['ekashu_amount'])) {
                return false;
            } elseif (!isset($data['ekashu_currency']) || $order->get_currency() !== sanitize_text_field($data['ekashu_currency'])) {
                return false;
            } elseif (!isset($data['ekashu_seller_key']) || $this->ekashuSellerKey !== sanitize_text_field($data['ekashu_seller_key'])) {
                return false;
            } elseif (!isset($data['ekashu_seller_id']) || $this->ekashuSellerId !== sanitize_text_field($data['ekashu_seller_id'])) {
                return false;
            }

            return true;
        }

    }
}

?>
