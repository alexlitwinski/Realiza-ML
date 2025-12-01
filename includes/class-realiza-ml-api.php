<?php

class Realiza_ML_API
{

    private $api_base = 'https://api.mercadolibre.com';

    public function exchange_code($code, $code_verifier)
    {
        $app_id = get_option('realiza_ml_app_id');
        $secret_key = get_option('realiza_ml_secret_key');
        $redirect_uri = admin_url('admin.php?page=realiza-ml');

        $body = array(
            'grant_type' => 'authorization_code',
            'client_id' => $app_id,
            'client_secret' => $secret_key,
            'code' => $code,
            'redirect_uri' => $redirect_uri,
            'code_verifier' => $code_verifier,
        );

        error_log('Realiza ML Debug - Exchange Code Body: ' . print_r($body, true));

        $response = wp_remote_post($this->api_base . '/oauth/token', array(
            'body' => $body,
            'timeout' => 45,
        ));

        error_log('Realiza ML Debug - Exchange Code Response: ' . print_r($response, true));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error($body['error'], $body['message'] ?? 'Unknown error');
        }

        return $body;
    }

    public function refresh_token()
    {
        $app_id = get_option('realiza_ml_app_id');
        $secret_key = get_option('realiza_ml_secret_key');
        $refresh_token = get_option('realiza_ml_refresh_token');

        if (!$refresh_token) {
            return new WP_Error('missing_refresh_token', 'Você precisa conectar ao Mercado Livre nas configurações do plugin.');
        }

        $body = array(
            'grant_type' => 'refresh_token',
            'client_id' => $app_id,
            'client_secret' => $secret_key,
            'refresh_token' => $refresh_token,
        );

        $response = wp_remote_post($this->api_base . '/oauth/token', array(
            'body' => $body,
            'timeout' => 45,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error($body['error'], $body['message'] ?? 'Erro desconhecido ao atualizar token');
        }

        if (isset($body['access_token'])) {
            update_option('realiza_ml_access_token', $body['access_token']);
            update_option('realiza_ml_refresh_token', $body['refresh_token']);
            update_option('realiza_ml_expires_in', time() + $body['expires_in']);
            return $body['access_token'];
        }

        return new WP_Error('refresh_failed', 'Falha ao atualizar token. Resposta inesperada.');
    }

    public function get_access_token()
    {
        $expires = get_option('realiza_ml_expires_in');

        // Se expirou ou não existe expiração (0), tenta refresh
        if (!$expires || time() > $expires) {
            return $this->refresh_token();
        }

        $token = get_option('realiza_ml_access_token');
        if (!$token) {
            return $this->refresh_token(); // Tenta refresh se não tiver token salvo mas tiver expiração válida (estranho, mas seguro)
        }

        return $token;
    }

    /**
     * Generic GET request
     */
    public function get($endpoint)
    {
        $token = $this->get_access_token();
        if (is_wp_error($token))
            return $token;
        if (!$token)
            return new WP_Error('no_token', 'Token de acesso não disponível.');

        $response = wp_remote_get($this->api_base . $endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
            )
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        if (wp_remote_retrieve_response_code($response) == 401) {
            return new WP_Error('token_expired', 'Token expirado ou inválido. Por favor, reconecte nas configurações.');
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Generic POST/PUT request
     */
    public function post($endpoint, $data, $method = 'POST')
    {
        $token = $this->get_access_token();
        if (is_wp_error($token))
            return $token;
        if (!$token)
            return new WP_Error('no_token', 'Token de acesso não disponível.');

        $response = wp_remote_request($this->api_base . $endpoint, array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
            ),
            'body' => json_encode($data),
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        if (wp_remote_retrieve_response_code($response) == 401) {
            return new WP_Error('token_expired', 'Token expirado ou inválido. Por favor, reconecte nas configurações.');
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }
}
