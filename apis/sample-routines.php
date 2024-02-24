<?php

///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists(__DIR__ . '/../functions/jwt-functions.php')) {
    include_once __DIR__ . '/../functions/jwt-functions.php';
}


require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');


/////////////////////////////// Retrieve Sample Routines from json file




// Register a custom REST API endpoint
add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/sample-routines/', array(
        'methods' => 'GET',
        'callback' => 'get_sample_routines_by_skill',
        'permission_callback' => 'evolo_jwt_permission_callback' // Reference the existing JWT auth function
    ));
});

// Callback function to handle the GET request
function get_sample_routines_by_skill(WP_REST_Request $request) {
    $file_path = dirname(__DIR__) . '/json/sample_routines.json';
    $skill = $request->get_param('skill'); // Retrieve the skill from query parameters

    if (empty($skill)) {
        return new WP_Error('invalid_request', 'Skill parameter is required', array('status' => 400));
    }

    if (!file_exists($file_path)) {
        return new WP_Error('file_not_found', 'File not found', array('status' => 404));
    }

    $data = file_get_contents($file_path);
    $json_data = json_decode($data, true);

    if (!$json_data) {
        return new WP_Error('invalid_json', 'Invalid JSON', array('status' => 500));
    }

    // Check if the 'skills' key exists in the JSON data
    if (!array_key_exists('skills', $json_data) || !is_array($json_data['skills'])) {
        return new WP_Error('invalid_data_structure', 'Invalid data structure', array('status' => 500));
    }

    // Find the skill within the 'skills' array
    $filtered_data = array_filter($json_data['skills'], function ($item) use ($skill) {
        return strtolower($item['skill']) === strtolower($skill);
    });

    if (empty($filtered_data)) {
        return new WP_REST_Response(['message' => 'No routines found for this skill'], 200);
    }

    // Since we have found the skill, extract its 'routines' data and the skill name
    $skill_data = reset($filtered_data); // Get the first match (should be unique)
    $templates = isset($skill_data['routines']) ? $skill_data['routines'] : [];
    $skill_name = $skill_data['skill'];

    // Include the skill name in the response
    $response_data = [
        'skill' => $skill_name,
        'templates' => $templates,
    ];

    return new WP_REST_Response($response_data, 200);
}