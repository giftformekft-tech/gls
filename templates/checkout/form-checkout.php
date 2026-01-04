<?php
/**
 * MyGLS Custom Checkout Template
 *
 * This template replaces the default WooCommerce checkout form
 * when GLS Custom Checkout is enabled in settings.
 *
 * @version 1.1.0
 */

defined('ABSPATH') || exit;

// If checkout registration is disabled and not logged in, the user cannot checkout.
if (!$checkout->is_registration_enabled() && $checkout->is_registration_required() && !is_user_logged_in()) {
    echo esc_html(apply_filters('woocommerce_checkout_must_be_logged_in_message', __('Vásárláshoz be kell jelentkeznie.', 'mygls-woocommerce')));
    return;
}

?>

<form name="checkout" method="post" class="checkout woocommerce-checkout mygls-custom-checkout-active" action="<?php echo esc_url(wc_get_checkout_url()); ?>" enctype="multipart/form-data">

    <?php if ($checkout->get_checkout_fields()) : ?>

        <?php do_action('woocommerce_checkout_before_customer_details'); ?>

        <div class="mygls-custom-checkout-container">
            <!-- Left Column: Customer Details & Sections -->
            <div class="mygls-checkout-main">
                <div class="mygls-checkout-sections">
                    <?php
                    // Get singleton controller instance and render sections
                    $controller = MyGLS\Checkout\Controller::get_instance();

                    // Render custom sections except payment (rendered in sidebar)
                    $controller->render_checkout_sections(['payment']);
                    ?>
                </div>
            </div>

            <!-- Right Column: Order Review -->
            <div class="mygls-order-review-sidebar">
                <div class="mygls-payment-review">
                    <?php $controller->render_checkout_section('payment'); ?>
                </div>

                <div class="mygls-order-review">
                    <h3 class="mygls-order-review-title">
                        <span class="dashicons dashicons-cart"></span>
                        <?php _e('Rendelés összesítő', 'mygls-woocommerce'); ?>
                    </h3>
                    <div class="mygls-order-review-content">
                        <?php do_action('woocommerce_checkout_before_order_review'); ?>

                        <div id="order_review" class="woocommerce-checkout-review-order">
                            <?php do_action('woocommerce_checkout_order_review'); ?>
                        </div>

                        <?php do_action('woocommerce_checkout_after_order_review'); ?>
                    </div>
                </div>

                <div class="mygls-payment-review">
                    <?php $controller->render_checkout_section('payment'); ?>
                </div>
            </div>
        </div>

        <?php do_action('woocommerce_checkout_after_customer_details'); ?>

    <?php endif; ?>

</form>

<?php do_action('woocommerce_after_checkout_form', $checkout); ?>
