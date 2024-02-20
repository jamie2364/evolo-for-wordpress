<?php


///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists( get_stylesheet_directory() . '/jwt_api.php' )) {
    include_once get_stylesheet_directory() . '/jwt_api.php';
}

require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');


///////////////////// Get List of quotes for each skill


// Define a custom API endpoint
function custom_skill_quotes_api_init() {
    register_rest_route('jhg-apps/v1', '/skill-quotes', array(
        'methods' => 'GET',
        'callback' => 'get_random_skill_quote',
        'permission_callback' => 'jwt_permission_callback',
    ));
}

// Register the API endpoint
add_action('rest_api_init', 'custom_skill_quotes_api_init');

// Callback function to get a random skill quote based on the skill query parameter
function get_random_skill_quote($request) {
    // Retrieve the 'skill' query parameter from the request
    $skill_param = $request->get_param('skill');

    // Ensure that the 'skill' parameter is provided
    if (!$skill_param) {
        return new WP_Error('missing_skill_param', 'The "skill" query parameter is missing.', array('status' => 400));
    }

    // Split the 'skill' parameter into an array, assuming skills are separated by commas
    $skills_array = explode(',', $skill_param);

    // Define the file path
    $file_path = WP_CONTENT_DIR . '/practice-routines-pro/skill-quotes/skill_quotes.json';

    // Check if the file exists
    if (file_exists($file_path)) {
        // Read the file content
        $file_content = file_get_contents($file_path);

        // Parse the JSON content
        $json_data = json_decode($file_content, true);

        // Initialize an array to hold the response
        $response = array();

        // Loop through each skill provided
        foreach ($skills_array as $skill) {
            // Convert the 'skill' to lowercase for case-insensitive comparison
            $skill_lower = strtolower(trim($skill));

            // Loop through the JSON data to find a match for the current skill
            foreach ($json_data as $item) {
                if (isset($item['skill_name']) && strtolower($item['skill_name']) === $skill_lower) {
                    // Check if there are quotes available for the skill
                    if (isset($item['quotes']) && is_array($item['quotes'])) {
                        // Select a random quote from the 'quotes' array
                        $random_quote = $item['quotes'][array_rand($item['quotes'])];

                        // Add the random quote to the response array with the skill as the key
                        $response[$skill] = $random_quote;
                    }
                    break; // Stop searching once a match is found for this skill
                }
            }
        }

        if (!empty($response)) {
            // Return the response as a JSON response
            return rest_ensure_response($response);
        } else {
            // No matching items or quotes found
            return new WP_Error('item_not_found', 'No skill quotes found for the specified skills.', array('status' => 404));
        }
    } else {
        // File does not exist
        return new WP_Error('file_not_found', 'The skill quotes file was not found.', array('status' => 404));
    }
}