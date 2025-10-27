<?php
class Woo_MyGLSD_Settings {
  const OPT = 'woo_myglsd_settings';

  public static function init(){
    add_action('admin_init', [__CLASS__,'register']);
    add_action('admin_menu', [__CLASS__,'menu'], 99);
  }

  public static function menu(){
    $cap = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
    // Only submenu under WooCommerce
    add_submenu_page('woocommerce','MyGLS','MyGLS',$cap,'woo-myglsd',[__CLASS__,'render']);
  }

  public static function render(){
    echo '<div class="wrap"><h1>MyGLS (DocSpec)</h1><form method="post" action="options.php">';
    settings_fields(self::OPT); do_settings_sections('woo-myglsd'); submit_button(); echo '</form>';

    // Inline test panel (so no submenu is needed)
    echo '<hr/><h2>Gyors teszt</h2>';
    echo '<p>Ezek nem módosítják az adatbázist (Ping), illetve tesztkörnyezetben javasoltak (Demó PrintLabels).</p>';
    echo '<form style="display:inline-block;margin-right:10px" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="woo_myglsd_ping" />';
    echo '<input type="text" name="name" placeholder="Vevő neve" value="Forme.hu" /> ';
    echo '<input type="text" name="link_name" placeholder="LinkName" value="" style="width:140px" /> ';
    echo '<input type="text" name="country" placeholder="Ország" value="HU" style="width:70px" /> ';
    echo '<input type="text" name="return_type" placeholder="ReturnType" value="1" style="width:90px" /> ';
    echo wp_nonce_field('woo_myglsd_ping','woo_myglsd_ping_nonce', true, false);
    echo '<button class="button button-primary">Ping MyGLS</button>';
    echo '</form>';
    echo '<form style="display:inline-block" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="woo_myglsd_print" />';
    echo wp_nonce_field('woo_myglsd_print','woo_myglsd_print_nonce', true, false);
    echo '<button class="button">Demó PrintLabels (PDF)</button>';
    echo '</form>';
    echo '<form style="display:inline-block;margin-left:10px" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
    echo '<input type="hidden" name="action" value="woo_myglsd_tri_ping" />';
    echo '<input type="hidden" name="restore_mode" value="'.esc_attr(self::get('password_mode','base64')).'" />';
    echo wp_nonce_field('woo_myglsd_tri_ping','woo_myglsd_tri_ping_nonce', true, false);
    echo '<button class="button button-secondary">Tri‑Ping (base64 / hex / byte array)</button>';
    echo '</form>';

    if(isset($_GET['msg'])){
      echo '<div class="notice notice-info" style="margin-top:12px"><pre>'.esc_html(base64_decode($_GET['msg'])).'</pre></div>';
    }
    echo '</div>';
  }

  public static function register(){
    if ( ! function_exists('WC') ) return;
    register_setting(self::OPT, self::OPT);
    add_settings_section('sec_api','API beállítások',null,'woo-myglsd');

    add_settings_field('env','Környezet (test/prod)',[__CLASS__,'text'],'woo-myglsd','sec_api',['key'=>'env','ph'=>'test|prod']);
    add_settings_field('base_url','API Base URL',[__CLASS__,'text'],'woo-myglsd','sec_api',['key'=>'base_url','ph'=>'https://api.test.mygls.hu/ParcelService.svc/json/']);
    add_settings_field('username','Felhasználónév (email)',[__CLASS__,'text'],'woo-myglsd','sec_api',['key'=>'username']);
    add_settings_field('password','Jelszó (plain)',[__CLASS__,'password'],'woo-myglsd','sec_api',['key'=>'password']);
    add_settings_field('api_key','API kulcs (ha van)',[__CLASS__,'text'],'woo-myglsd','sec_api',['key'=>'api_key']);
    add_settings_field('password_mode','Jelszó kódolás módja',[__CLASS__,'select'],'woo-myglsd','sec_api',['key'=>'password_mode','options'=>['base64'=>'SHA512→base64 (ajánlott)','byte_array'=>'SHA512→JSON byte array','hex'=>'SHA512→hex string']]);

    add_settings_field('client_number','GLS ClientNumber',[__CLASS__,'text'],'woo-myglsd','sec_api',['key'=>'client_number']);
    add_settings_field('webshop_engine','WebshopEngine',[__CLASS__,'text'],'woo-myglsd','sec_api',['key'=>'webshop_engine','ph'=>'WooCommerce']);
    add_settings_field('type_of_printer','TypeOfPrinter',[__CLASS__,'select'],'woo-myglsd','sec_api',[
      'key'=>'type_of_printer',
      'options'=>[
        'Thermo'=>'Thermo',
        'ThermoZPL'=>'ThermoZPL',
        'ThermoZPL_300DPI'=>'ThermoZPL_300DPI',
        'ShipItThermoPdf'=>'ShipItThermoPdf',
        'A4_2x2'=>'A4_2x2',
        'A4_4x1'=>'A4_4x1'
      ]
    ]);

    add_settings_section('sec_sender','Feladó adatok (PickupAddress)',null,'woo-myglsd');
    add_settings_field('sender_name','Név',[__CLASS__,'text'],'woo-myglsd','sec_sender',['key'=>'sender_name']);
    add_settings_field('sender_street','Utca',[__CLASS__,'text'],'woo-myglsd','sec_sender',['key'=>'sender_street']);
    add_settings_field('sender_house','Házszám',[__CLASS__,'text'],'woo-myglsd','sec_sender',['key'=>'sender_house']);
    add_settings_field('sender_city','Város',[__CLASS__,'text'],'woo-myglsd','sec_sender',['key'=>'sender_city']);
    add_settings_field('sender_zip','Irányítószám',[__CLASS__,'text'],'woo-myglsd','sec_sender',['key'=>'sender_zip']);
    add_settings_field('sender_country','Ország ISO (pl. HU)',[__CLASS__,'text'],'woo-myglsd','sec_sender',['key'=>'sender_country','ph'=>'HU']);
    add_settings_field('sender_phone','Telefon',[__CLASS__,'text'],'woo-myglsd','sec_sender',['key'=>'sender_phone']);
    add_settings_field('sender_email','E-mail',[__CLASS__,'text'],'woo-myglsd','sec_sender',['key'=>'sender_email']);
  }

  public static function get($k,$def=''){ $o=get_option(self::OPT,[]); return isset($o[$k])?$o[$k]:$def; }

  public static function text($a){ $k=$a['key']; $v=esc_attr(self::get($k)); $ph=esc_attr($a['ph']??''); echo "<input type='text' class='regular-text' name='".self::OPT."[$k]' value='$v' placeholder='$ph'/>"; }
  public static function password($a){ $k=$a['key']; $v=esc_attr(self::get($k)); echo "<input type='password' class='regular-text' name='".self::OPT."[$k]' value='$v'/>"; }
  public static function textarea($a){ $k=$a['key']; $v=esc_textarea(self::get($k)); $ph=esc_textarea($a['ph']??''); echo "<textarea rows='6' class='large-text code' name='".self::OPT."[$k]' placeholder='$ph'>$v</textarea>"; }
  public static function select($a){ $k=$a['key']; $v=self::get($k); echo "<select name='".self::OPT."[$k]'>"; foreach(($a['options']??[]) as $val=>$lab){ $sel=selected($v,$val,false); echo "<option value='".esc_attr($val)."' $sel>".esc_html($lab)."</option>"; } echo "</select>"; }
}
