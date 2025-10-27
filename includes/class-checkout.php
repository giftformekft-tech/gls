<?php
class Woo_MyGLSD_Checkout {
  public static function init(){
    add_action('woocommerce_after_order_notes',[__CLASS__,'psd_field']);
    add_action('woocommerce_checkout_create_order',[__CLASS__,'save_order_meta'],10,2);
    add_action('woocommerce_email_order_meta',[__CLASS__,'email_meta'],10,3);
  }
  public static function psd_field($checkout){
    echo '<div id="mygls_psd_wrap">';
    woocommerce_form_field('mygls_psd_id',[
      'type'=>'text','class'=>['form-row-wide'],
      'label'=>'GLS ParcelShop/Locker ID (PSD StringValue)',
      'required'=>false,'placeholder'=>'pl. 2351-CSOMAGPONT'
    ], $checkout->get_value('mygls_psd_id'));
    echo '</div>';
  }
  public static function save_order_meta($order,$data){
    if (!empty($_POST['mygls_psd_id'])){
      $order->update_meta_data('_mygls_psd_id', sanitize_text_field($_POST['mygls_psd_id']));
    }
  }
  public static function email_meta($order,$admin,$plain){
    $id = $order->get_meta('_mygls_psd_id');
    if ($id){
      $line = 'GLS átvételi pont: '.$id;
      echo $plain ? "\n$line\n" : "<p>$line</p>";
    }
    $trk = $order->get_meta('_mygls_tracking_url');
    if ($trk){ $line='Követés: '.$trk; echo $plain ? "\n$line\n" : "<p>$line</p>"; }
  }
}
