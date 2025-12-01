<div class="wrap">
    <h1>Mapeamento de Categorias</h1>

    <form method="post" action="options.php">
        <?php
        // We will store mappings in a single option array for simplicity
        // Option name: realiza_ml_category_mappings
        // Structure: [ wc_cat_id => ml_cat_id ]
        
        $mappings = get_option('realiza_ml_category_mappings', array());

        // Get all WC categories
        $args = array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        );
        $product_categories = get_terms($args);
        ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Categoria WooCommerce</th>
                    <th>Categoria Mercado Livre</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($product_categories) && !is_wp_error($product_categories)): ?>
                    <?php foreach ($product_categories as $category): ?>
                        <?php
                        $mapped_id = isset($mappings[$category->term_id]) ? $mappings[$category->term_id] : '';
                        $mapped_name = ''; // We would need to fetch the name, but for now ID is enough or we fetch via JS on load
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($category->name); ?></strong>
                            </td>
                            <td>
                                <div class="ml-category-selector" data-wc-id="<?php echo esc_attr($category->term_id); ?>">
                                    <input type="hidden"
                                        name="realiza_ml_category_mappings[<?php echo esc_attr($category->term_id); ?>]"
                                        value="<?php echo esc_attr($mapped_id); ?>" class="ml-cat-id">
                                    <span
                                        class="ml-cat-display"><?php echo $mapped_id ? 'ID: ' . esc_html($mapped_id) : 'Nenhuma selecionada'; ?></span>
                                    <button type="button" class="button button-secondary select-ml-cat-btn">Selecionar
                                        Categoria</button>
                                    <div class="ml-cat-dropdown" style="display:none; margin-top: 5px;">
                                        <select class="ml-cat-select" style="width: 100%;">
                                            <option value="">Carregando...</option>
                                        </select>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="2">Nenhuma categoria encontrada no WooCommerce.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <input type="hidden" name="action" value="update" />
        <input type="hidden" name="page_options" value="realiza_ml_category_mappings" />

        <?php submit_button('Salvar Mapeamento'); ?>
    </form>
</div>

<script>
    jQuery(document).ready(function ($) {

        // Open dropdown and load root categories
        $('.select-ml-cat-btn').on('click', function () {
            var container = $(this).closest('.ml-category-selector');
            var dropdown = container.find('.ml-cat-dropdown');
            var select = container.find('.ml-cat-select');

            dropdown.toggle();

            if (dropdown.is(':visible') && select.find('option').length <= 1) {
                loadCategories('MLB', select);
            }
        });

        // Handle selection change
        $(document).on('change', '.ml-cat-select', function () {
            var select = $(this);
            var container = select.closest('.ml-category-selector');
            var selectedId = select.val();
            var selectedName = select.find('option:selected').text();

            if (!selectedId) return;

            // Check if it has children (we assume API returns this info, or we try to fetch)
            // For simplicity, we just try to fetch children. If empty, it's a leaf.

            // Disable temporarily
            select.prop('disabled', true);

            $.post(ajaxurl, {
                action: 'realiza_ml_get_categories',
                category_id: selectedId
            }, function (response) {
                select.prop('disabled', false);

                if (response.success) {
                    var data = response.data;

                    // If response is an array of children
                    if (Array.isArray(data) && data.length > 0) {
                        // It has children, replace options with children
                        // Add a "Back" option or handle breadcrumbs? 
                        // For MVP: Just replace options. User can't go back easily without closing/reopening (reset).
                        populateSelect(select, data);
                    } else {
                        // It's a leaf category (or specific category details returned)
                        // If it's a leaf, we select it.
                        // Note: The API /categories/{id} returns details. /sites/MLB/categories returns root.
                        // If we called with a specific ID, response.data might be the category object itself or children.
                        // My PHP logic handles this: if children_categories exists, it returns them.

                        // If we are here, it means no children were returned (empty array).
                        // So it is a leaf.
                        container.find('.ml-cat-id').val(selectedId);
                        container.find('.ml-cat-display').text(selectedName + ' (' + selectedId + ')');
                        container.find('.ml-cat-dropdown').hide();

                        // Reset select for next time (optional)
                        // select.html('<option value="">Carregando...</option>'); 
                    }
                } else {
                    alert('Erro ao carregar categorias: '.response.data);
                }
            });
        });

        function loadCategories(categoryId, selectElement) {
            selectElement.html('<option value="">Carregando...</option>');

            $.post(ajaxurl, {
                action: 'realiza_ml_get_categories',
                category_id: categoryId
            }, function (response) {
                if (response.success) {
                    populateSelect(selectElement, response.data);
                } else {
                    selectElement.html('<option value="">Erro</option>');
                }
            });
        }

        function populateSelect(selectElement, categories) {
            selectElement.empty();
            selectElement.append('<option value="">Selecione...</option>');

            $.each(categories, function (index, cat) {
                selectElement.append($('<option>', {
                    value: cat.id,
                    text: cat.name
                }));
            });
        }
    });
</script>