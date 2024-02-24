<?php
/*
Plugin Name: Evolo for Wordpress
Plugin URI: https://github.com/jamie2364/evolo-for-wordpress
Description: All API functionality for practice routines app.
Version: 1.0.16
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
    
    // Define the directory containing JSON files
    $json_files_dir = dirname(__DIR__) . '/json/skills-modules-tags-tools/';
    $json_files = glob($json_files_dir . '*.json');
    
    foreach ($json_files as $file) {
        // Read the JSON file
        $json_data = file_get_contents($file);
        if ($json_data === false) {
            error_log("Unable to read JSON file: $file");
            continue;
        }

        $data = json_decode($json_data, true);
        if ($data === null) {
            error_log('Invalid JSON format in file: ' . $file);
            continue;
        }

        // Skill Processing
        if(isset($data['skill_name'])) {
            $skill_name = sanitize_text_field($data['skill_name']);
            $skill_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM pr_skills WHERE skill_name = %s", $skill_name
            ));

            if (!$skill_id) {
                $wpdb->insert('pr_skills', ['skill_name' => $skill_name]);
                $skill_id = $wpdb->insert_id;
            }
        } else {
            continue; // Skip this file if 'skill_name' not present
        }

        $user_id = 1; // Adjust as necessary

        // Modules Processing
        if(isset($data['skill_modules']) && is_array($data['skill_modules'])) {
            foreach ($data['skill_modules'] as $module) {
                $module_name = sanitize_text_field($module['module_name']);
                $description = sanitize_text_field($module['description']);
                $images = maybe_serialize($module['images']); // Serialize images array

                $existing_module_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM pr_modules WHERE skill_id = %d AND module_name = %s", $skill_id, $module_name
                ));

                if (!$existing_module_id) {
                    $wpdb->insert('pr_modules', [
                        'user_id' => $user_id,
                        'skill_id' => $skill_id,
                        'module_name' => $module_name,
                        'description' => $description,
                        'images' => $images,
                    ]);
                    $module_id = $wpdb->insert_id;
                } else {
                    $module_id = $existing_module_id;
                }

                // Tags Processing for each module
                foreach ($module['tags'] as $tag) {
                    $tag_name = sanitize_text_field($tag);
                    $existing_tag_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM pr_tag_connectors WHERE tag_name = %s", $tag_name
                    ));

                    if (!$existing_tag_id) {
                        $wpdb->insert('pr_tag_connectors', ['tag_name' => $tag_name]);
                        $tag_id = $wpdb->insert_id;
                    } else {
                        $tag_id = $existing_tag_id;
                    }

                    $wpdb->insert('pr_module_tags', [
                        'module_id' => $module_id,
                        'tag_id' => $tag_id,
                    ]);
                }
            }
        }

        // Tools Processing
        if(isset($data['skill_tools']) && is_array($data['skill_tools'])) {
            foreach ($data['skill_tools'] as $tool) {
                $tool_name = sanitize_text_field($tool['tool_name']);
                $description = sanitize_text_field($tool['description']);

                $existing_tool_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM pr_tools WHERE tool_name = %s AND skill_id = %d", $tool_name, $skill_id
                ));

                if (!$existing_tool_id) {
                    $wpdb->insert('pr_tools', [
                        'user_id' => $user_id,
                        'skill_id' => $skill_id,
                        'tool_name' => $tool_name,
                        'description' => $description,
                    ]);
                }
            }
        }
    }
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




