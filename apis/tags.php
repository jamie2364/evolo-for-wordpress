<?php


///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists(__DIR__ . '/../functions/jwt-functions.php')) {
    include_once __DIR__ . '/../functions/jwt-functions.php';
}

require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');


/////////////////////////////////////////////Add tag and list all tags


add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/tags/', array(
        array(
            'methods' => 'POST',
            'callback' => 'add_new_tag',
            'permission_callback' => 'evolo_jwt_permission_callback'
        ),
        array(
            'methods' => 'GET',
            'callback' => 'list_all_tags',
            'permission_callback' => 'evolo_jwt_permission_callback'
        )
    ));
});


function add_new_tag($request) {
    global $wpdb;

    // Retrieve parameters from the JSON body of the request
    $body = $request->get_json_params();
    $tag_name = isset($body['tag_name']) ? $body['tag_name'] : null;
    $skill_name = isset($body['skill']) ? $body['skill'] : null;

    // Extract user_id from JWT token
    $user_id = get_current_user_id();

    if (empty($tag_name) || empty($skill_name)) {
        return new WP_Error('missing_parameters', 'Both tag name and skill parameters are required', array('status' => 400));
    }

    $skill_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM pr_skills WHERE skill_name = %s", 
        $skill_name
    ));

    if (!$skill_id) {
        return new WP_Error('invalid_skill', 'Skill does not exist', array('status' => 404));
    }

    $existing_tag = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM pr_tag_connectors WHERE tag_name = %s AND skill_id = %d AND user_id = %d", 
        $tag_name, $skill_id, $user_id
    ));

    if ($existing_tag) {
        return new WP_REST_Response('This tag already exists for the specified skill', 409);
    }

    $result = $wpdb->insert('pr_tag_connectors', array(
        'tag_name' => $tag_name, 
        'skill_id' => $skill_id,
        'user_id' => $user_id // Insert user_id into the table
    ));

    if ($result) {
        return new WP_REST_Response(array(
            'message' => 'Tag added successfully',
            'tag_id' => $wpdb->insert_id,
            'tag_name' => $tag_name,
            'skill_id' => $skill_id
        ), 200);
    } else {
        return new WP_Error('db_error', 'Error inserting tag', array('status' => 500));
    }
}



function list_all_tags($request) {
    global $wpdb;
    $skill_name = $request->get_param('skill');

    // Extract user_id from JWT token
    $user_id = get_current_user_id();

    if (empty($skill_name)) {
        return new WP_Error('missing_skill', 'Skill parameter is required', array('status' => 400));
    }

    $skill_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM pr_skills WHERE skill_name = %s", 
        $skill_name
    ));

    if (!$skill_id) {
        return new WP_Error('invalid_skill', 'Skill does not exist', array('status' => 404));
    }

    $tags = $wpdb->get_results($wpdb->prepare(
        "SELECT id, tag_name FROM pr_tag_connectors WHERE skill_id = %d AND user_id = %d", 
        $skill_id, $user_id
    ));

    if (is_null($tags)) {
        return new WP_Error('db_error', 'Error fetching tags', array('status' => 500));
    }

    return new WP_REST_Response($tags, 200);
}