<?php
if (!defined('ABSPATH')) {
    exit;
}

class LL_Datalayer_Tracking_JS
{
    /** @var object Class Instance */
    private static $instance;

    /** @var array Inherited Analytics options */
    private static $options;

    // currency
    private $current_currency;

    /**
     * Get the class instance
     */
    public static function get_instance($options = array())
    {
        return null === self::$instance ? (self::$instance = new self($options)) : self::$instance;
    }

    /**
     * Constructor
     * Takes our options from the parent class so we can later use them in the JS snippets
     */
    public function __construct($options = array())
    {
        self::$options = $options;
        $this->current_currency = $this->get_current_currency();
        $this->check_redirect_to_cart_setting();
    }

    // Function to check the WooCommerce "Redirect to Cart" setting
    private function check_redirect_to_cart_setting() {
        $this->redirect_enabled = (get_option('woocommerce_cart_redirect_after_add') === 'yes');
    }

    // get currency | support plugin WooCommerce Multilingual & Multicurrency
    private function get_current_currency()
    {
        if (class_exists('WCML_Multi_Currency')) {
            global $woocommerce_wpml;
            $current_currency = $woocommerce_wpml->multi_currency->get_client_currency();
            write_log("currency from multi price: " . $current_currency);
        } else {
            $current_currency = get_option('woocommerce_currency');
            write_log("currency from default: " . $current_currency);
        }
        write_log("current currency: " . $current_currency);
        return $current_currency;
    }

    // get product price by currency | support plugin WooCommerce Multilingual & Multicurrency
    public function get_product_price_in_current_currency($product_id, $fixed_price)
    {
        $fixed_prices = 0;
        if (!empty($fixed_price)) {
            $fixed_prices = $fixed_price;
        }
        $product = wc_get_product($product_id);

        if (!$product) {
            return null;
        }

        if (class_exists('WCML_Multi_Currency')) {
            global $woocommerce_wpml;
            $price = $woocommerce_wpml->multi_currency->prices->convert_price_amount($product->get_price(), $this->current_currency);

            // Support plugin WPC bought together
            if ($price != $fixed_prices && $fixed_prices > 0 && class_exists('WPCleverWoobt')) {
                $price = $fixed_prices;
            }

            write_log("price multi:" . $price);
        } else {
            $price = $product->get_price();
            write_log("price default:" . $price);
        }
        write_log("price currently:" . $price);
        return $price;
    }


    private static function product_get_category_line($_product)
    {
        $out            = array();
        $variation_data = version_compare(WC_VERSION, '3.0', '<') ? $_product->variation_data : ($_product->is_type('variation') ? wc_get_product_variation_attributes($_product->get_id()) : '');
        $categories     = get_the_terms($_product->get_id(), 'product_cat');

        if (is_array($variation_data) && !empty($variation_data)) {
            $parent_product = wc_get_product(version_compare(WC_VERSION, '3.0', '<') ? $_product->parent->id : $_product->get_parent_id());
            $categories = get_the_terms($parent_product->get_id(), 'product_cat');
        }

        if ($categories) {
            foreach ($categories as $category) {
                $out[] = $category->name;
            }
        }

        return "'" . esc_js(join("/", $out)) . "',";
    }

    function ldtjs_view_list_item()
    {
        global $posts;

        $args = array(
            'post_type'          => 'product',
            'post_status'          => 'publish',
        );


        // Check if it's the product categories archive page
        if (is_product_category()) {
            // Get the current queried object
            $current_category = get_queried_object();

            // Check if the queried object is a category
            if ($current_category instanceof WP_Term && $current_category->taxonomy === 'product_cat') {
                // Retrieve the category slug
                $category_slug = $current_category->slug;

                $category = get_term_by('slug', $category_slug, 'product_cat');

                $args = array(
                    'post_type' => 'product',
                    'post_status' => 'publish',
                    'tax_query' => array(
                        array(
                            'taxonomy' => 'product_cat',
                            'field' => 'term_id',
                            'terms' => $category->term_id,
                            'include_children' => true,
                        ),
                    ),
                );
            }
        }

        $products_query = new WP_Query($args);
        $products = $products_query->posts;

        if ($posts[0]->post_type == 'product') {
            $products = $posts;
        }

        $list_name = woocommerce_page_title(false);

        $data_items = "'items': [";
        $index = 1;

        foreach ($products as $product) {
            $product_id = $product->ID;
            $product = wc_get_product($product_id);
            if ($product->get_type() == 'variable') {
                $variations = $product->get_available_variations();
                foreach ($variations as $variation => $val) {
                    $variation_product =  wc_get_product($val['variation_id']);
                    !isset($item_variant) && $item_variant = '';
                    if ($variation === 0) {
                        $item_variant .= "'item_variant': '" . $variation_product->name . "',";
                    } else {
                        $item_variant .= "'item_variant" . $variation . "': '" . $variation_product->name . "',";
                    }
                }
            }

            $categories = get_the_terms($product_id, 'product_cat');
            if (is_array($categories) || is_object($categories)) {
                !isset($item_category) && $item_category = '';
                foreach ($categories as $cat => $val) {
                    if ($cat === 0) {
                        $item_category .= "'item_category': '" . $val->name . "',";
                    } else {
                        $item_category .= "'item_category" . $cat . "': '" . $val->name . "',";
                    }
                }
            }

            $data_items .= "{
                'item_id': '" . $product_id . "',
                'item_name': `" . $product->get_title() . "`,
                'quantity': '" . $product->get_min_purchase_quantity() . "',
                'price': '" . $product->get_price() . "',
                'currency': '" . $this->current_currency . "',
                'item_list_name': '" . $list_name . "',
                'index': '" . $index . "', ";
            $data_items .= !empty($item_category) ? $item_category : null;
            $data_items .= !empty($item_variant) ? $item_variant : null;

            unset($item_category);
            unset($item_variant);

            $data_items .= "},";
            $index++;
        }

        $data_items .= "]";

        wc_enqueue_js("
            if (window.localStorage.getItem('DevLLDebug')) {
                console.log('WOO DEBUG: - view_item_list -', JSON.stringify(" . json_encode($products) . "));
            }
            
            window.viewItemListEvent = {
                'data': {
                    'item_list_name': '" . $list_name . "',
                    " . $data_items . "
                }   
            };

            window.postMessage(
                JSON.stringify({
                    'source': 'screen-one-woocommerce-tracking', 
                    'action': 'view_item_list',
                    'data': {
                        'item_list_name': '" . $list_name . "',
                        " . $data_items . "
                    }
                }),'*'
            );
        ");
    }

    function ldtjs_select_item($product)
    {

        if ($_SERVER['HTTP_REFERER']) {
            if (empty($product)) {
                return;
            }

            !isset($item_category) && $item_category = '';
            $url_referer = $_SERVER['HTTP_REFERER'];

            if ($product->get_related()) {
                foreach ($product->get_related() as $product_id) {
                    if (get_permalink($product_id) == $url_referer) {
                        $item_list = "'item_list_id': 'related_products',
                                    'item_list_name': 'Related products',";
                        break;
                    }
                }
            }

            $categories = get_the_terms($product->get_id(), 'product_cat');

            if (is_array($categories) || is_object($categories)) {
                foreach ($categories as $cat => $val) {
                    if ($cat === 0) {
                        $item_category .= "'item_category': '" . $val->name . "',";
                    } else {
                        $item_category .= "'item_category" . $cat . "': '" . $val->name . "',";
                    }
                }
            }
            !isset($item_variant) && $item_variant = '';
            !isset($item_list) && $item_list = '';

            if ($product->get_type() == 'variable') {
                $variations = $product->get_available_variations();

                foreach ($variations as $variation => $val) {
                    $variation_product =  wc_get_product($val['variation_id']);
                    if ($variation === 0 && !empty($variation_product->name)) {
                        $item_variant .= "'item_variant': '" . $variation_product->name . "',";
                    } else {
                        $item_variant .= "'item_variant" . $variation . "': '" . $variation_product->name . "',";
                    }
                }
            }

            wc_enqueue_js("
                if (window.localStorage.getItem('DevLLDebug')) {
                    console.log('WOO DEBUG: - selectItemEvent -', JSON.stringify(" . json_encode($product) . "));
                }
                window.selectItemEvent = {
                    'data': {
                        " . $item_list . "
                        'items': [{
                            'item_id': '" . $product->get_id() . "',     
                            'item_name' : `" . $product->get_title() . "`,
                            'price': '" . $product->get_price() . "',
                            'quantity': '" . $product->get_min_purchase_quantity() . "',
                            'currency': '" . $this->current_currency . "',
                            'index': '1',
                            " . $item_category . "
                            " . $item_variant . "
                            " . $item_list . "
                        }] 
                    }   
                };

                window.postMessage(
                    JSON.stringify({
                        'source': 'screen-one-woocommerce-tracking', 
                        'action': 'select_item',
                        'data': {
                            " . $item_list . "
                            'items': [{
                                'item_id': '" . $product->get_id() . "',     
                                'item_name' : `" . $product->get_title() . "`,
                                'price': '" . $product->get_price() . "',
                                'currency': '" . $this->current_currency . "',
                                'quantity': '" . $product->get_min_purchase_quantity() . "',
                                'index': '1',
                                " . $item_category . "
                                " . $item_variant . "
                                " . $item_list . "
                            }] 
                        }
                    }),'*'
                );
            ");
        }
    }

    /**
     * Tracks a product detail view
     */
    function ldtjs_product_detail($product)
    {
        if (empty($product)) {
            return;
        }

        $categories = get_the_terms($product->get_id(), 'product_cat');
        $item_category = '';
        $item_variant = '';

        if (is_array($categories) || is_object($categories)) {
            if (!empty($categories)) {
                foreach ($categories as $cat => $val) {
                    if ($cat === 0) {
                        $item_category .= "'item_category': '" . $val->name . "',";
                    } else {
                        $item_category .= "'item_category" . $cat . "': '" . $val->name . "',";
                    }
                }
            }
        }

        if ($product->get_type() == 'variable') {
            $variations = $product->get_available_variations();
            foreach ($variations as $variation => $val) {
                $variation_product =  wc_get_product($val['variation_id']);
                if ($variation === 0) {
                    $item_variant .= "'item_variant': '" . $variation_product->name . "',";
                } else {
                    $item_variant .= "'item_variant" . $variation . "': '" . $variation_product->name . "',";
                }
            }
        }

        wc_enqueue_js("
            if (window.localStorage.getItem('DevLLDebug')) {
                console.log('WOO DEBUG: - viewItemEvent -', JSON.stringify(" . json_encode($product) . "));
            }
            window.viewItemEvent = {
                'data': {
                    'currency': '" . $this->current_currency . "',
                    'value': '" . $this->get_product_price_in_current_currency($product->get_id(), $product->get_price()) . "',
                    'items': [{
                        'item_id': '" . $product->get_id() . "',
                        'item_name' : `" . $product->get_title() . "`,
                        'price': '" . $product->get_price() . "',
                        'quantity': '" . $product->get_min_purchase_quantity() . "',
                        'currency': '" . $this->current_currency . "',
                        'index': '1',
                        " . $item_category . "
                        " . $item_variant . "
                    }]
                }   
            };

            window.postMessage(
                JSON.stringify({
                    'source': 'screen-one-woocommerce-tracking', 
                    'action': 'view_item',
                    'data': {
                        'currency': '" . $this->current_currency . "',
                        'value': '" . $this->get_product_price_in_current_currency($product->get_id(), $product->get_price()) . "',
                        'items': [{
                            'item_id': '" . $product->get_id() . "',
                            'item_name' : `" . $product->get_title() . "`,
                            'price': '" . $product->get_price() . "',
                            'quantity': '" . $product->get_min_purchase_quantity() . "',
                            'currency': '" . $this->current_currency . "',
                            " . $item_category . "
                            " . $item_variant . "
                        }]
                    }
                }),'*'
            );
        ");

        if ($product->get_related()) {
            unset($item_variant);
            $data_items = "'items': [";
            $index = 1;
            $products = $product->get_related();

            foreach ($products as $product_id) {

                $product_related = wc_get_product($product_id);

                // $variations = $product->get_available_variations();

                if ($product_related->get_type() == 'variable') {
                    $variations = $product_related->get_available_variations();
                    if (!isset($item_variant)) {
                        $item_variant = '';
                    }
                    foreach ($variations as $variation => $val) {
                        $variation_product =  wc_get_product($val['variation_id']);
                        if ($variation === 0) {
                            $item_variant .= "'item_variant': '" . $variation_product->name . "',";
                        } else {
                            $item_variant .= "'item_variant" . $variation . "': '" . $variation_product->name . "',";
                        }
                    }
                }

                $categories = get_the_terms($product_id, 'product_cat');

                if (is_array($categories) || is_object($categories)) {
                    if (!empty($categories)) {
                        !isset($item_category) && $item_category = '';
                        foreach ($categories as $cat => $val) {
                            if ($cat === 0 && !empty($val->name)) {
                                $item_category .= "'item_category': '" . $val->name . "',";
                            } else {
                                $item_category .= "'item_category" . $cat . "': '" . $val->name . "',";
                            }
                        }
                    }
                }

                $data_items .= "{
                'item_id': '" . $product_id . "',
                'item_name': `" . $product_related->get_title() . "`,
                'quantity': '" . $product_related->get_min_purchase_quantity() . "',
                'currency': '" . $this->current_currency . "',
                'price': '" . $product_related->get_price() . "',
                'item_list_id': 'related_products',
                'item_list_name': 'Related products',
                'index': '" . $index . "', ";
                $data_items .= !empty($item_category) ? $item_category : null;
                $data_items .= !empty($item_variant) ? $item_variant : null;

                unset($item_category);
                unset($item_variant);

                $data_items .= "},";
                $index++;
            }

            $data_items .= "]";

            wc_enqueue_js("
                if (window.localStorage.getItem('DevLLDebug')) {
                    console.log('WOO DEBUG: - view_item_list(related) -', JSON.stringify(" . json_encode($products) . "));
                }

                window.viewItemListEvent = {
                    'data': {
                        'item_list_id': 'related_products',
                        'item_list_name': 'Related products',
                        " . $data_items . "
                    }   
                };

                window.postMessage(
                    JSON.stringify({
                        'source': 'screen-one-woocommerce-tracking', 
                        'action': 'view_item_list',
                        'data': {
                            'item_list_id': 'related_products',
                            'item_list_name': 'Related products',
                            " . $data_items . "
                        }
                    }),'*'
                );
            ");
        }
    }

    /*
    * tracking event add to cart if redirect
    * page: viewCart
    */
    function trackingAddToCart($products) {
        if ($this->redirect_enabled) {
            if (is_cart()) {
                $productAddToCart = $_SESSION['llproductAddToCart'];

                if (isset($productAddToCart)) {
                    foreach ($productAddToCart['items'] as $key => $item) {
                        foreach ($products as $keyProduct => $product) {
                            if ($item['item_id'] === $product['product_id'] && isset($product['woobt_parent_id'])) {

                                $variantItems = ($product['variation_id']) ? wc_get_product($product['variation_id']) : null;

                                $fixed_price = !empty($product['data']) && !empty($product['data']->get_price()) && $product['data']->get_price() > 0 ? $product['data']->get_price() : 0;

                                $productPrice = ($product['variation_id']) ? $variantItems->price : $this->get_product_price_in_current_currency($product['product_id'], $fixed_price);

                                $productAddToCart['items'][$key]['price'] = $productPrice;
                                break;
                            }
                        }
                    }

                    $ldt_addToCartData = "
                        if (window.localStorage.getItem('DevLLDebug')) {
                            console.log('WOO DEBUG: - addToCart -', trackingAddToCart);
                        }
                        const productAddCart = " . json_encode($productAddToCart) . ";

                        itemAddToCartGA4 = [...itemAddToCartGA4, ...productAddCart['items']];

                        valueAddToCartGA4 = 0;
                        productAddCart['items'].forEach((item) => {
                            valueAddToCartGA4 += item.price * item.quantity;
                        })

                        const trackingAddToCart = {
                            'value': Number(valueAddToCartGA4.toFixed(10)),
                            'currency' : '" . $this->current_currency . "',
                            items: itemAddToCartGA4
                        };
                        
                        window.addToCartEvent = {
                            'data': trackingAddToCart
                        };

                        window.postMessage(
                            JSON.stringify({
                                'source': 'screen-one-woocommerce-tracking', 
                                'action': 'add_single_product_to_cart',
                                'data': trackingAddToCart
                            }
                        ),'*')
                    ";

                    unset($_SESSION['llproductAddToCart']);
                    unset($_SESSION['ltd_flag_redirect_addtocart']);
                    wc_enqueue_js($ldt_addToCartData);
                }
            }
        }
    }

    /**
     * Tracks view cart
     */
    function ldtjs_view_cart($woocommerce)
    {
        if (empty($woocommerce)) return;

        $items = $woocommerce->cart->get_cart();

        // call function fire AddToCart
        $this->trackingAddToCart($items);

        $subtotal_price = $woocommerce->cart->subtotal;
        $total_price = $woocommerce->cart->total;

        $shipping_price = '';
        if ($woocommerce->cart->get_shipping_total() > 0) {
            $shipping_price = $woocommerce->cart->get_shipping_total();
        }

        $tax_price = '';

        if ($woocommerce->cart->get_total_tax()) {
            $tax_price = $woocommerce->cart->get_total_tax();
        }

        $coupon_cart_level = [];
        $coupon_product_level = [];
        $discount_cart_level = 0;
        $coupons = [];
        $discount = $woocommerce->cart->get_discount_total();

        foreach ($woocommerce->cart->get_coupons() as $coupon) {
            $coupons[] = $coupon->get_code();
            if ($coupon->get_discount_type() == 'fixed_cart') {
                $coupon_cart_level[] = $coupon->get_code();
                $discount_cart_level += $coupon->get_amount();
            } else {
                $coupon_product_level[] = $coupon;
            }
        }

        $index = 1;
        $data_items = "'items': [";

        foreach ($items as $item => $values) {

            // Product ID
            $discount_product_item = 0;

            $coupon_product_item = [];

            $product_exclude_coupon = false;

            $productId = $values['data']->get_id(); // if product is variation product, productId will be variationId

            $product = wc_get_product($productId);

            $categories = get_the_terms($values['product_id'], 'product_cat');

            $variantItems = ($values['variation_id']) ? wc_get_product($values['variation_id']) : null;

            $variantName = ($values['variation_id']) ? $variantItems->name : '';

            $fixed_price = !empty($values['data']) && !empty($values['data']->get_price()) && $values['data']->get_price() > 0 ? $values['data']->get_price() : 0;

            $productPrice = ($values['variation_id']) ? $variantItems->price : $this->get_product_price_in_current_currency($product->get_id(), $fixed_price);

            $id_check_coupon = $values['variation_id'] ? $values['variation_id'] : $values['product_id'];

            // Check productId in coupon
            foreach ($coupon_product_level as $coupon) {
                if (count($coupon->get_product_ids()) > 0) {
                    if (in_array($id_check_coupon, $coupon->get_product_ids())) {
                        $coupon_product_item[] = $coupon->get_code();
                        if ($coupon->get_discount_type() == 'percent') {
                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                        } else {
                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
                        }
                    }
                } else if (count($coupon->get_excluded_product_ids()) > 0) {
                    if (!in_array($id_check_coupon, $coupon->get_excluded_product_ids())) {
                        $coupon_product_item[] = $coupon->get_code();
                        if ($coupon->get_discount_type() == 'percent') {
                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                        } else {
                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
                        }
                    } else {
                        $product_exclude_coupon = true;
                    }
                }
            }

            // Check categoryId in coupon
            if (is_array($categories) || is_object($categories)) {
                $item_category = '';

                foreach ($categories as $cat => $val) {
                    if ($cat === 0) {
                        $item_category .= "'item_category': '" . $val->name . "',";
                    } else {
                        $item_category .= "'item_category" . $cat . "': '" . $val->name . "',";
                    }
                    foreach ($coupon_product_level as $coupon) {
                        if (count($coupon->get_product_categories()) > 0) {
                            if (in_array($val->term_id, $coupon->get_product_categories())) {
                                if (!$product_exclude_coupon) {
                                    if (is_array($coupon_product_item) && !in_array($coupon->get_code(), $coupon_product_item)) {
                                        $coupon_product_item[] = $coupon->get_code();
                                        if ($coupon->get_discount_type() == 'percent') {
                                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                                        } else {
                                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
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
                                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                                        } else {
                                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
                                        }
                                    }
                                }
                            } else {
                                if (($key = array_search($coupon->get_code(), $coupon_product_item)) !== false) {
                                    unset($coupon_product_item[$key]);
                                }
                                if ($coupon->get_discount_type() == 'percent') {
                                    $discount_product_item -= ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                                } else {
                                    $discount_product_item -= $coupon->get_amount() * $values['quantity'];
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
                        $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                    } else {
                        $discount_product_item += $coupon->get_amount() * $values['quantity'];
                    }
                }
            }

            $discount_product_item_data = $discount_product_item > 0 ? "'discount': '" . $discount_product_item . "'," : '';

            $data_items .= "{
                'item_id': '" . $productId . "',
                'item_name': `" . $product->get_title() . "`,
                'quantity': '" . $values['quantity'] . "',
                'price': '" . $productPrice . "',
                'coupon': '" . implode(", ", $coupon_product_item) . "',
                'currency' : '" . $this->current_currency . "',
                " . $discount_product_item_data . "
                'item_variant': '" . $variantName . "',
                'index': '" . $index . "', ";
            $data_items .= $item_category;

            unset($item_category);
            $data_items .= "},";

            $index++;
        }

        $data_items .= "]";

        $discount_data = $discount > 0 ? "'discount': '" . $discount . "'," : '';
        $discount_cart_level_data = $discount_cart_level > 0 ? "'cart_level_discount': '" . $discount_cart_level . "'," : '';

        wc_enqueue_js("
            if (window.localStorage.getItem('DevLLDebug')) {
                console.log('WOO DEBUG: - view_cart -', JSON.stringify(" . json_encode($items) . "));
            }

            window.viewCartEvent = {
                'data': {
                    " . $data_items . ",
                    'currency' : '" . $this->current_currency . "',
                    'coupon': '" . implode(", ",  $coupons) . "',
                    " . $discount_data . "
                    'value': '" . $total_price . "',
                    'cart_level_coupon': '" . implode(", ", $coupon_cart_level) . "',
                    'shipping' : '" . $shipping_price . "',
                    'tax': '" . $tax_price . "',
                    " . $discount_cart_level_data . "
                }   
            };

            window.postMessage(
                JSON.stringify({
                    'source': 'screen-one-woocommerce-tracking', 
                    'action': 'view_cart',
                    'data': {   " . $data_items . ",
                                'currency' : '" . $this->current_currency . "',
                                'coupon': '" . implode(", ",  $coupons) . "',
                                " . $discount_data . "
                                'value': '" . $total_price . "',
                                'cart_level_coupon': '" . implode(", ", $coupon_cart_level) . "',
                                'shipping' : '" . $shipping_price . "',
                                'tax': '" . $tax_price . "',
                                " . $discount_cart_level_data . "
                            }}),'*')
        ");
    }

    /**
     * Tracks view checkout
     */
    function ldtjs_view_checkout($fields, $items)
    {
        global $woocommerce;

        if (empty($fields)) return;

        $subtotal_price = $woocommerce->cart->subtotal;
        $total_price = $woocommerce->cart->total;

        $coupon_cart_level = [];
        $coupon_product_level = [];
        $discount_cart_level = 0;
        $coupons = [];
        $discount = $woocommerce->cart->get_discount_total();

        foreach ($woocommerce->cart->get_coupons() as $coupon) {
            $coupons[] = $coupon->get_code();
            if ($coupon->get_discount_type() == 'fixed_cart') {
                $coupon_cart_level[] = $coupon->get_code();
                $discount_cart_level += $coupon->get_amount();
            } else {
                $coupon_product_level[] = $coupon;
            }
        }

        $tax_price = '';
        if ($woocommerce->cart->get_total_tax()) {
            $tax_price = $woocommerce->cart->get_total_tax();
        }

        $index = 1;

        $data_fields = "'items': [";

        foreach ($items as $item => $values) {
            // Product ID

            $discount_product_item = 0;

            $coupon_product_item = [];

            $product_exclude_coupon = false;

            $productId = $values['data']->get_id(); // if product is variation product, productId will be variationId

            $product =  wc_get_product($productId);

            $categories = get_the_terms($values['product_id'], 'product_cat');

            $variantItems = ($values['variation_id']) ? wc_get_product($values['variation_id']) : '';

            $variantName = ($values['variation_id']) ? $variantItems->name : '';

            $fixed_price = !empty($values['data']) && !empty($values['data']->get_price()) && $values['data']->get_price() > 0 ? $values['data']->get_price() : 0;

            $productPrice = ($values['variation_id']) ? $variantItems->price : $this->get_product_price_in_current_currency($product->get_id(), $fixed_price);

            $id_check_coupon = $values['variation_id'] ? $values['variation_id'] : $values['product_id'];

            // $productSalePrice = ($values['variation_id']) ? $variantItems->sale_price : $product->get_sale_price();

            // Check productId in coupon
            foreach ($coupon_product_level as $coupon) {
                if (count($coupon->get_product_ids()) > 0) {
                    if (in_array($id_check_coupon, $coupon->get_product_ids())) {
                        $coupon_product_item[] = $coupon->get_code();
                        if ($coupon->get_discount_type() == 'percent') {
                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                        } else {
                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
                        }
                    }
                } else if (count($coupon->get_excluded_product_ids()) > 0) {
                    if (!in_array($id_check_coupon, $coupon->get_excluded_product_ids())) {
                        $coupon_product_item[] = $coupon->get_code();
                        if ($coupon->get_discount_type() == 'percent') {
                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                        } else {
                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
                        }
                    } else {
                        $product_exclude_coupon = true;
                    }
                }
            }

            // Check categoryId in coupon
            if (is_array($categories) || is_object($categories)) {
                $item_category = '';
                foreach ($categories as $cat => $val) {
                    if ($cat === 0) {
                        $item_category .= "'item_category': '" . $val->name . "',";
                    } else {
                        $item_category .= "'item_category" . $cat . "': '" . $val->name . "',";
                    }
                    foreach ($coupon_product_level as $coupon) {
                        if (count($coupon->get_product_categories()) > 0) {
                            if (in_array($val->term_id, $coupon->get_product_categories())) {
                                if (!$product_exclude_coupon) {
                                    if (is_array($coupon_product_item) && !in_array($coupon->get_code(), $coupon_product_item)) {
                                        $coupon_product_item[] = $coupon->get_code();
                                        if ($coupon->get_discount_type() == 'percent') {
                                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                                        } else {
                                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
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
                                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                                        } else {
                                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
                                        }
                                    }
                                }
                            } else {
                                if (($key = array_search($coupon->get_code(), $coupon_product_item)) !== false) {
                                    unset($coupon_product_item[$key]);
                                }
                                if ($coupon->get_discount_type() == 'percent') {
                                    $discount_product_item -= ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                                } else {
                                    $discount_product_item -= $coupon->get_amount() * $values['quantity'];
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
                        $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                    } else {
                        $discount_product_item += $coupon->get_amount() * $values['quantity'];
                    }
                }
            }

            $discount_product_item_data = $discount_product_item > 0 ? "'discount': '" . $discount_product_item . "'," : '';

            $data_fields .= "{
                'item_id': '" . $productId . "',
                'item_name': `" . $product->get_title() . "`,
                'quantity': '" . $values['quantity'] . "',
                'price': '" . $productPrice . "',
                'coupon': '" . implode(", ", $coupon_product_item) . "',
                " . $discount_product_item_data . "
                'index': '" . $index . "', 
                'item_variant': '" . $variantName . "',
                'currency' : '" . $this->current_currency . "',";

            $data_fields .= $item_category;

            unset($item_category);

            $data_fields .= "},";
            $index++;
        }

        $data_fields .= "]";

        $discount_data = $discount > 0 ? "'discount': '" . $discount . "'," : '';
        $discount_cart_level_data = $discount_cart_level > 0 ? "'cart_level_discount': '" . $discount_cart_level . "'," : '';

        wc_enqueue_js("
            if (window.localStorage.getItem('DevLLDebug')) {
                console.log('WOO DEBUG: - view_checkout -', JSON.stringify(" . json_encode($items) . "));
            }
            window.viewCheckoutEvent = {
                'data': {
                    " . $data_fields . ",
                    'currency' : '" . $this->current_currency . "',
                    'value' : '" . $total_price . "',
                    'coupon': '" . implode(", ",  $coupons) . "',
                    " . $discount_data . "
                    'cart_level_coupon': '" . implode(", ", $coupon_cart_level) . "',
                    'tax': '" . $tax_price . "',
                    " . $discount_cart_level_data . "
                }
            };

            window.postMessage(
                JSON.stringify({
                    'source': 'screen-one-woocommerce-tracking', 
                    'action': 'view_checkout',
                    'data': {
                        " . $data_fields . ",
                        'currency' : '" . $this->current_currency . "',
                        'value' : '" . $total_price . "',
                        'coupon': '" . implode(", ",  $coupons) . "',
                        " . $discount_data . "
                        'cart_level_coupon': '" . implode(", ", $coupon_cart_level) . "',
                        'tax': '" . $tax_price . "',
                        " . $discount_cart_level_data . "
                    }
                }),'*'
            );
        ");
    }

    function ldtjs_after_checkout_shipping_form()
    {

        global $woocommerce;

        $items = $woocommerce->cart->get_cart();

        $subtotal_price = $woocommerce->cart->subtotal;
        $total_price = $woocommerce->cart->total;

        $coupon_cart_level = [];
        $coupon_product_level = [];
        $discount_cart_level = 0;
        $coupons = [];
        $discount = $woocommerce->cart->get_discount_total();

        foreach ($woocommerce->cart->get_coupons() as $coupon) {
            $coupons[] = $coupon->get_code();
            if ($coupon->get_discount_type() == 'fixed_cart') {
                $coupon_cart_level[] = $coupon->get_code();
                $discount_cart_level += $coupon->get_amount();
            } else {
                $coupon_product_level[] = $coupon;
            }
        }


        $data_fields = "'items': [";
        $index = 1;
        foreach ($items as $item => $values) {
            // Product ID
            $discount_product_item = 0;

            $coupon_product_item = [];

            $product_exclude_coupon = false;

            $productId = $values['data']->get_id(); // if product is variation product, productId will be variationId

            $product =  wc_get_product($productId);

            $categories = get_the_terms($values['product_id'], 'product_cat');

            $variantItems = ($values['variation_id']) ? wc_get_product($values['variation_id']) : '';

            $variantName = ($values['variation_id']) ? $variantItems->name : '';

            $fixed_price = !empty($values['data']) && !empty($values['data']->get_price()) && $values['data']->get_price() > 0 ? $values['data']->get_price() : 0;

            $productPrice = ($values['variation_id']) ? $variantItems->price : $this->get_product_price_in_current_currency($product->get_id(), $fixed_price);

            $id_check_coupon = $values['variation_id'] ? $values['variation_id'] : $values['product_id'];

            // $productSalePrice = ($values['variation_id']) ? $variantItems->sale_price : $product->get_sale_price();

            // Check productId in coupon
            foreach ($coupon_product_level as $coupon) {
                if (count($coupon->get_product_ids()) > 0) {
                    if (in_array($id_check_coupon, $coupon->get_product_ids())) {
                        $coupon_product_item[] = $coupon->get_code();
                        if ($coupon->get_discount_type() == 'percent') {
                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                        } else {
                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
                        }
                    }
                } else if (count($coupon->get_excluded_product_ids()) > 0) {
                    if (!in_array($id_check_coupon, $coupon->get_excluded_product_ids())) {
                        $coupon_product_item[] = $coupon->get_code();
                        if ($coupon->get_discount_type() == 'percent') {
                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                        } else {
                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
                        }
                    } else {
                        $product_exclude_coupon = true;
                    }
                }
            }

            // Check categoryId in coupon
            if (is_array($categories) || is_object($categories)) {
                $item_category = '';

                foreach ($categories as $cat => $val) {
                    if ($cat === 0) {
                        $item_category .= "'item_category': '" . $val->name . "',";
                    } else {
                        $item_category .= "'item_category" . $cat . "': '" . $val->name . "',";
                    }
                    foreach ($coupon_product_level as $coupon) {
                        if (count($coupon->get_product_categories()) > 0) {
                            if (in_array($val->term_id, $coupon->get_product_categories())) {
                                if (!$product_exclude_coupon) {
                                    if (is_array($coupon_product_item) && !in_array($coupon->get_code(), $coupon_product_item)) {
                                        $coupon_product_item[] = $coupon->get_code();
                                        if ($coupon->get_discount_type() == 'percent') {
                                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                                        } else {
                                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
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
                                            $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                                        } else {
                                            $discount_product_item += $coupon->get_amount() * $values['quantity'];
                                        }
                                    }
                                }
                            } else {
                                if (($key = array_search($coupon->get_code(), $coupon_product_item)) !== false) {
                                    unset($coupon_product_item[$key]);
                                }
                                if ($coupon->get_discount_type() == 'percent') {
                                    $discount_product_item -= ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                                } else {
                                    $discount_product_item -= $coupon->get_amount() * $values['quantity'];
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
                        $discount_product_item += ($productPrice * $coupon->get_amount() * $values['quantity']) / 100;
                    } else {
                        $discount_product_item += $coupon->get_amount() * $values['quantity'];
                    }
                }
            }

            $discount_product_item_data = $discount_product_item > 0 ? "'discount': '" . $discount_product_item . "'," : '';

            $data_fields .= "{
                'item_id': '" . $productId . "',
                'item_name': `" . $product->get_title() . "`,
                'quantity': '" . $values['quantity'] . "',
                'currency' : '" . $this->current_currency . "',
                'price': '" . $productPrice . "',
                'coupon': '" . implode(", ", $coupon_product_item) . "',
                " . $discount_product_item_data . "
                'index': '" . $index . "',
                'item_variant': '" . $variantName . "', ";

            $data_fields .= $item_category;

            unset($item_category);

            $data_fields .= "},";
            $index++;
        }

        $data_fields .= "]";

        $discount_data = $discount > 0 ? "'discount': '" . $discount . "'," : '';
        $discount_cart_level_data = $discount_cart_level > 0 ? "'cart_level_discount': '" . $discount_cart_level . "'," : '';

        wc_enqueue_js("
            if (window.localStorage.getItem('DevLLDebug')) {
                console.log('WOO DEBUG: - add_shipping_info -', JSON.stringify(" . json_encode($items) . "));
            }

            function datalayerPushShippingAndPayment(shippingInfo, paymentType) {
                window.addShippingInfoEvent = {
                    'data': {
                        " . $data_fields . ",
                        'currency' : '" . $this->current_currency . "',
                        'value' : '" . $total_price . "',
                        'coupon': '" . implode(", ",  $coupons) . "',
                        " . $discount_data . "
                        'cart_level_coupon': '" . implode(", ", $coupon_cart_level) . "',
                        " . $discount_cart_level_data . "
                    }
                };

                window.addPaymentInfoEvent = {
                    'data': {
                        " . $data_fields . ",
                        'currency' : '" . $this->current_currency . "',
                        'value' : '" . $total_price . "',
                        'coupon': '" . implode(", ",  $coupons) . "',
                        " . $discount_data . "
                        'cart_level_coupon': '" . implode(", ", $coupon_cart_level) . "',
                        'payment_type': paymentType,
                        " . $discount_cart_level_data . "
                    }
                };

                window.postMessage(
                    JSON.stringify({
                        'source': 'screen-one-woocommerce-tracking', 
                        'action': 'add_shipping_info',
                        'data': {
                            " . $data_fields . ",
                            'currency' : '" . $this->current_currency . "',
                            'value' : '" . $total_price . "',
                            'coupon': '" . implode(", ",  $coupons) . "',
                            'discount': '" . $discount . "',
                            'cart_level_coupon': '" . implode(", ", $coupon_cart_level) . "',
                            'cart_level_discount':  " . $discount_cart_level . ",
                        }
                    }),'*'
                );

                window.postMessage(
                    JSON.stringify({
                        'source': 'screen-one-woocommerce-tracking', 
                        'action': 'add_payment_info',
                        'data': {
                            " . $data_fields . ",
                            'currency' : '" . $this->current_currency . "',
                            'value' : '" . $total_price . "',
                            'coupon': '" . implode(", ",  $coupons) . "',
                            " . $discount_data . "
                            'cart_level_coupon': '" . implode(", ", $coupon_cart_level) . "',
                            'payment_type': paymentType,
                            " . $discount_cart_level_data . "
                        }
                    }),'*'
                );
            }

            function datalayerGetFormData(form){
                var unindexed_array = form.serializeArray();
                var indexed_array = {};
                let differentAddress = false
                
                $.map(unindexed_array, function(n, i){
                    if($('#ship-to-different-address-checkbox:checked').length == 0){
                        if(n['name'].indexOf('billing') > -1){
                            indexed_array[n['name']] = n['value'];
                        }
                    } else {
                        if(n['name'].indexOf('shipping') > -1 && n['name'] !== 'shipping_method[0]'){
                            indexed_array[n['name']] = n['value'];
                        }
                    }
                });

                return indexed_array;
            }

            function datalayerSetupDataShippingAndPayment() {
                var paymentType = {value: $.trim(jQuery('#payment .wc_payment_method .input-radio:checked+label').text())};

                var shippingInfo = datalayerGetFormData($('form.checkout'));

                datalayerPushShippingAndPayment(shippingInfo, paymentType);
            }

            const dlTargetNode = document.querySelector('form.checkout.woocommerce-checkout');

            const dlConfig = { attributes: true, attributeFilter: ['class']};

            const dlCallback = function(mutationsList, observer) {
                for(const mutation of mutationsList) {
                    if(mutation.target.classList.contains('processing')){
                        window.addEventListener('beforeunload', datalayerSetupDataShippingAndPayment , true);
                    } else {
                        window.removeEventListener('beforeunload', datalayerSetupDataShippingAndPayment , true);
                    }
                }
            };

            const dlObserver = new MutationObserver(dlCallback);

            dlObserver.observe(dlTargetNode, dlConfig);

        ");
    }

    /**
     * Tracks purchase completed
     */
    function ldtjs_purchase_completed($orderId)
    {
        global $woocommerce;

        // Get the order object
        $order = wc_get_order($orderId);

        if (!$order->get_meta('_purchase_event_tracked', true)) {
            $coupon_cart_level = [];
            $coupon_product_level = [];
            $discount_cart_level = 0;
            $coupons = [];

            foreach ($order->get_coupon_codes() as $coupon_code) {
                // Get the WC_Coupon object
                $coupon = new WC_Coupon($coupon_code);

                $coupons[] = $coupon->get_code();

                if ($coupon->get_discount_type() == 'fixed_cart') {
                    $coupon_cart_level[] = $coupon_code;
                    $discount_cart_level += $coupon->get_amount();
                } else {
                    $coupon_product_level[] = $coupon;
                }
            }

            $order_data = array(
                'order_id' => $order->get_id(),
                'order_number' => $order->get_order_number(),
                'order_date' => date('Y-m-d H:i:s', strtotime(get_post($order->get_id())->post_date)),
                'status' => $order->get_status(),
                'shipping_total' => $order->get_total_shipping(),
                'shipping_tax_total' => wc_format_decimal($order->get_shipping_tax(), 2),
                // 'fee_total' => wc_format_decimal($fee_total, 2),
                // 'fee_tax_total' => wc_format_decimal($fee_tax_total, 2),
                'tax_total' => wc_format_decimal($order->get_total_tax(), 2),
                'cart_discount' => (defined('WC_VERSION') && (WC_VERSION >= 2.3)) ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_cart_discount(), 2),
                'order_discount' => (defined('WC_VERSION') && (WC_VERSION >= 2.3)) ? wc_format_decimal($order->get_total_discount(), 2) : wc_format_decimal($order->get_order_discount(), 2),
                'discount_total' => wc_format_decimal($order->get_total_discount(), 2),
                'order_total' => wc_format_decimal($order->get_total(), 2),
                'order_currency' => $order->get_currency(),
                'payment_method' => $order->get_payment_method(),
                'shipping_method' => $order->get_shipping_method(),
                'customer_id' => $order->get_user_id(),
                'customer_user' => $order->get_user_id(),
                'customer_email' => ($a = get_userdata($order->get_user_id())) ? $a->user_email : '',
                'billing_first_name' => $order->get_billing_first_name(),
                'billing_last_name' => $order->get_billing_last_name(),
                'billing_company' => $order->get_billing_company(),
                'billing_email' => $order->get_billing_email(),
                'billing_phone' => $order->get_billing_phone(),
                'billing_address_1' => $order->get_billing_address_1(),
                'billing_address_2' => $order->get_billing_address_2(),
                'billing_postcode' => $order->get_billing_postcode(),
                'billing_city' => $order->get_billing_city(),
                'billing_state' => $order->get_billing_state(),
                'billing_country' => $order->get_billing_country(),
                'shipping_first_name' => $order->get_shipping_first_name(),
                'shipping_last_name' => $order->get_shipping_last_name(),
                'shipping_company' => $order->get_shipping_company(),
                'shipping_address_1' => $order->get_shipping_address_1(),
                'shipping_address_2' => $order->get_shipping_address_2(),
                'shipping_postcode' => $order->get_shipping_postcode(),
                'shipping_city' => $order->get_shipping_city(),
                'shipping_state' => $order->get_shipping_state(),
                'shipping_country' => $order->get_shipping_country(),
                'customer_note' => $order->get_customer_note(),
            );

            $discount = $order->get_discount_total();

            $discount_data = $discount > 0 ? "'discount': '" . $discount . "'," : '';
            $discount_cart_level_data = $discount_cart_level > 0 ? "'cart_level_discount': '" . $discount_cart_level . "'," : '';
            $order_total = $order->get_total(); // Total amount of the order

            // Get total fees
            $total_fees = 0;
            foreach ($order->get_fees() as $fee) {
                $total_fees += $fee->get_total();
            }

            // Get total tax
            // currenly will remove total tax from {$total_amount_with_fees_and_tax} because it was added to the order before
            $total_tax = $order->get_total_tax();

            // Calculate total amount including fees and tax
            $total_amount_with_fees_and_tax = $order_total + $total_fees;

            $purchaseCompleted = '';
            $purchaseCompleted .= "{
                'transaction_id': '" . $order->get_order_number() . "',
                'value': '" . $total_amount_with_fees_and_tax . "',
                'tax': '" . number_format($total_tax, 2, ".", "") . "',
                'shipping': '" . number_format($order->calculate_shipping(), 2, ".", "") . "',
                'status':'" . $order_data['status'] . "',
                'coupon': '" . implode(", ",  $coupons) . "',
                " . $discount_data . "
                'cart_level_coupon': '" . implode(", ", $coupon_cart_level) . "',
                " . $discount_cart_level_data . "
                'billing_address': {
                    'billing_first_name':'" . $order_data['billing_first_name'] . "',
                    'billing_last_name':'" . $order_data['billing_last_name'] . "',
                    'billing_company':'" . $order_data['billing_company'] . "',
                    'billing_email':'" . $order_data['billing_email'] . "',
                    'billing_phone':" . $order_data['billing_phone'] . ",
                    'billing_address_1':'" . $order_data['billing_address_1'] . "',
                    'billing_address_2':'" . $order_data['billing_address_2'] . "',
                    'billing_postcode':'" . $order_data['billing_postcode'] . "',
                    'billing_city':'" . $order_data['billing_city'] . "',
                    'billing_state':'" . $order_data['billing_state'] . "',
                    'billing_country':'" . $order_data['billing_country'] . "'
                },
                'shipping_address': {
                    'shipping_first_name':'" . $order_data['shipping_first_name'] . "',
                    'shipping_last_name':'" . $order_data['shipping_last_name'] . "',
                    'shipping_company':'" . $order_data['shipping_company'] . "',
                    'shipping_address_1':'" . $order_data['shipping_address_1'] . "',
                    'shipping_address_2':'" . $order_data['shipping_address_2'] . "',
                    'shipping_postcode':'" . $order_data['shipping_postcode'] . "',
                    'shipping_city':'" . $order_data['shipping_city'] . "',
                    'shipping_state':'" . $order_data['shipping_state'] . "',
                    'shipping_country':'" . $order_data['shipping_country'] . "'
                },
                'customer_note': '" . $order_data['customer_note'] . "',
                'currency': '" . $order_data['order_currency'] . "',";

            $temp = '';
            $i = 0;

            $index = 1;
            $purchaseCompleted .= "'items': [";
            foreach ($order->get_items() as $key => $item) :
                $discount_product_item = 0;

                $coupon_product_item = [];

                $product_exclude_coupon = false;

                // $product = $order->get_product_from_item($item);
                $product        = $item->get_product();

                $item_subtotal  = $item->get_subtotal();
                $item_quantity  = $item->get_quantity();
                $item_cal_price = $item_subtotal / $item_quantity;

                $categories = get_the_terms($item['product_id'], 'product_cat');

                $variantItems = ($item['variation_id']) ? wc_get_product($item['variation_id']) : '';

                $variantName = ($item['variation_id']) ? $variantItems->name : '';

                $productPrice = ($item['variation_id']) ? $variantItems->price : $item_cal_price;

                $id_check_coupon = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];

                // $productSalePrice = ($item['variation_id']) ? $variantItems->sale_price : $product->get_sale_price();

                if (!function_exists('str_contains')) {
                    function str_contains(string $haystack, string $needle): bool
                    {
                        return '' === $needle || false !== strpos($haystack, $needle);
                    }
                }

                foreach ($coupon_product_level as $coupon) {
                    if (count($coupon->get_product_ids()) > 0) {
                        if (in_array($id_check_coupon, $coupon->get_product_ids())) {
                            $coupon_product_item[] = $coupon->get_code();
                            if ($coupon->get_discount_type() == 'percent') {
                                $discount_product_item += ($productPrice * $coupon->get_amount() * $item['quantity']) / 100;
                            } else {
                                $discount_product_item += $coupon->get_amount() * $item['quantity'];
                            }
                        }
                    } else if (count($coupon->get_excluded_product_ids()) > 0) {
                        if (!in_array($id_check_coupon, $coupon->get_excluded_product_ids())) {
                            $coupon_product_item[] = $coupon->get_code();
                            if ($coupon->get_discount_type() == 'percent') {
                                $discount_product_item += ($productPrice * $coupon->get_amount() * $item['quantity']) / 100;
                            } else {
                                $discount_product_item += $coupon->get_amount() * $item['quantity'];
                            }
                        } else {
                            $product_exclude_coupon = true;
                        }
                    }
                }

                if (is_array($categories) || is_object($categories)) {
                    $item_category = '';

                    foreach ($categories as $cat => $val) {
                        if ($cat === 0) {
                            $item_category .= "'item_category': '" . $val->name . "',";
                        } else {
                            $item_category .= "'item_category" . $cat . "': '" . $val->name . "',";
                        }
                        foreach ($coupon_product_level as $coupon) {
                            if (count($coupon->get_product_categories()) > 0) {
                                if (in_array($val->term_id, $coupon->get_product_categories())) {
                                    if (!$product_exclude_coupon) {
                                        if (is_array($coupon_product_item) && !in_array($coupon->get_code(), $coupon_product_item)) {
                                            $coupon_product_item[] = $coupon->get_code();
                                            if ($coupon->get_discount_type() == 'percent') {
                                                $discount_product_item += ($productPrice * $coupon->get_amount() * $item['quantity']) / 100;
                                            } else {
                                                $discount_product_item += $coupon->get_amount() * $item['quantity'];
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
                                                $discount_product_item += ($productPrice * $coupon->get_amount() * $item['quantity']) / 100;
                                            } else {
                                                $discount_product_item += $coupon->get_amount() * $item['quantity'];
                                            }
                                        }
                                    }
                                } else {
                                    if (($key = array_search($coupon->get_code(), $coupon_product_item)) !== false) {
                                        unset($coupon_product_item[$key]);
                                    }
                                    if ($coupon->get_discount_type() == 'percent') {
                                        $discount_product_item -= ($productPrice * $coupon->get_amount() * $item['quantity']) / 100;
                                    } else {
                                        $discount_product_item -= $coupon->get_amount() * $item['quantity'];
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
                            $discount_product_item += ($productPrice * $coupon->get_amount() * $item['quantity']) / 100;
                        } else {
                            $discount_product_item += $coupon->get_amount() * $item['quantity'];
                        }
                    }
                }

                $discount_product_item_data = $discount_product_item > 0 ? "'discount': '" . $discount_product_item . "'," : '';

                $purchaseCompleted .= "{
                            'item_id': '" . $item['product_id'] . "',
                            'item_name': `" . $product->get_title() . "`,
                            'quantity': '" . $item['quantity'] . "',
                            'price': '" . $productPrice . "',
                            'coupon': '" . implode(", ", $coupon_product_item) . "',
                            " . $discount_product_item_data . " 
                            'index': '" . $index . "',
                            'item_variant': '" . $variantName . "', ";

                $purchaseCompleted .= $item_category;

                // if (is_array($variantAttr) || is_object($variantAttr)) {
                //     foreach($variantAttr as $attr => $val) {
                //         $purchaseCompleted .= "'item_".$attr."': '".$val."',";
                //     }
                // }

                unset($item_category);

                $index++;

                $purchaseCompleted .= "},";
            endforeach;

            $purchaseCompleted .= "]";

            $purchaseCompleted .= "}";

            // Mark the event as tracked to prevent multiple tracking
            $order->update_meta_data('_purchase_event_tracked', true);
            $order->save();
            wc_enqueue_js("
                if (window.localStorage.getItem('DevLLDebug')) {
                    console.log('WOO DEBUG: - purchase_completed -', JSON.stringify(" . json_encode($order) . "));
                }
                localStorage.setItem('Data Tracking purchase completed', JSON.stringify({
                    'source': 'screen-one-woocommerce-tracking', 
                    'action': 'purchase_completed',
                    'data': " . $purchaseCompleted . "
                }));

                window.viewPurchaseCompletedEvent = {
                    'data': " . $purchaseCompleted . "
                };
            ");
        }
    }


    /**
     * Tracks remove_product_from_cart
     */
    function ldtjs_remove_from_cart($woocommerce)
    {

        wc_enqueue_js("
            var dlAjaxUrl = '" . admin_url('admin-ajax.php') . "';

            $( document.body ).on( 'click', 'form.woocommerce-cart-form .remove', function(e) {
                
                var aTag = $(e.currentTarget),
                    href = aTag.attr('href');

                var regex = new RegExp('[\\?&]remove_item=([^&#]*)'),
                    results = regex.exec(href);
                if(results !== null) {
                    var cart_key_item = results[1];

                    const dataQuery = {
                        action: 'll_datalayer_remove_item_query',
                        cart_key_item: cart_key_item,
                    }

                    $.ajax({
                        url: dlAjaxUrl,
                        type: 'GET',
                        data: dataQuery,
                        success: function(response) {
                            const { itemRemoveGA4 ,valueRemoveCart } = JSON.parse(response);
                            
                            window.removeFromCartEvent = {
                                'data': { 
                                    'currency' : '" . $this->current_currency . "',
                                    'value': valueRemoveCart,
                                    'items': [ itemRemoveGA4 ]
                                }  
                            };

                            window.postMessage(
                                JSON.stringify({
                                    'source': 'screen-one-woocommerce-tracking', 
                                    'action': 'remove_from_cart',
                                    'data': { 
                                        'currency' : '" . $this->current_currency . "',
                                        'value': valueRemoveCart,
                                        'items': [ itemRemoveGA4 ]
                                    }
                                }),'*'
                            );
                        },
                        error: function () { console.log('error'); } 
                    });
                }
            });
        ");
    }

    /**
     * Add to cart ajax
     */
    public function ldtjs_tracking_add_to_cart_simple($selector)
    {
        if (!$this->redirect_enabled) {
            wc_enqueue_js("
                var dlAjaxUrl = '" . admin_url('admin-ajax.php') . "';

                $( '" . $selector . "' ).click( function() {
                    const _quantityEle = $(this).parent().find('input.qty');
                    const _quantity = _quantityEle.length > 0 ? _quantityEle[0].value : '1';

                    const dataQuery = {
                        action: 'll_datalayer_add_to_cart_query',
                        productId: $(this).data('product_id'),
                        quantity: _quantity
                    }
                    $.ajax({
                        url: dlAjaxUrl,
                        type: 'GET',
                        data: dataQuery,
                        success: function(response) {
                            const { GA4 } = JSON.parse(response);

                            const dataTracking = JSON.stringify({
                                'source': 'screen-one-woocommerce-tracking', 
                                'action': 'add_single_product_to_cart',
                                'data': {
                                    'currency' : '" . $this->current_currency . "',
                                    'value': GA4.price,
                                    items: [
                                        GA4
                                    ] 
                                }
                            });

                            window.postMessage(dataTracking, '*');
                        },
                        error: function () { console.log('error'); } 
                    });
                    
                });
            ");
        }
    }

    /**
     * Add to cart hook
     * find data product from cart
     */

    function ldtjs_find_product_from_cart($product_id, $index) {
        $product_related = wc_get_product($product_id);
        
        if (!empty($product_related)) {
            $data_item = [
                "item_name" => $product_related->get_title(),
                "item_id" => $product_id,
                "price" => $this->get_product_price_in_current_currency($product_related->get_id(), $product_related->get_price()),
                "quantity" => $product_related->get_min_purchase_quantity(),
                "index" => $index,
            ];

            // get variant product
            if ($product_related->get_type() == 'variable') {
                $variations = $product_related->get_available_variations();

                foreach ($variations as $index => $variation) {
                    $variation_product = wc_get_product($val['variation_id']);

                    if ($index === 0) {
                        $data_item['item_variant'] = $variation_product->get_name();
                    } else {
                        $data_item['item_variant' . $index] = $variation_product->get_name();
                    }
                }
            }

            // get category name from product
            $categories = get_the_terms($product_id, 'product_cat');

            if (is_array($categories) || is_object($categories)) {
                if (!empty($categories)) {
                    foreach ($categories as $index => $category) {
                        if ($index === 0) {
                            $data_item['item_category'] = $category->name;
                        } else {
                            $data_item['item_category' . $index] = $category->name;
                        }
                    }
                }
            }

            return $data_item;
        }

        return '';
    }

    /**
     * Add to cart hook
     * addToCart`
     */
    function ldtjs_add_to_cart_advanced($cart_item_key, $product_id, $quantity, $variation_id, $cart_item_data)
    {
        write_log("addToCart Begin", 'tracking event addToCart');
        $product_price = null;
        $price_from_cart = null;

        foreach (WC()->cart->get_cart() as $cart_item) {
            if ($cart_item['data']->get_id() == $product_id) {
                $price_from_cart = $cart_item['data']->get_price();
                break;
            }
        }

        $product_price = !empty($price_from_cart) ? $price_from_cart : 0;
        $product = wc_get_product($product_id);
        $categories = get_the_terms($product_id, 'product_cat');

        $get_category_ids = json_encode($product->get_category_ids());

        $get_tag_ids = json_encode($product->get_tag_ids());

        $variantItems = ($variation_id) ? wc_get_product($variation_id) : '{}';

        $variant_name = ($variation_id) ? $variantItems->name : '';

        $productPrice = ($variation_id) ? $variantItems->price : $product_price;

        $list_name = woocommerce_page_title(false);

        $productItem = [
            "item_name" => $product->get_name(),
            "item_id" => $product_id,
            "price" => $productPrice,
            "quantity" => $quantity,
            "index" => 1,
            "item_list_name" => $list_name,
            "item_variant" => $variant_name
        ];

        foreach ($categories as $index => $category) {
            if ($index === 0) {
                $productItem['item_category'] = $category;
            } else {
                $productItem['item_category' . $index] = $category;
            }
        }

        $productAddToCart = [
            "currency" => $this->current_currency,
            "items" => []
        ];

        $productAddToCart["items"][] = $productItem;

        if (!function_exists('write_log')) {
            function write_log ( $log, $type)  {
               if ( is_array( $log ) || is_object( $log ) ) {
                error_log( json_encode( $log ) );
                $logger  = wc_get_logger();
                $context = array( 'source' => 'yardistry-'.$type );
                $logger->log( 'info', print_r( $log, true ), $context );
               } else {
                error_log($log);
                $logger  = wc_get_logger();
                $context = array( 'source' => 'yardistry-'.$type );
                $logger->log( 'info', $log, $context );
               }
            }
        }

        write_log('PRODUCT ID: '.$product_id, 'Ductesting');

        if ($this->redirect_enabled) {
            $extracted_ids = $cart_item_data['extracted_ids'];

            if (!empty($extracted_ids)) {
                $newIndex = 2;

                foreach ($extracted_ids as $id) {
                    $newItem = $this->ldtjs_find_product_from_cart((int)$id, $newIndex);

                    if (!empty($newItem)) {
                        $productAddToCart["items"][] = $newItem;
                    }
                    $newIndex++;
                }
            }

            $_SESSION['llproductAddToCart'] = $productAddToCart;
        } else {
            $var_dataTracking = "dataTracking" . $product_id;
            $keyProductAddToCart = "productAddCart" . $product_id;

            $ldt_addToCartData = "
                const " . $keyProductAddToCart . " = " . json_encode($productAddToCart) . ";

                itemAddToCartGA4 = [...itemAddToCartGA4, ..." . $keyProductAddToCart . "['items']];

                valueAddToCartGA4 = 0;
                " . $keyProductAddToCart . "['items'].forEach((item) => {
                    valueAddToCartGA4 += item.price * item.quantity;
                })

                const " . $var_dataTracking . " = {
                    'value': Number(valueAddToCartGA4.toFixed(10)),
                    'currency' : '" . $this->current_currency . "',
                    items: itemAddToCartGA4
                };
                
                window.addToCartEvent = {
                    'data': " . $var_dataTracking . "
                };

                window.postMessage(
                    JSON.stringify({
                        'source': 'screen-one-woocommerce-tracking', 
                        'action': 'add_single_product_to_cart',
                        'data': " . $var_dataTracking . "
                    }
                ),'*')
            ";

            wc_enqueue_js($ldt_addToCartData);
        }
    }

    /**
     * Quantity product update
     */

    function ldtjs_cart_updated_quantity()
    {
        global $woocommerce;
        $total_price = $woocommerce->cart->total;

        wc_enqueue_js("
            var dlAjaxUrl = '" . admin_url('admin-ajax.php') . "';

            $( document.body ).on( 'click', '.woocommerce button[name=\"update_cart\"]', function(e) {
                const dataQuery = {
                    action: 'll_datalayer_update_cart_query',
                }
                $.ajax({
                    url: dlAjaxUrl,
                    type: 'GET',
                    data: dataQuery,
                    success: function(response) {
                        const {arrayItems} = JSON.parse(response);

                        let valueRemoveCart = 0;
                        let valueAddToCart = 0;

                        const pQuantity = $('.woocommerce-cart-form').find('.input-text.qty');
                        const _arrProducts = [];
                        const itemRemoveGA4 = [], itemAddGA4 = [];

                        if (pQuantity.length > 0) {
                            for (let i = 0; i < pQuantity.length; i++) {
                                const _name = pQuantity[i].name;
                                const _quantity = pQuantity[i].valueAsNumber ? pQuantity[i].valueAsNumber : parseInt(pQuantity[i].value);
                                const matches = _name.match(/\[(.*?)\]/);

                                _arrProducts.push({key: matches[1], quantity: _quantity });
                            }
                        }

                        arrayItems.forEach((data, index) => {
                            if (_arrProducts.length > 0) {
                                const existedProductChange = _arrProducts.find(a => a.key == data.key);
                                if (existedProductChange) {
                                    if (data.quantity < existedProductChange.quantity) {
                                        const newQuantity = existedProductChange.quantity - data.quantity;
                                        delete data.itemGA4.coupon
                                        delete data.itemGA4.discount
                                        data.itemGA4.quantity = newQuantity;
                                        itemAddGA4.push(data.itemGA4);
                                        valueAddToCart += data.itemGA4.price * data.itemGA4.quantity;
                                    }

                                    if (data.quantity > existedProductChange.quantity) {
                                        const newQuantity = data.quantity - existedProductChange.quantity;
                                        valueRemoveCart += newQuantity * data.itemGA4.price;
                                        if(data.itemGA4.discount){
                                            data.itemGA4.discount = (data.itemGA4.discount * newQuantity).toFixed(2);
                                            valueRemoveCart -= data.itemGA4.discount;
                                        } 
                                        data.itemGA4.quantity = newQuantity;
                                        itemRemoveGA4.push(data.itemGA4);
                                    }
                                } else {
                                    valueAddToCart += data.itemGA4.price;
                                    if(data.itemGA4.discount){
                                        valueRemoveCart -= data.itemGA4.discount;
                                    }
                                    itemRemoveGA4.push(data.itemGA4);
                                }
                            } else {
                                valueAddToCart += data.itemGA4.price;
                                if(data.itemGA4.discount){
                                    valueRemoveCart -= data.itemGA4.discount;
                                }
                                itemRemoveGA4.push(data.itemGA4);
                            }
                        });

                        if (itemRemoveGA4.length > 0) {
                            window.removeFromCartEvent = {
                                'data': { 
                                    'currency' : '" . $this->current_currency . "',
                                    'value': valueRemoveCart,
                                    'items': itemRemoveGA4 
                                }
                            };

                            window.postMessage(
                                JSON.stringify({
                                    'source': 'screen-one-woocommerce-tracking',
                                    'action': 'remove_from_cart',
                                    'data': {
                                        'currency' : '" . $this->current_currency . "',
                                        'value': valueRemoveCart,
                                        'items': itemRemoveGA4 
                                    }
                                }), '*'
                            );
                        }

                        if (itemAddGA4.length > 0) {
                            const dataTracking = {
                                'value' : valueAddToCart,
                                'currency' : '" . $this->current_currency . "',
                                items: itemAddGA4
                            }

                            window.addToCartEvent = { 'data': dataTracking };

                            window.postMessage(
                                JSON.stringify({
                                    'source': 'screen-one-woocommerce-tracking', 
                                    'action': 'add_single_product_to_cart',
                                    'data': dataTracking
                                }
                            ),'*')
                        }
                    },
                    error: function () { console.log('error'); } 
                });
            });
        ");
    }
}
