<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

if(!empty($_GET['post'])) {
    global $wpdb, $current_user;

    try {

        require_once(WC()->plugin_path() . '/includes/wc-cart-functions.php');
        if (!WC()->session) {
            include_once(WC()->plugin_path() . '/includes/abstracts/abstract-wc-session.php');
            include_once(WC()->plugin_path() . '/includes/class-wc-session-handler.php');

            WC()->session = new WC_Session_Handler();
        }
        $carry_current = $current_user;

        wp_set_current_user($order->user_id);

        $payment_gateway = wc_get_payment_gateway_by_order($order);
        $order_id = (!is_object($order)) ? absint($order) : $order->get_id();
        $shippingTotal = (method_exists($order, 'get_total_shipping')) ? $order->get_total_shipping() : $order->get_shipping_total();
        $manualLineItems = $order->get_items(apply_filters('woocommerce_admin_order_item_types', 'line_item'));
        if (!defined('WOOCOMMERCE_CHECKOUT')) {
            define('WOOCOMMERCE_CHECKOUT', true);
        }
        $shippingAddressForBilling = apply_filters('mwc_cc_set_shipping', $order);

        WC()->cart = new WC_Cart();
        WC()->customer = new WC_Customer($order->user_id);
        if (method_exists(WC()->customer, 'set_props')) {
            WC()->customer->set_props(array(
                'shipping_country' => $shippingAddressForBilling['Country'],
                'shipping_state' => $shippingAddressForBilling['State'],
                'shipping_postcode' => $shippingAddressForBilling['PostalCode'],
                'shipping_city' => $shippingAddressForBilling['City'],
                'shipping_address_1' => $shippingAddressForBilling['Address1'],
                'shipping_address_2' => $shippingAddressForBilling['Address2']
            ));
        } else {
            WC()->customer->set_country($shippingAddressForBilling['Country']);
            WC()->customer->set_state($shippingAddressForBilling['State']);
            WC()->customer->set_postcode($shippingAddressForBilling['PostalCode']);
            WC()->customer->set_city($shippingAddressForBilling['City']);
            WC()->customer->set_address($shippingAddressForBilling['Address1']);
            WC()->customer->set_address_2($shippingAddressForBilling['Address2']);
        }


        if (!empty($shippingAddressForBilling['Country']) && method_exists(WC()->customer, 'set_calculated_shipping')) {
            WC()->customer->set_calculated_shipping(true);
        }

        WC()->cart->init();
        WC()->cart->empty_cart();
        foreach ($manualLineItems as $li) {
            if (method_exists($li, 'get_product_id')) {
                $productId = $li->get_product_id();
                $productQty = $li->get_quantity();
                $variationId = $li->get_variation_id();
            } else {
                $productId = $li['product_id'];
                $productQty = $li['qty'];
                $variationId = $li['variation_id'];
            }

            WC()->cart->add_to_cart((int) $productId, $productQty, $variationId);

            $_product = wc_get_product($productId);
            $current_stock 	= $_product->get_stock_quantity();

            $temp_stock = (int) $current_stock + (int) $productQty;
            $_product->set_stock( 10 * $productQty );
            WC()->cart->add_to_cart((int) $productId, $productQty, $variationId);
            $_product->set_stock($current_stock);
        }
        
        WC()->cart->calculate_totals();
    }catch(Exception $e){
        echo($e->getMessage());
    }
    $revision = ($_POST) ? $_POST['revision'] : '';
    ?>

    <div id="woocommerce-order-manual-payment-scripts">
        <script type="text/template" id="tmpl-wc-modal-mc-shipping<?php echo($revision); ?>">
            <div class="wc-backbone-modal">
                <div class="wc-backbone-modal-content">
                    <section class="wc-backbone-modal-main" role="main" style="padding-bottom: 0">
                        <header class="wc-backbone-modal-header">
                            <h1><?php _e( 'Select Shipping Method', 'woocommerce' ); ?></h1>
                            <button class="modal-close modal-close-link dashicons dashicons-no-alt">
                                <span class="screen-reader-text">Close modal panel</span>
                            </button>
                        </header>
                        <article>
                            <div class="shipping">
                                <?php
                                if(WC()->shipping->get_packages()) {
                                    ?>
                                    <table>
                                        <?php wc_cart_totals_shipping_html() ?>
                                    </table>
                                    <?php
                                }else{
                                    echo('No products in the cart require shipping.');
                                }
                                ?>
                            </div>
                        </article>
                    </section>
                </div>
            </div>
            <div class="wc-backbone-modal-backdrop modal-close"></div>
        </script>
        <script type="text/template" id="tmpl-wc-modal-mc-payment">
        <div class="wc-backbone-modal">
            <div class="wc-backbone-modal-content">
                <section class="wc-backbone-modal-main" role="main">
                    <header class="wc-backbone-modal-header">
                        <h1><?php _e( 'Collect Payment', 'woocommerce' ); ?></h1>
                        <button class="modal-close modal-close-link dashicons dashicons-no-alt">
                            <span class="screen-reader-text">Close modal panel</span>
                        </button>
                    </header>
                    <article>

                        <div class="wc-order-data-row wc-order-totals-items wc-order-items-editable">

                            <div class="manualProcessing first-payment">
                                <h3>Order Total</h3>

                                <div class="view manual-total">
                                    <center><?php echo $order->get_formatted_order_total(); ?></center>
                                </div>
                            </div>

                            <div class="manualProcessing second-payment">
                                <h3>Available Gateways</h3>

                                <?php
                                $gatewayForms[] = array();
                                if (  $available_gateways = WC()->payment_gateways->get_available_payment_gateways() ) {
                                    foreach ($available_gateways as $gateway) {
                                        if (!$gateway->has_fields) {
                                            continue;
                                        }
                                        ?>
                                        <label
                                            for="payment_method_<?php echo $gateway->id; ?>" style="font-weight:bold">
                                            <input id="payment_method_<?php echo $gateway->id; ?>" type="radio"
                                                   class="input-radio"
                                                   name="payment_method"
                                                   value="<?php echo esc_attr($gateway->id); ?>" <?php checked($gateway->chosen, true); ?> /><?php echo $gateway->get_title(); ?>
                                            (<?php echo($gateway->method_title); ?>)</label>
                                        <br>
                                        <?php
                                        echo($gateway->description);
                                        ?>
                                        <hr>
                                        <?php
                                    }
                                }
                                ?>
                            </div>

                            <div class="manualProcessing third-payment">
                                <h3>Card Information</h3>

                                <div class="payment_form" style="text-align: center; color: #c1c1c1">
                                    Please select a gateway in order to move forward.
                                </div>
                                <?php
                                foreach ($available_gateways as $gateway) {
                                    if (!$gateway->has_fields) {
                                        continue;
                                    }
                                    echo('<div id="cc-form-' . $gateway->id . '" class="payment_form" style="display:none">');

                                    $gateway->payment_fields();

                                    echo('</div>');
                                }
                                ?>

                            </div>
                            <div class="clear"></div>
                        </div>

                    </article>
                    <footer>
                        <div class="inner">
                            <div id="charge_spinner" class="spinner" style="float:none"></div>
                            <button type="button" id="manual_cc_submit" style="display:none"
                                    class="button button-primary"><?php _e('Charge Card', 'woocommerce'); ?></button>
                        </div>
                    </footer>
                </section>
            </div>
        </div>
        <div class="wc-backbone-modal-backdrop modal-close"></div>
    </script>
        <?php
        if (!isset($packages)) {
            $packages = WC()->shipping->get_packages();
            echo('<div id="package_details">' . json_encode($packages[0]['rates']) . '</div>');
        }
        ?>
    </div>

    <div id="manual-payments-data">
        <div id="manual-payments-data-payment">
            <?php echo $order->get_formatted_order_total(); ?>
        </div>
    </div>


    <?php
    wp_set_current_user($carry_current->ID);
    unset($carry_current);
}
?>