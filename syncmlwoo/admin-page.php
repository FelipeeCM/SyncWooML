<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap woo-ml-admin">
    <h1><span class="dashicons dashicons-admin-plugins"></span> WooCommerce MercadoLibre Sync</h1>
    
    <!-- Tabs Navigation -->
    <h2 class="nav-tab-wrapper">
        <a href="#settings" class="nav-tab nav-tab-active" data-tab="settings">Configuración</a>
        <a href="#products" class="nav-tab" data-tab="products">Productos</a>
    </h2>

    <div class="woo-ml-messages">
        <?php
        $error_messages = get_settings_errors('woo_ml_messages');
        foreach ($error_messages as $message) {
            $class = ($message['type'] === 'error') ? 'woo-ml-error' : 'woo-ml-success';
            echo "<div class='$class'><p>{$message['message']}</p></div>";
        }
        ?>
    </div>

    <!-- Settings Tab -->
    <div class="tab-content" id="settings-tab">
        <div class="woo-ml-admin-container">
            <div class="woo-ml-admin-section">
                <h2><span class="dashicons dashicons-admin-network"></span> Configuración de API</h2>
                <p class="description">Ingrese sus credenciales de MercadoLibre para comenzar la sincronización.</p>
                <form method="post" action="">
                    <?php
                    wp_nonce_field('woo_ml_save_credentials', 'woo_ml_credentials_nonce');
                    $client_id = get_option('woo_ml_client_id');
                    $client_secret = get_option('woo_ml_client_secret');
                    ?>
                    <div class="woo-ml-form-group">
                        <label for="woo_ml_client_id">Client ID</label>
                        <input type="text" id="woo_ml_client_id" name="woo_ml_client_id" value="<?php echo esc_attr($client_id); ?>" class="regular-text" />
                        <p class="description">Ingrese el Client ID proporcionado por MercadoLibre.</p>
                    </div>
                    <div class="woo-ml-form-group">
                        <label for="woo_ml_client_secret">Client Secret</label>
                        <input type="password" id="woo_ml_client_secret" name="woo_ml_client_secret" value="<?php echo esc_attr($client_secret); ?>" class="regular-text" />
                        <p class="description">Ingrese el Client Secret proporcionado por MercadoLibre.</p>
                    </div>
                    <p class="submit">
                        <?php submit_button('Guardar Credenciales', 'primary', 'woo_ml_save_credentials', false); ?>
                        <?php submit_button('Verificar Credenciales', 'secondary', 'woo_ml_verify_credentials', false); ?>
                    </p>
                </form>
            </div>

            <?php if ($client_id && $client_secret): ?>
            <div class="woo-ml-admin-section">
                <h2><span class="dashicons dashicons-admin-links"></span> Conectar con MercadoLibre</h2>
                <?php
                $auth_url = $this->get_auth_url();
                if ($auth_url):
                ?>
                    <p>Para sincronizar sus productos, necesita conectar su cuenta de MercadoLibre:</p>
                    <a href="<?php echo esc_url($auth_url); ?>" class="button button-primary">Conectar con MercadoLibre</a>
                <?php else: ?>
                    <p class="woo-ml-error">Error: No se pudo generar la URL de autorización. Por favor, verifique sus credenciales.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($this->access_token): ?>
            <div class="woo-ml-admin-section">
                <h2><span class="dashicons dashicons-yes-alt"></span> Estado de la Conexión</h2>
                <p class="woo-ml-success"><strong>Conectado a MercadoLibre</strong></p>
                <p>Puede realizar las siguientes acciones:</p>
                <form method="post" action="" class="inline-form">
                    <?php wp_nonce_field('ml_logout', 'ml_logout_nonce'); ?>
                    <?php submit_button('Cerrar Sesión', 'secondary', 'ml_logout', false); ?>
                </form>
                <form method="post" action="" class="inline-form">
                    <?php submit_button('Probar Conexión', 'secondary', 'test_ml_connection', false); ?>
                </form>
            </div>

            <div class="woo-ml-admin-section">
                <h2><span class="dashicons dashicons-update"></span> Sincronización de Productos</h2>
                <p>Haga clic en el botón para sincronizar todos sus productos de WooCommerce con MercadoLibre:</p>
                <button id="sync-all-products" class="button button-primary">Sincronizar Todos los Productos</button>
                <div id="sync-result" class="woo-ml-result"></div>
            </div>
            <?php endif; ?>

            <div class="woo-ml-admin-section">
                <h2><span class="dashicons dashicons-admin-tools"></span> Registro de Depuración</h2>
                <p>Aquí puede ver los registros de depuración para solucionar problemas:</p>
                <textarea readonly class="woo-ml-debug-log">
<?php
foreach ($this->debug_messages as $message) {
    echo esc_html($message) . "\n";
}
?>
                </textarea>
            </div>
        </div>
    </div>

    <!-- Products Tab -->
    <div class="tab-content" id="products-tab" style="display: none;">
        <div class="woo-ml-admin-container">
            <div class="woo-ml-admin-section">
                <h2><span class="dashicons dashicons-products"></span> Productos Sincronizados</h2>
                <div class="woo-ml-products-filters">
                    <select id="sync-status-filter">
                        <option value="">Todos los estados</option>
                        <option value="synced">Sincronizados</option>
                        <option value="not-synced">No sincronizados</option>
                        <option value="error">Con errores</option>
                    </select>
                    <input type="text" id="product-search" placeholder="Buscar productos..." />
                </div>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Imagen</th>
                            <th>ID</th>
                            <th>Producto</th>
                            <th>SKU</th>
                            <th>Estado ML</th>
                            <th>ID ML</th>
                            <th>Última Sync</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="products-list">
                        <!-- Products will be loaded here via AJAX -->
                    </tbody>
                </table>
                <div class="woo-ml-pagination">
                    <button class="button" id="load-prev-page" disabled>← Anterior</button>
                    <span id="page-info">Página 1</span>
                    <button class="button" id="load-next-page">Siguiente →</button>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.woo-ml-admin {
    max-width: 1200px;
    margin: 0 auto;
}
.woo-ml-admin-container {
    margin-top: 20px;
}
.woo-ml-admin-section {
    background: #fff;
    border: 1px solid #e5e5e5;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    margin-bottom: 20px;
    padding: 20px;
    border-radius: 4px;
}
.woo-ml-admin-section h2 {
    margin-top: 0;
    border-bottom: 1px solid #eee;
    padding-bottom: 10px;
    color: #23282d;
}
.woo-ml-success {
    color: #46b450;
    font-size: 16px;
    background-color: #ecf7ed;
    border: 1px solid #46b450;
    padding: 10px;
    border-radius: 4px;
}
.woo-ml-error {
    color: #dc3232;
    background-color: #fbeaea;
    border: 1px solid #dc3232;
    padding: 10px;
    border-radius: 4px;
}
.woo-ml-debug-log {
    width: 100%;
    height: 200px;
    font-family: monospace;
    background-color: #f6f6f6;
    border: 1px solid #ddd;
    padding: 10px;
    white-space: pre-wrap;
    word-wrap: break-word;
    max-height: 300px;
    overflow-y: auto;
}
.inline-form {
    display: inline-block;
    margin-right: 10px;
}
.woo-ml-result {
    margin-top: 10px;
    padding: 10px;
    background-color: #f8f8f8;
    border: 1px solid #ddd;
    border-radius: 4px;
}
.woo-ml-form-group {
    margin-bottom: 15px;
}
.woo-ml-form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: bold;
}
.woo-ml-products-filters {
    margin-bottom: 20px;
    display: flex;
    gap: 10px;
    align-items: center;
}
.woo-ml-products-filters select,
.woo-ml-products-filters input {
    min-width: 200px;
}
.woo-ml-pagination {
    margin-top: 20px;
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
}
#page-info {
    margin: 0 10px;
}
.sync-status {
    padding: 5px 10px;
    border-radius: 3px;
    font-size: 12px;
    font-weight: bold;
}
.sync-status.synced {
    background-color: #dff0d8;
    color: #3c763d;
}
.sync-status.not-synced {
    background-color: #fcf8e3;
    color: #8a6d3b;
}
.sync-status.error {
    background-color: #f2dede;
    color: #a94442;
}
.product-actions {
    display: flex;
    gap: 5px;
}
.product-actions button {
    padding: 2px 8px;
    font-size: 12px;
}
.tab-content {
    margin-top: 20px;
}
/* WordPress Admin Tab Styles */
.nav-tab-wrapper {
    border-bottom: 1px solid #ccc;
    margin: 0;
    padding-top: 9px;
    padding-bottom: 0;
    line-height: inherit;
}
.nav-tab {
    border: 1px solid #ccc;
    border-bottom: none;
    background: #e5e5e5;
    color: #555;
    display: inline-block;
    padding: 5px 10px;
    font-size: 14px;
    line-height: 24px;
    font-weight: 600;
    margin: 0 3px -1px 0;
    transition: all .3s;
    text-decoration: none;
}
.nav-tab:hover,
.nav-tab:focus {
    background-color: #fff;
    color: #444;
}
.nav-tab-active,
.nav-tab-active:focus,
.nav-tab-active:focus:active,
.nav-tab-active:hover {
    border-bottom: 1px solid #fff;
    background: #fff;
    color: #000;
}
.product-image {
    width: 50px;
    height: 50px;
    object-fit: cover;
    border-radius: 4px;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        const tabId = $(this).data('tab');
        
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        $('.tab-content').hide();
        $(`#${tabId}-tab`).show();

        if (tabId === 'products') {
            loadProducts(1);
        }
    });

    // Products pagination and filtering
    let currentPage = 1;
    let totalPages = 1;

    function loadProducts(page) {
        const statusFilter = $('#sync-status-filter').val();
        const searchQuery = $('#product-search').val();
        const tableBody = $('#products-list');
        
        tableBody.html('<tr><td colspan="8" class="text-center">Cargando...</td></tr>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'get_synced_products',
                nonce: '<?php echo wp_create_nonce("get_synced_products_nonce"); ?>',
                page: page,
                status: statusFilter,
                search: searchQuery
            },
            success: function(response) {
                if (response.success) {
                    const products = response.data.products;
                    totalPages = response.data.total_pages;
                    currentPage = page;

                    $('#load-prev-page').prop('disabled', page <= 1);
                    $('#load-next-page').prop('disabled', page >= totalPages);
                    $('#page-info').text(`Página ${page} de ${totalPages}`);

                    tableBody.empty();

                    if (products.length === 0) {
                        tableBody.html('<tr><td colspan="8" class="text-center">No se encontraron productos</td></tr>');
                        return;
                    }

                    products.forEach(function(product) {
                        const row = `
                            <tr>
                                <td><img src="${product.image}" alt="${product.name}" class="product-image" /></td>
                                <td>${product.id}</td>
                                <td>${product.name}</td>
                                <td>${product.sku}</td>
                                <td>
                                    <span class="sync-status ${product.ml_status}">
                                        ${getStatusLabel(product.ml_status)}
                                    </span>
                                </td>
                                <td>${product.ml_id || '-'}</td>
                                <td>${product.last_sync || 'Nunca'}</td>
                                <td class="product-actions">
                                    <button class="button sync-single" data-id="${product.id}">
                                        Sincronizar
                                    </button>
                                </td>
                            </tr>
                        `;
                        tableBody.append(row);
                    });
                }
            },
            error: function() {
                tableBody.html('<tr><td colspan="8" class="text-center">Error al cargar los productos</td></tr>');
            }
        });
    }

    function getStatusLabel(status) {
        const labels = {
            'synced': 'Sincronizado',
            'not-synced': 'No sincronizado',
            'error': 'Error'
        };
        return labels[status] || status;
    }

    // Pagination handlers
    $('#load-prev-page').on('click', function() {
        if (currentPage > 1) {
            loadProducts(currentPage - 1);
        }
    });

    $('#load-next-page').on('click', function() {
        if (currentPage < totalPages) {
            loadProducts(currentPage + 1);
        }
    });

    // Filter handlers
    $('#sync-status-filter').on('change', function() {
        loadProducts(1);
    });

    let searchTimeout;
    $('#product-search').on('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(function() {
            loadProducts(1);
        }, 500);
    });

    // Sync all products handler
    $('#sync-all-products').on('click', function() {
        var button = $(this);
        var resultDiv = $('#sync-result');
        button.prop('disabled', true);
        button.text('Sincronizando...');
        resultDiv.text('Sincronización en progreso...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sync_all_products',
                nonce: '<?php echo wp_create_nonce("sync_all_products_nonce"); ?>'
            },
            success: function(response) {
                if (response.success) {
                    resultDiv.html('<p class="woo-ml-success">' + response.data + '</p>');
                } else {
                    resultDiv.html('<p class="woo-ml-error">' + response.data + '</p>');
                }
            },
            error: function() {
                resultDiv.html('<p class="woo-ml-error">Error en la sincronización. Por favor, intente nuevamente.</p>');
            },
            complete: function() {
                button.prop('disabled', false);
                button.text('Sincronizar Todos los Productos');
            }
        });
    });

    // Single product sync handler
    $(document).on('click', '.sync-single', function() {
        const button = $(this);
        const productId = button.data('id');
        
        button.prop('disabled', true);
        button.text('Sincronizando...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'sync_single_product',
                nonce: '<?php echo wp_create_nonce("sync_single_product_nonce"); ?>',
                product_id: productId
            },
            success: function(response) {
                if (response.success) {
                    loadProducts(currentPage);
                } else {
                    alert('Error al sincronizar: ' + response.data);
                }
            },
            error: function() {
                alert('Error de conexión al sincronizar el producto');
            },
            complete: function() {
                button.prop('disabled', false);
                button.text('Sincronizar');
            }
        });
    });
});
</script>

