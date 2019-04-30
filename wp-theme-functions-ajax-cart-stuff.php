<?php
// Custom AJAX Endpoints for cart drawer in theme
add_action( 'wp_ajax_scalar_cart', 'scalar_ajax_cart' );
add_action( 'wp_ajax_nopriv_scalar_cart', 'scalar_ajax_cart' );
function scalar_ajax_cart() {
	global $wpdb, $woocommerce;
    
    $items = '';
    foreach($woocommerce->cart->get_cart() as $product ) {
        ob_start();
        include('views/ajax-product.php');
        $items .= ob_get_clean();
    }
    $items = empty(trim($items)) ? 'No products found in your cart.' : '<table>' . $items . '</table>';    
    echo(json_encode(array(
        'subtotal' => $woocommerce->cart->get_cart_subtotal(),
        'items' => $items
    )));

	wp_die();
}

add_action( 'wp_ajax_scalar_quantity', 'scalar_ajax_quantity' );
add_action( 'wp_ajax_nopriv_scalar_quantity', 'scalar_ajax_quantity' );
function scalar_ajax_quantity() {
    global $woocommerce;
    try{
        $cart = $woocommerce->cart;
        $product = $_POST['product'];
        $variation = $_POST['variation'];
        $new_qty = $_POST['quantity'];
        foreach( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $product_id = $cart_item['product_id'];

            // Confirm Correct Product
            if($product != $product_id){
                continue;
            }

            // Confirm Correct Product Variation
            if(!empty($variation) && $variation != $cart_item['variation_id']){
                continue;
            }

            if( $cart_item['quantity'] != $new_qty ){
                $cart->set_quantity( $cart_item_key, $new_qty );
                echo(1);
            }
            break;
        }
    }catch(Exception $ex){
        echo($ex->getMessage());
    }
	wp_die();
}
