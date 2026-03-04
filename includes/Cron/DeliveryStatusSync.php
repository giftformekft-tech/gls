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
            'status'  => 'wc-szallitas-alatt',
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

        $status_list = $result['ParcelStatusList'] ?? [];

        foreach ( $status_list as $status ) {
            // A GLS API "DEL" kóddal jelzi a sikeres kézbesítést
            $code = $status['StatusCode'] ?? '';
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

            // DEL = Kézbesítve, DLV = Kézbesítve (egyes verziókban)
            if ( in_array( strtoupper( $event_code ), [ 'DEL', 'DLV' ], true ) ) {
                mygls_log( "DeliveryStatusSync EOne kézbesítve (status): {$parcel_number} [{$event_code}]", 'info' );
                return true;
            }
        }

        // --- 2. próba: get_parcel_history (state = 4 = leadva/kézbesítve) ---
        $history_result = $this->expressone_get_parcel_history( $client, $parcel_number );

        if ( $history_result !== null ) {
            $state = (int) ( $history_result['state'] ?? -1 );
            if ( $state === 4 ) {
                mygls_log( "DeliveryStatusSync EOne kézbesítve (history state=4): {$parcel_number}", 'info' );
                return true;
            }

            // Alternatíva: az esemény-listából is megállapítható
            $history = $history_result['history'] ?? [];
            foreach ( $history as $event ) {
                $ec = strtoupper( $event['event_code'] ?? '' );
                if ( in_array( $ec, [ 'DEL', 'DLV' ], true ) ) {
                    mygls_log( "DeliveryStatusSync EOne kézbesítve (history event): {$parcel_number} [{$ec}]", 'info' );
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Express One csomag-történet lekérdezése.
     * Az API kliens egyelőre nem tartalmaz erre dedikált metódust, ezért itt hívjuk.
     *
     * @param \ExpressOne\API\Client $client
     * @param string                 $parcel_number
     * @return array|null  A response tömb, vagy null hiba esetén
     */
    private function expressone_get_parcel_history( $client, $parcel_number ) {
        // A request() metódus private, ezért wp_remote_request-tel hívjuk
        $settings = get_option( 'expressone_settings', [] );

        $company_id = $settings['company_id'] ?? '';
        $user_name  = $settings['user_name']  ?? '';
        $password   = $settings['password']   ?? '';

        if ( empty( $company_id ) || empty( $user_name ) || empty( $password ) ) {
            return null;
        }

        $url  = 'https://webservice.expressone.hu/tracking/get_parcel_history/response_format/json';
        $body = wp_json_encode( [
            'auth'          => [
                'company_id' => $company_id,
                'user_name'  => $user_name,
                'password'   => $password,
            ],
            'parcel_number' => $parcel_number,
        ] );

        $response = wp_remote_post( $url, [
            'headers'     => [ 'Content-Type' => 'application/json' ],
            'body'        => $body,
            'timeout'     => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            mygls_log( 'DeliveryStatusSync EOne history hiba: ' . $response->get_error_message(), 'error' );
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! empty( $data['successfull'] ) && isset( $data['response'] ) ) {
            return $data['response'];
        }

        return null;
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
