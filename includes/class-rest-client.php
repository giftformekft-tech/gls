<?php
class Woo_MyGLSD_Rest {
  private $base;
  private $typeOfPrinter;

  public function __construct(){
    $this->base = Woo_MyGLSD_Util::api_base();
    $this->typeOfPrinter = Woo_MyGLSD_Settings::get('type_of_printer','Thermo');
  }

  private function post($endpoint, $payload){
    // BASE URL normalizálása és metódus hozzáfűzése
    $base = trim($this->base);
    if (!$base) { $base = 'https://api.mygls.hu/ParcelService.svc/json/'; }
    $base = rtrim($base, '/');
    if (!preg_match('~\/ParcelService\.svc($|/)~i', $base)) {
      $base .= '/ParcelService.svc';
    }
    if (!preg_match('~\/json($|/)~i', $base)) {
      $base .= '/json';
    }
    $url = $base . '/' . ltrim($endpoint, '/');

    $args = [
      'method'  => 'POST',
      'headers' => ['Accept'=>'application/json','Content-Type'=>'application/json'],
      'timeout' => 45,
      'body'    => wp_json_encode($payload, JSON_UNESCAPED_UNICODE),
    ];

    $res  = wp_remote_post($url, $args);
    if (is_wp_error($res)) throw new Exception($res->get_error_message());
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code >= 300) throw new Exception('MyGLS HTTP '.$code.'; URL='.$url.'; body='.$body);

    $json = json_decode($body, true);
    if ($json===null && json_last_error()) {
      throw new Exception('JSON decode error: '.json_last_error_msg().'; URL='.$url.'; body='.$body);
    }
    return $json;
  }
