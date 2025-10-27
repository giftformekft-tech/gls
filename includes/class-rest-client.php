<?php
class Woo_MyGLSD_Rest {
  private $base;
  private $clientNumber;
  private $printConfig;

  public function __construct(){
    $this->base = Woo_MyGLSD_Util::api_base();
    $this->clientNumber = Woo_MyGLSD_Util::client_number();
    $this->printConfig = Woo_MyGLSD_Util::print_config();
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

  private function require_client_number($value = null){
    $number = $value !== null ? (int)$value : (int)$this->clientNumber;
    if ($number <= 0){
      throw new \InvalidArgumentException('MyGLS ClientNumber hiányzik vagy érvénytelen.');
    }
    return $number;
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

  private function format_pickup_date($value){
    return Woo_MyGLSD_Util::pickup_date($value);
  }

  private function normalize_address($address, $label){
    if (!is_array($address)){
      throw new \InvalidArgumentException($label.': hibás struktúra.');
    }
    $normalized = [];
    foreach ($address as $key => $value){
      if ($value === null) continue;
      if (is_scalar($value)){
        $value = trim((string)$value);
        if ($value === '') continue;
        $normalized[$key] = $value;
        continue;
      }
      if (is_array($value)){
        $normalized[$key] = $value;
      }
    }
    if (empty($normalized['Name'])){
      throw new \InvalidArgumentException($label.': Name kötelező.');
    }
    if (empty($normalized['CountryIsoCode'])){
      throw new \InvalidArgumentException($label.': CountryIsoCode kötelező.');
    }
    foreach (['Street','City','ZipCode'] as $required){
      if (empty($normalized[$required])){
        throw new \InvalidArgumentException($label.': '.$required.' kötelező.');
      }
    }
    return $normalized;
  }

  private function normalize_parcel($parcel){
    if (!is_array($parcel)){
      throw new \InvalidArgumentException('PrintLabels: Parcel struktúra hibás.');
    }
    $parcel['ClientNumber'] = $this->require_client_number($parcel['ClientNumber'] ?? null);
    $parcel['PickupDate'] = $this->format_pickup_date($parcel['PickupDate'] ?? null);
    if (empty($parcel['PickupAddress'])){
      $parcel['PickupAddress'] = Woo_MyGLSD_Util::sender_address();
    }
    $parcel['PickupAddress'] = $this->normalize_address($parcel['PickupAddress'], 'PickupAddress');
    if (empty($parcel['DeliveryAddress'])){
      throw new \InvalidArgumentException('PrintLabels: DeliveryAddress hiányzik.');
    }
    $parcel['DeliveryAddress'] = $this->normalize_address($parcel['DeliveryAddress'], 'DeliveryAddress');
    $parcel['ServiceList'] = Woo_MyGLSD_Util::normalize_service_list($parcel['ServiceList'] ?? []);
    if (empty($parcel['ServiceList'])){
      throw new \InvalidArgumentException('PrintLabels: ServiceList üres.');
    }
    $parcel['ClientReference'] = isset($parcel['ClientReference']) && trim((string)$parcel['ClientReference']) !== ''
      ? trim((string)$parcel['ClientReference'])
      : 'AUTO-'.date('YmdHis');
    if (isset($parcel['Content'])){
      $parcel['Content'] = trim((string)$parcel['Content']);
    }
    return $parcel;
  }

  private function print_option_payload(){
    $cfg = $this->printConfig;
    $payload = [
      'TypeOfPrinter' => $cfg['type_of_printer'] ?? 'Thermo',
    ];
    if (!empty($cfg['waybill_document_type'])){
      $payload['WaybillDocumentType'] = $cfg['waybill_document_type'];
    }
    if (array_key_exists('show_return_labels', $cfg)){
      $payload['ShowReturnLabels'] = (bool)$cfg['show_return_labels'];
    }
    if (!empty($cfg['return_labels_type'])){
      $payload['ReturnLabelsType'] = $cfg['return_labels_type'];
    }
    if (array_key_exists('print_parcel_count', $cfg)){
      $payload['PrintParcelCount'] = (bool)$cfg['print_parcel_count'];
    }
    return $payload;
  }

  public function PrintLabels(array $parcels){
    $parcels = array_values(array_filter($parcels));
    if (empty($parcels)){
      throw new \Exception('PrintLabels: üres csomag lista.');
    }
    $normalized = [];
    foreach ($parcels as $parcel){
      $normalized[] = $this->normalize_parcel($parcel);
    }
    $payload = $this->auth_payload(array_merge(
      ['PrintLabelsInfoList' => $normalized],
      $this->print_option_payload()
    ));
    return $this->post('PrintLabels', $payload);
  }

  public function GetPrintedLabels(array $parcelIds, $clientNumber = null){
    $ids = $this->clean_list($parcelIds);
    if (empty($ids)){
      throw new \Exception('GetPrintedLabels: üres ParcelIdList.');
    }
    $payload = $this->auth_payload(array_merge([
      'ClientNumber' => $this->require_client_number($clientNumber),
      'ParcelIdList' => $ids,
    ], $this->print_option_payload()));
    return $this->post('GetPrintedLabels', $payload);
  }

  public function DeleteLabels(array $parcelNumbers){
    $list = $this->clean_list($parcelNumbers);
    if (empty($list)){
      throw new \Exception('DeleteLabels: üres ParcelNumberList.');
    }
    $payload = $this->auth_payload([
      'ClientNumber' => $this->require_client_number(),
      'ParcelNumberList' => $list,
    ]);
    return $this->post('DeleteLabels', $payload);
  }

  public function ModifyCOD($parcelNumber, $amount, $currency = 'HUF', $reference = '', $clientNumber = null){
    $parcelNumber = trim((string)$parcelNumber);
    if ($parcelNumber === ''){
      throw new \Exception('ModifyCOD: hiányzó ParcelNumber.');
    }
    $currency = strtoupper(trim((string)$currency));
    if ($currency === ''){
      $currency = 'HUF';
    }
    $clientNumber = $this->require_client_number($clientNumber);
    $payload = $this->auth_payload([
      'ClientNumber' => $clientNumber,
      'ParcelNumber' => $parcelNumber,
      'CODAmount' => (float)$amount,
      'CurrencyCode' => $currency,
    ]);
    if ($reference !== ''){
      $payload['CODReference'] = $reference;
    }
    return $this->post('ModifyCOD', $payload);
  }

  public function GetParcelStatuses(array $parcelNumbers, $clientNumber = null){
    $list = $this->clean_list($parcelNumbers);
    if (empty($list)){
      throw new \Exception('GetParcelStatuses: üres ParcelNumberList.');
    }
    $payload = $this->auth_payload([
      'ClientNumber' => $this->require_client_number($clientNumber),
      'ParcelNumberList' => $list,
    ]);
    return $this->post('GetParcelStatuses', $payload);
  }

  public function GetParcelList($clientNumber, $dateFrom, $dateTo, $status = null){
    $clientNumber = $this->require_client_number($clientNumber);
    $payload = $this->auth_payload([
      'ClientNumber' => $clientNumber,
      'DateFrom' => $this->format_pickup_date($dateFrom),
      'DateTo' => $this->format_pickup_date($dateTo),
    ]);
    if ($status !== null){
      $payload['Status'] = $status;
    }
    return $this->post('GetParcelList', $payload);
  }

  public function GetClientReturnAddress($clientNumber, $name, $countryIso, $returnType = null, $linkName = null){
    $clientNumber = $this->require_client_number($clientNumber);

    $countryIso = strtoupper(trim((string)$countryIso));
    if ($countryIso === ''){
      throw new \Exception('GetClientReturnAddress: hiányzó CountryIsoCode.');
    }

    $payload = [
      'ClientNumber' => $clientNumber,
      'CountryIsoCode' => $countryIso,
    ];

    $name = trim((string)$name);
    if ($name !== ''){
      $payload['Name'] = $name;
    }

    if ($linkName !== null){
      $linkName = trim((string)$linkName);
      if ($linkName !== ''){
        $payload['LinkName'] = $linkName;
      }
    }

    if ($returnType !== null && $returnType !== ''){
      $payload['ReturnType'] = is_numeric($returnType) ? (int)$returnType : $returnType;
    }

    return $this->post('GetClientReturnAddress', $this->auth_payload($payload));
  }

  public function Ping(){
    return $this->post('Ping', $this->auth_payload([
      'ClientNumber' => $this->require_client_number(),
    ]));
  }
}
