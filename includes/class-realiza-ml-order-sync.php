<?php

class Realiza_ML_Order_Sync {

    private $api;

    public function __construct() {
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-realiza-ml-api.php';
        $this->api = new Realiza_ML_API();
    }

    public function init() {
        add_action( 'rest_api_init', array( $this, 'register_webhook_route' ) );
    }

    public function register_webhook_route() {
        register_rest_route( 'realiza-ml/v1', '/notifications', array(
            'methods' => 'POST',
            'callback' => array( $this, 'handle_notification' ),
            'permission_callback' => '__return_true',
        ) );
    }

    public function handle_notification( $request ) {
        $body = $request->get_json_params();

        // Mercado Livre sends a notification with resource and topic
        // Example: { "resource": "/orders/123456", "topic": "orders", ... }
        
        if ( ! isset( $body['topic'] ) || ! isset( $body['resource'] ) ) {
            return new WP_Error( 'invalid_data', 'Invalid notification data', array( 'status' => 400 ) );
        }

        if ( $body['topic'] === 'orders_v2' || $body['topic'] === 'orders' ) {
            $this->process_order( $body['resource'] );
        }

        return new WP_REST_Response( array( 'status' => 'ok' ), 200 );
    }

    private function process_order( $resource ) {
        // Resource is like "/orders/123456"
        $order_data = $this->api->get( $resource );

        if ( is_wp_error( $order_data ) ) {
            error_log( 'Realiza ML: Error fetching order ' . $resource );
            return;
        }

        $ml_order_id = $order_data['id'];

        // Check if order already exists
        $args = array(
            'meta_key' => '_realiza_ml_order_id',
            'meta_value' => $ml_order_id,
            'post_type' => 'shop_order',
            'post_status' => 'any',
            'numberposts' => 1
        );
        $existing = get_posts( $args );

        if ( ! empty( $existing ) ) {
            // Order already exists, maybe update status?
            return;
        }

        $this->create_woo_order( $order_data );
    }

    private function create_woo_order( $data ) {
        $order = wc_create_order();

        // Add items
        foreach ( $data['order_items'] as $item ) {
            // Try to find product by ML ID stored in meta
            $args = array(
                'meta_key' => '_realiza_ml_id',
                'meta_value' => $item['item']['id'],
                'post_type' => 'product',
                'numberposts' => 1
            );
            $products = get_posts( $args );
            
            if ( ! empty( $products ) ) {
                $product = wc_get_product( $products[0]->ID );
                $order->add_product( $product, $item['quantity'] );
            } else {
                // Product not found, maybe add as custom item or handle error
                // For MVP, adding as custom item with name
                $item_id = $order->add_item( array(
                    'name' => $item['item']['title'],
                    'qty' => $item['quantity'],
                    'tax_class' => '',
                    'total' => $item['unit_price'] * $item['quantity'],
                ) );
            }
        }

        // Set address
        $buyer = $data['buyer'];
        // ML API structure for buyer address varies, simplifying for MVP
        // Usually we need to fetch shipment details for full address
        
        $address = array(
            'first_name' => $buyer['first_name'],
            'last_name'  => $buyer['last_name'],
            'email'      => $buyer['email'] ?? 'no-email@mercadolibre.com', // ML often hides email
            // 'phone'      => $buyer['phone']['number'],
        );

        $order->set_address( $address, 'billing' );
        $order->set_address( $address, 'shipping' );

        $order->calculate_totals();
        $order->update_status( 'processing', 'Importado do Mercado Livre' );
        
        update_post_meta( $order->get_id(), '_realiza_ml_order_id', $data['id'] );
        update_post_meta( $order->get_id(), '_realiza_ml_raw_data', json_encode( $data ) );
    }
}
