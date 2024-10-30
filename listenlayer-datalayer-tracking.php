<?php

/**
 * Plugin Name: ListenLayer - Cloud Data Layer Platform
 * Plugin URI: http://wordpress.org/plugins/listenlayer/
 * Description: ListenLayer is the world’s first, and only cloud data layer platform. Use Listeners to create powerful Data Layers that enhance the accuracy and quality of your website tracking & marketing data – all without programming.
 * Author: ListenLayer
 * Author URI: https://www.listenlayer.com/
 * Version: 1.9.19
 */

// Start session on 'init' hook
if (!function_exists('start_session')) {
    function start_session()
    {
        if (!session_id()) {
            session_start();
        }
    }
    add_action('init', 'start_session', 1);
}

// ADD TO CART REDIRECT & SESSION INFO
function handle_session_unset()
{
    if (!is_cart() && !is_product()) {
        if (isset($_SESSION['ltd_flag_redirect_addtocart']) && is_shop()) {
            unset($_SESSION['ltd_flag_redirect_addtocart']);
        }
    }
}
add_action('wp_footer', 'handle_session_unset');

// End session on logout and login
if (!function_exists('end_session')) {
    function end_session()
    {
        if (session_id()) {
            // Ensure session is cleared properly
            session_start();
            $_SESSION = array();
            session_destroy();
        }
    }
    // add_action('wp_logout', 'end_session');
    // add_action('wp_login', 'end_session');
}

include_once 'includes/class-listenlayer-datalayer-tracking.php';
$listenlayer_datalayer_tracking = new LL_Datalayer_Tracking();
$listenlayer_datalayer_tracking->call_hook();

add_action('wp_ajax_ll_datalayer_add_to_cart_query', 'll_datalayer_add_to_cart_query'); //fire get_more_posts on AJAX call for logged-in users;
add_action('wp_ajax_nopriv_ll_datalayer_add_to_cart_query', 'll_datalayer_add_to_cart_query'); //fire get_more_posts on AJAX call for all other users;

function ll_datalayer_add_to_cart_query()
{
    if ($_GET['productId']) {
        $productId = sanitize_text_field($_GET['productId']);
        $_product =  wc_get_product($productId);

        $categories = get_the_terms($productId, 'product_cat');

        // Price support plugin WooCommerce Multilingual & Multicurrency
        if (class_exists('WCML_Multi_Currency')) {
            global $woocommerce_wpml;
            $current_currency = $woocommerce_wpml->multi_currency->get_client_currency();
            $price = $woocommerce_wpml->multi_currency->prices->convert_price_amount($_product->get_price(), $current_currency);
        } else {
            $price = $product->get_price();
        }


        $data_fields = array(
            'item_id' => $productId,
            'item_name' => $_product->get_title(),
            'quantity' => sanitize_text_field($_GET['quantity']),
            'price' => $price,
            'index' => 1,
            // 'sale_price' => $_product->get_sale_price(),
        );

        foreach ($categories as $cat => $val) {
            if ($cat === 0) {
                $data_fields['item_category'] = $val->name;
            } else {
                $data_fields['item_category' . $cat] = $val->name;
            }
        }

        echo json_encode(array('GA4' => $data_fields));
        die();
    }
}

add_action('wp_ajax_ll_datalayer_remove_item_query', 'll_datalayer_remove_item_query');
add_action('wp_ajax_nopriv_ll_datalayer_remove_item_query', 'll_datalayer_remove_item_query');

function ll_datalayer_remove_item_query()
{
    if ($_GET['cart_key_item']) {

        global $woocommerce;

        $items_cart = $woocommerce->cart->get_cart();
        $total_price = $woocommerce->cart->total;

        $index = 1;

        foreach ($items_cart as $key => $value) {
            if ($value['key'] == sanitize_text_field($_GET['cart_key_item'])) {
                $item_remove = $value;
                break;
            }
            $index++;
        }


        $productId = $item_remove['data']->get_id(); // if product is variation product, productId will be variationId

        $product = wc_get_product($productId);

        $categories = get_the_terms($item_remove['product_id'], 'product_cat');

        $price_fixed = $item_remove['line_subtotal'] / $item_remove['quantity'];

        $current_currency = get_option('woocommerce_currency');

        $price_from_woobt = $values['woobt_price_item'];

        // Support plugin WCML Multi Currency
        if (class_exists('WCML_Multi_Currency')) {
            global $woocommerce_wpml;
            $current_currency = $woocommerce_wpml->multi_currency->get_client_currency();
            $price = $woocommerce_wpml->multi_currency->prices->convert_price_amount($price_fixed, $current_currency);
        }

        !empty($price_from_woobt) && $price = $price_from_woobt;

        $rounded_price = round($price, 0);


        $variantItems = ($item_remove['variation_id']) ? wc_get_product($item_remove['variation_id']) : '';

        $variantName = ($item_remove['variation_id']) ? $variantItems->name : '';

        $productPrice = ($item_remove['variation_id']) ? $variantItems->price : $rounded_price;


        $item_remove_GA4 = array(
            'item_id' => $productId,
            'item_name' => $product->get_title(),
            'quantity' => $item_remove['quantity'],
            'price' => $productPrice,
            'index' => $index,
            'currency' => $current_currency,
            'item_variant' => $variantName,
        );

        $coupon_product_level = [];
        $discount_product_item = 0;
        $product_exclude_coupon = false;
        $coupon_product_item = [];

        foreach ($woocommerce->cart->get_coupons() as $coupon) {
            if ($coupon->get_discount_type() !== 'fixed_cart') {
                $coupon_product_level[] = $coupon;
            }
        }

        $id_check_coupon = $item_remove['variation_id'] ? $item_remove['variation_id'] : $item_remove['product_id'];

        foreach ($coupon_product_level as $coupon) {
            if (count($coupon->get_product_ids()) > 0) {
                if (in_array($id_check_coupon, $coupon->get_product_ids())) {
                    $coupon_product_item[] = $coupon->get_code();
                    if ($coupon->get_discount_type() == 'percent') {
                        $discount_product_item += ($productPrice * $coupon->get_amount() * $item_remove['quantity']) / 100;
                    } else {
                        $discount_product_item += $coupon->get_amount() * $item_remove['quantity'];
                    }
                }
            } else if (count($coupon->get_excluded_product_ids()) > 0) {
                if (!in_array($id_check_coupon, $coupon->get_excluded_product_ids())) {
                    $coupon_product_item[] = $coupon->get_code();
                    if ($coupon->get_discount_type() == 'percent') {
                        $discount_product_item += ($productPrice * $coupon->get_amount() * $item_remove['quantity']) / 100;
                    } else {
                        $discount_product_item += $coupon->get_amount() * $item_remove['quantity'];
                    }
                } else {
                    $product_exclude_coupon = true;
                }
            }
        }

        if (is_array($categories) || is_object($categories)) {
            foreach ($categories as $cat => $val) {
                if ($cat === 0) {
                    $item_remove_GA4['item_category'] = $val->name;
                } else {
                    $item_remove_GA4['item_category' . $cat . ''] = $val->name;
                }
                foreach ($coupon_product_level as $coupon) {
                    if (count($coupon->get_product_categories()) > 0) {
                        if (in_array($val->term_id, $coupon->get_product_categories())) {
                            if (!$product_exclude_coupon) {
                                if (is_array($coupon_product_item) && !in_array($coupon->get_code(), $coupon_product_item)) {
                                    $coupon_product_item[] = $coupon->get_code();
                                    if ($coupon->get_discount_type() == 'percent') {
                                        $discount_product_item += ($productPrice * $coupon->get_amount() * $item_remove['quantity']) / 100;
                                    } else {
                                        $discount_product_item += $coupon->get_amount() * $item_remove['quantity'];
                                    }
                                }
                            }
                        }
                    } else if (count($coupon->get_excluded_product_categories()) > 0) {
                        if (!in_array($val->term_id, $coupon->get_excluded_product_categories())) {
                            if (!$product_exclude_coupon) {
                                if (is_array($coupon_product_item) && !in_array($coupon->get_code(), $coupon_product_item)) {
                                    $coupon_product_item[] = $coupon->get_code();
                                    if ($coupon->get_discount_type() == 'percent') {
                                        $discount_product_item += ($productPrice * $coupon->get_amount() * $item_remove['quantity']) / 100;
                                    } else {
                                        $discount_product_item += $coupon->get_amount() * $item_remove['quantity'];
                                    }
                                }
                            }
                        } else {
                            if (($key = array_search($coupon->get_code(), $coupon_product_item)) !== false) {
                                unset($coupon_product_item[$key]);
                            }
                            if ($coupon->get_discount_type() == 'percent') {
                                $discount_product_item -= ($productPrice * $coupon->get_amount() * $item_remove['quantity']) / 100;
                            } else {
                                $discount_product_item -= $coupon->get_amount() * $item_remove['quantity'];
                            }
                        }
                    }
                }
            }
        }

        foreach ($coupon_product_level as $coupon) {
            if (count($coupon->get_product_ids()) < 1 && count($coupon->get_excluded_product_ids()) < 1 &&  count($coupon->get_product_categories()) < 1 && count($coupon->get_excluded_product_categories()) < 1) {
                $coupon_product_item[] = $coupon->get_code();
                if ($coupon->get_discount_type() == 'percent') {
                    $discount_product_item += ($productPrice * $coupon->get_amount() * $item_remove['quantity']) / 100;
                } else {
                    $discount_product_item += $coupon->get_amount() * $item_remove['quantity'];
                }
            }
        }

        $item_remove_GA4['coupon'] = implode(", ", $coupon_product_item);

        $value_remove_cart = $productPrice * $item_remove['quantity'];

        if ($discount_product_item > 0) {
            $item_remove_GA4['discount'] = $discount_product_item;
            $value_remove_cart -= $discount_product_item;
        }

        echo json_encode(array('itemRemoveGA4' => $item_remove_GA4, 'valueRemoveCart' => $value_remove_cart));

        die();
    }
}

add_action('wp_ajax_ll_datalayer_update_cart_query', 'll_datalayer_update_cart_query');
add_action('wp_ajax_nopriv_ll_datalayer_update_cart_query', 'll_datalayer_update_cart_query');

function ll_datalayer_update_cart_query()
{
    global $woocommerce;

    $arrayItems = array();
    $items = $woocommerce->cart->get_cart();
    $total_price = $woocommerce->cart->total;

    $coupon_product_level = [];

    foreach ($woocommerce->cart->get_coupons() as $coupon) {
        if ($coupon->get_discount_type() !== 'fixed_cart') {
            $coupon_product_level[] = $coupon;
        }
    }

    $index = 1;
    foreach ($items as $item => $values) {
        // Product ID

        $discount_product_item = 0;
        $product_exclude_coupon = false;
        $coupon_product_item = [];
        $price = 0;
        $price_from_woobt = [];

        $productId = $values['data']->get_id(); // if product is variation product, productId will be variationId

        $product = wc_get_product($productId);

        $categories = get_the_terms($values['product_id'], 'product_cat');

        $variantItems = ($values['variation_id']) ? wc_get_product($values['variation_id']) : '';

        $variantName = ($values['variation_id']) ? $variantItems->name : '';

        $price_fixed = $values['line_subtotal'] / $values['quantity'];

        $current_currency = get_option('woocommerce_currency');

        $price_from_woobt = $values['woobt_price_item'];

        // Support plugin WCML Multi Currency
        if (class_exists('WCML_Multi_Currency') && empty($price_from_woobt)) {
            global $woocommerce_wpml;
            $current_currency = $woocommerce_wpml->multi_currency->get_client_currency();
            $price = $woocommerce_wpml->multi_currency->prices->convert_price_amount($price_fixed, $current_currency);
        }

        !empty($price_from_woobt) && $price = $price_from_woobt;

        $rounded_price = round($price, 2);

        $productPrice = ($values['variation_id']) ? $variantItems->price : $rounded_price;

        $item_GA4 = array(
            'item_id' => $productId,
            'item_name' => $product->get_title(),
            'quantity' => $values['quantity'],
            'price' => $productPrice,
            'item_variant' => $variantName,
            'currency' => $current_currency,
            'index' => $index,
        );

        $id_check_coupon = $values['variation_id'] ? $values['variation_id'] : $values['product_id'];

        // Check productId in coupon
        foreach ($coupon_product_level as $coupon) {
            if (count($coupon->get_product_ids()) > 0) {
                if (in_array($id_check_coupon, $coupon->get_product_ids())) {
                    $coupon_product_item[] = $coupon->get_code();
                    if ($coupon->get_discount_type() == 'percent') {
                        $discount_product_item += ($productPrice * $coupon->get_amount()) / 100;
                    } else {
                        $discount_product_item += $coupon->get_amount();
                    }
                }
            } else if (count($coupon->get_excluded_product_ids()) > 0) {
                if (!in_array($id_check_coupon, $coupon->get_excluded_product_ids())) {
                    $coupon_product_item[] = $coupon->get_code();
                    if ($coupon->get_discount_type() == 'percent') {
                        $discount_product_item += ($productPrice * $coupon->get_amount()) / 100;
                    } else {
                        $discount_product_item += $coupon->get_amount();
                    }
                } else {
                    $product_exclude_coupon = true;
                }
            }
        }

        // Check categoryId in coupon
        if (is_array($categories) || is_object($categories)) {
            foreach ($categories as $cat => $val) {
                if ($cat === 0) {
                    $item_GA4['item_category'] = $val->name;
                } else {
                    $item_GA4['item_category' . $cat . ''] = $val->name;
                }
                foreach ($coupon_product_level as $coupon) {
                    if (count($coupon->get_product_categories()) > 0) {
                        if (in_array($val->term_id, $coupon->get_product_categories())) {
                            if (!$product_exclude_coupon) {
                                if (is_array($coupon_product_item) && !in_array($coupon->get_code(), $coupon_product_item)) {
                                    $coupon_product_item[] = $coupon->get_code();
                                    if ($coupon->get_discount_type() == 'percent') {
                                        $discount_product_item += ($productPrice * $coupon->get_amount()) / 100;
                                    } else {
                                        $discount_product_item += $coupon->get_amount();
                                    }
                                }
                            }
                        }
                    } else if (count($coupon->get_excluded_product_categories()) > 0) {
                        if (!in_array($val->term_id, $coupon->get_excluded_product_categories())) {
                            if (!$product_exclude_coupon) {
                                if (is_array($coupon_product_item) && !in_array($coupon->get_code(), $coupon_product_item)) {
                                    $coupon_product_item[] = $coupon->get_code();
                                    if ($coupon->get_discount_type() == 'percent') {
                                        $discount_product_item += ($productPrice * $coupon->get_amount()) / 100;
                                    } else {
                                        $discount_product_item += $coupon->get_amount();
                                    }
                                }
                            }
                        } else {
                            if (($key = array_search($coupon->get_code(), $coupon_product_item)) !== false) {
                                unset($coupon_product_item[$key]);
                            }
                            if ($coupon->get_discount_type() == 'percent') {
                                $discount_product_item -= ($productPrice * $coupon->get_amount()) / 100;
                            } else {
                                $discount_product_item -= $coupon->get_amount();
                            }
                        }
                    }
                }
            }
        }

        foreach ($coupon_product_level as $coupon) {
            if (count($coupon->get_product_ids()) < 1 && count($coupon->get_excluded_product_ids()) < 1 &&  count($coupon->get_product_categories()) < 1 && count($coupon->get_excluded_product_categories()) < 1) {
                $coupon_product_item[] = $coupon->get_code();
                if ($coupon->get_discount_type() == 'percent') {
                    $discount_product_item += ($productPrice * $coupon->get_amount()) / 100;
                } else {
                    $discount_product_item += $coupon->get_amount();
                }
            }
        }

        $item_GA4['coupon'] = implode(", ", $coupon_product_item);

        if ($discount_product_item > 0) {
            $item_GA4['discount'] = $discount_product_item;
        }

        $index++;
        array_push($arrayItems, array('quantity' => $values['quantity'], 'key' => $item, 'itemGA4' => $item_GA4));
    }

    echo json_encode(array('arrayItems' => $arrayItems));

    die();
}
if (!function_exists('write_log')) {

    function write_log($log)
    {
        if (true === WP_DEBUG) {
            if (is_array($log) || is_object($log)) {
                error_log(print_r($log, true));
            } else {
                error_log($log);
            }
        }
    }
}
