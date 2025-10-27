<?php
class Woo_MyGLSD_Admin {
  public static function init(){
    add_action('admin_post_woo_myglsd_ping', [__CLASS__,'handle_ping']);
    add_action('admin_post_woo_myglsd_print', [__CLASS__,'handle_print_demo']);
    add_action('admin_post_woo_myglsd_tri_ping', [__CLASS__,'handle_tri_ping']);
  }

  public static function render_tools(){
    echo '<div class="wrap"><h2>MyGLS Teszt eszközök</h2>';
    echo '<p>Pingeljük az API-t (GetClientReturnAddress) – nem módosít semmit.</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="woo_myglsd_ping" />';
    echo '<input type="text" name="name" placeholder="Link Name (pl. Forme.hu)" value="Forme.hu" /> ';
    echo '<button class="button button-primary">Ping MyGLS</button>';
    echo '</form>';

    echo '<hr/><p>Demó PrintLabels – NEM küld csomagot, csak kipróbálja a hívást (tesztkörnyezet ajánlott).</p>';
    echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="woo_myglsd_print" />';
    echo '<button class="button">Demó címke generálás</button>';
    echo '</form>';

    if(isset($_GET['msg'])){
      echo '<div class="notice notice-info"><pre>'.esc_html(base64_decode($_GET['msg'])).'</pre></div>';
    }
    echo '</div>';
  }

  private static function back_msg($arr, $page = 'woo-myglsd'){
    $msg = base64_encode(is_string($arr)?$arr:print_r($arr,true));
    wp_redirect( add_query_arg('msg', $msg, admin_url('admin.php?page='.$page)) );
    exit;
  }

  public static function handle_ping(){
    if(!current_user_can('manage_woocommerce')) wp_die('Nope');
    try{
      $s = Woo_MyGLSD_Util::settings();
      $api = new Woo_MyGLSD_Rest();
      $res = $api->GetClientReturnAddress( (int)($s['client_number']??0), sanitize_text_field($_POST['name']??'Forme.hu'), 'HU', 1 );
      self::back_msg($res);
    } catch (\Throwable $e){
      self::back_msg('ERR: '.$e->getMessage());
    }
  }

  public static function handle_print_demo(){
    if(!current_user_can('manage_woocommerce')) wp_die('Nope');
    try{
      $api = new Woo_MyGLSD_Rest();
      $sender = Woo_MyGLSD_Util::sender_address();
      $clientNumber = Woo_MyGLSD_Util::client_number();
      $parcel = [
        'ClientNumber' => $clientNumber,
        'ClientReference' => 'DEMO-PRINT-'.time(),
        'PickupDate' => date('Y-m-d'),
        'PickupAddress' => $sender,
        'DeliveryAddress' => [
          'Name'=>'Teszt Vevő','Street'=>'Kossuth','HouseNumber'=>'1','City'=>'Budapest','ZipCode'=>'1051','CountryIsoCode'=>'HU',
          'ContactPhone'=>'+361234567','ContactEmail'=>'teszt@example.com'
        ],
        'ServiceList' => [ ['Code'=>'24H'] ]
      ];
      $res = $api->PrintLabels([$parcel]);
      // Mentsük le PDF-ként ha van
      if (!empty($res['Labels'])){
        $pdf = base64_decode($res['Labels']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="mygls-demo.pdf"');
        echo $pdf;
        exit;
      }
      self::back_msg($res);
    } catch (\Throwable $e){
      self::back_msg('ERR: '.$e->getMessage());
    }
  }

  public static function handle_tri_ping(){
    if(!current_user_can('manage_woocommerce')) wp_die('Nope');
    $s = Woo_MyGLSD_Util::settings();
    $results = [];
    $modes = ['base64','hex','byte_array'];
    foreach($modes as $mode){
      try{
        $s['password_mode'] = $mode;
        update_option(Woo_MyGLSD_Settings::OPT, $s);
        $api = new Woo_MyGLSD_Rest();
        $res = $api->GetClientReturnAddress( (int)($s['client_number']??0), 'Forme.hu', 'HU', 1 );
        $results[$mode] = ['ok'=>true,'data'=>$res];
      } catch (\Throwable $e){
        $results[$mode] = ['ok'=>false,'err'=>$e->getMessage()];
      }
    }
    if(isset($_POST['restore_mode'])){
      $s['password_mode'] = sanitize_text_field($_POST['restore_mode']);
      update_option(Woo_MyGLSD_Settings::OPT, $s);
    }
    $msg = base64_encode(print_r($results,true));
    wp_redirect( add_query_arg('msg', $msg, admin_url('admin.php?page=woo-myglsd')) );
    exit;
  }
}
