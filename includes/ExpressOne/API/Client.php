<?php
/**
 * Express One API Client
 * Handles all API communication with Express One
 */

namespace ExpressOne\API;

if (!defined('ABSPATH')) {
    exit;
}

class Client {
    private $company_id;
    private $user_name;
    private $password;
    private $api_url = 'https://webservice.expressone.hu';
    
    public function __construct() {
        $settings = get_option('expressone_settings', []);
        
        $this->company_id = $settings['company_id'] ?? '';
        $this->user_name = $settings['user_name'] ?? '';
        $this->password = $settings['password'] ?? '';
        
        // A teszt mód esetén egy másik előfizetést vagy flag-et kellhet használni az Express One-nál, 
        // de általában a hozzáférés dönti el. Az API URL u.a.
    }
    
    /**
     * Alap auth tömb összeállítása
     */
    private function getAuth() {
        return [
            'company_id' => $this->company_id,
            'user_name' => $this->user_name,
            'password' => $this->password
        ];
    }
    
    /**
     * Make API request
     */
    private function request($group, $method, $data = [], $options = 'response_format/json') {
        if (empty($this->company_id) || empty($this->user_name) || empty($this->password)) {
            return ['successfull' => false, 'error' => 'Express One credentials are not configured'];
        }

        $url = "{$this->api_url}/{$group}/{$method}/{$options}";
        
        // Auth mindig kötelező
        $request_data = ['auth' => $this->getAuth()];
        $request_data = array_merge($request_data, $data);

        $json_body = wp_json_encode($request_data);

        $args = [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => $json_body,
            'timeout' => 60,
            'data_format' => 'body'
        ];

        mygls_log("ExpressOne API Request to {$group}/{$method}", 'debug');
        mygls_log("Request URL: {$url}", 'debug');
        mygls_log("Request body: " . $json_body, 'debug');

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            mygls_log("ExpressOne API Error: " . $response->get_error_message(), 'error');
            return ['successfull' => false, 'error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            mygls_log("ExpressOne API Error: Invalid JSON response", 'error');
            return ['successfull' => false, 'error' => 'Invalid JSON response from ExpressOne API'];
        }

        mygls_log("ExpressOne API Response from {$method}: " . $body, 'debug');

        if (isset($result['successfull']) && $result['successfull'] === false) {
            $error_message = $result['error_messages'] ?? ($result['error_messages'] ?? 'Unknown API error');
            $error_code = $result['error_code'] ?? '';
            mygls_log("ExpressOne API Error Response: {$error_message} (Code: {$error_code})", 'error');
            return ['successfull' => false, 'error' => $error_message, 'code' => $error_code];
        }

        return $result;
    }
    
    /**
     * Create Delivery Labels
     */
    public function createLabels($deliveries, $label_settings = null) {
        if (!$label_settings) {
            $label_settings = [
                'data_type' => 'PDF',
                'size' => 'A4',
                'dpi' => '300',
                'pdf_etiket_position' => '0'
            ];
        }

        $data = [
            'deliveries' => $deliveries,
            'labels' => $label_settings
        ];
        
        return $this->request('parcel', 'create_labels', $data);
    }
    
    /**
     * Delete Labels
     */
    public function deleteLabels($parcel_number) {
        $data = [
            'parcel_number' => $parcel_number
        ];
        
        return $this->request('parcel', 'delete_labels', $data);
    }

    /**
     * Get Parcel Label
     */
    public function getParcelLabel($parcel_number, $label_settings = null) {
        if (!$label_settings) {
            $label_settings = [
                'data_type' => 'PDF',
                'size' => 'A4',
                'dpi' => '300',
                'pdf_etiket_position' => '0'
            ];
        }

        $data = [
            'parcel_number' => $parcel_number,
            'labels' => $label_settings
        ];
        
        return $this->request('parcel_label', 'get_parcel_label', $data);
    }

    /**
     * Get Parcel Status
     */
    public function getParcelStatus($parcel_number) {
        $data = [
            'parcel_number' => $parcel_number
        ];
        
        return $this->request('tracking', 'get_parcel_status', $data);
    }

    /**
     * Get Parcel Shops
     */
    public function getParcelShops() {
        return $this->request('eoneshop', 'get_list');
    }
    
    /**
     * Format phone number
     */
    private function formatPhoneNumber($phone) {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        if (strpos($phone, '06') === 0) {
            $phone = '+36' . substr($phone, 2);
        } elseif (strpos($phone, '36') === 0) {
            $phone = '+' . $phone;
        } elseif (strpos($phone, '+') !== 0) {
            $phone = '+36' . $phone;
        }
        return substr($phone, 0, 20); // API max 20 kar
    }

    /**
     * Build parcel data from WooCommerce order (Express One structure)
     */
    public function buildParcelFromOrder($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return null;
        }
        
        // Delivery address
        $shipping_address_1 = $order->get_shipping_address_1();
        $contact_name = $order->get_formatted_billing_full_name();
        if (empty($contact_name)) {
            $contact_name = $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name();
        }

        $consig = [
            'name' => $contact_name,
            'contact_name' => $contact_name,
            'city' => substr($order->get_shipping_city(), 0, 25),
            'street' => substr($shipping_address_1, 0, 100),
            'country' => substr($order->get_shipping_country(), 0, 2),
            'post_code' => substr($order->get_shipping_postcode(), 0, 6),
            'phone' => $this->formatPhoneNumber($order->get_billing_phone())
        ];

        // Parcels info
        $items = [];
        $weight = 0;
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name() . ' x' . $item->get_quantity();
            $product = $item->get_product();
            if ($product && $product->get_weight()) {
                $weight += (float)$product->get_weight() * $item->get_quantity();
            }
        }
        
        if ($weight <= 0) $weight = 1; // Min 1 kg (Express One kerekít)

        $parcels = [
            'type' => 0, // 0 - parcel, 1 - pallet
            'qty' => 1,
            'weight' => ceil($weight),
            'parcel_name' => substr(implode(', ', $items), 0, 100)
        ];

        // Services
        $is_cod = $order->get_payment_method() === 'cod';
        $services = [
            'delivery_type' => '24H' // Alap standard kézbesítés
        ];

        if ($is_cod) {
            $services['cod'] = [
                'amount' => (float)$order->get_total(),
                'itemized' => false
            ];
        }
        
        $parcelshop_id = get_post_meta($order_id, '_expressone_parcelshop_id', true);
        if (!empty($parcelshop_id)) {
            $services['delivery_type'] = 'D2S'; // Kiszállítás shop-ba
            $services['eone_shop'] = $parcelshop_id;
        }

        // Email és SMS értesítés
        $email = $order->get_billing_email();
        $phone = $this->formatPhoneNumber($order->get_billing_phone());
        if (!empty($email) || !empty($phone)) {
            $services['notification'] = [];
            if (!empty($email)) $services['notification']['email'] = $email;
            if (!empty($phone)) $services['notification']['sms'] = $phone;
        }

        // Végleges delivery tömb
        $delivery = [
            'post_date' => current_time('Y-m-d'),
            'consig' => $consig,
            'parcels' => $parcels,
            'services' => $services,
            'ref_number' => substr('WC-' . $order->get_order_number(), 0, 50)
        ];

        return $delivery;
    }
}
