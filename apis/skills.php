<?php

///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists(__DIR__ . '/../functions/jwt-functions.php')) {
    include_once __DIR__ . '/../functions/jwt-functions.php';
}


require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');


////////////////////////////////////Skills APIs





/////////////////////////// Submit user skill 




add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/user-skills', array(
        'methods' => 'POST',
        'callback' => 'submit_user_skill',
        'permission_callback' => 'jwt_permission_callback' // Updated permission callback
    ));
});

function submit_user_skill($request) {
    global $wpdb;
    $parameters = $request->get_json_params();
    
    $user_id = isset($parameters['user_id']) ? intval($parameters['user_id']) : null;
    $skill_name = isset($parameters['skill_name']) ? sanitize_text_field($parameters['skill_name']) : null;
    $start_date = isset($parameters['start_date']) ? $parameters['start_date'] : null;

    // Validate input
    if (!$user_id || !$skill_name || !$start_date) {
        return new WP_Error('missing_parameters', 'Missing required parameter', array('status' => 400));
    }

    // Find the skill_id from pr_skills table
    $skill_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM pr_skills WHERE skill_name = %s",
        $skill_name
    ));

    if (!$skill_id) {
        return new WP_Error('invalid_skill', 'Skill not found', array('status' => 404));
    }

    // Check for existing entry with the same user_id and skill_id
    $existing_entry = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM pr_user_skills WHERE user_id = %d AND skill_id = %d",
        $user_id, $skill_id
    ));

    if ($existing_entry > 0) {
        // Entry exists, return error
        return new WP_Error('duplicate_entry', 'An entry with the provided user_id and skill_id already exists.', ['status' => 400]);
    }

    // Insert into pr_user_skills table
    $insert_result = $wpdb->insert('pr_user_skills', [
        'user_id' => $user_id,
        'skill_id' => $skill_id,
        'start_date' => $start_date
    ], ['%d', '%d', '%s']);

    if ($insert_result === false) {
        return new WP_Error('db_error', 'Error inserting user skill', array('status' => 500));
    }

    return new WP_REST_Response(array('message' => 'User skill added successfully', 'id' => $wpdb->insert_id), 200);
}



////////////////////////////get skills 

// add_action('rest_api_init', function () {
//     register_rest_route('jhg-apps/v1', '/skills', array(
//         'methods' => 'GET',
//         'callback' => 'get_user_skill',
//         'permission_callback' => 'jwt_permission_callback' // Updated permission callback
//     ));
// });

// function get_user_skill() {
//     global $wpdb;
// 	$arr = array(
// 		array(
// 			"skill_id" => "0",
// 			"skill_name" => "Guitar",
// 			"start_date" => "2024-02-02"
// 		),
// 		array(
// 			"skill_id" => "1",
// 			"skill_name" => "Piano",
// 			"start_date" => "2024-02-02"
// 		)
// 	);
    
//     return new WP_REST_Response(array("skills"=> $arr), 200);
// }

add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/user-skills', array(
        'methods' => 'GET',
        'callback' => 'get_user_skills_from_db',
        'permission_callback' => function () {
            return get_current_user_id() !== 0;
        }
    ));
});

function get_user_skills_from_db(WP_REST_Request $request) {
    global $wpdb;

    // Directly use WordPress function to get current user ID.
    $user_id = get_current_user_id(); 
    if (!$user_id) {
        return new WP_Error('jwt_auth_failed', 'User authentication failed', array('status' => 403));
    }

    // Query to get user skills from the `pr_user_skills` table and corresponding names from `pr_skills`
    $query = $wpdb->prepare("
        SELECT us.skill_id, s.skill_name, us.start_date 
        FROM pr_user_skills us
        INNER JOIN pr_skills s ON us.skill_id = s.id
        WHERE us.user_id = %d
    ", $user_id);

    $skills = $wpdb->get_results($query, ARRAY_A);

    if(empty($skills)) {
        return new WP_REST_Response(array("message"=> "No skills found for user"), 404);
    }

    return new WP_REST_Response(array("skills"=> $skills), 200);
}



/////////////////////// Post new skill

add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/skills', array(
        'methods' => 'POST',
        'callback' => 'add_skill',
        'permission_callback' => 'jwt_permission_callback' // Assuming permission callback function
    ));
});

function add_skill($request) {
    global $wpdb;

    // Get skill name from request body
    $skill_name = $request->get_param('skill_name');

    // Validate skill name
    if (empty($skill_name)) {
        return new WP_Error('missing_skill_name', 'Skill name parameter is required', array('status' => 400));
    }

    // Insert the skill into pr_skills table
    $insert_result = $wpdb->insert('pr_skills', array('skill_name' => $skill_name));

    if ($insert_result === false) {
        // Error inserting skill
        return new WP_Error('db_error', 'Error inserting skill', array('status' => 500));
    }

    // Retrieve the ID of the inserted skill
    $skill_id = $wpdb->insert_id;

    // Return success response
    return new WP_REST_Response(array(
        'message' => 'Skill added successfully',
        'skill_id' => $skill_id
    ), 200);
}


/////////////////// Get list of Skills


add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/skills', array(
        'methods' => 'GET',
        'callback' => 'get_skills',
        'permission_callback' => 'jwt_permission_callback' // Assuming permission callback function
    ));
});

function get_skills($request) {
    global $wpdb;

    // Retrieve parameters from the request query
    $skill_name = $request->get_param('skill_name');
    $id = $request->get_param('id');

    // Check if skill_name or id is provided
    if (!empty($skill_name)) {
        // Query to get skill ID based on skill name
        $skill_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM pr_skills WHERE skill_name = %s",
            $skill_name
        ));

        if ($skill_id) {
            // Return success response with skill ID
            return new WP_REST_Response(array(
                'skill_name' => $skill_name,
                'skill_id' => $skill_id
            ), 200);
        } else {
            // Skill not found
            return new WP_Error('skill_not_found', 'Skill not found', array('status' => 404));
        }
    } elseif (!empty($id)) {
        // Query to get skill name based on skill ID
        $skill_name = $wpdb->get_var($wpdb->prepare(
            "SELECT skill_name FROM pr_skills WHERE id = %d",
            $id
        ));

        if ($skill_name) {
            // Return success response with skill name
            return new WP_REST_Response(array(
                'skill_id' => $id,
                'skill_name' => $skill_name
            ), 200);
        } else {
            // Skill not found
            return new WP_Error('skill_not_found', 'Skill not found', array('status' => 404));
        }
    } else {
        // No parameter provided, return all skills
        $skills = $wpdb->get_results("SELECT id, skill_name FROM pr_skills");

        if (empty($skills)) {
            return new WP_Error('no_skills_found', 'No skills found', array('status' => 404));
        }

        // Return success response with all skills
        return new WP_REST_Response($skills, 200);
    }
}




////////////////////////////////////Edit Skill Name


add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/skills', array(
        'methods' => 'PUT',
        'callback' => 'update_skill',
        'permission_callback' => 'jwt_permission_callback' // Assuming permission callback function
    ));
});

function update_skill($request) {
    global $wpdb;

    // Retrieve parameters from the request body
    $id = $request->get_param('id');
    $new_skill_name = $request->get_param('skill_name');

    // Validate parameters
    if (empty($id) || empty($new_skill_name)) {
        return new WP_Error('missing_parameters', 'Both skill ID and new skill name parameters are required', array('status' => 400));
    }

    // Update the skill name in the pr_skills table
    $update_result = $wpdb->update(
        'pr_skills',
        array('skill_name' => $new_skill_name),
        array('id' => $id),
        array('%s'),
        array('%d')
    );

    if ($update_result === false) {
        // Error updating skill name
        return new WP_Error('db_error', 'Error updating skill name', array('status' => 500));
    }

    // Return success response
    return new WP_REST_Response(array(
        'message' => 'Skill updated successfully',
        'skill_id' => $id,
        'new_skill_name' => $new_skill_name
    ), 200);
}


/////////////////// Delete Skill


add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/skills', array(
        'methods' => 'DELETE',
        'callback' => 'delete_skill',
        'permission_callback' => 'jwt_permission_callback' // Assuming permission callback function
    ));
});

function delete_skill($request) {
    global $wpdb;

    // Retrieve parameters from the request body
    $id = $request->get_param('id');
    $skill_name = $request->get_param('skill_name');

    // Validate parameters
    if (empty($id) && empty($skill_name)) {
        return new WP_Error('missing_parameters', 'Either skill ID or skill name parameter is required', array('status' => 400));
    }

    // Delete the skill from the pr_skills table
    if (!empty($id)) {
        // Delete by ID
        $delete_result = $wpdb->delete(
            'pr_skills',
            array('id' => $id),
            array('%d')
        );
    } else {
        // Delete by skill name
        $delete_result = $wpdb->delete(
            'pr_skills',
            array('skill_name' => $skill_name),
            array('%s')
        );
    }

    if ($delete_result === false) {
        // Error deleting skill
        return new WP_Error('db_error', 'Error deleting skill', array('status' => 500));
    }

    // Return success response
    return new WP_REST_Response(array(
        'message' => 'Skill deleted successfully'
    ), 200);
}