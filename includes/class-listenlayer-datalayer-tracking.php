<?php
if (!defined('ABSPATH')) {
	exit;
}

class LL_Datalayer_Tracking
{

	public function __construct()
	{
		// Contains snippets/JS tracking code
		include_once('class-listenlayer-datalayer-tracking-js.php');
	}

	public function call_hook()
	{
		// Event tracking woocommerce
		add_action('wp_footer', array($this, 'ldt_add_to_cart_variable'));

		add_action('woocommerce_after_add_to_cart_button', array($this, 'ldt_simple_add_to_cart'));
		add_action('woocommerce_after_shop_loop', array($this, 'ldt_after_shop_loop'));
		add_action('woocommerce_after_cart', array($this, 'ldt_after_cart'), 24);
		add_action('woocommerce_after_single_product', array($this, 'ldt_product_detail'));
		add_action('woocommerce_after_checkout_form', array($this, 'ldt_after_checkout_shipping_form'));
		add_action('woocommerce_after_checkout_form', array($this, 'ldt_view_checkout'));
		add_action('woocommerce_thankyou', array($this, 'ldt_purchase_completed'), 30, 1);
		add_action('woocommerce_add_to_cart', array($this, 'ldt_add_to_cart_advanced'), 150, 5);
	}

	public function ldt_add_to_cart_variable()
	{ ?>
		<script>
			let itemAddToCartGA4 = [];
			let valueAddToCartGA4 = 0;
			let indexAddToCart = 1;
		</script>
		<?php

		// view list item
		if (is_product_category()) {
			LL_Datalayer_Tracking_JS::get_instance()->ldtjs_view_list_item();
		}
	}

	public function ldt_add_to_cart_advanced($cart_item_key = 0, $product_id = 0, $quantity = 1, $variation_id = 0, $cart_item_data = [])
	{
		if (isset($_POST['woobt_ids'])) {
	        $woobt_ids = sanitize_text_field($_POST['woobt_ids']);
        
	        $items = explode(',', $woobt_ids);

	        $extracted_ids = [];

	        foreach ($items as $item) {
	            $parts = explode('/', $item);
	            if (isset($parts[0])) {
	                $extracted_ids[] = $parts[0];
	            }
	        }

	        if (!empty($extracted_ids)) {
	        	$cart_item_data['extracted_ids'] = $extracted_ids;
	        }
	    }
		
		LL_Datalayer_Tracking_JS::get_instance()->ldtjs_add_to_cart_advanced($cart_item_key, $product_id, $quantity, $variation_id, $cart_item_data);
	}

	public function ldt_simple_add_to_cart()
	{
		if (!is_single()) {
			return;
		}
		LL_Datalayer_Tracking_JS::get_instance()->ldtjs_tracking_add_to_cart_simple('.add_to_cart_button.product_type_simple');
	}

	public function ldt_after_shop_loop()
	{
		if (!is_product_category()) {
			LL_Datalayer_Tracking_JS::get_instance()->ldtjs_view_list_item();
		}

		LL_Datalayer_Tracking_JS::get_instance()->ldtjs_tracking_add_to_cart_simple('.add_to_cart_button.product_type_simple');
	}

	public function ldt_product_detail()
	{
		global $product;

		LL_Datalayer_Tracking_JS::get_instance()->ldtjs_select_item($product);
		LL_Datalayer_Tracking_JS::get_instance()->ldtjs_product_detail($product);
	}

	public function ldt_after_cart()
	{
		global $woocommerce;
		if (is_single()) {
			return;
		}
		LL_Datalayer_Tracking_JS::get_instance()->ldtjs_remove_from_cart($woocommerce);
		LL_Datalayer_Tracking_JS::get_instance()->ldtjs_view_cart($woocommerce);
		LL_Datalayer_Tracking_JS::get_instance()->ldtjs_cart_updated_quantity();
	}

	public function ldt_view_checkout()
	{
		global $woocommerce;
		if (empty($woocommerce)) return;
		$items = $woocommerce->cart->get_cart();
		$checkout = new WC_Checkout;
		$fields = $checkout->get_checkout_fields('billing');
		LL_Datalayer_Tracking_JS::get_instance()->ldtjs_view_checkout($fields, $items);
	}

	public function ldt_after_checkout_shipping_form()
	{
		LL_Datalayer_Tracking_JS::get_instance()->ldtjs_after_checkout_shipping_form();
	}

	public function ldt_purchase_completed($order_id)
	{
		LL_Datalayer_Tracking_JS::get_instance()->ldtjs_purchase_completed($order_id);
	}
}
