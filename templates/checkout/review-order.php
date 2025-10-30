<?php
/**
 * Review order table
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/review-order.php.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 5.2.0
 */

defined( 'ABSPATH' ) || exit;
?>
<table class="shop_table woocommerce-checkout-review-order-table">
	<thead>
		<tr>
			<th class="product-thumbnail"><?php esc_html_e( 'Product', 'woocommerce' ); ?></th>
			<th class="product-name"><?php esc_html_e( 'Name', 'woocommerce' ); ?></th>
			<th class="product-total"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php
		do_action( 'woocommerce_review_order_before_cart_contents' );

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );

			if ( $_product && $_product->exists() && $cart_item['quantity'] > 0 && apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
				?>
				<tr class="<?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>">
					<td class="product-thumbnail">
						<?php
						$thumbnail = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image( 'thumbnail' ), $cart_item, $cart_item_key );
						if ( ! $cart_item['data']->is_visible() ) {
							echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
							printf( '<a href="%s">%s</a>', esc_url( $_product->get_permalink( $cart_item ) ), $thumbnail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
					</td>
					<td class="product-name">
						<?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key ) ); ?>
						<?php echo apply_filters( 'woocommerce_checkout_cart_item_quantity', ' <strong class="product-quantity">' . sprintf( '&times;&nbsp;%s', $cart_item['quantity'] ) . '</strong>', $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</td>
					<td class="product-total">
						<?php echo apply_filters( 'woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal( $_product, $cart_item['quantity'] ), $cart_item, $cart_item_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					</td>
				</tr>
				<?php
			}
		}

		do_action( 'woocommerce_review_order_after_cart_contents' );
		?>
	</tbody>
	<tfoot>

		<tr class="cart-subtotal">
			<th colspan="2"><?php esc_html_e( 'Subtotal', 'woocommerce' ); ?></th>
			<td><?php wc_cart_totals_subtotal_html(); ?></td>
		</tr>

		<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
			<tr class="cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
				<th colspan="2"><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
				<td><?php wc_cart_totals_coupon_html( $coupon ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>

			<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>

                        <?php
                        $shipping_summary_lines = [];

                        if ( WC()->cart && WC()->shipping() ) {
                                $packages        = WC()->shipping()->get_packages();
                                $chosen_methods  = WC()->session ? (array) WC()->session->get( 'chosen_shipping_methods', [] ) : [];

                                foreach ( $packages as $package_index => $package ) {
                                        $chosen_rate_id = $chosen_methods[ $package_index ] ?? '';

                                        if ( $chosen_rate_id && isset( $package['rates'][ $chosen_rate_id ] ) ) {
                                                $rate        = $package['rates'][ $chosen_rate_id ];
                                                $label_html  = function_exists( 'wc_cart_totals_shipping_method_label' )
                                                        ? wc_cart_totals_shipping_method_label( $rate )
                                                        : $rate->get_label();

                                                if ( $label_html ) {
                                                        $shipping_summary_lines[] = $label_html;
                                                }
                                        }
                                }
                        }

                        if ( empty( $shipping_summary_lines ) && WC()->cart ) {
                                $shipping_total = WC()->cart->get_cart_shipping_total();

                                if ( $shipping_total ) {
                                        $shipping_summary_lines[] = $shipping_total;
                                }
                        }

                        if ( empty( $shipping_summary_lines ) ) {
                                $shipping_summary_lines[] = '&ndash;';
                        }
                        ?>

                        <tr class="woocommerce-shipping-totals shipping">
                                <th colspan="2"><?php esc_html_e( 'Szállítás', 'mygls-woocommerce' ); ?></th>
                                <td data-title="<?php esc_attr_e( 'Szállítás', 'mygls-woocommerce' ); ?>">
                                        <?php foreach ( $shipping_summary_lines as $shipping_line ) : ?>
                                                <div class="mygls-order-review-shipping-line"><?php echo wp_kses_post( $shipping_line ); ?></div>
                                        <?php endforeach; ?>
                                </td>
                        </tr>

			<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>

		<?php endif; ?>

		<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
			<tr class="fee">
				<th colspan="2"><?php echo esc_html( $fee->name ); ?></th>
				<td><?php wc_cart_totals_fee_html( $fee ); ?></td>
			</tr>
		<?php endforeach; ?>

		<?php if ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) : ?>
			<?php if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) : ?>
				<?php foreach ( WC()->cart->get_tax_totals() as $code => $tax ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited ?>
					<tr class="tax-rate tax-rate-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
						<th colspan="2"><?php echo esc_html( $tax->label ); ?></th>
						<td><?php echo wp_kses_post( $tax->formatted_amount ); ?></td>
					</tr>
				<?php endforeach; ?>
			<?php else : ?>
				<tr class="tax-total">
					<th colspan="2"><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
					<td><?php wc_cart_totals_taxes_total_html(); ?></td>
				</tr>
			<?php endif; ?>
		<?php endif; ?>

		<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>

		<tr class="order-total">
			<th colspan="2"><?php esc_html_e( 'Total', 'woocommerce' ); ?></th>
			<td><?php wc_cart_totals_order_total_html(); ?></td>
		</tr>

		<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>

	</tfoot>
</table>
