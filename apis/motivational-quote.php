<?php

///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists(__DIR__ . '/../functions/jwt-functions.php')) {
    include_once __DIR__ . '/../functions/jwt-functions.php';
}

require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');


////////////////////////////////////////////Retrieve Motivational Quote for Home page


// Register a custom REST API endpoint for motivational quotes
add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/motivational-quotes/', array(
        'methods' => 'GET',
        'callback' => 'get_random_motivational_quote',
        'permission_callback' => 'evolo_jwt_permission_callback' // Reference the existing JWT auth function
    ));
});

// Callback function to handle the GET request for motivational quotes
function get_random_motivational_quote(WP_REST_Request $request) {
    $file_path = dirname(__DIR__) . '/json/motivational_quotes.json';

    if (!file_exists($file_path)) {
        return new WP_Error('file_not_found', 'File not found', array('status' => 404));
    }

    $data = file_get_contents($file_path);
    $json_data = json_decode($data, true);

    if (!$json_data || !is_array($json_data)) {
        return new WP_Error('invalid_json', 'Invalid JSON', array('status' => 500));
    }

    // Choose a random index within the array
    $random_index = array_rand($json_data);

    // Get the randomly selected quote
    $random_quote = $json_data[$random_index];

    return new WP_REST_Response($random_quote, 200);
}