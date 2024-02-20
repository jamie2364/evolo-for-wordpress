<?php


///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists( get_stylesheet_directory() . '/jwt_api.php' )) {
    include_once get_stylesheet_directory() . '/jwt_api.php';
}

require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');


///////////////////////////Add a tool to the database and retrieve it also

// Register the REST API endpoint for both GET and POST methods
add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/tools', array(
        array(
            'methods' => 'GET',
            'callback' => 'get_tools_by_skill',
            'permission_callback' => 'jwt_permission_callback' // Ensure JWT authentication is set up
        ),
        array(
            'methods' => 'POST',
            'callback' => 'post_tool',
            'permission_callback' => 'jwt_permission_callback' // Ensure JWT authentication is set up
        )
    ));
});

// Callback function to handle POST request for adding a new tool
function post_tool($request) {
    global $wpdb;

    // Decode the JSON request body
    $data = json_decode($request->get_body(), true);
    $tools = isset($data['tools']) ? $data['tools'] : null;

    // Validate the 'tools' array
    if (!$tools || !is_array($tools)) {
        return new WP_Error('invalid_format', "The 'tools' parameter should be an array", ['status' => 400]);
    }

    // Extract user_id from the JWT token
    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_Error('invalid_user', 'User ID could not be retrieved from token', ['status' => 400]);
    }

    $results = [];
    foreach ($tools as $tool) {
        $tool_name = isset($tool['tool_name']) ? sanitize_text_field($tool['tool_name']) : null;
        $skill_name = isset($tool['skill']) ? sanitize_text_field($tool['skill']) : null;
        // Accepting the description field
        $description = isset($tool['description']) ? sanitize_textarea_field($tool['description']) : null;

        if (!$tool_name || !$skill_name) {
            $results[] = [
                'tool_name' => $tool_name,
                'message' => "Missing 'tool_name' or 'skill' parameter",
                'status' => 'error'
            ];
            continue;
        }

        $skill_id = get_skill_id_by_name($skill_name);
        if (!$skill_id) {
            $results[] = [
                'tool_name' => $tool_name,
                'message' => "Skill not found",
                'status' => 'error'
            ];
            continue;
        }

        $existing_tool = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM pr_tools WHERE tool_name = %s AND skill_id = %d",
            $tool_name, $skill_id
        ));

        if ($existing_tool) {
            $results[] = [
                'tool_name' => $tool_name,
                'message' => 'Tool already exists',
                'status' => 'error'
            ];
            continue;
        }

        $result = $wpdb->insert('pr_tools', [
            'tool_name' => $tool_name,
            'skill_id' => $skill_id,
            'user_id' => $user_id,
            'description' => $description // Inserting the description into the database
        ]);

        if ($result) {
            $tool_id = $wpdb->insert_id;
            $results[] = [
                'tool_id' => $tool_id,
                'tool_name' => $tool_name,
                'message' => 'Tool successfully added to the database',
                'status' => 'success'
            ];
        } else {
            $results[] = [
                'tool_name' => $tool_name,
                'message' => 'Error adding tool to the database',
                'status' => 'error'
            ];
        }
    }

    return new WP_REST_Response($results, 200);
}






function get_tools_by_skill($request) {
    global $wpdb;

    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_Error('jwt_auth_failed', 'User authentication failed', array('status' => 403));
    }
    $skill_name = $request->get_param('skill');

    $skill_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM pr_skills WHERE skill_name = %s",
        $skill_name
    ));

    if (!$skill_id) {
        return new WP_Error('invalid_skill', 'Skill not found', array('status' => 404));
    }

    if (!empty($skill_id)) {
        $query = $wpdb->prepare("SELECT * FROM pr_tools WHERE skill_id = %s AND user_id = %d", $skill_id, $user_id);
    } else {
        $query = "SELECT * FROM pr_tools";
    }

    $tools = $wpdb->get_results($query, ARRAY_A); // Fetch as associative array to include column names

    if (is_null($tools)) {
        return new WP_Error('db_error', 'Error fetching tools', array('status' => 500));
    }

    // Return tools including the description field
    return new WP_REST_Response($tools, 200);
}