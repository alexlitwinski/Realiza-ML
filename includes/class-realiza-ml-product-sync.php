<?php

class Realiza_ML_Product_Sync
{

    private $api;

    public function __construct()
    {
        require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-realiza-ml-api.php';
        $this->api = new Realiza_ML_API();
    }

    public function init()
    {
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('wp_ajax_realiza_ml_sync_product', array($this, 'sync_product_ajax'));
    }

    public function add_meta_box()
    {
        add_meta_box(
            'realiza_ml_product_sync',
            'Mercado Livre',
            array($this, 'render_meta_box'),
            'product',
            'side',
            'default'
        );
    }

    public function render_meta_box($post)
    {
        $ml_id = get_post_meta($post->ID, '_realiza_ml_id', true);
        $last_sync = get_post_meta($post->ID, '_realiza_ml_last_sync', true);

        echo '<div id="realiza-ml-sync-wrapper">';

        if ($ml_id) {
            echo '<p><strong>Status:</strong> Sincronizado</p>';
            echo '<p><strong>ML ID:</strong> <a href="https://mercadolibre.com.br/jm/item?id=' . esc_attr($ml_id) . '" target="_blank">' . esc_html($ml_id) . '</a></p>';
            if ($last_sync) {
                echo '<p><strong>Última Sincronização:</strong> ' . esc_html($last_sync) . '</p>';
            }
            echo '<button type="button" class="button button-primary" id="realiza-ml-sync-btn" data-id="' . $post->ID . '">Atualizar no Mercado Livre</button>';
        } else {
            echo '<p><strong>Status:</strong> Não sincronizado</p>';
            echo '<button type="button" class="button button-primary" id="realiza-ml-sync-btn" data-id="' . $post->ID . '">Enviar para Mercado Livre</button>';
        }

        echo '<p id="realiza-ml-status-msg" style="margin-top: 10px;"></p>';
        echo '</div>';

        ?>
        <script>
            jQuery(document).ready(function ($) {
                $('#realiza-ml-sync-btn').on('click', function () {
                    var btn = $(this);
                    var productId = btn.data('id');
                    var msg = $('#realiza-ml-status-msg');

                    btn.prop('disabled', true).text('Processando...');
                    msg.text('');

                    $.post(ajaxurl, {
                        action: 'realiza_ml_sync_product',
                        product_id: productId
                    }, function (response) {
                        if (response.success) {
                            msg.css('color', 'green').text(response.data.message);
                            setTimeout(function () { location.reload(); }, 2000);
                        } else {
                            msg.css('color', 'red').text('Erro: ' + response.data.message);
                            btn.prop('disabled', false).text('Tentar Novamente');
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public function sync_product_ajax()
    {
        if (!current_user_can('edit_products')) {
            wp_send_json_error(array('message' => 'Permissão negada.'));
        }

        $product_id = intval($_POST['product_id']);
        if (!$product_id) {
            wp_send_json_error(array('message' => 'ID do produto inválido.'));
        }

        $result = $this->sync_product($product_id);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            wp_send_json_success(array('message' => 'Produto sincronizado com sucesso!'));
        }
    }

    private function sync_product($product_id)
    {
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('invalid_product', 'Produto não encontrado.');
        }

        // Map data
        $data = array(
            'title' => $product->get_name(),
            'category_id' => 'MLB3530', // Default category (Others) - In real app, needs mapping
            'price' => (float) $product->get_price(),
            'currency_id' => 'BRL',
            'available_quantity' => (int) $product->get_stock_quantity() ?: 1,
            'buying_mode' => 'buy_it_now',
            'listing_type_id' => 'gold_pro', // Default listing type
            'condition' => 'new',
            'description' => array(
                'plain_text' => strip_tags($product->get_description())
            ),
            'pictures' => array(),
            'attributes' => array()
        );

        // Attributes
        $brand = 'Genérica';
        $model = 'Padrão';

        $attributes = $product->get_attributes();
        foreach ($attributes as $attribute) {
            $name = $attribute->get_name();
            if (stripos($name, 'marca') !== false || stripos($name, 'brand') !== false) {
                if ($attribute->is_taxonomy()) {
                    $terms = wp_get_post_terms($product->get_id(), $name, 'all');
                    if (!empty($terms)) {
                        $brand = $terms[0]->name;
                    }
                } else {
                    $options = $attribute->get_options();
                    if (!empty($options)) {
                        $brand = $options[0];
                    }
                }
            }
            if (stripos($name, 'modelo') !== false || stripos($name, 'model') !== false) {
                if ($attribute->is_taxonomy()) {
                    $terms = wp_get_post_terms($product->get_id(), $name, 'all');
                    if (!empty($terms)) {
                        $model = $terms[0]->name;
                    }
                } else {
                    $options = $attribute->get_options();
                    if (!empty($options)) {
                        $model = $options[0];
                    }
                }
            }
        }

        $data['attributes'][] = array(
            'id' => 'BRAND',
            'value_name' => $brand
        );
        $data['attributes'][] = array(
            'id' => 'MODEL',
            'value_name' => $model
        );

        // Images
        $image_id = $product->get_image_id();
        if ($image_id) {
            $data['pictures'][] = array('source' => wp_get_attachment_url($image_id));
        }
        $gallery_ids = $product->get_gallery_image_ids();
        foreach ($gallery_ids as $gid) {
            $data['pictures'][] = array('source' => wp_get_attachment_url($gid));
        }

        // Check if exists
        $ml_id = get_post_meta($product_id, '_realiza_ml_id', true);

        if ($ml_id) {
            // Update
            // ML API for update is different, usually PUT /items/{id}
            // Note: ML doesn't allow updating all fields via PUT. Some need specific endpoints.
            // For MVP, let's just try to update price and stock which are most common.

            $update_data = array(
                'price' => $data['price'],
                'available_quantity' => $data['available_quantity'],
                'title' => $data['title'],
                'attributes' => $data['attributes']
            );

            $response = $this->api->post('/items/' . $ml_id, $update_data, 'PUT'); // Assuming API class handles method or we need to adjust it
        } else {
            // Create
            $response = $this->api->post('/items', $data);
        }

        if (is_wp_error($response)) {
            return $response;
        }

        if (isset($response['id'])) {
            update_post_meta($product_id, '_realiza_ml_id', $response['id']);
            update_post_meta($product_id, '_realiza_ml_last_sync', current_time('mysql'));
            return true;
        } else {
            return new WP_Error( 'api_error', $this->format_error_message( $response ) );
        }
    }

    private function format_error_message( $response ) {
        if ( isset( $response['message'] ) && $response['message'] === 'Validation error' && isset( $response['cause'] ) ) {
            $messages = array();
            foreach ( $response['cause'] as $cause ) {
                if ( isset( $cause['message'] ) ) {
                    // Translate common messages if needed, or just show them
                    $msg = $cause['message'];
                    if ( strpos( $msg, 'The attributes [MODEL, BRAND] are required' ) !== false ) {
                        $msg = 'Os atributos Marca e Modelo são obrigatórios para esta categoria.';
                    }
                    $messages[] = $msg;
                }
            }
            return 'Erro de validação: ' . implode( ' ', $messages );
        }
        
        if ( isset( $response['message'] ) ) {
            return 'Erro na API: ' . $response['message'];
        }

        return 'Erro desconhecido na API: ' . json_encode( $response );
    }
}
