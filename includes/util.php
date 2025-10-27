<?php
class Woo_MyGLSD_Util {
  public static function settings(){ return get_option(Woo_MyGLSD_Settings::OPT, []); }

  public static function api_base(){
    $s=self::settings();
    $u = trim($s['base_url'] ?? '');
    if(!$u){
      $env = strtolower($s['env'] ?? 'test');
      $u = $env==='prod' ? 'https://api.mygls.hu/ParcelService.svc/json/' : 'https://api.test.mygls.hu/ParcelService.svc/json/';
    }
    if(substr($u,-1)!=='/') $u.='/';
    return $u;
  }

  public static function sha512_bytes($plain){
    return hash('sha512', $plain, true); // raw bytes
  }

  public static function password_field(){
    $s=self::settings();
    $mode = $s['password_mode'] ?? 'base64';
    $plain = $s['password'] ?? '';
    $bytes = self::sha512_bytes($plain);
    if($mode==='hex'){ return bin2hex($bytes); }
    if($mode==='byte_array'){
      $arr = array_values(unpack('C*', $bytes));
      return $arr;
    }
    // default base64
    return base64_encode($bytes);
  }

  public static function auth_block(){
    $s = self::settings();
    $block = [
      'UserName' => $s['username'] ?? '',
      'Password' => self::password_field(),
    ];

    $client = intval($s['client_number'] ?? 0);
    if ($client > 0){
      $block['CustomerNumber'] = $client;
    }

    $engine = trim((string)($s['webshop_engine'] ?? ''));
    if ($engine === ''){
      $engine = 'WooCommerce';
    }
    $block['WebshopEngine'] = $engine;

    $apiKey = trim((string)($s['api_key'] ?? ''));
    if ($apiKey !== ''){
      $block['ApiKey'] = $apiKey;
    }

    return $block;
  }

  public static function sender_address(){
    $s = self::settings();
    $addr = [
      'Name' => $s['sender_name'] ?? '',
      'Street' => $s['sender_street'] ?? '',
      'HouseNumber' => $s['sender_house'] ?? '',
      'City' => $s['sender_city'] ?? '',
      'ZipCode' => $s['sender_zip'] ?? '',
      'CountryIsoCode' => $s['sender_country'] ?? 'HU',
      'ContactPhone' => $s['sender_phone'] ?? '',
      'ContactEmail' => $s['sender_email'] ?? '',
    ];
    return $addr;
  }

  public static function client_number(){
    return intval(self::settings()['client_number'] ?? 0);
  }

  public static function tracking_url($parcelNumber){
    return 'https://gls-group.eu/track/'.urlencode($parcelNumber);
  }
}
