<?php
/**
 * MyGLS API Client
 * Handles all API communication with MyGLS
 */

namespace MyGLS\API;

if (!defined('ABSPATH')) {
    exit;
}

class Client {
    private $username;
    private $password_hash;
    private $client_number;
    private $api_url;
    private $test_mode;
    
    public function __construct() {
        $settings = get_option('mygls_settings', []);
        
        $this->username = $settings['username'] ?? '';
        $this->client_number = $settings['client_number'] ?? '';
        $this->test_mode = ($settings['test_mode'] ?? '0') === '1';
        
        // API URL based on country and test mode
        $country = $settings['country'] ?? 'hu';
        $domain = $this->test_mode ? "api.test.mygls.{$country}" : "api.mygls.{$country}";
        $this->api_url = "https://{$domain}";
        
        // Hash password
        if (!empty($settings['password'])) {
            $this->password_hash = $this->hashPassword($settings['password']);
        }
    }
    
    /**
     * Hash password with SHA512
     */
    private function hashPassword($password) {
        $hash = hash('sha512', $password, true);
        return array_values(unpack('C*', $hash));
    }
    
    /**
     * Make API request
     */
    private function request($endpoint, $data, $method = 'POST') {
        // Validate credentials before making request
        if (empty($this->username)) {
            return ['error' => 'Username is not configured'];
        }
        if (empty($this->password_hash)) {
            return ['error' => 'Password is not configured'];
        }
        if (empty($this->client_number)) {
            return ['error' => 'Client number is not configured'];
        }

        $url = "{$this->api_url}/ParcelService.svc/json/{$endpoint}";

        // Add authentication
        $data['Username'] = $this->username;
        $data['Password'] = $this->password_hash;
        $data['WebshopEngine'] = 'WooCommerce';

        $args = [
            'method' => $method,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode($data),
            'timeout' => 60,
            'sslverify' => !$this->test_mode
        ];

        mygls_log("API Request to {$endpoint}: " . json_encode($data), 'debug');

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            mygls_log("API Error: " . $response->get_error_message(), 'error');
            return ['error' => $response->get_error_message()];
        }

        // Check HTTP status code
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code < 200 || $status_code >= 300) {
            $error_message = "HTTP {$status_code}: " . wp_remote_retrieve_response_message($response);
            mygls_log("API HTTP Error: {$error_message}", 'error');
            return ['error' => $error_message];
        }

        $body = wp_remote_retrieve_body($response);

        // Validate JSON response
        if (empty($body)) {
            mygls_log("API Error: Empty response from {$endpoint}", 'error');
            return ['error' => 'Empty response from API'];
        }

        $result = json_decode($body, true);

        // Check for JSON decode errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            mygls_log("API Error: Invalid JSON response - " . json_last_error_msg(), 'error');
            return ['error' => 'Invalid JSON response from API'];
        }

        mygls_log("API Response from {$endpoint}: " . $body, 'debug');

        // Check for API-level errors in response
        if (isset($result['ErrorCode']) && $result['ErrorCode'] !== 0) {
            $error_message = $result['ErrorMessage'] ?? 'Unknown API error';
            mygls_log("API Error Response: {$error_message} (Code: {$result['ErrorCode']})", 'error');
            return ['error' => $error_message];
        }

        return $result;
    }
    
    /**
     * Print Labels - Generate labels in one step
     */
    public function printLabels($parcels, $printer_type = 'A4_2x2') {
        $data = [
            'ParcelList' => $parcels,
            'TypeOfPrinter' => $printer_type,
            'PrintPosition' => 1,
            'ShowPrintDialog' => false
        ];
        
        return $this->request('PrintLabels', $data);
    }
    
    /**
     * Prepare Labels - Validate and save parcel data
     */
    public function prepareLabels($parcels) {
        $data = [
            'ParcelList' => $parcels
        ];
        
        return $this->request('PrepareLabels', $data);
    }
    
    /**
     * Get Printed Labels - Get PDF for existing parcels
     */
    public function getPrintedLabels($parcel_ids, $printer_type = 'A4_2x2') {
        $data = [
            'ParcelIdList' => $parcel_ids,
            'TypeOfPrinter' => $printer_type,
            'PrintPosition' => 1,
            'ShowPrintDialog' => false
        ];
        
        return $this->request('GetPrintedLabels', $data);
    }
    
    /**
     * Delete Labels
     */
    public function deleteLabels($parcel_ids) {
        $data = [
            'ParcelIdList' => $parcel_ids
        ];
        
        return $this->request('DeleteLabels', $data);
    }
    
    /**
     * Get Parcel Statuses
     */
    public function getParcelStatuses($parcel_number, $return_pod = false, $language = 'HU') {
        $data = [
            'ParcelNumber' => $parcel_number,
            'ReturnPOD' => $return_pod,
            'LanguageIsoCode' => $language
        ];
        
        return $this->request('GetParcelStatuses', $data);
    }
    
    /**
     * Get Parcel List by date range
     */
    public function getParcelList($pickup_from = null, $pickup_to = null, $print_from = null, $print_to = null) {
        $data = [];
        
        if ($pickup_from) {
            $data['PickupDateFrom'] = $this->formatDate($pickup_from);
        }
        if ($pickup_to) {
            $data['PickupDateTo'] = $this->formatDate($pickup_to);
        }
        if ($print_from) {
            $data['PrintDateFrom'] = $this->formatDate($print_from);
        }
        if ($print_to) {
            $data['PrintDateTo'] = $this->formatDate($print_to);
        }
        
        return $this->request('GetParcelList', $data);
    }
    
    /**
     * Format date for API
     */
    private function formatDate($date) {
        if (is_string($date)) {
            $date = strtotime($date);
        }
        return '/Date(' . ($date * 1000) . ')/';
    }
    
    /**
     * Build parcel data from WooCommerce order
     */
    public function buildParcelFromOrder($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }
        
        $settings = get_option('mygls_settings', []);
        
        // Get parcelshop if PSD service is used
        $parcelshop_id = get_post_meta($order_id, '_mygls_parcelshop_id', true);
        $use_parcelshop = !empty($parcelshop_id);
        
        // Build services
        $services = [];
        
        if ($use_parcelshop) {
            $services[] = [
                'Code' => 'PSD',
                'PSDParameter' => [
                    'StringValue' => $parcelshop_id
                ]
            ];
        }
        
        // COD service
        if ($order->get_payment_method() === 'cod') {
            $services[] = [
                'Code' => 'COD'
            ];
        }
        
        // FDS - Flexible Delivery Service
        if (!empty($order->get_billing_email())) {
            $services[] = [
                'Code' => 'FDS',
                'FDSParameter' => [
                    'Value' => $order->get_billing_email()
                ]
            ];
        }
        
        // SMS service if phone provided
        if (!empty($order->get_billing_phone())) {
            $services[] = [
                'Code' => 'SM2',
                'SM2Parameter' => [
                    'Value' => $this->formatPhoneNumber($order->get_billing_phone())
                ]
            ];
        }
        
        // Pickup address (from settings)
        $pickup_address = [
            'Name' => $settings['sender_name'] ?? get_bloginfo('name'),
            'Street' => $settings['sender_street'] ?? '',
            'HouseNumber' => $settings['sender_house_number'] ?? '',
            'HouseNumberInfo' => $settings['sender_house_info'] ?? '',
            'City' => $settings['sender_city'] ?? '',
            'ZipCode' => $settings['sender_zip'] ?? '',
            'CountryIsoCode' => $settings['sender_country'] ?? 'HU',
            'ContactName' => $settings['sender_contact_name'] ?? '',
            'ContactPhone' => $settings['sender_contact_phone'] ?? '',
            'ContactEmail' => $settings['sender_contact_email'] ?? ''
        ];
        
        // Delivery address
        $delivery_address = [
            'Name' => $order->get_formatted_billing_full_name(),
            'Street' => $order->get_shipping_address_1(),
            'HouseNumber' => $this->extractHouseNumber($order->get_shipping_address_1()),
            'City' => $order->get_shipping_city(),
            'ZipCode' => $order->get_shipping_postcode(),
            'CountryIsoCode' => $order->get_shipping_country(),
            'ContactName' => $order->get_formatted_billing_full_name(),
            'ContactPhone' => $this->formatPhoneNumber($order->get_billing_phone()),
            'ContactEmail' => $order->get_billing_email()
        ];
        
        // Build parcel
        $parcel = [
            'ClientNumber' => (int)$this->client_number,
            'ClientReference' => 'WC-' . $order->get_order_number(),
            'CODAmount' => $order->get_payment_method() === 'cod' ? (float)$order->get_total() : 0,
            'CODReference' => $order->get_order_number(),
            'Content' => $this->getOrderContent($order),
            'Count' => 1,
            'PickupDate' => $this->formatDate(current_time('timestamp')),
            'PickupAddress' => $pickup_address,
            'DeliveryAddress' => $delivery_address,
            'ServiceList' => $services
        ];
        
        return $parcel;
    }
    
    /**
     * Extract house number from address string
     */
    private function extractHouseNumber($address) {
        // Try to extract number from address
        preg_match('/\d+/', $address, $matches);
        return $matches[0] ?? '1';
    }
    
    /**
     * Format phone number to international format
     */
    private function formatPhoneNumber($phone) {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        // Add +36 if starts with 06 or 36
        if (strpos($phone, '06') === 0) {
            $phone = '+36' . substr($phone, 2);
        } elseif (strpos($phone, '36') === 0) {
            $phone = '+' . $phone;
        } elseif (strpos($phone, '+') !== 0) {
            $phone = '+36' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Get order content description
     */
    private function getOrderContent($order) {
        $items = [];
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        return implode(', ', array_slice($items, 0, 3)) . (count($items) > 3 ? '...' : '');
    }
}
