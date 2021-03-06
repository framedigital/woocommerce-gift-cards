<?php

// Exit if accessed directly
if (! defined('ABSPATH')) {
    exit;
}

/**
 * Class WCGC_Checkout_Hooks
 */
class WCGC_Checkout_Hooks
{
    /**
     * Hook filters and actions
     */
    public function __construct()
    {
        add_action('wp_ajax_woocommerce_apply_giftcard', [ $this, 'ajax_apply_gift_card' ]);
        add_action('wp_ajax_nopriv_woocommerce_apply_giftcard', [ $this, 'ajax_apply_gift_card' ]);

        add_action('woocommerce_review_order_before_order_total', [ $this, 'order_gift_card' ]);
        add_action('woocommerce_cart_totals_before_order_total', [ $this, 'order_gift_card' ]);

        add_action('woocommerce_add_to_cart', [ $this, 'add_card_data' ], 10, 3);

        add_action('woocommerce_order_details_after_order_table', [ $this, 'display_gift_card' ]);
        add_action('woocommerce_email_after_order_table', [ $this, 'display_gift_card' ]);

        add_filter('woocommerce_add_to_cart_validation', [ $this, 'validate_form_complete' ], 10, 5);

        add_filter('woocommerce_get_order_item_totals', [ $this, 'add_order_giftcard' ], 10, 2);

        add_action('woocommerce_calculate_totals', [ $this, 'subtract_gift_card' ]);
        add_filter('woocommerce_calculated_total', [ $this, 'apply_discount' ], 1, 2);

        add_action('woocommerce_checkout_order_processed', [ $this, 'update_card' ]);
    }

    /**
     * AJAX apply gift card on checkout page
     * @access public
     * @return void
     */
    public function ajax_apply_gift_card()
    {
        // Validate missing field
        if (! isset($_POST['giftcard_code']) || ! $_POST['giftcard_code']) {
            wc_add_notice(__('Gift Card number is invalid', 'woocommerce-gift-cards'), 'error');
            wc_print_notices();
            die();
        }

        // Get gift card object
        $gift_card = new WC_Gift_Card();
        $gift_card->get_by_number(sanitize_text_field($_POST['giftcard_code']));

        $response = WCGC()->apply_gift_card_to_cart($gift_card);

        if ($response instanceof WP_Error) {
            wc_add_notice($response->get_error_message(), 'error');
        } elseif ($response) {
            WC()->session->giftcard_post = $gift_card->get_id();
            wc_add_notice(__('Gift card applied successfully.', 'woocommerce-gift-cards'), 'success');
        }

        wc_print_notices();
        die();
    }

    /**
     * Function to add the giftcard data to the cart display on both the card page and the checkout page WC()->session->giftcard_balance
     *
     */
    public function order_gift_card()
    {
        if (isset($_GET['remove_giftcards'])) {
            $type = $_GET['remove_giftcards'];

            if (1 == $type) {
                unset(WC()->session->giftcard_payment, WC()->session->giftcard_post);
                WC()->cart->calculate_totals();
            }
        }

        if (isset(WC()->session->giftcard_post)) {
            if (WC()->session->giftcard_post) {
                $discount = WC()->session->giftcard_payment;

                wc_get_template('checkout-discount-row.php', ['discount' => $discount ], WCGC()->theme_template_path, WCGC()->plugin_template_path);
            }
        }
    }

    /**
     * @param $gift_card_id
     * @param $cart
     *
     * @return float
     */
    public function calculate_cart_discount($gift_card_id, $cart)
    {
        if (isset($gift_card_id)) {
            $gift_card = new WC_Gift_Card();
            $gift_card->get_by_post_id($gift_card_id);
            $balance = $gift_card->get_balance();

            $charge_shipping = get_option('woocommerce_enable_giftcard_charge_shipping');
            $charge_tax      = get_option('woocommerce_enable_giftcard_charge_tax');
            $charge_fee      = get_option('woocommerce_enable_giftcard_charge_fee');
            //$charge_giftcard 	= get_option('woocommerce_enable_giftcard_charge_giftcard');
            $exclude_product = []; //get_option('exclude_product_ids');


            $gift_card_discount = 0;

            foreach ($cart->cart_contents as $key => $product) {
                if (! in_array($product['product_id'], $exclude_product)) {
                    if ($charge_tax == 'yes') {
                        $gift_card_discount += $product['line_total'];
                        $gift_card_discount += $product['line_tax'];
                    } else {
                        $gift_card_discount += $product['line_total'];
                    }
                }
            }


            if ($charge_shipping == 'yes') {
                $gift_card_discount += $cart->shipping_total;

                if ($charge_tax == "yes") {
                    $gift_card_discount += $cart->shipping_tax_total;
                }
            }

            if ($charge_fee == "yes") {
                $gift_card_discount += WC()->session->fee_total;
            }


            if ($gift_card_discount <= $balance) {
                return (float) $gift_card_discount;
            }

            return (float) $balance;
        }
    }

    /**
     * Function to decrease the cart amount by the amount in the giftcard
     */
    public function subtract_gift_card($cart)
    {
        $gift_card_id = WC()->session->giftcard_post;

        $discount = $this->calculate_cart_discount($gift_card_id, $cart);

        WC()->session->giftcard_payment = $discount;
        WC()->session->discount_cart    = $discount;
    }

    /**
     * Filter cart_total value
     *
     * @param $total
     * @param $cart
     *
     * @return float
     */
    public function apply_discount($total, $cart)
    {
        $gift_card_id = WC()->session->giftcard_post;
        $discount     = WC()->session->giftcard_payment;

        if (isset($gift_card_id)) {
            $total = $total - $discount;
        }

        return (float) $total;
    }

    public function add_card_data($cart_item_key, $product_id, $quantity)
    {
        global $woocommerce, $post;

        $is_giftcard = get_post_meta($product_id, '_giftcard', true);

        if ($is_giftcard == "yes") {
            $giftcard_data = [
                'To'       => 'NA',
                'To Email' => 'NA',
                'Note'     => 'NA',
            ];

            if (isset($_POST['wcgc_to']) && ($_POST['wcgc_to'] <> '')) {
                $giftcard_data['To'] = wc_clean($_POST['wcgc_to']);
            }

            if (isset($_POST['wcgc_to_email']) && ($_POST['wcgc_to_email'] <> '')) {
                $giftcard_data['To Email'] = wc_clean($_POST['wcgc_to_email']);
            }

            if (isset($_POST['wcgc_note']) && ($_POST['wcgc_note'] <> '')) {
                $giftcard_data['Note'] = wc_clean($_POST['wcgc_note']);
            }

            $giftcard_data = apply_filters('wcgc_giftcard_data', $giftcard_data, $_POST);

            WC()->cart->cart_contents[ $cart_item_key ]["variation"] = $giftcard_data;

            return $woocommerce;
        }
    }

    public function validate_form_complete($passed, $product_id, $quantity, $variation_id = '', $variations = '')
    {
        $passed = true;

        $is_giftcard = get_post_meta($product_id, '_giftcard', true);

        if (($is_giftcard == "yes")) {
            if ($_POST["wcgc_to"] == '') {
                $passed = false;
            }
            if ($_POST["wcgc_to_email"] == '') {
                $passed = false;
            }

            if ($passed == false) {
                wc_add_notice(__('Please complete form.', 'woocommerce-gift-cards'), 'error');
            }
        }

        return $passed;
    }

    /**
     * Displays the giftcard data on the order thank you page
     *
     */
    public function display_gift_card($order)
    {
        $theIDNum   = get_post_meta($order->get_id(), 'wcgc_id', true);
        $theBalance = get_post_meta($order->get_id(), 'wcgc_balance', true);

        if (isset($theIDNum)) {
            if ($theIDNum <> '') {
                ?>
				<h4><?php _e('Gift Card Balance After Order:', 'woocommerce-gift-cards'); ?><?php echo ' ' . woocommerce_price($theBalance); ?> <?php do_action('wcgc_after_remaining_balance', $theIDNum, $theBalance); ?></h4>

			<?php
            }
        }

        $theGiftCardData = get_post_meta($order->get_id(), 'wcgc_data', true);
        if (isset($theGiftCardData)) {
            if ($theGiftCardData <> '') {
                ?>
				<h4><?php _e('Gift Card Information:', 'woocommerce-gift-cards'); ?></h4>
				<?php
                $i = 1;

                foreach ($theGiftCardData as $giftcard) {
                    if ($i % 2) {
                        echo '<div style="margin-bottom: 10px;">';
                    }
                    echo '<div style="float: left; width: 45%; margin-right: 2%;>';
                    echo '<h6><strong> ' . __('Giftcard', 'woocommerce-gift-cards') . ' ' . $i . '</strong></h6>';
                    echo '<ul style="font-size: 0.85em; list-style: none outside none;">';
                    if ($giftcard[ wcgc_product_num ]) {
                        echo '<li>' . __('Card', 'woocommerce-gift-cards') . ': ' . get_the_title($giftcard[ wcgc_product_num ]) . '</li>';
                    }
                    if ($giftcard[ wcgc_to ]) {
                        echo '<li>' . __('To', 'woocommerce-gift-cards') . ': ' . $giftcard[ wcgc_to ] . '</li>';
                    }
                    if ($giftcard[ wcgc_to_email ]) {
                        echo '<li>' . __('Send To', 'woocommerce-gift-cards') . ': ' . $giftcard[ wcgc_to_email ] . '</li>';
                    }
                    if ($giftcard[ wcgc_balance ]) {
                        echo '<li>' . __('Balance', 'woocommerce-gift-cards') . ': ' . woocommerce_price($giftcard[ wcgc_balance ]) . '</li>';
                    }
                    if ($giftcard[ wcgc_note ]) {
                        echo '<li>' . __('Note', 'woocommerce-gift-cards') . ': ' . $giftcard[ wcgc_note ] . '</li>';
                    }
                    if ($giftcard[ wcgc_quantity ]) {
                        echo '<li>' . __('Quantity', 'woocommerce-gift-cards') . ': ' . $giftcard[ wcgc_quantity ] . '</li>';
                    }
                    echo '</ul>';
                    echo '</div>';
                    if (! ($i % 2)) {
                        echo '</div>';
                    }
                    $i ++;
                }
                echo '<div class="clear"></div>';
            }
        }
    }

    public function add_order_giftcard($total_rows, $order)
    {
        $return = [];

        $order_id = $order->get_id();

        $giftCardPayment = get_post_meta($order_id, 'wcgc_payment', true);

        if ($giftCardPayment <> 0) {
            $newRow['wcgc_data'] = [
                'label' => __('Gift Card Payment:', 'woocommerce-gift-cards'),
                'value' => wc_price(- 1 * $giftCardPayment)
            ];

            if (get_option('woocommerce_enable_giftcard_process') == 'no') {
                array_splice($total_rows, 1, 0, $newRow);
            } else {
                array_splice($total_rows, 2, 0, $newRow);
            }
        }

        return $total_rows;
    }

    /**
     * Updates the Gift Card and the order information when the order is processed
     *
     */
    public function update_card($order_id)
    {
        global $woocommerce;

        $giftCard_id = WC()->session->giftcard_post;
        if ($giftCard_id <> '') {
            $newBalance = wcgc_get_giftcard_balance($giftCard_id) - WC()->session->giftcard_payment;

            // Check if the gift card ballance is 0 and if it is change the post status to zerobalance
            if (wcgc_get_giftcard_balance($giftCard_id) == 0) {
                wcgc_update_giftcard_status($giftCard_id, 'zerobalance');
            }


            $giftCard_IDs   = get_post_meta($giftCard_id, 'wcgc_existingOrders_id', true);
            $giftCard_IDs[] = $order_id;


            update_post_meta($giftCard_id, 'wcgc_balance', $newBalance); // Update balance of Giftcard
            update_post_meta($giftCard_id, 'wcgc_existingOrders_id', $giftCard_IDs); // Saves order id to gifctard post
            update_post_meta($order_id, 'wcgc_id', $giftCard_id);
            update_post_meta($order_id, 'wcgc_payment', WC()->session->giftcard_payment);
            update_post_meta($order_id, 'wcgc_balance', $newBalance);

            WC()->session->idForEmail = $order_id;
            unset(WC()->session->giftcard_payment, WC()->session->giftcard_post);
        }

        if (isset(WC()->session->giftcard_data)) {
            update_post_meta($order_id, 'wcgc_data', WC()->session->giftcard_data);

            unset(WC()->session->giftcard_data);
        }
    }
}


/**
 * @param $giftcard_code
 * @deprecated
 */
function woocommerce_apply_giftcard($giftcard_code)
{
    global $wpdb;

    if (! empty($_POST['giftcard_code'])) {
        $giftcard_number = sanitize_text_field($_POST['giftcard_code']);

        if (! isset(WC()->session->giftcard_post)) {
            $giftcard_id = wcgc_get_giftcard_by_code($giftcard_number);

            if ($giftcard_id) {
                $current_date   = date("Y-m-d");
                $cardExperation = wcgc_get_giftcard_expiration($giftcard_id);

                if ((strtotime($current_date) <= strtotime($cardExperation)) || (strtotime($cardExperation) == '')) {
                    if (wcgc_get_giftcard_balance($giftcard_id) > 0) {
                        WC()->session->giftcard_post = $giftcard_id;

                        wc_add_notice(__('Gift card applied successfully.', 'woocommerce-gift-cards'), 'success');
                    } else {
                        wc_add_notice(__('Gift Card does not have a balance!', 'woocommerce-gift-cards'), 'error');
                    }
                } else {
                    wc_add_notice(__('Gift Card has expired!', 'woocommerce-gift-cards'), 'error'); // Giftcard Entered has expired
                }
            } else {
                wc_add_notice(__('Gift Card does not exist!', 'woocommerce-gift-cards'), 'error'); // Giftcard Entered does not exist
            }
        } else {
            wc_add_notice(__('Gift Card already in the cart!', 'woocommerce-gift-cards'), 'error');  //  You already have a gift card in the cart
        }

        wc_print_notices();
    }
}
