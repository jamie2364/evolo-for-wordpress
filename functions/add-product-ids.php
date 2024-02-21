<?php


// Add a submenu item in the WordPress admin panel under Settings
function add_app_product_ids_submenu() {
    add_submenu_page(
        'options-general.php', // Parent slug
        'App Product IDs Settings', // Page title
        'App Product IDs', // Menu title
        'manage_options', // Capability
        'app_product_ids_settings', // Menu slug
        'app_product_ids_settings_page' // Callback function
    );
}
add_action('admin_menu', 'add_app_product_ids_submenu');

// Display the settings page
function app_product_ids_settings_page() {
    ?>
    <div class="wrap">
        <h2>App Product IDs Settings</h2>
        <form method="post" action="options.php">
            <?php settings_fields('product_ids_group'); ?>
            <?php do_settings_sections('product_ids_group'); ?>
            <input type="submit" class="button-primary" value="Save Changes"/>
        </form>
    </div>
    <?php
}

// Register and define the settings
function register_app_product_ids_settings() {
    register_setting('product_ids_group', 'product_ids_option');
    add_settings_section('product_ids_section', 'Product IDs', 'product_ids_section_callback', 'product_ids_group');
    add_settings_field('product_ids_field', 'Enter Product IDs (comma-separated)', 'product_ids_field_callback', 'product_ids_group', 'product_ids_section');
}
add_action('admin_init', 'register_app_product_ids_settings');

// Callback functions
function product_ids_section_callback() {
    echo '<p>Please enter the product IDs for the subscription products that give access to apps below:</p>';
}

function product_ids_field_callback() {
    $value = get_option('product_ids_option');
    echo '<input type="text" id="product_ids_field" name="product_ids_option" value="' . esc_attr($value) . '" />';
}


