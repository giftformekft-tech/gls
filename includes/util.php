<?php
class Woo_MyGLSD_Util {
  public static function settings(){ return get_option(Woo_MyGLSD_Settings::OPT, []); }

  public static function bool_value($value){
    if (is_bool($value)) return $value;
    if ($value === null) return false;
    if (is_numeric($value)) return (int)$value === 1;
    $value = strtolower(trim((string)$value));
    if ($value === '') return false;
    return in_array($value, ['1','true','yes','on'], true);
  }

  public static function bool_setting($key){
    $s = self::settings();
    return self::bool_value($s[$key] ?? false);
  }

  public static function pickup_date($value = null){
    if ($value instanceof \DateTimeInterface){
      return $value->format('Y-m-d');
    }
    if (is_numeric($value)){
      return gmdate('Y-m-d', (int)$value);
    }
    $value = trim((string)($value ?? ''));
    if ($value === '' || strtolower($value) === 'now'){
      return gmdate('Y-m-d');
    }
    if (preg_match('~^\\d{4}-\\d{2}-\\d{2}$~', $value)){
      return $value;
    }
    $ts = strtotime($value);
    if ($ts !== false){
      return date('Y-m-d', $ts);
    }
    return gmdate('Y-m-d');
  }

  public static function print_config(){
    $s = self::settings();
    $returnType = trim((string)($s['return_labels_type'] ?? ''));
    return [
      'type_of_printer' => $s['type_of_printer'] ?? 'Thermo',
      'waybill_document_type' => $s['waybill_document_type'] ?? 'Thermo',
      'show_return_labels' => self::bool_value($s['show_return_labels'] ?? false),
      'return_labels_type' => $returnType !== '' ? $returnType : null,
      'print_parcel_count' => self::bool_value($s['print_parcel_count'] ?? false),
    ];
  }

  public static function normalize_service_list(array $services){
    $normalized = [];
    foreach ($services as $service){
      if (is_string($service)){
        $service = ['Code' => trim($service)];
      } elseif (is_array($service)){
        $service = array_filter($service, function($value){ return $value !== null && $value !== ''; });
        if (isset($service['Code'])){
          $service['Code'] = trim((string)$service['Code']);
        }
        if (isset($service['PSDParameter']) && is_string($service['PSDParameter'])){
          $service['PSDParameter'] = ['StringValue' => $service['PSDParameter']];
        }
      } else {
        continue;
      }
      if (($service['Code'] ?? '') === ''){
        continue;
      }
      $normalized[] = $service;
    }
    if (empty($normalized)){
      $normalized[] = ['Code' => '24H'];
    }
    return $normalized;
  }

  public static function order_delivery_address($order){
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
    if (empty($delivery['Name'])){
      $delivery['Name'] = 'Webshop vevő';
    }
    foreach (['Street','City','ZipCode','CountryIsoCode'] as $required){
      if (empty($delivery[$required])){
        $delivery[$required] = 'N/A';
      }
    }
    foreach ($delivery as $key => $value){
      if (is_scalar($value)){
        $delivery[$key] = trim((string)$value);
      }
    }
    return $delivery;
  }

  public static function order_service_list($order){
    $services = [['Code' => '24H']];
    $psd = $order->get_meta('_mygls_psd_id');
    if ($psd){
      $services[] = ['Code' => 'PSD', 'PSDParameter' => ['StringValue' => $psd]];
    }
    if ($order->get_payment_method() === 'cod'){
      $currency = $order->get_currency();
      if (!$currency && function_exists('get_woocommerce_currency')){
        $currency = get_woocommerce_currency();
      }
      if (!$currency){
        $currency = 'HUF';
      }
      $services[] = [
        'Code' => 'COD',
        'CODAmount' => (float)$order->get_total(),
        'CODCurrencyCode' => strtoupper($currency),
      ];
    }
    return self::normalize_service_list($services);
  }

  public static function order_reference($order){
    $reference = $order->get_order_number();
    if (!$reference){
      $reference = $order->get_id();
    }
    return (string)$reference;
  }

  public static function order_content($order){
    $items = $order->get_items();
    if (empty($items)){
      return 'Webshop order';
    }
    $names = [];
    foreach ($items as $item){
      $name = trim($item->get_name());
      if ($name !== ''){
        $names[] = $name;
      }
    }
    if (empty($names)){
      return 'Webshop order';
    }
    $max = 3;
    $summary = implode(', ', array_slice($names, 0, $max));
    if (count($names) > $max){
      $summary .= ' +' . (count($names) - $max) . ' tétel';
    }
    if (function_exists('mb_substr')){
      return mb_substr($summary, 0, 70);
    }
    return substr($summary, 0, 70);
  }

  public static function demo_print_parcel($clientNumber){
    return [
      'ClientNumber' => $clientNumber,
      'ClientReference' => 'DEMO-' . date('Ymd-His'),
      'PickupDate' => self::pickup_date('now'),
      'PickupAddress' => self::sender_address(),
      'DeliveryAddress' => [
        'Name' => 'GLS Demo Címzett',
        'Street' => 'Váci út',
        'HouseNumber' => '33',
        'City' => 'Budapest',
        'ZipCode' => '1134',
        'CountryIsoCode' => 'HU',
        'ContactPhone' => '+361234567',
        'ContactEmail' => 'demo@example.com',
      ],
      'Content' => 'Demo küldemény a GLS minta csomag alapján',
      'ServiceList' => self::normalize_service_list([
        ['Code' => '24H'],
        ['Code' => 'SM2'],
      ]),
    ];
  }

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
    foreach ($addr as $key => $value){
      if (is_scalar($value)){
        $addr[$key] = trim((string)$value);
      }
    }
    if ($addr['Name'] === ''){
      $addr['Name'] = 'Webshop feladó';
    }
    foreach (['Street','City','ZipCode'] as $required){
      if ($addr[$required] === ''){
        $addr[$required] = 'N/A';
      }
    }
    if ($addr['CountryIsoCode'] === ''){
      $addr['CountryIsoCode'] = 'HU';
    }
    return $addr;
  }

  public static function client_number(){
    return intval(self::settings()['client_number'] ?? 0);
  }

  public static function tracking_url($parcelNumber){
    return 'https://gls-group.eu/track/'.urlencode($parcelNumber);
  }
}
