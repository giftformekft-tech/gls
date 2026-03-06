<?php
/**
 * Delivery Status Sync (Cron)
 * 6 óránként lekérdezi a GLS / Express One API-t és ha a csomag kézbesítve,
 * átállítja a WooCommerce rendelés státuszát "teljesítve"-re.
 */

namespace MyGLS\Cron;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DeliveryStatusSync {

    public function __construct() {
        add_action( 'mygls_sync_delivery_statuses', [ $this, 'run' ] );
    }

    /**
     * Fő futtatási logika – ezt hívja meg a WP cron hook.
     */
    public function run() {
        mygls_log( 'DeliveryStatusSync: futás kezdete', 'info' );

        global $wpdb;

        // Csak "Szállítás alatt" státuszú rendelések
        $orders = wc_get_orders( [
            'status'  => [ 'wc-szallitas-alatt', 'szallitas-alatt' ],
            'limit'   => -1,
            'return'  => 'ids',
        ] );

        if ( empty( $orders ) ) {
            mygls_log( 'DeliveryStatusSync: nincs "Szállítás alatt" rendelés', 'info' );
            return;
        }

        mygls_log( 'DeliveryStatusSync: ' . count( $orders ) . ' rendelés vizsgálata', 'info' );

        // API kliensek – csak egyszer példányosítjuk
        $gls_client  = null;
        $eone_client = null;

        foreach ( $orders as $order_id ) {
            // Label rekord a saját táblánkból
            $label = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}mygls_labels WHERE order_id = %d ORDER BY created_at DESC LIMIT 1",
                $order_id
            ) );

            if ( ! $label ) {
                mygls_log( "DeliveryStatusSync: #{$order_id} rendeléshez nincs label rekord, kihagyjuk", 'debug' );
                continue;
            }

            // Már rögzített "delivered" → kihagyjuk
            if ( $label->status === 'delivered' ) {
                continue;
            }

            $parcel_number = $label->parcel_number;
            $carrier       = $label->carrier;

            mygls_log( "DeliveryStatusSync: #{$order_id} – carrier={$carrier}, parcel={$parcel_number}", 'debug' );

            $is_delivered = false;

            if ( $carrier === 'expressone' ) {
                $is_delivered = $this->check_expressone( $parcel_number, $eone_client );
            } else {
                // GLS (alapértelmezett)
                $is_delivered = $this->check_gls( $parcel_number, $gls_client );
            }

            if ( $is_delivered ) {
                $this->mark_order_completed( $order_id, $label->id, $carrier, $parcel_number );
            }
        }

        mygls_log( 'DeliveryStatusSync: futás vége', 'info' );
    }

    // -------------------------------------------------------------------------
    // GLS státusz ellenőrzés
    // -------------------------------------------------------------------------

    /**
     * @param string          $parcel_number
     * @param \MyGLS\API\Client|null $client  (referencia – csak egyszer példányosítjuk)
     * @return bool
     */
    private function check_gls( $parcel_number, &$client ) {
        if ( $client === null ) {
            if ( ! function_exists( 'mygls_get_api_client' ) ) {
                return false;
            }
            $client = mygls_get_api_client();
        }

        if ( ! $client ) {
            return false;
        }

        $result = $client->getParcelStatuses( $parcel_number );

        if ( isset( $result['error'] ) ) {
            mygls_log( "DeliveryStatusSync GLS hiba ({$parcel_number}): " . $result['error'], 'error' );
            return false;
        }

        // Debug: nyers API válasz naplózása
        mygls_log( "DeliveryStatusSync GLS nyers válasz ({$parcel_number}): " . wp_json_encode( $result ), 'debug' );

        // A GLS API válasza {"d": {"ParcelStatusList": [...]}} formátumú
        $data        = $result['d'] ?? $result;
        $status_list = $data['ParcelStatusList'] ?? [];

        mygls_log( "DeliveryStatusSync GLS státuszok ({$parcel_number}): " . wp_json_encode( $status_list ), 'debug' );

        foreach ( $status_list as $status ) {
            // A GLS API "DEL" kóddal jelzi a sikeres kézbesítést
            $code = $status['StatusCode'] ?? '';
            mygls_log( "DeliveryStatusSync GLS státuszkód ({$parcel_number}): '{$code}'", 'debug' );
            if ( $code === 'DEL' ) {
                mygls_log( "DeliveryStatusSync GLS kézbesítve: {$parcel_number}", 'info' );
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Express One státusz ellenőrzés
    // -------------------------------------------------------------------------

    /**
     * @param string                      $parcel_number
     * @param \ExpressOne\API\Client|null $client  (referencia)
     * @return bool
     */
    private function check_expressone( $parcel_number, &$client ) {
        if ( $client === null ) {
            if ( ! class_exists( 'ExpressOne\\API\\Client' ) ) {
                return false;
            }
            $client = new \ExpressOne\API\Client();
        }

        // --- 1. próba: get_parcel_status (utolsó esemény) ---
        $result = $client->getParcelStatus( $parcel_number );

        if ( ! isset( $result['error'] ) ) {
            $response   = $result['response'] ?? [];
            $event_code = $response['event_code'] ?? '';
            $event_name = mb_strtolower( $response['event_name'] ?? '', 'UTF-8' );

            // DEL = Kézbesítve, DLV = Kézbesítve
            // POD = Proof of Delivery
            if ( in_array( strtoupper( $event_code ), [ 'DEL', 'DLV', 'POD' ], true ) || strpos( $event_name, 'sikeres kézbesítés' ) !== false ) {
                mygls_log( "DeliveryStatusSync EOne kézbesítve (status): {$parcel_number} [{$event_code}]", 'info' );
                return true;
            }
        }

        // --- 2. próba: get_parcel_history ---
        $history_result = $client->getParcelHistory( $parcel_number );

        if ( ! isset( $history_result['error'] ) && !empty( $history_result['response'] ) ) {
            $response = $history_result['response'];
            $state = (int) ( $response['state'] ?? -1 );
            
            if ( $state === 4 ) {
                mygls_log( "DeliveryStatusSync EOne kézbesítve (history state=4): {$parcel_number}", 'info' );
                return true;
            }

            // Alternatíva: az esemény-listából is megállapítható
            $history = $response['history'] ?? [];
            foreach ( $history as $event ) {
                $ec = strtoupper( $event['event_code'] ?? '' );
                $en = mb_strtolower( $event['event_name'] ?? '', 'UTF-8' );
                
                if ( in_array( $ec, [ 'DEL', 'DLV', 'POD' ], true ) || strpos( $en, 'sikeres kézbesítés' ) !== false ) {
                    mygls_log( "DeliveryStatusSync EOne kézbesítve (history event): {$parcel_number} [{$ec}]", 'info' );
                    return true;
                }
            }
        }

        return false;
    }


    // -------------------------------------------------------------------------
    // Rendelés lezárása
    // -------------------------------------------------------------------------

    /**
     * Rendelés átállítása "teljesítve"-re, label rekord frissítése.
     */
    private function mark_order_completed( $order_id, $label_db_id, $carrier, $parcel_number ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Csak ha még nem teljesítve (elkerüljük a dupla átállítást)
        if ( $order->get_status() === 'completed' ) {
            return;
        }

        $order->update_status(
            'completed',
            sprintf(
                __( '%s csomag kézbesítve – automatikusan teljesítve. Csomagszám: %s', 'mygls-woocommerce' ),
                strtoupper( $carrier ),
                $parcel_number
            )
        );

        mygls_log( "DeliveryStatusSync: #{$order_id} rendelés teljesítve ({$carrier} / {$parcel_number})", 'info' );

        // Label tábla frissítése
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'mygls_labels',
            [ 'status' => 'delivered' ],
            [ 'id'     => $label_db_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }
}
