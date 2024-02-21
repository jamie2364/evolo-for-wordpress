<?php

///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists(__DIR__ . '/../functions/jwt-functions.php')) {
    include_once __DIR__ . '/../functions/jwt-functions.php';
}


require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');


///////////////////////////////////////////Buddies API Call


function register_buddies_api_endpoints() {


    register_rest_route('jhg-apps', '/buddies', array(
        'methods' => 'POST',
        'callback' => 'add_buddy',
        'permission_callback' => 'evolo_jwt_permission_callback'
    ));


    register_rest_route('jhg-apps', '/buddies', array(
        'methods' => 'GET',
        'callback' => 'get_buddies',
        'permission_callback' => 'evolo_jwt_permission_callback'
    ));
	

	

    register_rest_route('jhg-apps', '/buddies', array(
    'methods' => 'PUT',
    'callback' => 'update_buddy_request',
    'permission_callback' => 'evolo_jwt_permission_callback'
));
	

register_rest_route('jhg-apps/v1', '/buddies', array(
    'methods' => 'DELETE',
    'callback' => 'delete_buddy_request',
    'permission_callback' => 'evolo_jwt_permission_callback',
    'args' => array(
        'id' => array(
            'required' => true,
            'validate_callback' => function($param) {
                return is_numeric($param);
            },
        ),
    ),
));
}


add_action('rest_api_init', 'register_buddies_api_endpoints');



function add_buddy($request) {
    global $wpdb;
    $buddies_table = 'pr_buddies';

    // Extract the user_id of the current user from the JWT token
    $requested_by = get_current_user_id();

    // Check if the current user is authenticated
    if (empty($requested_by)) {
        return new WP_Error('unauthorized', 'User not authenticated', array('status' => 401));
    }

    // Get the received_by username and skill name from the request
    $received_by_username = $request->get_param('received_by');
    $skill_name = $request->get_param('skill'); // This is the skill's name from the request body
    $note = $request->get_param('note');

    // Basic validation for received_by_username and skill_name
    if (empty($received_by_username) || empty($skill_name)) {
        return new WP_Error('invalid_data', 'Received by username and skill name are required', array('status' => 400));
    }

    // Find the user ID for the received_by username
    $received_by_id = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->users} WHERE user_login = %s",
        $received_by_username
    ));

    if (empty($received_by_id)) {
        return new WP_Error('invalid_user', 'No user found with the given username', array('status' => 404));
    }

    // Use the function to find the skill_id based on the skill name provided
    $skill_id = get_skill_id_by_name($skill_name);

    // Check if a valid skill_id was found
    if (empty($skill_id)) {
        return new WP_Error('invalid_skill', 'No skill found with the given name', array('status' => 404));
    }

    // Modify the existing request check to allow the same requested_by and received_by if skill_id is different
    $existing_request = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $buddies_table WHERE requested_by = %d AND received_by = %d AND skill_id = %d",
        $requested_by, $received_by_id, $skill_id
    ));

    if ($existing_request > 0) {
        return new WP_Error('duplicate_request', 'A buddy request with this skill already exists in the database', array('status' => 400));
    }

    // Proceed with inserting new buddy request using skill_id
    $data = array(
        'requested_by' => $requested_by,
        'received_by' => $received_by_id,
        'skill_id' => $skill_id,
        'note' => $note,
        'status' => 'pending'
    );

    $format = array('%d', '%d', '%d', '%s', '%s');

    $success = $wpdb->insert($buddies_table, $data, $format);

    if ($success === false) {
        return new WP_Error('db_error', 'Error inserting data into the database', array('status' => 500));
    }

    return array(
        'success' => true, 
        'id' => $wpdb->insert_id,
        'message' => 'Buddy request added successfully'
    );
}







/////////////////////////Get Buddies


function get_buddies($request) {
    global $wpdb;
    $buddies_table = 'pr_buddies';
    $users_table = $wpdb->users;
    $usermeta_table = $wpdb->usermeta;

    $user_id = get_current_user_id();
    $skill_filter = isset($request['skill']) ? $request['skill'] : '';

    if (empty($user_id)) {
        return new WP_Error('unauthorized', 'User not authenticated', array('status' => 401));
    }

    function get_user_profile_picture_url($user_id) {
        global $wpdb;
        $usermeta_table = $wpdb->usermeta;
        $attachment_id = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_value FROM $usermeta_table WHERE user_id = %d AND meta_key = 'profile-picture'",
            $user_id
        ));
        $url = wp_get_attachment_url($attachment_id);
        return $url ? $url : null;
    }

    $skill_condition = !empty($skill_filter) ? $wpdb->prepare(" AND s.skill_name = %s", $skill_filter) : "";

    $incoming_query = $wpdb->prepare("
        SELECT b.id, b.requested_by, b.note, s.skill_name AS skill,
       MAX(CASE WHEN um.meta_key = 'first_name' THEN um.meta_value END) AS first_name,
       MAX(CASE WHEN um.meta_key = 'last_name' THEN um.meta_value END) AS last_name,
       u.user_login AS username, b.status,
       b.date_created, b.date_updated
FROM $buddies_table AS b
JOIN $users_table AS u ON b.requested_by = u.ID
LEFT JOIN $usermeta_table AS um ON u.ID = um.user_id
LEFT JOIN pr_skills AS s ON b.skill_id = s.id
WHERE b.received_by = %d $skill_condition
GROUP BY b.id, b.requested_by, u.user_login, b.status, b.date_created, b.date_updated, b.note, s.skill_name
",
        $user_id
    );
    $incoming_requests = $wpdb->get_results($incoming_query);

    foreach ($incoming_requests as &$request) {
        $request->profile_picture = get_user_profile_picture_url($request->requested_by);
    }

    $outgoing_query = $wpdb->prepare("
        SELECT b.id, b.received_by, b.note, s.skill_name AS skill,
       MAX(CASE WHEN um.meta_key = 'first_name' THEN um.meta_value END) AS first_name,
       MAX(CASE WHEN um.meta_key = 'last_name' THEN um.meta_value END) AS last_name,
       u.user_login AS username, b.status,
       b.date_created, b.date_updated
FROM $buddies_table AS b
JOIN $users_table AS u ON b.received_by = u.ID
LEFT JOIN $usermeta_table AS um ON u.ID = um.user_id
LEFT JOIN pr_skills AS s ON b.skill_id = s.id
WHERE b.requested_by = %d $skill_condition
GROUP BY b.id, b.received_by, u.user_login, b.status, b.date_created, b.date_updated, b.note, s.skill_name
",
        $user_id
    );
    $outgoing_requests = $wpdb->get_results($outgoing_query);

    foreach ($outgoing_requests as &$request) {
        $request->profile_picture = get_user_profile_picture_url($request->received_by);
    }

    $result = array(
        'incoming_requests' => $incoming_requests,
        'outgoing_requests' => $outgoing_requests
    );

    if (empty($incoming_requests) && empty($outgoing_requests)) {
        return new WP_Error('no_buddies_found', 'No buddies requests found for the given user', array('status' => 404));
    }

    return $result;
}





function update_buddy_request($request) {
    global $wpdb;
    $table_name = 'pr_buddies';

    // Decode the JSON request body
    $data = json_decode($request->get_body(), true);

    // Extract and sanitize input data
    $id = isset($data['id']) ? (int) $data['id'] : null;
    $status = isset($data['status']) ? sanitize_text_field($data['status']) : null;

    // Basic validation
    if (empty($id) || empty($status)) {
        return new WP_Error('invalid_data', 'ID and Status fields are required', array('status' => 400));
    }

    // Update the status
    $updated = $wpdb->update(
        $table_name,
        array('status' => $status), // New value
        array('id' => $id), // Where condition
        array('%s'), // Format of the new value
        array('%d') // Format of the where condition
    );

    if ($updated === false) {
        return new WP_Error('db_error', 'Error updating the database', array('status' => 500));
    } elseif ($updated === 0) {
        return new WP_Error('no_update', 'No matching buddy request found or no change in status', array('status' => 400));
    }

    return array(
        'success' => true, 
        'message' => 'Buddy request status updated',
        'updated_status' => $status
    );
}




// function delete_buddy_request($request) {
//     global $wpdb;
//     $table_name = 'pr_buddies';

//     // Retrieve the id from the request
//     $id = $request->get_param('id');

//     // Basic validation for id
//     if (empty($id)) {
//         return new WP_Error('invalid_data', 'ID is required', array('status' => 400));
//     }

//     // Delete the record based on id
//     $deleted = $wpdb->delete(
//         $table_name,
//         array('id' => $id),
//         array('%d')
//     );

//     if ($deleted === false) {
//         return new WP_Error('db_error', 'Error deleting the record from the database', array('status' => 500));
//     } elseif ($deleted === 0) {
//         return new WP_Error('no_deletion', 'No matching record found to delete', array('status' => 404));
//     }

//     return array('success' => true, 'message' => 'Buddy request deleted successfully');
// }
// 

function delete_buddy_request($request) {
    $id = $request->get_param('id');
    global $wpdb;
    $table_name = 'pr_buddies';

    // Basic validation for 'id'
    if (empty($id) || !is_numeric($id)) {
        return new WP_Error('invalid_data', 'Invalid or missing ID parameter', array('status' => 400));
    }

    // Delete the record based on 'id'
    $deleted = $wpdb->delete(
        $table_name,
        array('id' => $id),
        array('%d')
    );

    if ($deleted === false) {
        return new WP_Error('db_error', 'Error deleting the record from the database', array('status' => 500));
    } elseif ($deleted === 0) {
        return new WP_Error('no_deletion', 'No matching record found to delete', array('status' => 404));
    }

    return array('success' => true, 'message' => 'Buddy request deleted successfully');
}