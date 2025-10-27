<?php
class Woo_MyGLSD_Bulk {
  private static function save_pdf_to_order($order, $base64pdf){
    if (empty($base64pdf)) return null;
    $upload = wp_upload_dir();
    $dir = trailingslashit($upload['basedir']).'mygls';
    if ( ! file_exists($dir) ) wp_mkdir_p($dir);
    $fname = 'label-'.$order->get_id().'-'.time().'.pdf';
    $fpath = trailingslashit($dir).$fname;
    $data = base64_decode($base64pdf);
    if ($data===false) return null;
    file_put_contents($fpath, $data);
    $url = trailingslashit($upload['baseurl']).'mygls/'.$fname;
    // PHP string concat will be done in PHP code; this line is placeholder for Python context.
    return $fpath;
  }

  public static function init(){
    add_filter('bulk_actions-edit-shop_order',[__CLASS__,'bulk_action']);
    add_filter('handle_bulk_actions-edit-shop_order',[__CLASS__,'handle_bulk'],10,3);
    add_filter('bulk_actions-woocommerce_page_wc-orders',[__CLASS__,'bulk_action']);
    add_filter('handle_bulk_actions-woocommerce_page_wc-orders',[__CLASS__,'handle_bulk'],10,3);
    add_action('add_meta_boxes',[__CLASS__,'box']);
    add_action('add_meta_boxes_woocommerce_page_wc-orders',[__CLASS__,'box']);
    add_action('admin_post_myglsd_download_label',[__CLASS__,'download_label']);
  }

  public static function bulk_action($actions){
    $actions['myglsd_print'] = 'MyGLS címke (PrintLabels)';
    return $actions;
  }

  private static function make_parcel_from_order($order){
    $s = Woo_MyGLSD_Util::settings();
    $sender = Woo_MyGLSD_Util::sender_address();
    $client = Woo_MyGLSD_Util::client_number();

    $delivery = [
      'Name' => trim($order->get_formatted_shipping_full_name()) ?: trim($order->get_formatted_billing_full_name()),
      'Street' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
      'HouseNumber' => $order->get_shipping_address_2() ?: $order->get_billing_address_2(),
      'City' => $order->get_shipping_city() ?: $order->get_billing_city(),
      'ZipCode' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
      'CountryIsoCode' => $order->get_shipping_country() ?: $order->get_billing_country(),
      'ContactPhone' => $order->get_billing_phone(),
      'ContactEmail' => $order->get_billing_email(),
    ];

    $service = [ ['Code'=>'24H'] ];
    $psd = $order->get_meta('_mygls_psd_id');
    if ($psd){ $service[] = ['Code'=>'PSD','PSDParameter'=>['StringValue'=>$psd]]; }

    if ($order->get_payment_method()==='cod'){
      $service[] = ['Code'=>'COD'];
    }

    $parcel = [
      'ClientNumber' => $client,
      'ClientReference' => (string)$order->get_order_number(),
      'PickupDate' => date('Y-m-d'),
      'PickupAddress' => $sender,
      'DeliveryAddress' => $delivery,
      'ServiceList' => $service,
    ];

    return $parcel;
  }

  public static function handle_bulk($redirect, $action, $ids){
    if ($action!=='myglsd_print') return $redirect;
    $ok=0; $fail=0;
    $api = new Woo_MyGLSD_Rest();
    foreach ($ids as $order_id){
      $order = wc_get_order($order_id);
      try{
        $parcel = self::make_parcel_from_order($order);
        $res = $api->PrintLabels([$parcel]);
        if (!empty($res['PrintLabelsInfoList'][0]['ParcelNumber'])){
          $pn = $res['PrintLabelsInfoList'][0]['ParcelNumber'];
          $order->update_meta_data('_mygls_parcel_number', $pn);
          $order->update_meta_data('_mygls_tracking_url', Woo_MyGLSD_Util::tracking_url($pn));
          // Save PDF
          if (!empty($res['Labels'])){
            $path = self::save_pdf_to_order($order, $res['Labels']);
            if ($path){
              $order->update_meta_data('_mygls_label_pdf', $path);
            }
          }
          $order->save();
          $ok++;
        } else {
          $fail++;
        }
      } catch (\Throwable $e){
        $fail++;
      }
    }
    return add_query_arg(['mygls_ok'=>$ok,'mygls_fail'=>$fail], $redirect);
  }

  public static function box(){
    add_meta_box('myglsd_box','MyGLS', function($post){
      $order = wc_get_order($post->ID);
      $pn = $order->get_meta('_mygls_parcel_number');
      $trk = $order->get_meta('_mygls_tracking_url');
      if ($pn){ echo '<p><strong>Csomagszám:</strong> '.esc_html($pn).'</p>'; }
      if ($trk){ echo '<p><a target="_blank" href="'.esc_url($trk).'">Követés</a></p>'; }
      echo '<p><a class="button" href="'.esc_url(admin_url('admin-post.php?action=myglsd_download_label&order_id='.$post->ID)).'">Címke letöltése (ha elérhető)</a></p>';
    }, 'shop_order','side');
  }

  public static function download_label(){
    if (!current_user_can('manage_woocommerce')) wp_die('Nope');
    $order_id = absint($_GET['order_id']??0);
    $order = wc_get_order($order_id);
    $pn = $order->get_meta('_mygls_parcel_number');
    if(!$pn) wp_die('Nincs csomagszám.');
    try{
      // A GetPrintedLabels a ParcelIdList alapján ad PDF-et; a PrintLabels válaszban nincs mindig ParcelId.
      // Itt csak tájékoztatunk – célszerű azonnal elmenteni a PrintLabels->Labels PDF-et rendeléshez.
      wp_die('A PDF közvetlen letöltéséhez a PrintLabels válasz Labels mezőjét mentsd azonnal. ParcelId alapú GetPrintedLabels itt nem garantáltan működik ParcelId hiányában.');
    } catch (\Throwable $e){
      wp_die('Hiba: '.$e->getMessage());
    }
  }
}
