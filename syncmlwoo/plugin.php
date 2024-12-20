<?php
/**
* Plugin Name: WooCommerce MercadoLibre Sync
* Description: Sincroniza productos de WooCommerce con MercadoLibre
* Version: 1.8.5
* Author: CRISTIAN, JIMMY, FELIPE Y MARCO
* Text Domain: woo-ml-sync
* Domain Path: /languages
*/

// Evita el acceso directo al archivo
if (!defined('ABSPATH')) {
   exit;
}

// Define constants
define('WOO_ML_API_ENDPOINT', 'https://api.mercadolibre.com');
define('WOO_ML_MIN_PRICE', 1100);
define('WOO_ML_VERSION', '1.8.5');

class WooMercadoLibreSync {
   private $access_token;
   private $refresh_token;
   private $client_id;
   private $client_secret;
   private $redirect_uri;
   private $debug_messages = array();

   public function __construct() {
       register_activation_hook(__FILE__, array($this, 'activate_plugin'));
       add_action('init', array($this, 'load_settings'));
       add_action('admin_menu', array($this, 'add_admin_menu'));
       add_action('admin_init', array($this, 'register_settings'));
       add_action('admin_init', array($this, 'handle_oauth_response'));
       add_action('woocommerce_update_product', array($this, 'sync_product_to_mercadolibre'), 10, 1);
       add_action('woocommerce_product_set_stock', array($this, 'sync_stock_to_mercadolibre'), 10, 1);
       add_action('woocommerce_variation_set_stock', array($this, 'sync_stock_to_mercadolibre'), 10, 1);
       add_action('woocommerce_product_set_price', array($this, 'sync_price_to_mercadolibre'), 10, 3);
       add_action('woocommerce_variation_set_price', array($this, 'sync_price_to_mercadolibre'), 10, 3);
       add_action('woocommerce_update_product_variation', array($this, 'sync_variation_to_mercadolibre'), 10, 1);
       add_action('wp_ajax_sync_all_products', array($this, 'sync_all_products'));
       add_action('wp_ajax_get_synced_products', array($this, 'ajax_get_synced_products'));
       add_action('wp_ajax_sync_single_product', array($this, 'ajax_sync_single_product'));
       add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'agregar_enlace_ajustes'));
       
       add_action('woocommerce_product_options_general_product_data', array($this, 'add_size_grid_id_field'));
       add_action('woocommerce_process_product_meta', array($this, 'save_size_grid_id_field'));

       add_action('woo_ml_sync_stock_from_mercadolibre', array($this, 'sync_stock_from_mercadolibre'));

       if (!wp_next_scheduled('woo_ml_sync_stock_from_mercadolibre')) {
           wp_schedule_event(time(), 'hourly', 'woo_ml_sync_stock_from_mercadolibre');
       }

       add_action('plugins_loaded', array($this, 'load_plugin_textdomain'));

       add_action('woocommerce_product_options_general_product_data', array($this, 'add_gender_field'));
       add_action('woocommerce_process_product_meta', array($this, 'save_gender_field'));

       add_action('woocommerce_product_options_general_product_data', array($this, 'add_size_grid_row_id_field'));
       add_action('woocommerce_process_product_meta', array($this, 'save_size_grid_row_id_field'));
   }

   public function load_plugin_textdomain() {
       load_plugin_textdomain('woo-ml-sync', false, dirname(plugin_basename(__FILE__)) . '/languages/');
   }

   public function load_settings() {
       $this->client_id = get_option('woo_ml_client_id');
       $this->client_secret = get_option('woo_ml_client_secret');
       $this->access_token = get_option('woo_ml_access_token');
       $this->refresh_token = get_option('woo_ml_refresh_token');
       $this->redirect_uri = admin_url('admin.php?page=woo-ml-sync');
       $this->log_debug('Configuración cargada');
   }

   public function activate_plugin() {
       $options = array(
           'woo_ml_client_id',
           'woo_ml_client_secret',
           'woo_ml_access_token',
           'woo_ml_refresh_token',
           'woo_ml_token_expiration'
       );

       foreach ($options as $option) {
           if (!get_option($option)) {
               add_option($option, '');
           }
       }
   }

   public function add_admin_menu() {
       add_menu_page(
           __('WooCommerce MercadoLibre Sync', 'woo-ml-sync'),
           __('WooML Sync', 'woo-ml-sync'),
           'manage_options',
           'woo-ml-sync',
           array($this, 'admin_page'),
           'dashicons-sync',
           56
       );
   }

   public function register_settings() {
       register_setting('woo_ml_options', 'woo_ml_client_id');
       register_setting('woo_ml_options', 'woo_ml_client_secret');
   }

   public function get_auth_url() {
       if (empty($this->client_id)) {
           return false;
       }
       $code_verifier = $this->generate_code_verifier();
       $code_challenge = $this->generate_code_challenge($code_verifier);
       update_option('woo_ml_code_verifier', $code_verifier);
       $params = array(
           'response_type' => 'code',
           'client_id' => $this->client_id,
           'redirect_uri' => $this->redirect_uri,
           'code_challenge' => $code_challenge,
           'code_challenge_method' => 'S256'
       );
       return add_query_arg($params, 'https://auth.mercadolibre.cl/authorization');
   }

   private function generate_code_verifier() {
       $random = bin2hex(random_bytes(32));
       return rtrim(strtr(base64_encode($random), '+/', '-_'), '=');
   }

   private function generate_code_challenge($code_verifier) {
       $hashed = hash('sha256', $code_verifier, true);
       return rtrim(strtr(base64_encode($hashed), '+/', '-_'), '=');
   }

   public function admin_page() {
       $this->load_settings();
       if (isset($_POST['woo_ml_save_credentials'])) {
           $this->save_credentials();
       }
       if (isset($_POST['woo_ml_verify_credentials'])) {
           $this->verify_credentials();
       }
       if (isset($_POST['ml_logout'])) {
           $this->logout();
       }
       if (isset($_POST['test_ml_connection'])) {
           $this->test_mercadolibre_connection();
       }
       
       include plugin_dir_path(__FILE__) . 'admin-page.php';
   }

   private function save_credentials() {
       if (!isset($_POST['woo_ml_credentials_nonce']) || !wp_verify_nonce($_POST['woo_ml_credentials_nonce'], 'woo_ml_save_credentials')) {
           $this->log_error('Error de seguridad al guardar las credenciales.');
           return;
       }
       $client_id = sanitize_text_field($_POST['woo_ml_client_id']);
       $client_secret = sanitize_text_field($_POST['woo_ml_client_secret']);
       update_option('woo_ml_client_id', $client_id);
       update_option('woo_ml_client_secret', $client_secret);
       $this->client_id = $client_id;
       $this->client_secret = $client_secret;
       $this->log_debug('Credenciales guardadas exitosamente.');
       add_settings_error('woo_ml_messages', 'credentials_updated', __('Credenciales actualizadas con éxito.', 'woo-ml-sync'), 'success');
   }

   private function verify_credentials() {
       if (!isset($_POST['woo_ml_credentials_nonce']) || !wp_verify_nonce($_POST['woo_ml_credentials_nonce'], 'woo_ml_save_credentials')) {
           $this->log_error('Error de seguridad al verificar las credenciales.');
           return;
       }
       $client_id = $this->client_id;
       $client_secret = $this->client_secret;
       if (empty($client_id) || empty($client_secret)) {
           add_settings_error('woo_ml_messages', 'credentials_empty', __('Por favor, ingrese el Client ID y Client Secret antes de verificar.', 'woo-ml-sync'), 'error');
           return;
       }
       $response = wp_remote_post(WOO_ML_API_ENDPOINT . '/oauth/token', array(
           'body' => array(
               'grant_type' => 'client_credentials',
               'client_id' => $client_id,
               'client_secret' => $client_secret
           ),
           'timeout' => 30,
       ));
       if (is_wp_error($response)) {
           $this->log_error('Error al verificar las credenciales: ' . $response->get_error_message());
           add_settings_error('woo_ml_messages', 'credentials_error', __('Error al verificar las credenciales:', 'woo-ml-sync') . ' ' . $response->get_error_message(), 'error');
           return;
       }
       $body = json_decode(wp_remote_retrieve_body($response), true);
       $status_code = wp_remote_retrieve_response_code($response);
       $this->log_debug('Respuesta de verificación de credenciales:');
       $this->log_debug('Código de estado: ' . $status_code);
       $this->log_debug('Cuerpo de la respuesta: ' . print_r($body, true));
       if ($status_code === 200 && isset($body['access_token'])) {
           $this->log_debug('Credenciales verificadas exitosamente.');
           add_settings_error('woo_ml_messages', 'credentials_verified', __('Credenciales verificadas exitosamente.', 'woo-ml-sync'), 'success');
       } else {
           $error_message = isset($body['message']) ? $body['message'] : __('Error desconocido', 'woo-ml-sync');
           $this->log_error('Error al verificar las credenciales: ' . $error_message);
           add_settings_error('woo_ml_messages', 'credentials_error', __('Error al verificar las credenciales:', 'woo-ml-sync') . ' ' . $error_message, 'error');
       }
   }

   public function handle_oauth_response() {
       if (!isset($_GET['code'])) {
           return;
       }
       $code = sanitize_text_field($_GET['code']);
       $code_verifier = get_option('woo_ml_code_verifier');
       
       if (empty($this->client_id) || empty($this->client_secret)) {
           $this->log_error('Client ID o Client Secret no configurados correctamente.');
           add_settings_error('woo_ml_messages', 'oauth_error', __('Error: Client ID o Client Secret no configurados correctamente.', 'woo-ml-sync'), 'error');
           return;
       }

       $response = wp_remote_post(WOO_ML_API_ENDPOINT . '/oauth/token', array(
           'body' => array(
               'grant_type' => 'authorization_code',
               'client_id' => $this->client_id,
               'client_secret' => $this->client_secret,
               'code' => $code,
               'redirect_uri' => $this->redirect_uri,
               'code_verifier' => $code_verifier
           ),
           'timeout' => 30,
       ));

       if (is_wp_error($response)) {
           $this->log_error('Error al conectar con MercadoLibre: ' . $response->get_error_message());
           add_settings_error('woo_ml_messages', 'oauth_error', __('Error al conectar con MercadoLibre:', 'woo-ml-sync') . ' ' . $response->get_error_message(), 'error');
           return;
       }

       $body = json_decode(wp_remote_retrieve_body($response), true);
       $status_code = wp_remote_retrieve_response_code($response);

       if ($status_code === 200 && isset($body['access_token']) && isset($body['refresh_token'])) {
           update_option('woo_ml_access_token', $body['access_token']);
           update_option('woo_ml_refresh_token', $body['refresh_token']);
           update_option('woo_ml_token_expiration', time() + $body['expires_in']);
           $this->access_token = $body['access_token'];
           $this->refresh_token = $body['refresh_token'];
           $this->log_debug('Tokens guardados exitosamente');
           add_settings_error('woo_ml_messages', 'oauth_success', __('Conexión exitosa con MercadoLibre', 'woo-ml-sync'), 'success');
           wp_redirect(admin_url('admin.php?page=woo-ml-sync&ml_connected=1'));
           exit;
       } else {
           $error_message = isset($body['message']) ? $body['message'] : __('Error desconocido en la respuesta de MercadoLibre', 'woo-ml-sync');
           $this->log_error('Error en la respuesta de MercadoLibre: ' . $error_message);
           add_settings_error('woo_ml_messages', 'oauth_error', __('Error en la respuesta de MercadoLibre:', 'woo-ml-sync') . ' ' . $error_message, 'error');
       }
   }

   public function agregar_enlace_ajustes($enlaces) {
       $enlace_ajustes = '<a href="' . admin_url('admin.php?page=woo-ml-sync') . '">' . __('Ajustes', 'woo-ml-sync') . '</a>';
       array_unshift($enlaces, $enlace_ajustes);
       return $enlaces;
   }

   public function sync_product_to_mercadolibre($product_id) {
       $this->log_debug("Iniciando sincronización del producto ID: $product_id con MercadoLibre");
   
       if (!$this->check_and_refresh_token()) {
           $this->log_error("No se pudo obtener un token de acceso válido.");
           return false;
       }

       $product = wc_get_product($product_id);
       if (!$product) {
           $this->log_error("No se pudo obtener el producto con ID: $product_id");
           return false;
       }

       $ml_product_id = get_post_meta($product_id, '_mercadolibre_id', true);
   
       try {
           if ($ml_product_id) {
               $product_status = $this->get_mercadolibre_product_status($ml_product_id);
               if ($product_status === 'closed' || $product_status === null) {
                   $this->log_debug("El producto ID: $product_id está cerrado o eliminado en MercadoLibre. Creando un nuevo listado.");
                   $ml_product_data = $this->prepare_product_data($product);
                   $endpoint = WOO_ML_API_ENDPOINT . '/items';
                   $method = 'POST';
               } else {
                   $ml_product_data = $this->prepare_product_update_data($product);
                   $endpoint = WOO_ML_API_ENDPOINT . "/items/$ml_product_id";
                   $method = 'PUT';
               }
           } else {
               $ml_product_data = $this->prepare_product_data($product);
               $endpoint = WOO_ML_API_ENDPOINT . '/items';
               $method = 'POST';
           }

           $this->log_debug("Datos del producto a enviar: " . print_r($ml_product_data, true));

           $response = $this->make_api_request($endpoint, $method, $ml_product_data);

           if (is_wp_error($response)) {
               throw new Exception('Error al sincronizar el producto con MercadoLibre: ' . $response->get_error_message());
           }

           $body = json_decode(wp_remote_retrieve_body($response), true);
           $status_code = wp_remote_retrieve_response_code($response);

           if ($status_code === 200 || $status_code === 201) {
               $this->log_debug("Producto sincronizado exitosamente con MercadoLibre. ID en ML: " . $body['id']);
               if ($method === 'POST') {
                   update_post_meta($product_id, '_mercadolibre_id', $body['id']);
                   $this->log_debug("Nuevo producto creado en MercadoLibre con ID: " . $body['id']);
               }
               update_post_meta($product_id, '_mercadolibre_id', $body['id']);
           
               if ($product->is_type('variable')) {
                   $this->sync_variations_to_mercadolibre($product, $body['id']);
               }
           
               return true;
           } else {
               $error_message = isset($body['message']) ? $body['message'] : __('Error desconocido', 'woo-ml-sync');
               throw new Exception("Error al sincronizar el producto. Código de estado: $status_code. Mensaje: $error_message");
           }
       } catch (Exception $e) {
           $this->log_error($e->getMessage());
       
           if (isset($body['cause'])) {
               foreach ($body['cause'] as $cause) {
                   $this->log_error("Causa detallada: " . print_r($cause, true));
               }
           }
       
           return false;
       }
   }

   public function sync_stock_to_mercadolibre($product) {
       $product_id = $product->get_id();
       $ml_product_id = get_post_meta($product_id, '_mercadolibre_id', true);

       if (!$ml_product_id) {
           $this->log_debug("El producto ID: $product_id no está sincronizado con MercadoLibre.");
           return;
       }

       $stock = $product->get_stock_quantity();

       $data = array(
           'available_quantity' => $stock ? intval($stock) : 0
       );

       $endpoint = WOO_ML_API_ENDPOINT . "/items/$ml_product_id";
       $response = $this->make_api_request($endpoint, 'PUT', $data);

       if (is_wp_error($response)) {
           $this->log_error("Error al actualizar el stock en MercadoLibre para el producto ID: $product_id");
       } else {
           $this->log_debug("Stock actualizado en MercadoLibre para el producto ID: $product_id");
       }
   }

   public function sync_price_to_mercadolibre($product_id, $price, $price_type) {
       $ml_product_id = get_post_meta($product_id, '_mercadolibre_id', true);

       if (!$ml_product_id) {
           $this->log_debug("El producto ID: $product_id no está sincronizado con MercadoLibre.");
           return;
       }

       $this->log_debug("Intentando actualizar precio para producto ID: $product_id, ML ID: $ml_product_id, Nuevo precio: $price");

       $data = array(
           'price' => max(floatval($price), WOO_ML_MIN_PRICE)
       );

       $endpoint = WOO_ML_API_ENDPOINT . "/items/$ml_product_id";
       $response = $this->make_api_request($endpoint, 'PUT', $data);

       if (is_wp_error($response)) {
           $this->log_error("Error al actualizar el precio en MercadoLibre para el producto ID: $product_id");
       } else {
           $body = json_decode(wp_remote_retrieve_body($response), true);
           $status_code = wp_remote_retrieve_response_code($response);
           
           if ($status_code === 200) {
               $this->log_debug("Precio actualizado en MercadoLibre para el producto ID: $product_id");
           } else {
               $error_message = isset($body['message']) ? $body['message'] : __('Error desconocido', 'woo-ml-sync');
               $this->log_error("Error al actualizar el precio en MercadoLibre para el producto ID: $product_id. Código: $status_code. Mensaje: $error_message");
           }
       }
   }

   public function sync_variation_to_mercadolibre($variation_id) {
       $variation = wc_get_product($variation_id);
       if (!$variation || !$variation->is_type('variation')) {
           return;
       }

       $parent_id = $variation->get_parent_id();
       $ml_product_id = get_post_meta($parent_id, '_mercadolibre_id', true);

       if (!$ml_product_id) {
           $this->log_debug("El producto padre ID: $parent_id no está sincronizado con MercadoLibre.");
           return;
       }

       $data = $this->prepare_variation_data($variation);

       $endpoint = WOO_ML_API_ENDPOINT . "/items/$ml_product_id";
       $response = $this->make_api_request($endpoint, 'PUT', $data);

       if (is_wp_error($response)) {
           $this->log_error("Error al actualizar la variación en MercadoLibre para el producto ID: $variation_id");
       } else {
           $this->log_debug("Variación actualizada en MercadoLibre para el producto ID: $variation_id");
       }
   }

   private function check_and_refresh_token() {
       if (empty($this->access_token) || $this->is_token_expired()) {
           return $this->refresh_access_token();
       }
       return true;
   }

   private function is_token_expired() {
       $token_expiration = get_option('woo_ml_token_expiration');
       return !$token_expiration || $token_expiration < time();
   }

   public function refresh_access_token() {
       if (!$this->refresh_token) {
           $this->log_error('No hay refresh token disponible');
           return false;
       }
       $response = wp_remote_post(WOO_ML_API_ENDPOINT . '/oauth/token', array(
           'body' => array(
               'grant_type' => 'refresh_token',
               'client_id' => $this->client_id,
               'client_secret' => $this->client_secret,
               'refresh_token' => $this->refresh_token
           ),
           'timeout' => 30,
       ));
       if (is_wp_error($response)) {
           $this->log_error('Error al refrescar el token: ' . $response->get_error_message());
           return false;
       }

       $body = json_decode(wp_remote_retrieve_body($response), true);
       if (isset($body['access_token']) && isset($body['refresh_token'])) {
           update_option('woo_ml_access_token', $body['access_token']);
           update_option('woo_ml_refresh_token', $body['refresh_token']);
           update_option('woo_ml_token_expiration', time() + $body['expires_in']);
           $this->access_token = $body['access_token'];
           $this->refresh_token = $body['refresh_token'];
           $this->log_debug('Token refrescado exitosamente');
           return true;
       } else {
           $this->log_error('Error en la respuesta al refrescar el token: ' . print_r($body, true));
           return false;
       }
   }

   private function prepare_product_data($product) {
    $this->log_product_attributes($product);
    $description = $product->get_description();
    if (empty($description)) {
        $description = $product->get_short_description();
    }

    $price = floatval($product->get_price());
    if ($price <= 0) {
        $price = WOO_ML_MIN_PRICE;
    }

    $category_id = $this->get_mercadolibre_category($product);
    $attributes = $this->get_product_attributes($product);

    $data = array(
        'title' => $product->get_name(),
        'category_id' => $category_id,
        'price' => $price,
        'currency_id' => 'CLP',
        'available_quantity' => $product->get_stock_quantity() ? intval($product->get_stock_quantity()) : 1,
        'buying_mode' => 'buy_it_now',
        'condition' => 'new',
        'listing_type_id' => 'gold_special',
        'description' => array('plain_text' => strip_tags($description)),
        'pictures' => $this->get_product_images($product),
        'attributes' => $attributes,
    );


    // Add SIZE attribute
    $sizes = array();
    if ($product->is_type('variable')) {
        $variations = $product->get_available_variations();
        foreach ($variations as $variation) {
            $variation_product = wc_get_product($variation['variation_id']);
            $size = $variation_product->get_attribute('pa_size');
            if (empty($size)) {
                $size = $variation_product->get_attribute('size');
            }
            if (!empty($size) && !in_array($size, $sizes)) {
                $sizes[] = $size;
            }
        }
    } else {
        $size = $product->get_attribute('pa_size');
        if (empty($size)) {
            $size = $product->get_attribute('size');
        }
        if (!empty($size)) {
            $sizes[] = $size;
        }
    }

    // Remove this block to avoid duplicate SIZE attribute
    // if (!empty($sizes)) {
    //     $data['attributes'][] = array(
    //         'id' => 'SIZE',
    //         'name' => 'Talla',
    //         'value_name' => implode(', ', $sizes)
    //     );
    // }

    // Add GENDER attribute
    $gender = get_post_meta($product->get_id(), 'gender', true);
    $gender_map = [
        'male' => 'Hombre',
        'female' => 'Mujer',
        'unisex' => 'Sin género',
        '' => 'Sin género'
    ];
    $ml_gender = isset($gender_map[$gender]) ? $gender_map[$gender] : 'Sin género';
    $data['attributes'][] = array(
        'id' => 'GENDER',
        'name' => 'Género',
        'value_name' => $ml_gender
    );

    // Add SIZE_GRID_ID attribute
    $size_grid_id = get_post_meta($product->get_id(), 'SIZE_GRID_ID', true);
    if (!empty($size_grid_id)) {
        $data['attributes'][] = array(
            'id' => 'SIZE_GRID_ID',
            'value_name' => $size_grid_id
        );
    }

    if ($product->is_type('variable')) {
        $variations = $product->get_available_variations();
        $variation_attributes = $product->get_variation_attributes();
        $data['variations'] = $this->prepare_variations($variations, $variation_attributes);
    }

    $this->add_shipping_mode($data);

    return $data;
}

   private function prepare_variations($variations, $variation_attributes) {
    $prepared_variations = array();
    foreach ($variations as $variation) {
        $variation_product = wc_get_product($variation['variation_id']);
        $variation_data = array(
            'price' => max(floatval($variation_product->get_price()), WOO_ML_MIN_PRICE),
            'available_quantity' => intval($variation_product->get_stock_quantity()),
            'attribute_combinations' => array(),
            'picture_ids' => array()
        );

        foreach ($variation_attributes as $attribute_name => $options) {
            $attribute_value = $variation['attributes']['attribute_' . $attribute_name];
            if (!empty($attribute_value)) {
                $variation_data['attribute_combinations'][] = array(
                    'id' => $this->get_mercadolibre_attribute_id($attribute_name),
                    'name' => wc_attribute_label($attribute_name),
                    'value_name' => $attribute_value
                );
            }
        }

        // Add SIZE attribute for each variation
        $size = $variation_product->get_attribute('pa_size');
        if (empty($size)) {
            $size = $variation_product->get_attribute('size');
        }

        if (!empty($size)) {
            $variation_data['attribute_combinations'][] = array(
                'id' => 'SIZE',
                'name' => 'Talla',
                'value_name' => $size
            );
        }

        // Add SIZE_GRID_ROW_ID as a separate attribute for each variation
        $size_grid_id = get_post_meta($variation_product->get_parent_id(), 'SIZE_GRID_ID', true);
        if (!empty($size_grid_id) && !empty($size)) {
            $size_grid_row_id = $this->get_valid_size_grid_row_id($size_grid_id, $size);
            if ($size_grid_row_id) {
                $variation_data['attributes'][] = array(
                    'id' => 'SIZE_GRID_ROW_ID',
                    'value_name' => $size_grid_row_id
                );
            }
        }

        if (isset($variation['image_id'])) {
            $image_url = wp_get_attachment_url($variation['image_id']);
            if ($image_url) {
                $uploaded_image_id = $this->upload_image_to_mercadolibre($image_url);
                if ($uploaded_image_id) {
                    $variation_data['picture_ids'][] = $uploaded_image_id;
                }
            }
        }

        $prepared_variations[] = $variation_data;
    }

    return $prepared_variations;
}

   private function get_mercadolibre_attribute_id($woo_attribute_name) {
       $attribute_map = array(
           'pa_color' => 'COLOR',
           'pa_size' => 'SIZE',
           // Agrega más mapeos según sea necesario
       );

       return isset($attribute_map[$woo_attribute_name]) ? $attribute_map[$woo_attribute_name] : $woo_attribute_name;
   }

   private function prepare_product_update_data($product) {
       $data = array(
           'price' => max(floatval($product->get_price()), WOO_ML_MIN_PRICE),
           'available_quantity' => $product->get_stock_quantity() ? intval($product->get_stock_quantity()) : 0,
       );

       return $data;
   }

   private function get_product_attributes($product) {
       $attributes = array();
       $product_attributes = $product->get_attributes();

       foreach ($product_attributes as $attribute) {
           if ($attribute->get_variation()) {
               // Skip variation attributes as they will be handled in variations
               continue;
           }
           if ($attribute->is_taxonomy()) {
               $attribute_taxonomy = $attribute->get_taxonomy_object();
               $attribute_values = wc_get_product_terms($product->get_id(), $attribute->get_name(), array('fields' => 'names'));
               $attributes[] = array(
                   'id' => $attribute_taxonomy->attribute_name,
                   'name' => wc_attribute_label($attribute->get_name()),
                   'value_name' => implode(', ', $attribute_values),
               );
           } else {
               $attributes[] = array(
                   'id' => $attribute->get_name(),
                   'name' => $attribute->get_name(),
                   'value_name' => implode(', ', $attribute->get_options()),
               );
           }
       }

       // Add BRAND and MODEL attributes
       $attributes[] = array(
           'id' => 'BRAND',
           'name' => 'Marca',
           'value_name' => $product->get_attribute('pa_marca') ?:'No especificada'
       );

       $attributes[] = array(
           'id' => 'MODEL',
           'name' => 'Modelo',
           'value_name' => $product->get_attribute('pa_modelo') ?: 'No especificado'
       );

       return $attributes;
   }

   private function get_mercadolibre_category($product) {
       $category_id = get_post_meta($product->get_id(), '_mercadolibre_category_id', true);
       if (!$category_id) {
           $category_id = $this->find_best_category($product);
       }
       return $category_id;
   }

   private function find_best_category($product) {
       $woo_categories = wp_get_post_terms($product->get_id(), 'product_cat', array('fields' => 'names'));
       $product_name = $product->get_name();
       $search_terms = implode(' ', array_merge($woo_categories, array($product_name)));
       
       $endpoint = WOO_ML_API_ENDPOINT . '/sites/MLC/domain_discovery/search?q=' . urlencode($search_terms);
       $response = $this->make_api_request($endpoint, 'GET');
       
       if (is_wp_error($response)) {
           $this->log_error('Error al buscar la mejor categoría: ' . $response->get_error_message());
           return 'MLC1276'; // Categoría genérica como fallback
       }
       
       $body = json_decode(wp_remote_retrieve_body($response), true);
       
       if (!empty($body) && isset($body[0]['category_id'])) {
           return $body[0]['category_id'];
       }
       
       return 'MLC1276';
   }

   private function get_product_images($product) {
       $images = array();
       $attachment_ids = $product->get_gallery_image_ids();

       if ($product->get_image_id()) {
           array_unshift($attachment_ids, $product->get_image_id());
       }

       foreach ($attachment_ids as $attachment_id) {
           $image_url = wp_get_attachment_url($attachment_id);
           if ($image_url) {
               $images[] = array('source' => $image_url);
           }
       }

       return $images;
   }

   private function prepare_variation_data($variation) {
       $parent_product = wc_get_product($variation->get_parent_id());
       $variation_data = array(
           'price' => max(floatval($variation->get_price()), WOO_ML_MIN_PRICE),
           'available_quantity' => intval($variation->get_stock_quantity()),
           'attribute_combinations' => array(),
       );

       $attributes = $variation->get_attributes();
       foreach ($attributes as $attribute_name => $attribute_value) {
           if (!empty($attribute_value)) {
               $attribute_label = wc_attribute_label($attribute_name, $parent_product);
               $variation_data['attribute_combinations'][] = array(
                   'name' => $attribute_label,
                   'value_name' => $attribute_value,
               );
           }
       }

       return $variation_data;
   }

   private function sync_variations_to_mercadolibre($product, $ml_product_id) {
       $variations = $product->get_available_variations();
       $variation_attributes = $product->get_variation_attributes();
       $variation_data = $this->prepare_variations($variations, $variation_attributes);

       // Get the current product pictures
       $current_product = $this->get_mercadolibre_product($ml_product_id);
       $product_pictures = isset($current_product['pictures']) ? $current_product['pictures'] : array();

       // Add variation pictures to the main product picture list
       foreach ($variation_data as $variation) {
           if (isset($variation['picture_ids']) && is_array($variation['picture_ids'])) {
               foreach ($variation['picture_ids'] as $pic_id) {
                   if (!$this->picture_exists_in_list($pic_id, $product_pictures)) {
                       $product_pictures[] = array('id' => $pic_id);
                   }
               }
           }
       }

       $endpoint = WOO_ML_API_ENDPOINT . "/items/$ml_product_id";
       $data = array(
           'variations' => $variation_data,
           'pictures' => $product_pictures
       );

       $response = $this->make_api_request($endpoint, 'PUT', $data);

       if (is_wp_error($response)) {
           $this->log_error("Error al sincronizar las variaciones: " . $response->get_error_message());
       } else {
           $body = json_decode(wp_remote_retrieve_body($response), true);
           $status_code = wp_remote_retrieve_response_code($response);

           if ($status_code === 200) {
               $this->log_debug("Variaciones sincronizadas exitosamente para el producto ID: " . $product->get_id());
           } else {
               $error_message = isset($body['message']) ? $body['message'] : __('Error desconocido', 'woo-ml-sync');
               $this->log_error("Error al sincronizar las variaciones. Código de estado: $status_code. Mensaje: $error_message");
           }
       }
   }

   private function picture_exists_in_list($pic_id, $picture_list) {
       foreach ($picture_list as $picture) {
           if ($picture['id'] === $pic_id) {
               return true;
           }
       }
       return false;
   }

   private function get_mercadolibre_product($ml_product_id) {
       $endpoint = WOO_ML_API_ENDPOINT . "/items/$ml_product_id";
       $response = $this->make_api_request($endpoint, 'GET');

       if (is_wp_error($response)) {
           $this->log_error("Error al obtener el producto de MercadoLibre: " . $response->get_error_message());
           return array();
       }

       return json_decode(wp_remote_retrieve_body($response), true);
   }

   public function sync_all_products() {
       if (!wp_verify_nonce($_POST['nonce'], 'sync_all_products_nonce')) {
           wp_send_json_error(__('Error de seguridad', 'woo-ml-sync'));
       }

       $products = wc_get_products(array('limit' => -1));
       $total = count($products);
       $synced = 0;
       $failed = 0;

       foreach ($products as $product) {
           if ($this->sync_product_to_mercadolibre($product->get_id())) {
               $synced++;
           } else {
               $failed++;
           }
       }

       wp_send_json_success(sprintf(__("Sincronización completa. Total de productos: %d, Sincronizados: %d, Fallidos: %d", 'woo-ml-sync'), $total, $synced, $failed));
   }

   private function logout() {
       if (!isset($_POST['ml_logout_nonce']) || !wp_verify_nonce($_POST['ml_logout_nonce'], 'ml_logout')) {
           $this->log_error('Error de seguridad al cerrar sesión.');
           return;
       }
       delete_option('woo_ml_access_token');
       delete_option('woo_ml_refresh_token');
       delete_option('woo_ml_token_expiration');
       $this->access_token = null;
       $this->refresh_token = null;
       $this->log_debug('Sesión cerrada exitosamente.');
       add_settings_error('woo_ml_messages', 'logout_success', __('Se ha cerrado la sesión de MercadoLibre.', 'woo-ml-sync'), 'success');
   }

   private function test_mercadolibre_connection() {
       if (!$this->check_and_refresh_token()) {
           $this->log_error('No se pudo obtener un token de acceso válido para probar la conexión.');
           add_settings_error('woo_ml_messages', 'connection_error', __('Error: No se pudo obtener un token de acceso válido.', 'woo-ml-sync'), 'error');
           return;
       }

       $endpoint = WOO_ML_API_ENDPOINT . '/users/me';
       $response = $this->make_api_request($endpoint, 'GET');

       if (is_wp_error($response)) {
           $this->log_error('Error al probar la conexión con MercadoLibre: ' . $response->get_error_message());
           add_settings_error('woo_ml_messages', 'connection_error', __('Error al probar la conexión con MercadoLibre:', 'woo-ml-sync') . ' ' . $response->get_error_message(), 'error');
           return;
       }

       $body = json_decode(wp_remote_retrieve_body($response), true);
       $status_code = wp_remote_retrieve_response_code($response);

       if ($status_code === 200 && isset($body['id'])) {
           $this->log_debug('Conexión exitosa con MercadoLibre. ID de usuario: ' . $body['id']);
           add_settings_error('woo_ml_messages', 'connection_success', __('Conexión exitosa con MercadoLibre. ID de usuario:', 'woo-ml-sync') . ' ' . $body['id'], 'success');
       } else {
           $error_message = isset($body['message']) ? $body['message'] : __('Error desconocido', 'woo-ml-sync');
           $this->log_error('Error al probar la conexión con MercadoLibre. Código: ' . $status_code . '. Mensaje: ' . $error_message);
           add_settings_error('woo_ml_messages', 'connection_error', __('Error al probar la conexión con MercadoLibre. Código:', 'woo-ml-sync') . ' ' . $status_code . '. ' . __('Mensaje:', 'woo-ml-sync') . ' ' . $error_message, 'error');
       }
   }

   private function make_api_request($endpoint, $method = 'GET', $body = null) {
       if (!$this->check_and_refresh_token()) {
           return new WP_Error('token_error', __('No se pudo obtener un token de acceso válido.', 'woo-ml-sync'));
       }

       $args = array(
           'method' => $method,
           'headers' => array(
               'Authorization' => 'Bearer ' . $this->access_token,
               'Content-Type' => 'application/json',
           ),
           'timeout' => 30,
       );

       if ($body !== null) {
           $args['body'] = json_encode($body);
       }

       $response = wp_remote_request($endpoint, $args);

       if (is_wp_error($response)) {
           $this->log_error('Error en la solicitud API a MercadoLibre: ' . $response->get_error_message());
           return $response;
       }

       $status_code = wp_remote_retrieve_response_code($response);
       $response_body = wp_remote_retrieve_body($response);

       $this->log_debug("Respuesta de MercadoLibre (Endpoint: $endpoint, Método: $method):");
       $this->log_debug("Código de estado: $status_code");
       $this->log_debug("Cuerpo de la respuesta: $response_body");

       return $response;
   }

   private function log_debug($message) {
       $this->debug_messages[] = '[Debug] ' . $message;
       $this->write_log('debug', $message);
   }

   private function log_error($message) {
       $this->debug_messages[] = '[Error] ' . $message;
       $this->write_log('error', $message);
       error_log('WooCommerce MercadoLibre Sync Error: ' . $message);
   }

   private function write_log($type, $message) {
       $log_file = WP_CONTENT_DIR . '/woo-ml-sync.log';
       $timestamp = current_time('mysql');
       $log_message = "[$timestamp] [$type] $message\n";
       error_log($log_message, 3, $log_file);
   }

   public function add_size_grid_id_field() {
       global $woocommerce, $post;
       echo '<div class="options_group">';
       woocommerce_wp_text_input(
           array(
               'id' => 'SIZE_GRID_ID',
               'label' => __('SIZE GRID ID', 'woo-ml-sync'),
               'desc_tip' => 'true',
               'description' => __('Ingrese el ID de la grilla de tallas de MercadoLibre.', 'woo-ml-sync')
           )
       );
       echo '</div>';
   }

   public function save_size_grid_id_field($post_id) {
       $size_grid_id = isset($_POST['SIZE_GRID_ID']) ? sanitize_text_field($_POST['SIZE_GRID_ID']) : '';
       update_post_meta($post_id, 'SIZE_GRID_ID', $size_grid_id);
   }

   private function get_mercadolibre_product_status($ml_product_id) {
       $endpoint = WOO_ML_API_ENDPOINT . "/items/$ml_product_id";
       $response = $this->make_api_request($endpoint, 'GET');
       
       if (is_wp_error($response)) {
           $this->log_error("Error al obtener el estado del producto de MercadoLibre: " . $response->get_error_message());
           return null;
       }
       
       $body = json_decode(wp_remote_retrieve_body($response), true);
       return isset($body['status']) ? $body['status'] : null;
   }

   private function validate_size_grid_id($size_grid_id) {
       $endpoint = WOO_ML_API_ENDPOINT . "/catalog/charts/" . $size_grid_id;
       $response = $this->make_api_request($endpoint, 'GET');
       
       if (is_wp_error($response)) {
           $this->log_error("Error al validar SIZE_GRID_ID: " . $response->get_error_message());
           return false;
       }
       
       $status_code = wp_remote_retrieve_response_code($response);
       return $status_code === 200;
   }

   public function ajax_get_synced_products() {
       check_ajax_referer('get_synced_products_nonce', 'nonce');

       $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
       $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
       $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
       
       $args = array(
           'post_type' => 'product',
           'posts_per_page' => 20,
           'paged' => $page,
           'post_status' => 'publish'
       );

       if (!empty($search)) {
           $args['s'] = $search;
       }

       if (!empty($status)) {
           $args['meta_query'] = array(
               array(
                   'key' => '_mercadolibre_id',
                   'compare' => $status === 'synced' ? 'EXISTS' : 'NOT EXISTS'
               )
           );
       }

       $query = new WP_Query($args);
       $products = array();

       foreach ($query->posts as $post) {
           $product = wc_get_product($post);
           $ml_id = get_post_meta($product->get_id(), '_mercadolibre_id', true);
           
           $products[] = array(
               'id' => $product->get_id(),
               'name' => $product->get_name(),
               'sku' => $product->get_sku(),
               'ml_status' => $ml_id ? 'synced' : 'not-synced',
               'ml_id' => $ml_id,
               'last_sync' => get_post_meta($product->get_id(), '_ml_last_sync', true),
               'image' => wp_get_attachment_image_url($product->get_image_id(), 'thumbnail') ?: wc_placeholder_img_src('thumbnail')
           );
       }

       wp_send_json_success(array(
           'products' => $products,
           'total_pages' => $query->max_num_pages
       ));
   }

   public function ajax_sync_single_product() {
       check_ajax_referer('sync_single_product_nonce', 'nonce');

       $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
       
       if (!$product_id) {
           wp_send_json_error(__('ID de producto inválido', 'woo-ml-sync'));
           return;
       }

       try {
           $result = $this->sync_product_to_mercadolibre($product_id);
           if ($result) {
               update_post_meta($product_id, '_ml_last_sync', current_time('mysql'));
               wp_send_json_success(__('Producto sincronizado exitosamente', 'woo-ml-sync'));
           } else {
               wp_send_json_error(__('Error al sincronizar el producto', 'woo-ml-sync'));
           }
       } catch (Exception $e) {
           wp_send_json_error($e->getMessage());
       }
   }

   public function sync_stock_from_mercadolibre() {
       $this->log_debug("Iniciando sincronización de stock desde MercadoLibre");

       if (!$this->check_and_refresh_token()) {
           $this->log_error("No se pudo obtener un token de acceso válido.");
           return;
       }

       $args = array(
           'post_type' => 'product',
           'posts_per_page' => -1,
           'meta_query' => array(
               array(
                   'key' => '_mercadolibre_id',
                   'compare' => 'EXISTS',
               )
           )
       );

       $query = new WP_Query($args);

       foreach ($query->posts as $post) {
           $product = wc_get_product($post);
           $ml_id = get_post_meta($product->get_id(), '_mercadolibre_id', true);

           $endpoint = WOO_ML_API_ENDPOINT . "/items/$ml_id";
           $response = $this->make_api_request($endpoint, 'GET');

           if (is_wp_error($response)) {
               $this->log_error("Error al obtener información del producto de MercadoLibre: " . $response->get_error_message());
               continue;
           }

           $body = json_decode(wp_remote_retrieve_body($response), true);

           if (isset($body['available_quantity'])) {
               $ml_stock = intval($body['available_quantity']);
               $current_stock = $product->get_stock_quantity();

               if ($ml_stock !== $current_stock) {
                   wc_update_product_stock($product, $ml_stock);
                   $this->log_debug("Stock actualizado para el producto ID: {$product->get_id()}. Nuevo stock: $ml_stock");
               }
           } else {
               $this->log_error("No se pudo obtener el stock del producto de MercadoLibre para el producto ID: {$product->get_id()}");
           }
       }

       $this->log_debug("Sincronización de stock desde MercadoLibre completada");
   }

   private function upload_image_to_mercadolibre($image_url) {
       $endpoint = WOO_ML_API_ENDPOINT . '/pictures';
       $data = array('source' => $image_url);
       
       $response = $this->make_api_request($endpoint, 'POST', $data);
       
       if (is_wp_error($response)) {
           $this->log_error("Error al subir la imagen a MercadoLibre: " . $response->get_error_message());
           return '';
       }
       
       $body = json_decode(wp_remote_retrieve_body($response), true);
       
       if (isset($body['id'])) {
           return $body['id'];
       }
       
       return '';
   }

   private function add_shipping_mode(&$data) {
    $data['shipping'] = array(
        'mode' => 'me2',
        'local_pick_up' => true,
        'free_shipping' => false,
        'logistic_type' => 'not_specified',
        'methods' => array(
            array('id' => 'me2')
        )
    );
}

   public function add_gender_field() {
       global $woocommerce, $post;
       echo '<div class="options_group">';
       woocommerce_wp_select(
           array(
               'id' => 'gender',
               'label' => __('Género', 'woo-ml-sync'),
               'desc_tip' => 'true',
               'description' => __('Seleccione el género para este producto.', 'woo-ml-sync'),
               'options' => array(
                   'male' => __('Hombre', 'woo-ml-sync'),
                   'female' => __('Mujer', 'woo-ml-sync'),
                   'unisex' => __('Sin género', 'woo-ml-sync')
               )
           )
       );
       echo '</div>';
   }

   public function save_gender_field($post_id) {
       $gender = isset($_POST['gender']) ? sanitize_text_field($_POST['gender']) : 'unisex';
       update_post_meta($post_id, 'gender', $gender);
   }

   public function add_size_grid_row_id_field() {
       global $woocommerce, $post;
       echo '<div class="options_group">';
       woocommerce_wp_text_input(
           array(
               'id' => 'SIZE_GRID_ROW_ID',
               'label' => __('SIZE GRID ROW ID', 'woo-ml-sync'),
               'desc_tip' => 'true',
               'description' => __('Ingrese el ID de la fila de la grilla de tallas de MercadoLibre.', 'woo-ml-sync')
           )
       );
       echo '</div>';
   }

   public function save_size_grid_row_id_field($post_id) {
       $size_grid_row_id = isset($_POST['SIZE_GRID_ROW_ID']) ? sanitize_text_field($_POST['SIZE_GRID_ROW_ID']) : '';
       update_post_meta($post_id, 'SIZE_GRID_ROW_ID', $size_grid_row_id);
   }

   private function log_product_attributes($product) {
       $attributes = $product->get_attributes();
       $this->log_debug("Atributos del producto ID {$product->get_id()}:");
       foreach ($attributes as $attribute) {
           $this->log_debug("- Nombre: " . $attribute->get_name() . ", Valores: " . implode(', ', $attribute->get_options()));
       }
   }

   private function get_valid_size_grid_row_id($size_grid_id, $size) {
       $endpoint = WOO_ML_API_ENDPOINT . "/catalog/charts/" . $size_grid_id;
       $response = $this->make_api_request($endpoint, 'GET');

       if (is_wp_error($response)) {
           $this->log_error("Error al obtener información de la grilla de tallas: " . $response->get_error_message());
           return false;
       }

       $body = json_decode(wp_remote_retrieve_body($response), true);
       $status_code = wp_remote_retrieve_response_code($response);

       if ($status_code === 200 && isset($body['rows'])) {
           foreach ($body['rows'] as $row) {
               foreach ($row['attributes'] as $attribute) {
                   if ($attribute['id'] === 'SIZE' && strtolower($attribute['values'][0]['name']) === strtolower($size)) {
                       return $row['id'];
                   }
               }
           }
       }

       $this->log_error("No se encontró un SIZE_GRID_ROW_ID válido para SIZE_GRID_ID: $size_grid_id y talla: $size");
       return false;
   }
}

function woo_ml_init() {
   new WooMercadoLibreSync();
}
add_action('plugins_loaded', 'woo_ml_init');
?>

