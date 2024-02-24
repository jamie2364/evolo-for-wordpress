<?php

// Include the file where import_data_on_activation function is defined
require_once __DIR__ . '/post-json-data.php';

// Create a top-level menu item called 'Evolo'
function add_evolo_menu_page() {
    add_menu_page(
        'Evolo Settings', // Page title
        'Evolo', // Menu title
        'manage_options', // Capability
        'evolo_settings', // Menu slug
        'evolo_settings_page_callback', // Callback function to display the page
        'dashicons-admin-generic', // Icon URL
        6 // Position
    );
}
add_action('admin_menu', 'add_evolo_menu_page');

// Callback function to display the 'Evolo Settings' page
function evolo_settings_page_callback() {
    // Check if the refresh button was pressed
    if (isset($_POST['refresh_data'])) {
        // Call the function to import data
        import_data_on_activation();
        
        // Optionally, add an admin notice to confirm the data refresh
        echo '<div class="notice notice-success is-dismissible"><p>Data refreshed successfully.</p></div>';
    }

    ?>
    <div class="wrap">
        <h2>Evolo Settings</h2>
        <form method="post" action="options.php">
            <?php settings_fields('product_ids_group'); ?>
            <?php do_settings_sections('product_ids_group'); ?>
            <input type="submit" class="button-primary" value="Save Changes"/>
        </form>

        <hr>

        <!-- Data Refresh Section -->
        <h2>Data Refresh</h2>
        <p>Click on the below button to refresh the data stored in the database for Skills, Modules, Tools, and Tags.</p>
        <form method="post">
            <!-- Added functionality to refresh data -->
            <input type="submit" name="refresh_data" class="button action" value="Refresh Data">
        </form>
    </div>
    <?php
}

// Register and define the settings
function register_evolo_settings() {
    register_setting('product_ids_group', 'product_ids_option');
    add_settings_section('product_ids_section', 'Product IDs', 'product_ids_section_callback', 'product_ids_group');
    add_settings_field('product_ids_field', 'Enter Product IDs (comma-separated)', 'product_ids_field_callback', 'product_ids_group', 'product_ids_section');
}
add_action('admin_init', 'register_evolo_settings');

function product_ids_section_callback() {
    echo '<p>Please enter the product IDs for the subscription products that give access to apps below:</p>';
}

function product_ids_field_callback() {
    $value = get_option('product_ids_option');
    echo '<input type="text" id="product_ids_field" name="product_ids_option" value="' . esc_attr($value) . '" style="width: 100%;">';
}

