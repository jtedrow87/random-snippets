<?php
class MWC_CC_Processing {
    /**
     * Processing Instance
     * @var null
     */
    protected static $_instance = null;
    /**
     * Scripts required for extension
     * @var array
     */
    private static $scripts = array();
    private $assets_path, $frontend_script_path, $suffix;

    /**
     * @return MWC_CC_Processing|null
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }

    /**
     * MWC_CC_Processing constructor.
     */
    public function __construct() {
        if ( !session_id() ) {
            session_start();
        }

        $this->suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
        $this->assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', realpath('../../woocommerce/woocommerce.php') ) ) ) . '/woocommerce/assets/';
        $this->frontend_script_path = $this->assets_path . 'js/frontend/';
        $this->local_assets_path = str_replace( array( 'http:', 'https:' ), '', untrailingslashit( plugins_url( '/', realpath('../moco-woocommerce-manual-cc-processing.php') ) ) ) . '/manual-credit-card-processing-for-woocommerce-pro/assets/';

        add_action( 'add_meta_boxes_shop_order' , array($this, 'add_meta_boxes'), 30);
        add_action( 'woocommerce_order_item_add_action_buttons', array($this, 'add_custom_buttons'), 20, 1);
        add_action( 'wp_ajax_mc_wc_cc_manual', array($this, 'mc_wc_cc_manual_callback') );
        add_action( 'wp_ajax_mc_wc_set_shipping', array($this, 'mc_wc_cc_manual_shipping') );
        add_action( 'wp_ajax_mc_wc_calculate_shipping', array($this, 'mc_wc_cc_calculate_shipping') );
        add_action( 'admin_enqueue_scripts', array($this, 'add_payment_assets_to_admin'), 50, 1 );
        add_action( 'woocommerce_admin_order_data_after_order_details', array($this, 'add_create_customer') );
        add_action( 'save_post', array($this, '_create_customer'), 10, 3 );
        add_action( 'admin_notices', array($this, '_error') );
        
        add_filter( 'mwc_cc_set_shipping', array($this, 'set_shipping_address'), 10, 1 );

        WC_Frontend_Scripts::load_scripts();
        MoCoManualPayments::moco_wc_mcc_activate();
    }

    /**
     * @param $order
     * @return array
     */
    public function set_shipping_address($order){
        
        $legacy = (method_exists($order,'get_total_shipping'));

        if($legacy) {
            $address = $order->get_address('shipping');
            if(!is_array($address) || count($address) < 6){
                $address = $order->get_address('billing');
            }
            $shipping = array(
                'Country' => $address['country'],
                'State' => $address['state'],
                'PostalCode' => $address['postcode'],
                'City' => $address['city'],
                'Address1' => $address['address_1'],
                'Address2' => $address['address_2']
            );
        }else {
            $shipping = array(
                'Country' => (empty($order->get_shipping_country())) ? $order->get_billing_country() : $order->get_shipping_country(),
                'State' => (empty($order->get_shipping_state())) ? $order->get_billing_state() : $order->get_shipping_state(),
                'PostalCode' => (empty($order->get_shipping_postcode())) ? $order->get_billing_postcode() : $order->get_shipping_postcode(),
                'City' => (empty($order->get_shipping_city())) ? $order->get_billing_city() : $order->get_shipping_city(),
                'Address1' => (empty($order->get_shipping_address_1())) ? $order->get_billing_address_1() : $order->get_shipping_address_1(),
                'Address2' => (empty($order->get_shipping_address_2())) ? $order->get_billing_address_2() : $order->get_shipping_address_2()
            );
        }
        
        return $shipping;
        
    }

    /**
     * Add meta box to orders page
     */
    public function add_meta_boxes(){
        add_meta_box( 'woocommerce-order-manual-payment', __( 'Manual Payment', 'woocommerce' ), 'MWC_CC_Processing::output', 'shop_order', 'normal', 'high' );
    }

    /**
     * Add custom buttoms to admin order page
     * @param $order
     */
    public function add_custom_buttons($order ){
        if( $order->is_editable() ) {
            $disabled = ($order->get_status() == 'auto-draft') ? ' disabled' : '';
            if($disabled){
                submit_button( __( 'Quick Save' ), 'button button-primary quick-save-button', 'publish', false );
            }else{
                $calc_verb = translate('Calculate Shipping', 'woocommerce');
                $pay_verb = translate('Collect Payment', 'woocommerce');

                $calculate_shipping = <<<CSBTN
<button type="button" class="button button-primary calculate-shipping-button {$disabled}">{$calc_verb}</button>
CSBTN;
                $collect_payment = <<<CPBTN
<button type="button" class="button button-primary collect-payment-button {$disabled}">{$pay_verb}</button>
CPBTN;

                echo($collect_payment);
                echo($calculate_shipping);
            }
        }
    }

    /**
     *  Include payment assets into admin area
     */
    public function add_payment_assets_to_admin( $hook ){
        if ( $hook == 'post-new.php' || $hook == 'post.php' ) {
            global $post;
            if ( 'shop_order' === $post->post_type ) {
                self::register_script( 'jquery-payment', $this->assets_path . 'js/jquery-payment/jquery.payment' . $this->suffix . '.js', array( 'jquery' ), '1.4.1' );
                self::register_script( 'wc-credit-card-form', $this->frontend_script_path . 'credit-card-form' . $this->suffix . '.js', array( 'jquery', 'jquery-payment' ) );
                self::register_script( 'sv-wc-payment-gateway-frontend', $this->frontend_script_path . 'credit-card-form' . $this->suffix . '.js', array( 'jquery', 'jquery-payment' ) );
                self::register_script( 'sv-wc-payment-form-handler', $this->local_assets_path . 'scripts.js', array( 'jquery', 'jquery-payment' ), '1.0.0', false );

                if (!wp_script_is( 'jquery-payment', 'enqueued' )) {
                    self::enqueue_script('jquery-payment');
                }
                if (!wp_script_is( 'wc-credit-card-form', 'enqueued' )) {
                    self::enqueue_script('wc-credit-card-form');
                }
                if (!wp_script_is( 'sv-wc-payment-gateway-frontend', 'enqueued' )){
                    self::enqueue_script('sv-wc-payment-gateway-frontend');
                }
                if (!wp_script_is( 'sv-wc-payment-form-handler', 'enqueued' )){
                    self::enqueue_script('sv-wc-payment-form-handler');
                }

                $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
                foreach ($available_gateways as $gateway) {
                    if (method_exists($gateway, 'enqueue_scripts')) {
                        $gateway->enqueue_scripts();
                    }
                }

                $params = array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'ajax_nonce' => wp_create_nonce('moco_wc_cc_manual_payments')
                );
                wp_localize_script( 'sv-wc-payment-form-handler', 'moco_wc_cc', $params );
            }
        }
    }

    /**
     *  Output option to create customer from Guest
     */
    public function add_create_customer(){
        $create_customer_checkbox = <<<CCC
<p class="form-field form-field-wide">
    <label for="guest_to_customer">Create Customer:</label>
    <label><input type="checkbox" class="" style="width:0" name="guest_to_customer" id="guest_to_customer"> <i>Customer must be set to "Guest"</i>.</label>
</p>
CCC;
        echo($create_customer_checkbox);
    }

    /**
     * Save post metadata when a post is saved.
     *
     * @param int $post_id The post ID.
     * @param post $post The post object.
     * @param bool $update Whether this is an existing post being updated or not.
     */
    function _create_customer( $post_id, $post, $update ) {
        global $error;
        $slug = 'shop_order';
        if ( $slug != $post->post_type ) {
            return;
        }

        if ( isset( $_POST['guest_to_customer'] ) ) {
            if( (isset($_POST['customer_user']) && trim($_POST['customer_user']) == "") || !isset($_POST['customer_user'])) {
                $user_id = wc_create_new_customer($_POST['_billing_email'], $_POST['_billing_email'], wp_generate_password(12));
                if(is_int($user_id)) {
                    $fields = [
                        'billing_first_name',
                        'billing_last_name',
                        'billing_company',
                        'billing_address_1',
                        'billing_address_2',
                        'billing_city',
                        'billing_postcode',
                        'billing_country',
                        'billing_state',
                        'billing_email',
                        'billing_phone',
                        'shipping_first_name',
                        'shipping_last_name',
                        'shipping_company',
                        'shipping_address_1',
                        'shipping_address_2',
                        'shipping_city',
                        'shipping_postcode',
                        'shipping_country',
                        'shipping_state'
                    ];

                    foreach ($fields as $userField) {
                        update_user_meta($user_id, $userField, $_POST['_' . $userField]);
                    }
                    update_post_meta($post_id, '_customer_user', $user_id);
                    unset($_SESSION['mwc_cc_processing_error']);
                }else{
                    $_SESSION['mwc_cc_processing_error'] = $user_id->get_error_message();
                }
            }
        }
    }

    /**
     *  Display Payment Errors
     */
    public function _error() {
        if ( array_key_exists( 'mwc_cc_processing_error', $_SESSION ) ) {?>
            <div class="error">
            <p><?php echo $_SESSION['mwc_cc_processing_error']; ?></p>
            </div><?php
            unset( $_SESSION['mwc_cc_processing_error'] );
        }
    }

    /**
     * Output the metabox.
     *
     * @param WP_Post $post
     */
    public static function output( $post, $ajax = false ) {
        if(!$ajax) {
            global $post, $thepostid, $theorder;
        }else{
            $_GET['post'] = $post->ID;
        }

        if ( ! is_int( $thepostid ) ) {
            $thepostid = $post->ID;
        }

        if ( ! is_object( $theorder ) ) {
            $theorder = wc_get_order( $thepostid );
        }

        $order = $theorder;
        require_once(__DIR__ . '/../assets/styles.php');

        $data = get_post_meta($post->ID);
        require_once(__DIR__ . '/../templates/html-order-manual-payment.php');
    }

    /**
     * @param $url
     * @return array
     */
    public static function url2array($url){
        parse_str($url);
        if(is_array($url)){
            return $url;
        }
        $return = array();
        $queries = explode('&',$url);
        foreach($queries as $query){
            $broken = explode('=',$query);
            if(count($broken) == 2){
                $return[$broken[0]] = urldecode($broken[1]);
            }else{
                $return[$broken[0]] = null;
            }
        }
        return $return;
    }

    /**
     *  AJAX Function that Processes Payment
     */
    public static function mc_wc_cc_manual_callback() {
        global $wpdb;

        check_ajax_referer('moco_wc_cc_manual_payments', 'security');

        $orderId = (int)$_POST['order'];
        $gateway = $_POST['gateway'];
        $_POST = MWC_CC_Processing::url2array($_POST['data']);
        $order = wc_get_order( $orderId );
        $return = array();

        if($order->needs_payment()) {
            $payment_method     = isset( $gateway ) ? wc_clean( $gateway ) : false;
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
            update_post_meta( $orderId, '_payment_method', $payment_method );

            if ( isset( $available_gateways[ $payment_method ] ) ) {
                $payment_method_title = $available_gateways[ $payment_method ]->get_title();
            } else {
                $payment_method_title = '';
            }

            update_post_meta( $orderId, '_payment_method_title', $payment_method_title );

            // Validate
            $available_gateways[$payment_method]->validate_fields();

            // Process
            if ( wc_notice_count( 'error' ) == 0 ) {

                if(method_exists($available_gateways[$payment_method],'process_payment')) {

                    try{
                        $result = $available_gateways[$payment_method]->process_payment($orderId);
                        // Redirect to success/confirmation/payment page
                        if ('success' === $result['result']) {

                            // Update Order Status
                            $order->update_status('processing');

                            // Create Order Note
                            $csr = wp_get_current_user();
                            $order->add_order_note($csr->user_login . " manually charged credit card.", 0, true);

                            $return['status'] = true;
                        } else {
                            $return['status'] = false;
                            $return['message'] = 'Payment did not go through..';
                        }
                    }catch(Exception $e){
                        $return['status'] = false;
                        $return['message'] = $e->getMessage();
                    }


                    header('Content-type: application/json');

                }else{
                    $return['status'] = false;
                    $return['message'] = 'There is a issue with the payment gateway..';
                }
            }else{
                $errors = wc_get_notices();
                wc_clear_notices();
                $return['status'] = false;
                $return['message'] = implode("\n",$errors['error']);
            }
        }else{
            $return['status'] = false;
            $return['message'] = 'This order does not require a payment.';
        }
        echo(json_encode($return));
        wp_die();
    }

    /**
     *  AJAX Function that Updates Shipping on Order
     */
    public static function mc_wc_cc_manual_shipping(){
        global $wpdb;

        check_ajax_referer('moco_wc_cc_manual_payments', 'security');

        $orderId = (int)$_POST['order'];
        $shippingMethod = $_POST['method'];
        $shippingOptions = json_decode(stripslashes($_POST['details']));
        $order = wc_get_order( $orderId );

        foreach ($order->get_items('shipping') as $order_item_id => $item){
            wc_delete_order_item($order_item_id);
        }

        $rate = new WC_Shipping_Rate( $shippingOptions->$shippingMethod->id,
                                      $shippingOptions->$shippingMethod->label,
                                      $shippingOptions->$shippingMethod->cost,
                                      array(),
                                      $shippingOptions->$shippingMethod->method_id );

        
        $order->add_shipping($rate);
        echo(json_encode(array('status'=>true)));
        wp_die();
    }

    /**
     * AJAX Function to recalculate shipping rates and update modal
     */
    public static function mc_wc_cc_calculate_shipping(){
        check_ajax_referer('moco_wc_cc_manual_payments', 'security');
        $orderPost = get_post($_POST['order']);
        self::output($orderPost, true);
        wp_die();
    }
    
    /**
     * Register a script for use.
     *
     * @uses   wp_register_script()
     * @access private
     * @param  string   $handle
     * @param  string   $path
     * @param  string[] $deps
     * @param  string   $version
     * @param  boolean  $in_footer
     */
    private static function register_script( $handle, $path, $deps = array( 'jquery' ), $version = WC_VERSION, $in_footer = true ) {
        self::$scripts[] = $handle;
        wp_register_script( $handle, $path, $deps, $version, $in_footer );
    }

    /**
     * Register and enqueue a script for use.
     *
     * @uses   wp_enqueue_script()
     * @access private
     * @param  string   $handle
     * @param  string   $path
     * @param  string[] $deps
     * @param  string   $version
     * @param  boolean  $in_footer
     */
    private static function enqueue_script( $handle, $path = '', $deps = array( 'jquery' ), $version = WC_VERSION, $in_footer = true ) {
        if ( ! in_array( $handle, self::$scripts ) && $path ) {
            self::register_script( $handle, $path, $deps, $version, $in_footer );
        }
        wp_enqueue_script( $handle );
    }
}
