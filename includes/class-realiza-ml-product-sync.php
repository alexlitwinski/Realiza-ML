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
        add_action('save_post', array($this, 'save_meta_box'));
        add_action('wp_ajax_realiza_ml_sync_product', array($this, 'sync_product_ajax'));
        add_action('wp_ajax_realiza_ml_get_category_attributes', array($this, 'ajax_get_category_attributes'));
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

    public function save_meta_box($post_id)
    {
        if (!isset($_POST['realiza_ml_attributes_nonce']) || !wp_verify_nonce($_POST['realiza_ml_attributes_nonce'], 'realiza_ml_save_attributes')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['realiza_ml_attributes'])) {
            $attributes = array_map('sanitize_text_field', $_POST['realiza_ml_attributes']);
            update_post_meta($post_id, '_realiza_ml_attributes', $attributes);
        }
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

        // Attributes Section
        echo '<hr>';
        echo '<h4>Atributos do Mercado Livre</h4>';
        echo '<div id="realiza-ml-attributes-container">';
        echo '<p>Carregando atributos...</p>';
        echo '</div>';

        // Nonce for saving attributes
        wp_nonce_field('realiza_ml_save_attributes', 'realiza_ml_attributes_nonce');

        echo '</div>';

        ?>
        <script>
            jQuery(document).ready(function ($) {
                var productId = <?php echo $post->ID; ?>;
                var container = $('#realiza-ml-attributes-container');

                function loadAttributes() {
                    $.post(ajaxurl, {
                        action: 'realiza_ml_get_category_attributes',
                        product_id: productId
                    }, function (response) {
                        if (response.success) {
                            container.html(response.data);
                        } else {
                            container.html('<p style="color:red">Erro ao carregar atributos: ' + response.data + '</p>');
                        }
                    });
                }

                loadAttributes();

                $('#realiza-ml-sync-btn').on('click', function () {
                    var btn = $(this);
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

    public function ajax_get_category_attributes()
    {
        $product_id = intval($_POST['product_id']);
        if (!$product_id) {
            wp_send_json_error('ID do produto inválido.');
        }

        // Get mapped category
        $ml_category_id = 'MLB3530'; // Default
        $mappings = get_option('realiza_ml_category_mappings', array());
        $product_cats = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));

        if (!empty($product_cats) && !empty($mappings)) {
            foreach ($product_cats as $cat_id) {
                if (!empty($mappings[$cat_id])) {
                    $ml_category_id = $mappings[$cat_id];
                    break;
                }
            }
        }

        // Fetch attributes from ML
        $response = $this->api->get('/categories/' . $ml_category_id . '/attributes');

        if (is_wp_error($response)) {
            wp_send_json_error('Erro ao buscar atributos: ' . $response->get_error_message());
        }

        // Get saved attributes
        $saved_attributes = get_post_meta($product_id, '_realiza_ml_attributes', true);
        if (!is_array($saved_attributes)) {
            $saved_attributes = array();
        }

        ob_start();

        if (empty($response)) {
            echo '<p>Nenhum atributo obrigatório encontrado para esta categoria.</p>';
        } else {
            foreach ($response as $attr) {
                // We only care about required attributes or allow_variations for now, 
                // but let's just show required ones to keep it simple for MVP
                if (isset($attr['tags']) && isset($attr['tags']['required']) && $attr['tags']['required'] === true) {
                    $value = isset($saved_attributes[$attr['id']]) ? $saved_attributes[$attr['id']] : '';

                    // Try to auto-fill from WC attributes if empty
                    if (empty($value)) {
                        $product = wc_get_product($product_id);
                        $wc_attributes = $product->get_attributes();
                        foreach ($wc_attributes as $wc_attr) {
                            // Simple name matching
                            if (stripos($wc_attr->get_name(), $attr['name']) !== false) {
                                if ($wc_attr->is_taxonomy()) {
                                    $terms = wp_get_post_terms($product->get_id(), $wc_attr->get_name(), 'all');
                                    if (!empty($terms))
                                        $value = $terms[0]->name;
                                } else {
                                    $options = $wc_attr->get_options();
                                    if (!empty($options))
                                        $value = $options[0];
                                }
                            }
                        }
                    }

                    echo '<div class="realiza-ml-attribute-field" style="margin-bottom: 10px;">';
                    echo '<label style="display:block; font-weight:bold;">' . esc_html($attr['name']) . ' <span style="color:red">*</span></label>';

                    if (isset($attr['values']) && !empty($attr['values'])) {
                        echo '<select name="realiza_ml_attributes[' . esc_attr($attr['id']) . ']" style="width:100%">';
                        echo '<option value="">Selecione...</option>';
                        foreach ($attr['values'] as $opt) {
                            $selected = selected($value, $opt['name'], false); // Use name as value for simplicity
                            echo '<option value="' . esc_attr($opt['name']) . '" ' . $selected . '>' . esc_html($opt['name']) . '</option>';
                        }
                        echo '</select>';
                    } else {
                        echo '<input type="text" name="realiza_ml_attributes[' . esc_attr($attr['id']) . ']" value="' . esc_attr($value) . '" style="width:100%">';
                    }
                    echo '</div>';
                }
            }
        }

        $html = ob_get_clean();
        wp_send_json_success($html);
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

        // Get mapped category
        $ml_category_id = 'MLB3530'; // Default
        $mappings = get_option('realiza_ml_category_mappings', array());
        $product_cats = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'ids'));

        if (!empty($product_cats) && !empty($mappings)) {
            foreach ($product_cats as $cat_id) {
                if (!empty($mappings[$cat_id])) {
                    $ml_category_id = $mappings[$cat_id];
                    break; // Use the first mapped category found
                }
            }
        }

        // Map data
        $data = array(
            'title' => $product->get_name(),
            'category_id' => $ml_category_id,
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
        $saved_attributes = get_post_meta($product_id, '_realiza_ml_attributes', true);
        $attributes_to_send = array();
        $processed_ids = array();

        if (is_array($saved_attributes)) {
            foreach ($saved_attributes as $id => $value) {
                if (!empty($value)) {
                    $attributes_to_send[] = array(
                        'id' => $id,
                        'value_name' => $value
                    );
                    $processed_ids[] = $id;
                }
            }
        }

        // Fallback for BRAND and MODEL if not in saved attributes
        if (!in_array('BRAND', $processed_ids)) {
            $brand = 'Genérica';
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
            }
            $attributes_to_send[] = array(
                'id' => 'BRAND',
                'value_name' => $brand
            );
        }

        if (!in_array('MODEL', $processed_ids)) {
            $model = 'Padrão';
            $attributes = $product->get_attributes();
            foreach ($attributes as $attribute) {
                $name = $attribute->get_name();
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
            $attributes_to_send[] = array(
                'id' => 'MODEL',
                'value_name' => $model
            );
        }

        $data['attributes'] = $attributes_to_send;

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

            error_log('Realiza ML Debug - Update Data: ' . print_r($update_data, true));

            $response = $this->api->post('/items/' . $ml_id, $update_data, 'PUT'); // Assuming API class handles method or we need to adjust it

            error_log('Realiza ML Debug - Update Response: ' . print_r($response, true));
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
            $formatted_error = $this->format_error_message($response);
            error_log('Realiza ML Debug - Formatted Error: ' . $formatted_error);
            return new WP_Error('api_error', $formatted_error);
        }
    }

    private function format_error_message($response)
    {
        if (isset($response['message']) && $response['message'] === 'Validation error' && isset($response['cause'])) {
            $messages = array();
            foreach ($response['cause'] as $cause) {
                if (isset($cause['message'])) {
                    // Translate common messages if needed, or just show them
                    $msg = $cause['message'];
                    if (strpos($msg, 'The attributes [MODEL, BRAND] are required') !== false) {
                        $msg = 'Os atributos Marca e Modelo são obrigatórios para esta categoria.';
                    }
                    $messages[] = $msg;
                }
            }
            return 'Erro de validação: ' . implode(' ', $messages);
        }

        if (isset($response['message'])) {
            return 'Erro na API: ' . $response['message'];
        }

        return 'Erro desconhecido na API: ' . json_encode($response);
    }
}
