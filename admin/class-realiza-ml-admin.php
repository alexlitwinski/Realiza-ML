<?php

/**
 * The admin-specific functionality of the plugin.
 */

class Realiza_ML_Admin
{

    private $plugin_name;
    private $version;
    private $api;

    public function __construct($plugin_name, $version)
    {

        $this->plugin_name = $plugin_name;
        $this->version = $version;

        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-realiza-ml-api.php';
        $this->api = new Realiza_ML_API();

        add_action('wp_ajax_realiza_ml_get_categories', array($this, 'ajax_get_ml_categories'));
    }

    public function enqueue_styles()
    {
        // wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/realiza-ml-admin.css', array(), $this->version, 'all' );
    }

    public function enqueue_scripts()
    {
        // wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/realiza-ml-admin.js', array( 'jquery' ), $this->version, false );
    }

    public function add_plugin_admin_menu()
    {
        add_menu_page(
            'Realiza ML',
            'Realiza ML',
            'manage_options',
            'realiza-ml',
            array($this, 'display_plugin_setup_page'),
            'dashicons-cart',
            56
        );
    }

    public function register_settings()
    {
        register_setting('realiza_ml_options', 'realiza_ml_app_id');
        register_setting('realiza_ml_options', 'realiza_ml_secret_key');
        register_setting('realiza_ml_options', 'realiza_ml_access_token');
        register_setting('realiza_ml_options', 'realiza_ml_refresh_token');
        register_setting('realiza_ml_options', 'realiza_ml_expires_in');
        register_setting('realiza_ml_options', 'realiza_ml_user_id');
        register_setting('realiza_ml_category_mappings', 'realiza_ml_category_mappings');
    }

    public function display_plugin_setup_page()
    {
        // Check for OAuth return
        if (isset($_GET['code']) && isset($_GET['page']) && $_GET['page'] === 'realiza-ml') {
            $this->handle_oauth_callback($_GET['code']);
        }

        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'settings';

        echo '<div class="wrap">';
        echo '<h1>Realiza ML</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="?page=realiza-ml&tab=settings" class="nav-tab ' . ($active_tab == 'settings' ? 'nav-tab-active' : '') . '">Configurações</a>';
        echo '<a href="?page=realiza-ml&tab=mapping" class="nav-tab ' . ($active_tab == 'mapping' ? 'nav-tab-active' : '') . '">Mapeamento de Categorias</a>';
        echo '</h2>';

        if ($active_tab == 'mapping') {
            include_once 'partials/realiza-ml-admin-mapping.php';
        } else {
            include_once 'partials/realiza-ml-admin-display.php';
        }

        echo '</div>';
    }

    public function ajax_get_ml_categories()
    {
        $category_id = isset($_POST['category_id']) ? sanitize_text_field($_POST['category_id']) : 'MLB';

        if ($category_id === 'MLB') {
            $response = $this->api->get('/sites/MLB/categories');
        } else {
            $response = $this->api->get('/categories/' . $category_id);
        }

        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message());
        }

        // If it's a specific category detail, the children are in 'children_categories'
        if (isset($response['children_categories'])) {
            wp_send_json_success($response['children_categories']);
        } else {
            // Root categories list
            wp_send_json_success($response);
        }
    }

    public function get_auth_url()
    {
        $app_id = get_option('realiza_ml_app_id');
        $redirect_uri = admin_url('admin.php?page=realiza-ml');

        // Generate PKCE values
        $verifier = $this->generate_code_verifier();
        $challenge = $this->generate_code_challenge($verifier);

        // Store verifier for callback (valid for 10 minutes)
        set_transient('realiza_ml_verifier_' . get_current_user_id(), $verifier, 600);

        $auth_url = "https://auth.mercadolivre.com.br/authorization?response_type=code&client_id={$app_id}&redirect_uri={$redirect_uri}&code_challenge={$challenge}&code_challenge_method=S256";

        return $auth_url;
    }

    private function handle_oauth_callback($code)
    {
        $verifier = get_transient('realiza_ml_verifier_' . get_current_user_id());

        if (!$verifier) {
            add_settings_error('realiza_ml_messages', 'realiza_ml_message', 'Erro: Verificador de código expirou ou é inválido. Tente novamente.', 'error');
            return;
        }

        // Delete transient after use
        delete_transient('realiza_ml_verifier_' . get_current_user_id());

        $response = $this->api->exchange_code($code, $verifier);

        if (is_wp_error($response)) {
            add_settings_error('realiza_ml_messages', 'realiza_ml_message', 'Erro ao conectar com Mercado Livre: ' . $response->get_error_message(), 'error');
        } else {
            update_option('realiza_ml_access_token', $response['access_token']);
            update_option('realiza_ml_refresh_token', $response['refresh_token']);
            update_option('realiza_ml_expires_in', time() + $response['expires_in']);
            update_option('realiza_ml_user_id', $response['user_id']);

            add_settings_error('realiza_ml_messages', 'realiza_ml_message', 'Conectado com sucesso ao Mercado Livre!', 'updated');
        }
    }

    private function generate_code_verifier()
    {
        return $this->base64url_encode(random_bytes(32));
    }

    private function generate_code_challenge($verifier)
    {
        return $this->base64url_encode(hash('sha256', $verifier, true));
    }

    private function base64url_encode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

}
