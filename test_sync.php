<?php
require_once 'wp-load.php';

echo "Testing wc_get_orders: " . PHP_EOL;
$orders1 = wc_get_orders(['status' => 'wc-szallitas-alatt', 'limit' => 5, 'return' => 'ids']);
echo "with wc-szallitas-alatt: " . json_encode($orders1) . PHP_EOL;

$orders2 = wc_get_orders(['status' => 'szallitas-alatt', 'limit' => 5, 'return' => 'ids']);
echo "with szallitas-alatt: " . json_encode($orders2) . PHP_EOL;

// Testing ExpressOne client
if (class_exists('\ExpressOne\API\Client')) {
    $client = new \ExpressOne\API\Client();
    
    // Get a recent Express One label
    global $wpdb;
    $label = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}mygls_labels WHERE carrier = 'expressone' ORDER BY created_at DESC LIMIT 1");
    if ($label) {
        $parcel_number = $label->parcel_number;
        echo "Testing Express One parcel: $parcel_number\n";
        
        $status = $client->getParcelStatus($parcel_number);
        echo "getParcelStatus:\n";
        print_r($status);

        $history = $client->getParcelHistory($parcel_number);
        echo "getParcelHistory:\n";
        print_r($history);
    }
} else {
    echo "ExpressOne API client not found.\n";
}
