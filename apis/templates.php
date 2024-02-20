<?php

///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists(__DIR__ . '../functions/jwt-functions.php')) {
    include_once __DIR__ . '../functions/jwt-functions.php';
}

require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');

///////////////////////////////////////////////////// Creates a new User Template Practice Routine


add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/user-templates/', array(
        'methods' => 'POST',
        'callback' => 'create_user_templates',
        'permission_callback' => 'jwt_permission_callback'
    ));
});



function create_user_templates($request) {
    global $wpdb;
    $user_id = get_current_user_id(); // Assuming user ID retrieval is already correctly handled.
    $templates = $request->get_param('templates');
    $response_data = [];

    foreach ($templates as $template) {
        // Validate template data before proceeding
        $validation_result = validate_template_data($template);
        if ($validation_result !== true) {
            $response_data[] = $validation_result;
            continue; // Skip to the next template if validation fails
        }

        // Insert template and get template ID or error
        $template_insertion_result = insert_template($template, $user_id);
        if (is_wp_error($template_insertion_result) || isset($template_insertion_result['error'])) {
            // If there was an error inserting the template, add it to the response and continue
            $response_data[] = $template_insertion_result;
            continue;
        }

        // Assuming the template insertion was successful and we have a new template ID
        $new_template_id = $template_insertion_result;

        // Insert modules and tools, associating them with the template
        $inserted_modules = insert_modules($template['modules'], $new_template_id);
        $inserted_tools = insert_tools($template['tools'], $new_template_id);

        // Generate success response data for the template
        $skill_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM pr_skills WHERE skill_name = %s",
            $template['skill']
        ));

        $response_data[] = generate_response_data($template, $new_template_id, $inserted_modules, $inserted_tools, $skill_id);
    }

    // Return the response data with either success messages or errors for each template
    return new WP_REST_Response($response_data, 200);
}




function validate_template_data($template) {
    $required_params = ['template_name', 'total_duration', 'modules', 'skill'];
    foreach ($required_params as $param) {
        if (empty($template[$param])) {
            return [
                'error' => 'missing_parameter',
                'message' => "Parameter '$param' is missing in one of the templates",
                'status' => 400
            ];
        }
    }
    return true;
}


function insert_template($template, $user_id) {
    global $wpdb;
    // Resolve skill name to skill_id
    $skill_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM pr_skills WHERE skill_name = %s",
        $template['skill']
    ));

    if (!$skill_id) {
        return new WP_Error('skill_not_found', 'Specified skill does not exist', ['status' => 404]);
    }

    // Check for existing template with the same name, skill_id, and user_id
    $existing_template = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM pr_templates_user_templates WHERE user_id = %d AND template_name = %s AND skill_id = %d",
        $user_id, $template['template_name'], $skill_id
    ));

    if ($existing_template > 0) {
        return [
            'error' => 'template_exists',
            'message' => 'A template with this name and skill already exists for the user',
            'status' => 409
        ];
    }

    // Proceed to insert the new template if it doesn't exist
    $insertResult = $wpdb->insert('pr_templates_user_templates', [
        'user_id' => $user_id,
        'template_name' => $template['template_name'],
        'total_duration' => $template['total_duration'],
        'notes' => $template['notes'] ?? '',
        'skill_id' => $skill_id
    ]);

    if ($insertResult === false) {
        return [
            'error' => 'db_error',
            'message' => 'Failed to insert template: ' . $wpdb->last_error,
            'status' => 500
        ];
    }

    // Return the new template ID on successful insertion
    return $wpdb->insert_id;
}




function check_existing_template($template_name, $user_id, $skill_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM pr_templates_user_templates WHERE user_id = %d AND template_name = %s AND skill_id = %d",
        $user_id, $template_name, $skill_id
    ));
}


function insert_modules($modules, $template_id) {
    global $wpdb;
    $inserted_modules = [];

    foreach ($modules as $module) {
        // Check if the module exists in pr_modules and get its ID
        $module_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM pr_modules WHERE module_name = %s",
            sanitize_text_field($module['module_name'])
        ));

        // If the module doesn't exist, insert it into pr_modules
        if (!$module_id) {
            $wpdb->insert('pr_modules', [
                'module_name' => sanitize_text_field($module['module_name']),
                // Add any other module details here as needed
            ]);
            $module_id = $wpdb->insert_id; // Get the new module ID
        }

        // Now insert only the module_id and template_id into pr_templates_modules_used
        $insertResult = $wpdb->insert('pr_templates_modules_used', [
            'template_id' => $template_id,
            'module_id' => $module_id,
            'duration' => intval($module['duration'])
        ]);

        if($insertResult !== false) {
            $inserted_modules[] = [
                'module_id' => $module_id,
                // 'module_name' is no longer included here as the column will be removed
                'duration' => intval($module['duration'])
            ];
        }
        // Optionally handle or log if the insert failed
    }
    return $inserted_modules;
}




function insert_tools($tools, $template_id) {
    global $wpdb;
    $inserted_tools = [];

    foreach ($tools as $tool) {
        // Check if the tool exists and get its ID
        $tool_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM pr_tools WHERE tool_name = %s",
            sanitize_text_field($tool['tool_name'])
        ));

        if ($tool_id) {
            // If the tool exists, link it to the template
            $insertResult = $wpdb->insert('pr_templates_tools_used', [
                'template_id' => $template_id,
                'tool_id' => $tool_id
            ]);

            if($insertResult === false) {
                // Handle error (optional)
                continue; // Or log error
            }

            $inserted_tools[] = ['tool_id' => $tool_id, 'tool_name' => sanitize_text_field($tool['tool_name'])];
        }
        // Optionally handle or log if the tool does not exist
    }

    return $inserted_tools;
}




function generate_response_data($template, $template_id, $inserted_modules, $inserted_tools, $skill_id) {
    global $wpdb;
    $skill_name = $wpdb->get_var($wpdb->prepare("SELECT skill_name FROM pr_skills WHERE id = %d", $skill_id));
    return [
        'message' => 'Template Created Successfully',
        'template_id' => $template_id,
        'template_name' => $template['template_name'],
        'total_duration' => $template['total_duration'],
        'notes' => $template['notes'] ?? '',
        'skill_id' => $skill_id,
        'skill_name' => $skill_name, // Include skill name for clarity
        'modules' => $inserted_modules,
        'tools' => $inserted_tools
    ];
}







///////////////////////////////////Retrieves list of user templates

add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/user-templates/', array(
        'methods' => 'GET',
        'callback' => 'list_user_templates',
        'permission_callback' => 'jwt_permission_callback'
    ));
});

function list_user_templates($request) {
    $user_id = $request->get_param('user_id');
    $skill_name = $request->get_param('skill');

    // Convert skill name to skill_id
    $skill_id = get_skill_id_by_name($skill_name);
    if (is_null($skill_id)) {
        return new WP_REST_Response(array('message' => 'Skill not found'), 404);
    }

    $templates = fetch_templates($user_id, $skill_id, $skill_name);

    if (empty($templates)) {
        return new WP_REST_Response(array(), 200);
    }

    $response = format_response($templates, $skill_name);
    return new WP_REST_Response($response, 200);
}

function fetch_templates($user_id, $skill_id, $skill_name) {
    global $wpdb;
    $query = $wpdb->prepare("SELECT * FROM pr_templates_user_templates WHERE user_id = %d AND skill_id = %d", $user_id, $skill_id);
    $results = $wpdb->get_results($query, ARRAY_A);

    foreach ($results as &$template) {
        $template['skill_name'] = $skill_name; // Temporarily store skill_name for use in response formatting
    }

    return $results;
}

function format_response($templates, $skill_name) {
    $skillBasedResponse = array();

    foreach ($templates as $template) {
        $formattedTemplate = format_template($template);
        if (!isset($skillBasedResponse[$skill_name])) {
            $skillBasedResponse[$skill_name] = array('templates' => array());
        }
        $skillBasedResponse[$skill_name]['templates'][] = $formattedTemplate;
    }

    return array('skill' => $skill_name, 'templates' => $skillBasedResponse[$skill_name]['templates']);
}

function format_template($template) {
    $formattedTools = format_tools($template['id']); // Fetch tools based on template id
    $formattedModules = format_modules($template['id']); // Fetch modules based on template id

    return array(
        'template_id' => $template['id'],
        'user_id' => $template['user_id'],
        'template_name' => $template['template_name'],
        'total_duration' => $template['total_duration'],
        'notes' => $template['notes'],
        'modules' => $formattedModules,
        'tools' => $formattedTools
        // Removed 'skill' key here
    );
}

function format_tools($templateId) {
    global $wpdb;
    $query = $wpdb->prepare("
        SELECT tt.tool_id, pt.tool_name 
        FROM pr_templates_tools_used tt
        INNER JOIN pr_tools pt ON tt.tool_id = pt.id
        WHERE tt.template_id = %d
    ", $templateId);
    $results = $wpdb->get_results($query, ARRAY_A);

    return array_map(function($row) {
        return array(
            'tool_id' => intval($row['tool_id']),
            'tool_name' => $row['tool_name']
        );
    }, $results);
}

function format_modules($templateId) {
    global $wpdb;
    $query = $wpdb->prepare("
        SELECT tm.module_id, tm.duration, pm.module_name
        FROM pr_templates_modules_used tm
        INNER JOIN pr_modules pm ON tm.module_id = pm.id
        WHERE tm.template_id = %d
    ", $templateId);
    $results = $wpdb->get_results($query, ARRAY_A);

    return array_map(function($row) {
        return array(
            'module_id' => intval($row['module_id']),
            'module_name' => $row['module_name'],
            'duration' => intval($row['duration'])
        );
    }, $results);
}


















///////////////////////////////////////////////////Update User Template

// // Register the REST API endpoint for PUT method
// add_action('rest_api_init', function () {
//     register_rest_route('jhg-apps/v1', '/user-templates', array(
//         'methods' => 'PUT',
//         'callback' => 'update_user_template',
//         'permission_callback' => 'jwt_permission_callback'
//     ));
// });

// function update_user_template($request) {
//     global $wpdb;

//     // Retrieve 'template_id' from the request body or query parameters
//     $template_id = $request->get_param('template_id');
//     $user_id = $request->get_param('user_id');

//     // Check if the template exists for the given user
//     if ($wpdb->get_var($wpdb->prepare(
//         "SELECT COUNT(*) FROM pr_templates_user_templates WHERE template_id = %d AND user_id = %d",
//         $template_id, $user_id
//     )) == 0) {
//         return new WP_Error('no_template', 'Template does not exist', ['status' => 404]);
//     }


//     $wpdb->update(
//         'pr_templates_user_templates',
//         [
//             'template_name' => $request->get_param('template_name'),
//             'total_duration' => $request->get_param('total_duration'),
//             'notes' => $request->get_param('notes')
//         ],
//         ['template_id' => $template_id, 'user_id' => $user_id]
//     );

//     // Update tools
//     $tools = $request->get_param('tools');
//     if (!empty($tools) && is_array($tools)) {
//         $wpdb->delete('pr_templates_tools_used', ['template_id' => $template_id]);
//         foreach ($tools as $tool) {
//             if (!isset($tool['tool_name'])) {
//                 return new WP_Error('invalid_data', 'Invalid tool data', ['status' => 400]);
//             }
//             $tool_name = sanitize_text_field($tool['tool_name']);
//             $tool_id = $wpdb->get_var($wpdb->prepare(
//                 "SELECT id FROM pr_tools WHERE tool_name = %s AND user_id = %d",
//                 $tool_name, $user_id
//             ));
//             if (!$tool_id) {
//                 $wpdb->insert('pr_tools', ['tool_name' => $tool_name, 'user_id' => $user_id]);
//                 $tool_id = $wpdb->insert_id;
//             }
//             $wpdb->insert('pr_templates_tools_used', ['template_id' => $template_id, 'tool_id' => $tool_id]);
//         }
//     }

//     // Update modules
//     $modules = $request->get_param('modules');
//     if (!empty($modules) && is_array($modules)) {
//         $wpdb->delete('pr_templates_modules_used', ['template_id' => $template_id]);
//         foreach ($modules as $module) {
//             if (!isset($module['module_name']) || !isset($module['duration'])) {
//                 return new WP_Error('invalid_data', 'Invalid module data', ['status' => 400]);
//             }
//             $wpdb->insert('pr_templates_modules_used', [
//                 'template_id' => $template_id,
//                 'module_name' => sanitize_text_field($module['module_name']),
//                 'duration' => intval($module['duration'])
//             ]);
//         }
//     }

//     return new WP_REST_Response(['message' => 'Template Updated Successfully'], 200);
// }


add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/user-templates', array(
        'methods' => 'PUT',
        'callback' => 'update_user_template',
        'permission_callback' => 'jwt_permission_callback'
    ));
});

function update_user_template($request) {
    global $wpdb;
    $json_data = $request->get_json_params();

    if (empty($json_data['template_id'])) {
        return new WP_Error('missing_template_id', 'Template ID is required', array('status' => 400));
    }

    $template_id = intval($json_data['template_id']);
    $user_id = get_current_user_id();

    $existing_template = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM pr_templates_user_templates WHERE id = %d AND user_id = %d",
        $template_id, $user_id
    ));

    if (!$existing_template) {
        return new WP_Error('template_not_found', 'Template not found or does not belong to the user', array('status' => 404));
    }

    $updated_template_data = validate_and_update_template_data($json_data, $template_id, $user_id);

    if (is_wp_error($updated_template_data)) {
        return $updated_template_data;
    }

    $inserted_modules = insert_modules($json_data['modules'], $template_id);
    $inserted_tools = insert_tools($json_data['tools'], $template_id);

    $updated_template_data['modules'] = $inserted_modules;
    $updated_template_data['tools'] = $inserted_tools;

    return new WP_REST_Response($updated_template_data, 200);
}

function validate_and_update_template_data($json_data, $template_id, $user_id) {
    global $wpdb;

    if (empty($json_data)) {
        return new WP_Error('invalid_data', 'Invalid JSON data', array('status' => 400));
    }

    $validation_result = validate_template_data($json_data);
    if ($validation_result !== true) {
        return $validation_result;
    }

    $skill_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM pr_skills WHERE skill_name = %s",
        $json_data['skill']
    ));

    if (!$skill_id) {
        return new WP_Error('skill_not_found', 'Specified skill does not exist', array('status' => 404));
    }

    $existing_template = check_existing_template($json_data['template_name'], $user_id, $skill_id);

    if ($existing_template && $existing_template->id != $template_id) {
        return new WP_Error('template_exists', 'A template with this name and skill already exists for the user', array('status' => 409));
    }

    $update_result = $wpdb->update(
        'pr_templates_user_templates',
        array(
            'template_name' => $json_data['template_name'],
            'total_duration' => $json_data['total_duration'],
            'notes' => $json_data['notes'] ?? '',
            'skill_id' => $skill_id
        ),
        array('id' => $template_id),
        array('%s', '%s', '%s', '%d'),
        array('%d')
    );

    if ($update_result === false) {
        return new WP_Error('db_error', 'Error updating template', array('status' => 500));
    }

    return generate_response_data($json_data, $template_id, [], [], $skill_id);
}


function update_modules_and_tools($items, $template_id, $type = 'module') {
    global $wpdb;

    $table_name = ($type == 'module') ? 'pr_templates_modules_used' : 'pr_templates_tools_used';

    foreach ($items as $item) {
        // Insert item if it doesn't exist
        $item_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM pr_$type WHERE {$type}_name = %s",
            $item['name']
        ));

        if (!$item_id) {
            $wpdb->insert("pr_$type", array("{$type}_name" => $item['name']));
            $item_id = $wpdb->insert_id;
        }

        // Check if the item is already associated with the template
        $existing_item = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE template_id = %d AND {$type}_id = %d",
            $template_id, $item_id
        ));

        // Insert item into association table if not already associated
        if (!$existing_item) {
            $wpdb->insert($table_name, array("template_id" => $template_id, "{$type}_id" => $item_id));
        }
    }
}









///////////////////////////////Delete User Template


// Register the REST API endpoint for DELETE method
add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/user-templates', array(
        'methods' => 'DELETE',
        'callback' => 'delete_user_template',
        'permission_callback' => 'jwt_permission_callback'
    ));
});

function delete_user_template($request) {
    global $wpdb;

    // Retrieve 'template_id' from the request parameters
    $template_id = $request->get_param('template_id');

    if (!$template_id) {
        return new WP_Error('missing_parameter', 'Template ID is required', ['status' => 400]);
    }

    $user_id = get_current_user_id();
    $user = get_userdata($user_id);

    if (!$user) {
        return new WP_Error('invalid_user', 'Invalid user', ['status' => 400]);
    }

    // Check if the user is an admin or the creator of the template
    if (!in_array('administrator', $user->roles)) {
        // Verify that the template belongs to the user
        $owner_id = $wpdb->get_var($wpdb->prepare(
            "SELECT user_id FROM pr_templates_user_templates WHERE template_id = %d",
            $template_id
        ));

        if ($owner_id != $user_id) {
            return new WP_Error('unauthorized', 'You do not have permission to delete this template', ['status' => 403]);
        }
    }

    // Delete associated tools
    $wpdb->delete('pr_templates_tools_used', array('template_id' => $template_id));

    // Delete associated modules
    $wpdb->delete('pr_templates_modules_used', array('template_id' => $template_id));

    // Finally, delete the template itself
    $result = $wpdb->delete('pr_templates_user_templates', array('template_id' => $template_id));

    if ($result !== false) {
        return new WP_REST_Response(['message' => 'Template Deleted Successfully'], 200);
    } else {
        return new WP_Error('db_error', 'Error deleting template', array('status' => 500));
    }
}