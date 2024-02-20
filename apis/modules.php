<?php


///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists(__DIR__ . '../functions/jwt-functions.php')) {
    include_once __DIR__ . '../functions/jwt-functions.php';
}

require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');


/////////////////////////////////////////////////// Post module data to the database

/**
 * This code registers a WordPress REST API endpoint for posting practice module information.
 * It allows authorized users, both administrators and non-admin users, to submit module data
 * and handles the insertion and updating of module records in the database. It also manages
 * tags associated with the modules based on user roles and JWT authentication.
 */


// Register the REST API endpoint
add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/PracticeModuleInfo', array(
        'methods' => 'POST',
        'callback' => 'post_practice_module_info',
        'permission_callback' => 'jwt_permission_callback'
    ));
});

// Callback function to handle POST request for module info
function post_practice_module_info($request) {
    global $wpdb;

    // Decode the JSON request body
    $data = json_decode($request->get_body(), true);

    // Sanitize input data
    $skill_name = sanitize_text_field($data['skill_name']);
    $skill_modules = $data['skill_modules'];

    // Convert skill name to skill_id
    $skill_id = get_skill_id_by_name($skill_name);
    if (!$skill_id) {
        return new WP_Error('invalid_skill', 'Skill not found', array('status' => 404));
    }

    // Extract the user_id from the JWT token
    $user_id = get_current_user_id();

    $response_data = array(); // Initialize an array to store response data

    foreach ($skill_modules as $module) {
        $module_name = sanitize_text_field($module['module_name']);
        $description = sanitize_text_field($module['description']);
        $images = maybe_serialize($module['images']); // Serialize the array
        $tags = $module['tags']; // Assuming tags are already sanitized

        // Check if the module with the same name exists for the skill_id
        $existing_module = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM pr_modules WHERE skill_id = %d AND module_name = %s",
            $skill_id,
            $module_name
        ), ARRAY_A);

        if (!$existing_module) {
            // Module doesn't exist, so insert it
            $wpdb->insert(
                'pr_modules',
                array(
                    'user_id' => $user_id,
                    'skill_id' => $skill_id,
                    'module_name' => $module_name,
                    'description' => $description,
                    'images' => $images,
                )
            );
            $module_id = $wpdb->insert_id;

            // Handle tags
            $tag_data = array();

            foreach ($tags as $tag) {
                // Check if the tag already exists in pr_tag_connectors, if not insert it
                $tag_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM pr_tag_connectors WHERE tag_name = %s",
                    $tag
                ));

                if (!$tag_id) {
                    $wpdb->insert(
                        'pr_tag_connectors',
                        array(
                            'tag_name' => $tag,
                            'skill_id' => $skill_id // Insert skill_id into the skill_id column
                        )
                    );
                    $tag_id = $wpdb->insert_id;
                }

                // Associate the tag with the module
                $wpdb->insert(
                    'pr_module_tags',
                    array(
                        'module_id' => $module_id,
                        'tag_id' => $tag_id
                    )
                );

                // Store tag data (tag_name, tag_id, and skill_id) for later use
                $tag_data[] = array(
                    'tag_name' => $tag,
                    'tag_id' => $tag_id,
                    'skill_id' => $skill_id // Include skill_id in the tag data
                );
            }

            // Add the module_id, module_name, user_id, and associated tag data to the response data
            $response_data[] = array(
                'module_id' => $module_id,
                'module_name' => $module_name,
                'user_id' => $user_id,
                'tags' => $tag_data,
                'message' => 'Module Data Successfully Posted'
            );
        } else {
            // Module with the same name already exists for the skill_id, so show an error message
            $response_data[] = array(
                'error' => 'Module with the same name already exists for this skill in the database.',
                'module_name' => $module_name,
            );
        }
    }

    // Respond with the response data including module_id, user_id, and associated tag data
    return new WP_REST_Response($response_data, 200);
}





//////////////////////////Get the module data from the database to display in the app

/**
 * This code registers a WordPress REST API endpoint for retrieving practice module information
 * based on a specified skill. It accepts a 'skill' parameter in the GET request, validates it,
 * and then fetches relevant module data from the database. The response includes module details
 * and associated tags, organized by skill name.
 */

add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/PracticeModuleInfo', array(
        'methods' => 'GET',
        'callback' => 'get_practice_module_info',
        'args' => array(
            'skill' => array(
                'validate_callback' => function ($param, $request, $key) {
                    return is_string($param);
                },
                'required' => true,
            ),
        ),
        'permission_callback' => 'jwt_permission_callback',
    ));
});

function get_practice_module_info($request) {
    global $wpdb;
    $skill_name = $request->get_param('skill');

    // Sanitize the skill name parameter
    $skill_name = sanitize_text_field($skill_name);

    // Fetch skill_id for the given skill name
    $skill_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM pr_skills WHERE skill_name = %s",
        $skill_name
    ));

    if (!$skill_id) {
        return new WP_Error('invalid_skill', 'Skill not found', array('status' => 404));
    }

    // Fetch modules for the given skill_id
    $modules = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM pr_modules WHERE skill_id = %d",
        $skill_id
    ), ARRAY_A);

    if (!empty($modules)) {
        foreach ($modules as $key => $module) {
            $module_id = $module['id'];
            $tags = $wpdb->get_results($wpdb->prepare(
                "SELECT tc.id, tc.tag_name FROM pr_tag_connectors tc 
                INNER JOIN pr_module_tags mt ON tc.id = mt.tag_id 
                WHERE mt.module_id = %d",
                $module_id
            ), ARRAY_A);

            $tag_data = array();
            foreach ($tags as $tag) {
                $tag_data[] = array(
                    'tag_name' => $tag['tag_name'],
                    'tag_id' => $tag['id'],
                );
            }

            $modules[$key] = array(
                'module_id' => $module_id,
                'module_name' => $module['module_name'],
                'description' => $module['description'],
                'images' => maybe_unserialize($module['images']), // Assuming images are stored serialized
                'tags' => $tag_data,
                'user_id' => $module['user_id'],
            );
        }
    }

    $response_data = array('skill_name' => $skill_name, 'modules' => $modules);

    return new WP_REST_Response($response_data, 200);
}





/////////////////////////////Update modules 


add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/PracticeModuleInfo', array(
        'methods' => 'PUT',
        'callback' => 'put_practice_module_info',
        'permission_callback' => 'jwt_permission_callback',
    ));
});

function put_practice_module_info($request) {
    global $wpdb;

    // Extract the user_id from the JWT token
    $user_id = get_current_user_id();

    // Decode the JSON request body
    $data = json_decode($request->get_body(), true);

    // Check if the module_id is provided in the request body
    if (empty($data['module_id'])) {
        // Module ID is missing, return an error response
        return new WP_REST_Response(array('message' => 'Module ID is missing in the request body'), 400);
    }

    // Extract the module_id from the request body
    $module_id = intval($data['module_id']);

    // Check if the user is an administrator
    $is_administrator = in_array('administrator', wp_get_current_user()->roles);

    // Check if the module exists
    $existing_module = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM pr_modules WHERE id = %d",
        $module_id
    ), ARRAY_A);

    if (!$existing_module) {
        // Module doesn't exist, return an error response
        return new WP_REST_Response(array('message' => 'Module not found'), 404);
    }

    // Check if the user is the original creator of the module or an administrator
    if ($existing_module['user_id'] != $user_id && !$is_administrator) {
        // User doesn't have permission to edit this module, return an error response
        return new WP_REST_Response(array('message' => 'Permission denied'), 403);
    }

    // Sanitize and update module details
    $module_name = sanitize_text_field($data['module_name']); // Updated to 'module_name'
    $description = sanitize_text_field($data['description']);
    $images = maybe_serialize($data['images']); // Serialize the array
    $tags = $data['tags']; // Assuming tags are already sanitized

    // Update the module in the database
    $wpdb->update(
        'pr_modules',
        array(
            'module_name' => $module_name, // Updated to 'module_name'
            'description' => $description,
            'images' => $images,
        ),
        array('id' => $module_id)
    );

    // Handle tags
    if ($is_administrator) {
        // If the user is an administrator, remove all existing tags associated with the module
        $wpdb->query($wpdb->prepare(
            "DELETE FROM pr_module_tags WHERE module_id = %d",
            $module_id
        ));
    }

    // Reassociate tags with the module
    foreach ($tags as $tag) {
        // Check if the tag already exists in pr_tag_connectors, if not insert it
        $tag_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM pr_tag_connectors WHERE tag_name = %s",
            $tag
        ));

        if (!$tag_id) {
            $wpdb->insert(
                'pr_tag_connectors',
                array('tag_name' => $tag)
            );
            $tag_id = $wpdb->insert_id;
        }

        // Associate the tag with the module
        $wpdb->insert(
            'pr_module_tags',
            array(
                'module_id' => $module_id,
                'tag_id' => $tag_id
            )
        );
    }

    // Respond with a success message
    return new WP_REST_Response(array('message' => 'Module Data Successfully Updated'), 200);
}




////////////////////////////////////////Delete a module

add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/PracticeModuleInfo', array(
        'methods' => 'DELETE',
        'callback' => 'delete_practice_module_info',
        'permission_callback' => 'jwt_permission_callback',
        'args' => array(
            'module_id' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param);
                }
            )
        )
    ));
});

function delete_practice_module_info($request) {
    global $wpdb;

    // Extract the module_id from the request
    $module_id = $request->get_param('module_id');
    if (empty($module_id)) {
        // Module ID is missing, return an error response
        return new WP_Error('missing_data', 'Module ID is missing in the request', array('status' => 400));
    }

    // Convert module_id to integer
    $module_id = intval($module_id);

    // Check if the user has permission to delete modules
    // This example assumes the user must be an administrator
    if (!current_user_can('delete_others_posts')) {
        return new WP_Error('permission_denied', 'You do not have permission to delete this module.', array('status' => 403));
    }

    // First, delete dependent rows in pr_module_tags that reference the module
    $wpdb->delete('pr_module_tags', array('module_id' => $module_id));

    // Then, delete the module from pr_modules
    $result = $wpdb->delete('pr_modules', array('id' => $module_id));

    // Check if the deletion was successful
    if ($result === false) {
        return new WP_Error('db_error', 'Error deleting module from the database.', array('status' => 500));
    } elseif ($result === 0) {
        // No rows affected, meaning no module was found with the provided ID
        return new WP_Error('not_found', 'No module found with the provided ID.', array('status' => 404));
    }

    // Respond with a success message if the module was successfully deleted
    return new WP_REST_Response(array('message' => 'Module successfully deleted'), 200);
}