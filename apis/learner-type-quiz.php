<?php

///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists( get_stylesheet_directory() . '/jwt_api.php' )) {
    include_once get_stylesheet_directory() . '/jwt_api.php';
}

require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');


///////////////////////////////////////////////Retrieve Learner type quiz


// Register a custom REST API endpoint for learner_type.json
add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/learner-type/', array(
        'methods' => 'GET',
        'callback' => 'get_learner_type_data',
        'permission_callback' => 'jwt_permission_callback'
    ));
});

// Callback function to handle the GET request for learner_type.json
function get_learner_type_data(WP_REST_Request $request) {
    $file_path = WP_CONTENT_DIR . '/practice-routines-pro/quizzes/learner_type.json';

    if (!file_exists($file_path)) {
        return new WP_Error('file_not_found', 'File not found', array('status' => 404));
    }

    $data = file_get_contents($file_path);
    $json_data = json_decode($data, true);

    if (!$json_data) {
        return new WP_Error('invalid_json', 'Invalid JSON', array('status' => 500));
    }

    return new WP_REST_Response($json_data, 200);
}