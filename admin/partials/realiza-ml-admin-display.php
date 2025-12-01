<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <?php settings_errors('realiza_ml_messages'); ?>

    <form method="post" action="options.php">
        <?php settings_fields('realiza_ml_options'); ?>
        <?php do_settings_sections('realiza_ml_options'); ?>

        <table class="form-table">
            <tr valign="top">
                <th scope="row">App ID</th>
                <td><input type="text" name="realiza_ml_app_id"
                        value="<?php echo esc_attr(get_option('realiza_ml_app_id')); ?>" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">Secret Key</th>
                <td><input type="password" name="realiza_ml_secret_key"
                        value="<?php echo esc_attr(get_option('realiza_ml_secret_key')); ?>" /></td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>

    <hr>

    <h2>Conexão com Mercado Livre</h2>
    <?php
    $access_token = get_option('realiza_ml_access_token');
    if ($access_token) {
        echo '<p class="description" style="color: green;"><strong>Conectado!</strong></p>';
        echo '<p>User ID: ' . get_option('realiza_ml_user_id') . '</p>';
        // Add disconnect button logic here if needed
    } else {
        $app_id = get_option('realiza_ml_app_id');
        if ($app_id) {
            $auth_url = $this->get_auth_url();
            echo '<a href="' . esc_url($auth_url) . '" class="button button-primary">Conectar com Mercado Livre</a>';
            echo '<p class="description">Certifique-se de que a Redirect URI no seu App do Mercado Livre está configurada para: <code>' . admin_url('admin.php?page=realiza-ml') . '</code></p>';
        } else {
            echo '<p class="description">Salve o App ID e Secret Key antes de conectar.</p>';
        }
    }
    ?>
</div>