<?php
/*
Plugin Name: Evolo for Wordpress
Plugin URI: https://github.com/jamie2364/evolo-for-wordpress
Description: All API functionality for practice routines app.
Version: 1.0.14
Author: Jamie Harrison
GitHub Plugin URI: https://github.com/jamie2364/evolo-for-wordpress
Primary Branch: main
*/


// Automatically include all PHP files from the 'apis' folder
$api_files = glob(plugin_dir_path(__FILE__) . 'apis/*.php');

foreach ($api_files as $file) {
    require_once $file;
}

// Include 'add-product-ids.php' from the 'functions' folder
$functions_folder = plugin_dir_path(__FILE__) . 'functions/';
$add_product_ids_file = $functions_folder . 'add-product-ids.php';
if (file_exists($add_product_ids_file)) {
    require_once $add_product_ids_file;
} else {
    // Log an error if the file doesn't exist, using error_log instead of echo for better error handling
    error_log("Error: File $add_product_ids_file not found!");
}




/////////////////////// Run functions on activation and plugin update

require_once __DIR__ . '/functions/post-json-data.php';

// Ensure the function is available
if (!function_exists('get_plugin_data')) {
    require_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

// Get the plugin data
$plugin_data = get_plugin_data(__FILE__);
// Define the plugin version based on the data fetched from the plugin header
define('MY_PLUGIN_VERSION', $plugin_data['Version']);

// Function to import data on activation
function import_data_on_activation() {
    global $wpdb;
    // Your data import logic here...
}

// Runs on plugin activation
function my_plugin_activate() {
    import_data_on_activation();
    
    // Update the version in the database to the current version
    update_option('my_plugin_version', MY_PLUGIN_VERSION);
}

register_activation_hook(__FILE__, 'my_plugin_activate');

// Check for plugin update
function my_plugin_check_version() {
    if (get_option('my_plugin_version') != MY_PLUGIN_VERSION) {
        // The plugin has been updated
        my_plugin_activate(); // You can call the same function used for activation or define a new one for updates
        
        // Update the version in the database
        update_option('my_plugin_version', MY_PLUGIN_VERSION);
    }
}
add_action('admin_init', 'my_plugin_check_version');




