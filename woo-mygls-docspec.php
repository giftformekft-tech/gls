<?php
/**
 * Plugin Name: Woo MyGLS (DocSpec REST)
 * Description: MyGLS integráció (REST/JSON, HU) a hivatalos dokumentáció alapján. HPOS-kompatibilis. Címkegyártás (PrintLabels), státusz lekérdezés, törlés, COD módosítás, listázás, visszáru cím.
 * Author: Szoki Dev
 * Version: 1.0.6
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Tested up to: 6.6
 * WC tested up to: 9.4
 */

if ( ! defined('ABSPATH') ) exit;

define('WOO_MYGLSD_DIR', plugin_dir_path(__FILE__));
define('WOO_MYGLSD_URL', plugin_dir_url(__FILE__));

require_once WOO_MYGLSD_DIR.'includes/class-settings.php';
require_once WOO_MYGLSD_DIR.'includes/class-rest-client.php';
require_once WOO_MYGLSD_DIR.'includes/class-admin.php';
require_once WOO_MYGLSD_DIR.'includes/class-checkout.php';
require_once WOO_MYGLSD_DIR.'includes/class-bulk.php';
require_once WOO_MYGLSD_DIR.'includes/util.php';

// HPOS (Custom Order Tables) kompatibilitás deklarálása
add_action( 'before_woocommerce_init', function() {
  if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
  }
});

function woo_myglsd_admin_notice_missing_wc(){
  echo '<div class="notice notice-error"><p>A Woo MyGLS (DocSpec) bővítményhez szükséges a WooCommerce.</p></div>';
}

add_action('plugins_loaded', function() {
  if ( ! class_exists('WooCommerce') ) {
    add_action('admin_notices', 'woo_myglsd_admin_notice_missing_wc');
    return;
  }
  Woo_MyGLSD_Settings::init();
  Woo_MyGLSD_Admin::init();
  Woo_MyGLSD_Checkout::init();
  Woo_MyGLSD_Bulk::init();
}, 20);
