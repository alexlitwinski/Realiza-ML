<?php

class Realiza_ML_API {

    private $api_base = 'https://api.mercadolibre.com';

    public function exchange_code( $code ) {
        $app_id = get_option( 'realiza_ml_app_id' );
        $secret_key = get_option( 'realiza_ml_secret_key' );
        $redirect_uri = admin_url( 'admin.php?page=realiza-ml' );

        $body = array(
            'grant_type' => 'authorization_code',
            'client_id' => $app_id,
            'client_secret' => $secret_key,
            'code' => $code,
            'redirect_uri' => $redirect_uri,
        );

        $response = wp_remote_post( $this->api_base . '/oauth/token', array(
            'body' => $body,
            'timeout' => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error( $body['error'], $body['message'] ?? 'Unknown error' );
        }

        return $body;
    }

    public function refresh_token() {
        $app_id = get_option( 'realiza_ml_app_id' );
        $secret_key = get_option( 'realiza_ml_secret_key' );
        $refresh_token = get_option( 'realiza_ml_refresh_token' );

        $body = array(
            'grant_type' => 'refresh_token',
            'client_id' => $app_id,
            'client_secret' => $secret_key,
            'refresh_token' => $refresh_token,
        );

        $response = wp_remote_post( $this->api_base . '/oauth/token', array(
            'body' => $body,
            'timeout' => 45,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['access_token'] ) ) {
            update_option( 'realiza_ml_access_token', $body['access_token'] );
            update_option( 'realiza_ml_refresh_token', $body['refresh_token'] );
            update_option( 'realiza_ml_expires_in', time() + $body['expires_in'] );
            return $body['access_token'];
        }

        return false;
    }

    public function get_access_token() {
        $expires = get_option( 'realiza_ml_expires_in' );
        if ( time() > $expires ) {
            return $this->refresh_token();
        }
        return get_option( 'realiza_ml_access_token' );
    }

    /**
     * Generic GET request
     */
    public function get( $endpoint ) {
        $token = $this->get_access_token();
        if ( ! $token ) return new WP_Error( 'no_token', 'No access token available' );

        $response = wp_remote_get( $this->api_base . $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
            )
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /**
     * Generic POST/PUT request
     */
    public function post( $endpoint, $data, $method = 'POST' ) {
        $token = $this->get_access_token();
        if ( ! $token ) return new WP_Error( 'no_token', 'No access token available' );

        $response = wp_remote_request( $this->api_base . $endpoint, array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode( $data ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }
}
