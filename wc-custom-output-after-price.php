<?php

// This was something I threw together for someone in Slack or Facebook (I don't remember)

/**
 * Add custom field to the product editor screen
 */
function custom_output_after_price_flag()
{
    global $woocommerce, $post;
    echo '<div class="options_group">';
    woocommerce_wp_textarea_input(
        array(
            'id'          => '_custom_output_after_price',
            'label'       => __('Custom Output', 'woocommerce'),
            'desc_tip'    => 'true',
            'description' => __('You can include HTML, Shortcodes or just plain old text.', 'woocommerce'),
            'placeholder' => '[stickerpreview]',
            'rows'        => '5',
        )
    );
    echo '</div>';
}
add_action('woocommerce_product_options_general_product_data', 'custom_output_after_price_flag');

/**
 * Save the custom output on meta save
 *
 * @param $post_id
 */
function custom_output_after_price_save_flag($post_id)
{
    $custom_output = isset($_POST['_custom_output_after_price']) ? $_POST['_custom_output_after_price'] : '';
    update_post_meta($post_id, '_custom_output_after_price', $custom_output);
}
add_action('woocommerce_process_product_meta', 'custom_output_after_price_save_flag');


/**
 * Display the custom output
 */
function display_custom_output_after_price_action()
{
    global $product;
    try {
        if ( ! empty($custom_output = get_post_meta($product->get_id(), '_custom_output_after_price', true))) {
            echo( do_shortcode($custom_output) );
        }
    }catch(Exception $e){
        // Can do some logging here, don't want to blow up the site if anything goes wrong.
    }
}
add_action('woocommerce_single_product_summary', 'display_custom_output_after_price_action', 15);
