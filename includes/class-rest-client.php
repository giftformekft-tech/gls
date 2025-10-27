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
    if (is_wp_error($res)) throw new \Exception($res->get_error_message());
    $code = wp_remote_retrieve_response_code($res);
    $body = wp_remote_retrieve_body($res);

    if ($code >= 300) throw new \Exception('MyGLS HTTP '.$code.'; URL='.$url.'; body='.$body);

    $json = json_decode($body, true);
    if ($json===null && json_last_error()) {
      throw new \Exception('JSON decode error: '.json_last_error_msg().'; URL='.$url.'; body='.$body);
    }
    return $json;
  }

  private function auth_payload(array $data = []){
    return array_merge([
      'Authentication' => Woo_MyGLSD_Util::auth_block(),
    ], $data);
  }

  private function clean_list(array $values){
    $filtered = [];
    foreach ($values as $value){
      if ($value === null) continue;
      $value = is_scalar($value) ? trim((string)$value) : $value;
      if ($value === '' && $value !== 0 && $value !== '0') continue;
      $filtered[] = $value;
    }
    return $filtered;
  }

  public function PrintLabels(array $parcels){
    $parcels = array_values(array_filter($parcels));
    if (empty($parcels)){
      throw new \Exception('PrintLabels: üres csomag lista.');
    }
    $payload = $this->auth_payload([
      'PrintLabelsInfoList' => $parcels,
      'TypeOfPrinter' => $this->typeOfPrinter,
    ]);
    return $this->post('PrintLabels', $payload);
  }

  public function DeleteLabels(array $parcelNumbers){
    $list = $this->clean_list($parcelNumbers);
    if (empty($list)){
      throw new \Exception('DeleteLabels: üres ParcelNumberList.');
    }
    $payload = $this->auth_payload([
      'ParcelNumberList' => $list,
    ]);
    return $this->post('DeleteLabels', $payload);
  }

  public function ModifyCOD($parcelNumber, $amount, $currency = 'HUF', $reference = ''){
    $parcelNumber = trim((string)$parcelNumber);
    if ($parcelNumber === ''){
      throw new \Exception('ModifyCOD: hiányzó ParcelNumber.');
    }
    $payload = $this->auth_payload([
      'ParcelNumber' => $parcelNumber,
      'CODAmount' => (float)$amount,
      'CurrencyCode' => $currency,
    ]);
    if ($reference !== ''){
      $payload['CODReference'] = $reference;
    }
    return $this->post('ModifyCOD', $payload);
  }

  public function GetParcelStatuses(array $parcelNumbers){
    $list = $this->clean_list($parcelNumbers);
    if (empty($list)){
      throw new \Exception('GetParcelStatuses: üres ParcelNumberList.');
    }
    $payload = $this->auth_payload([
      'ParcelNumberList' => $list,
    ]);
    return $this->post('GetParcelStatuses', $payload);
  }

  public function GetParcelList($clientNumber, $dateFrom, $dateTo, $status = null){
    $clientNumber = (int)$clientNumber;
    if ($clientNumber <= 0){
      throw new \Exception('GetParcelList: érvénytelen ClientNumber.');
    }
    $payload = $this->auth_payload([
      'ClientNumber' => $clientNumber,
      'DateFrom' => $dateFrom,
      'DateTo' => $dateTo,
    ]);
    if ($status !== null){
      $payload['Status'] = $status;
    }
    return $this->post('GetParcelList', $payload);
  }

  public function GetClientReturnAddress($clientNumber, $name, $countryIso, $returnType){
    $clientNumber = (int)$clientNumber;
    if ($clientNumber <= 0){
      throw new \Exception('GetClientReturnAddress: érvénytelen ClientNumber.');
    }
    $payload = $this->auth_payload([
      'ClientNumber' => $clientNumber,
      'Name' => $name,
      'CountryIsoCode' => $countryIso,
      'ReturnType' => (int)$returnType,
    ]);
    return $this->post('GetClientReturnAddress', $payload);
  }
}
