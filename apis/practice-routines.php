<?php

///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists( get_stylesheet_directory() . '/jwt_api.php' )) {
    include_once get_stylesheet_directory() . '/jwt_api.php';
}


require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');


/////////////////////////////////////////////Log user data into the database - occurs after a practice routine takes place
/**
 * This code registers a custom WordPress REST API endpoint for submitting practice routine data.
 * It allows users to send data about their practice routine, including practice time, mood, and
 * details about the modules they practiced. The data is validated and stored in the database.
 */




add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/submitPractice', array(
        'methods' => 'POST',
        'callback' => 'submit_practice_routine',
        'permission_callback' => 'jwt_permission_callback' // Assuming JWT auth is set up
    ));
});

function submit_practice_routine($request) {
    global $wpdb;

    // Updated to include 'name' and 'notes' in the required parameters check
    // 'location' is handled as an optional parameter
    $required_params = array('skill', 'user_time', 'total_practice_time', 'mood', 'modules', 'name', 'notes');

    foreach ($required_params as $param) {
        if (!$request->get_param($param)) {
            return new WP_Error('missing_parameter', "Parameter '$param' is missing", ['status' => 400]);
        }
    }

    // Extract user_id from the JWT token
    $user_id = get_current_user_id(); // This depends on how your JWT authentication is set up

    if (!$user_id) {
        return new WP_Error('invalid_user', 'User ID could not be retrieved from token', ['status' => 400]);
    }

    $practiceData = json_decode($request->get_body(), true);

    $user_time = isset($practiceData['user_time']) ? $practiceData['user_time'] : current_time('mysql');
    $skill_name = isset($practiceData['skill']) ? sanitize_text_field($practiceData['skill']) : '';
    // Convert skill name to skill_id
    $skill_id = get_skill_id_by_name($skill_name);
    if (!$skill_id) {
        return new WP_Error('invalid_skill', 'Skill not found', array('status' => 404));
    }

    $name = isset($practiceData['name']) ? sanitize_text_field($practiceData['name']) : '';
    $notes = isset($practiceData['notes']) ? sanitize_textarea_field($practiceData['notes']) : '';
    // Handle 'location' as an optional parameter
    $location = isset($practiceData['location']) ? sanitize_text_field($practiceData['location']) : null;

    // Check if an entry already exists
    $existing_entry = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM pr_practice_time_and_mood WHERE user_id = %d AND total_practice_time = %d AND mood = %s AND date = %s AND skill_id = %d",
        $user_id, $practiceData['total_practice_time'], $practiceData['mood'], $user_time, $skill_id
    ));

    if ($existing_entry > 0) {
        // Entry exists, return error
        return new WP_Error('duplicate_entry', 'An entry with the provided details already exists.', ['status' => 400]);
    }

    // Prepare data for insertion, including 'location' if provided
    $insertData = [
        'user_id' => $user_id,
        'skill_id' => $skill_id,
        'name' => $name,
        'total_practice_time' => $practiceData['total_practice_time'],
        'notes' => $notes,
        'mood' => $practiceData['mood'],
        'date' => $user_time,
    ];

    // Add 'location' to the insert data if it's not null
    if ($location !== null) {
        $insertData['location'] = $location;
    }

    $wpdb->insert('pr_practice_time_and_mood', $insertData);

    $practice_routine_id = $wpdb->insert_id; // Get the inserted row's ID

foreach ($practiceData['modules'] as $module) {
    // Assume $module['module_name'] is still used to fetch the corresponding module_id
    $module_name = $module['module_name'];
    
    // Fetch the module_id from the pr_modules table based on module_name
    $module_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM pr_modules WHERE module_name = %s",
        $module_name
    ));

    // Check if the module_id was successfully retrieved, otherwise throw an error
    if (!$module_id) {
        return new WP_Error('invalid_module', 'Module not found', ['status' => 404]);
    }

    // Insert into pr_module_distribution with module_id, omitting module_name
    $wpdb->insert('pr_module_distribution', [
        'practice_routine_id' => $practice_routine_id,
        'module_id' => $module_id, // Only module_id is necessary now
        'duration' => $module['duration']
    ]);
}

    if (isset($practiceData['tools']) && is_array($practiceData['tools'])) {
        foreach ($practiceData['tools'] as $tool) {
            $tool_name = $tool['tool_name'];
            $existing_tool = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM pr_tools WHERE tool_name = %s",
                $tool_name
            ));

            if (!$existing_tool) {
                $wpdb->insert('pr_tools', [
                    'tool_name' => $tool_name,
                    'skill' => $skill,
                    'user_id' => $user_id
                ]);
                $tool_id = $wpdb->insert_id;
            } else {
                $tool_id = $existing_tool;
            }

            $wpdb->insert('pr_practice_routine_tools', [
                'practice_routine_id' => $practice_routine_id,
                'tool_id' => $tool_id
            ]);
        }
    }

    // Create a response array including the 'id' of the inserted row
    $response_data = ['success' => 'Practice routine data recorded successfully', 'id' => $practice_routine_id];

    return new WP_REST_Response($response_data, 200);
}











////////////////////////Retrieve user practice data from database

/**
 * This code registers a custom WordPress REST API endpoint for retrieving practice data for a user.
 * It allows users to fetch information about their practice routines, including total practice time,
 * average practice time, the number of practice days, and details about each practice session,
 * including modules practiced and their durations.
 */



add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/getPracticeData', array(
        'methods' => 'GET',
        'callback' => 'get_practice_data',
        'args' => [
            'start_date' => ['required' => false, 'type' => 'string'],
            'end_date' => ['required' => false, 'type' => 'string'],
            'skill' => ['required' => false, 'type' => 'string'],
            'user_id' => ['required' => false, 'type' => 'integer'],
        ],
        'permission_callback' => 'custom_permission_callback',
    ));
});

function custom_permission_callback($request) {
    // Simplified permission callback logic
    return $request->get_param('user_id') ? jwt_permission_callback_no_user_id($request) : jwt_permission_callback($request);
}

function get_practice_data($request) {
    global $wpdb;

    $user_id = $request->get_param('user_id') ? intval($request->get_param('user_id')) : get_current_user_id();
    if (!$user_id) return new WP_Error('invalid_user', 'User ID could not be retrieved or is invalid', ['status' => 400]);

    $dateCondition = getDateCondition($request->get_param('start_date'), $request->get_param('end_date'));

    // Modify the query to optionally filter by skill if provided
    $skill_param = $request->get_param('skill');
    $skillCondition = '';
    if ($skill_param) {
        $skill_id = $wpdb->get_var($wpdb->prepare("SELECT id FROM pr_skills WHERE skill_name = %s", $skill_param));
        if (!$skill_id) return new WP_Error('invalid_skill', 'The skill does not exist in the database.', ['status' => 404]);
        $skillCondition = $wpdb->prepare(" AND pr.skill_id = %d", $skill_id);
    }

    // Updated query to include join with pr_modules for module names
    $query = "
        SELECT pr.id as practice_routine_id, pr.date as session_date, pr.total_practice_time, pr.mood,
               pm.module_name, prm.duration, prm.module_id, prt.tool_id, ps.skill_name, pr.skill_id
        FROM pr_practice_time_and_mood pr
        LEFT JOIN pr_module_distribution prm ON pr.id = prm.practice_routine_id
        LEFT JOIN pr_modules pm ON prm.module_id = pm.id
        LEFT JOIN pr_practice_routine_tools prt ON pr.id = prt.practice_routine_id
        LEFT JOIN pr_skills ps ON pr.skill_id = ps.id
        WHERE pr.user_id = %d" . $skillCondition . $dateCondition;

    $query = $wpdb->prepare($query, $user_id); // Prepare query with user_id and potentially skill_id
    $practice_session_details = $wpdb->get_results($query, ARRAY_A);

    if (empty($practice_session_details)) return new WP_Error('no_data', 'No practice data found for the given criteria.', ['status' => 404]);

    $grouped_sessions = groupSessionsBySkill($practice_session_details);

    // Process each group of sessions to compile the response
    $practice_data_by_skill = [];
    foreach ($grouped_sessions as $skill_name => $sessions) {
        $practice_sessions = processPracticeSessionDetails($sessions);
        $practice_data_by_skill[$skill_name] = [
            'total_practice_time' => calculateTotalPracticeTime($practice_sessions),
            'average_practice_time' => calculateAveragePracticeTime($practice_sessions),
            'number_of_days_practice_sessions_occurred' => calculateDaysOfPractice($practice_sessions),
            'total_number_of_practice_sessions' => count($practice_sessions),
            'practice_dates_with_time_and_mood_and_modules_practiced' => $practice_sessions
        ];
    }

    return new WP_REST_Response(['practice_data_by_skill' => $practice_data_by_skill], 200);
}


function groupSessionsBySkill($sessions) {
    $grouped = [];
    foreach ($sessions as $session) {
        $skill_name = $session['skill_name'] ?: 'Unknown Skill';
        if (!isset($grouped[$skill_name])) {
            $grouped[$skill_name] = [];
        }
        $grouped[$skill_name][] = $session;
    }
    return $grouped;
}



function calculateTotalPracticeTime($sessions) {
    $total = 0;
    foreach ($sessions as $session) {
        $total += $session['total_practice_time'];
    }
    return $total;
}

function calculateAveragePracticeTime($sessions) {
    if (count($sessions) == 0) return 0;
    return calculateTotalPracticeTime($sessions) / count($sessions);
}

function calculateDaysOfPractice($sessions) {
    $days = [];
    foreach ($sessions as $session) {
        $days[$session['date']] = true;
    }
    return count($days);
}


function getDateCondition($startDate, $endDate) {
    if ($startDate && $endDate) {
        global $wpdb;
        return $wpdb->prepare(" AND DATE(date) BETWEEN %s AND %s", $startDate, $endDate);
    }
    return '';
}

function fetchPracticeData($user_id, $skill_id, $dateCondition) {
    global $wpdb;

    $query = $wpdb->prepare("
        SELECT pr.id as practice_routine_id, pr.date as session_date, pr.total_practice_time, pr.mood, 
               prm.module_name, prm.duration, prm.id as module_id, prt.tool_id
        FROM pr_practice_time_and_mood pr
        LEFT JOIN pr_module_distribution prm ON pr.id = prm.practice_routine_id
        LEFT JOIN pr_practice_routine_tools prt ON pr.id = prt.practice_routine_id
        WHERE pr.user_id = %d AND pr.skill_id = %d" . $dateCondition, $user_id, $skill_id);
    $practice_session_details = $wpdb->get_results($query, ARRAY_A);

    if (empty($practice_session_details)) return [];

    return processPracticeSessionDetails($practice_session_details);
}

function processPracticeSessionDetails($details) {
    $processed = [];
    $tool_usage = [];
    foreach ($details as $session) {
        $tool_id = $session['tool_id'];
        if ($tool_id) {
            if (!isset($tool_usage[$tool_id])) {
                $tool_usage[$tool_id] = fetchToolData($tool_id);
            }
            $tool_usage[$tool_id]['count']++;
        }

        // Aggregate practice data by date
        $date = $session['session_date'];
        if (!isset($processed[$date])) {
            $processed[$date] = initializeDateData($session);
        }

        // Correctly aggregate module data to include 'module_id' and ensure duration is an integer
        aggregateModuleData($processed[$date]['modules_practiced'], $session);
    }

    // Finalize data by attaching tools used and ensuring modules_practiced is an array
    foreach ($processed as &$dateData) {
        $dateData['tools_used'] = array_values($tool_usage); // Attach tools used
        $dateData['modules_practiced'] = array_values($dateData['modules_practiced']); // Ensure modules_practiced is correctly formatted as an array
    }

    return array_values($processed); // Convert associative array to indexed array for uniform response
}




function fetchToolData($tool_id) {
    global $wpdb;
    $tool_data = $wpdb->get_row($wpdb->prepare("SELECT id, tool_name FROM pr_tools WHERE id = %d", $tool_id), ARRAY_A);
    return ['tool_id' => $tool_data['id'], 'tool_name' => $tool_data['tool_name'], 'count' => 0];
}

function initializeDateData($session) {
    return [
        'practice_routine_id' => $session['practice_routine_id'],
        'date' => $session['session_date'],
        'total_practice_time' => $session['total_practice_time'],
        'mood' => $session['mood'],
        'modules_practiced' => [],
        'tools_used' => [],
    ];
}

function aggregateModuleData(&$modules, $session) {
    $moduleId = $session['module_id'];
    if (!isset($modules[$moduleId])) {
        $modules[$moduleId] = [
            'module_id' => $moduleId,
            'module_name' => $session['module_name'],
            'duration' => (int) $session['duration'], // Cast duration to int
        ];
    } else {
        // Ensure duration is summed as an integer
        $modules[$moduleId]['duration'] += (int) $session['duration'];
    }
}



function fetchAllToolsUsed($user_id, $dateCondition) {
    global $wpdb;

    $query = $wpdb->prepare("
        SELECT prt.tool_id, t.tool_name, COUNT(DISTINCT pr.id) AS use_count
        FROM pr_practice_time_and_mood pr
        JOIN pr_practice_routine_tools prt ON pr.id = prt.practice_routine_id
        JOIN pr_tools t ON prt.tool_id = t.id
        WHERE pr.user_id = %d" . $dateCondition . "
        GROUP BY prt.tool_id, t.tool_name", $user_id);

    return $wpdb->get_results($query, ARRAY_A);
}




// add_action('rest_api_init', function () {
//     $permission_callback = function ($request) {
//         // Check if user_id parameter is provided in the query parameters
//         $user_id_param = $request->get_param('user_id');
        
//         if ($user_id_param) {
//             return jwt_permission_callback_no_user_id($request); // Use jwt_permission_callback_no_user_id
//         } else {
//             return jwt_permission_callback($request); // Use jwt_permission_callback
//         }
//     };


//     register_rest_route('jhg-apps/v1', '/getPracticeData', array(
//         'methods' => 'GET',
//         'callback' => 'get_practice_data',
//         'args' => [
//             'start_date' => [
//                 'required' => false,
//                 'type' => 'string',
//             ],
//             'end_date' => [
//                 'required' => false,
//                 'type' => 'string',
//             ],
//             'skill' => [
//                 'required' => false,
//                 'type' => 'string',
//             ],
//             'user_id' => [
//                 'required' => false,
//                 'type' => 'integer',
//             ],
//         ],
//         'permission_callback' => $permission_callback, // Set the permission callback dynamically
//     ));
// });

// function get_practice_data($request) {
//     global $wpdb;

//     $user_id = get_current_user_id();

//     if (!$user_id) {
//         return new WP_Error('invalid_user', 'User ID could not be retrieved', ['status' => 400]);
//     }

//     $user_id_param = $request->get_param('user_id');
//     if (!$user_id_param) {
//         return new WP_Error('missing_user_id', 'User ID parameter is missing', ['status' => 400]);
//     }

//     $user_id = intval($user_id_param);

//     if (!$user_id) {
//         return new WP_Error('invalid_user_id', 'Invalid user ID provided', ['status' => 400]);
//     }

//     $dateCondition = '';
//     $startDate = $request->get_param('start_date');
//     $endDate = $request->get_param('end_date');

//     if ($startDate && $endDate) {
//         $dateCondition = $wpdb->prepare(" AND DATE(date) BETWEEN %s AND %s", $startDate, $endDate);
//     }

//     $skill_param = $request->get_param('skill');

//     $skills_query = $wpdb->get_results($wpdb->prepare(
//         "SELECT DISTINCT skill FROM pr_practice_time_and_mood WHERE user_id = %d" . $dateCondition,
//         $user_id
//     ), ARRAY_N);

//     $practice_data_by_skill = [];
//     $all_tools_usage = [];

//     foreach ($skills_query as $skill_row) {
//         $db_skill = strtolower($skill_row[0]);

//         if ($skill_param && strtolower($skill_param) !== $db_skill) {
//             continue;
//         }

//         $practice_session_details = $wpdb->get_results($wpdb->prepare(
//             "SELECT pr.id as practice_routine_id, pr.date as session_date, pr.total_practice_time, pr.mood, 
//                     prm.module_name, prm.duration, prm.id as module_id, prt.tool_id
//              FROM pr_practice_time_and_mood pr
//              LEFT JOIN pr_module_distribution prm ON pr.id = prm.practice_routine_id
//              LEFT JOIN pr_practice_routine_tools prt ON pr.id = prt.practice_routine_id
//              WHERE pr.user_id = %d AND LOWER(pr.skill) = %s" . $dateCondition,
//             $user_id,
//             $db_skill
//         ), ARRAY_A);

//         $tools_used = []; // Initialize tools_used array here to prevent duplicates within a skill

//         foreach ($practice_session_details as $session) {
//             $tool_id = $session['tool_id'];

//             if ($tool_id) {
//                 if (!array_key_exists($tool_id, $all_tools_usage)) {
//                     $all_tools_usage[$tool_id] = ['count' => 0, 'name' => ''];
//                 }
//                 $all_tools_usage[$tool_id]['count']++;

//                 if (!isset($tools_used[$tool_id])) { // Use tool_id as key to avoid duplication
//                     $tool_data = $wpdb->get_row($wpdb->prepare(
//                         "SELECT id, tool_name FROM pr_tools WHERE id = %d",
//                         $tool_id
//                     ), ARRAY_A);

//                     if ($tool_data) {
//                         $tools_used[$tool_id] = [ // Assign tool data directly to avoid duplication
//                             'tool_id' => $tool_id,
//                             'tool_name' => $tool_data['tool_name']
//                         ];
//                     }
//                 }
//             }
//         }

//         $practice_dates_with_time_and_mood_and_modules_practiced = [];
//         $module_totals = [];
//         $addedModules = [];

//         foreach ($practice_session_details as $session) {
//             $date = $session['session_date'];
//             $moduleName = $session['module_name'];
//             $duration = (int)$session['duration'];
//             $moduleId = $session['module_id'];

//             if (!isset($practice_dates_with_time_and_mood_and_modules_practiced[$date])) {
//                 $practice_dates_with_time_and_mood_and_modules_practiced[$date] = [
//                     'practice_routine_id' => $session['practice_routine_id'],
//                     'date' => $date,
//                     'total_practice_time' => $session['total_practice_time'],
//                     'mood' => $session['mood'],
//                     'modules_practiced' => [],
//                     'tools_used' => array_values($tools_used) // Assign the filtered tools_used array here
//                 ];
//             }

//             if (!in_array($moduleId, $addedModules)) {
//                 $practice_dates_with_time_and_mood_and_modules_practiced[$date]['modules_practiced'][] = [
//                     'module_id' => $moduleId,
//                     'module_name' => $moduleName,
//                     'duration' => $duration
//                 ];
//                 $addedModules[] = $moduleId;

//                 if (!isset($module_totals[$moduleName])) {
//                     $module_totals[$moduleName] = 0;
//                 }
//                 $module_totals[$moduleName] += $duration;
//             }
//         }

// foreach ($practice_session_details as $session) {
//     $tool_id = $session['tool_id'];
//     if ($tool_id) {
//         if (!isset($all_tools_usage[$tool_id])) {
//             $all_tools_usage[$tool_id] = ['count' => 1]; // Initialize or increment count
//         } else {
//             $all_tools_usage[$tool_id]['count']++;
//         }
//     }
// }

// // Reset all_tools_usage counts based on unique session_ids to avoid multiplying
// foreach ($all_tools_usage as $tool_id => &$usage) {
//     $usage['count'] = $wpdb->get_var($wpdb->prepare(
//         "SELECT COUNT(DISTINCT pr.id) FROM pr_practice_time_and_mood pr
//          JOIN pr_practice_routine_tools prt ON pr.id = prt.practice_routine_id
//          WHERE pr.user_id = %d AND prt.tool_id = %d" . $dateCondition,
//         $user_id, $tool_id
//     ));
//     // Retrieve the tool_name for this tool_id
//     $tool_name = $wpdb->get_var($wpdb->prepare(
//         "SELECT tool_name FROM pr_tools WHERE id = %d",
//         $tool_id
//     ));

//     $all_tools_used[] = [
//         'tool_id' => $tool_id,
//         'tool_name' => $tool_name,
//         'use_count' => $usage['count']
//     ];
// }
// unset($usage); // Break the reference with the last element


//         $practice_dates_with_time_and_mood_and_modules_practiced = array_values($practice_dates_with_time_and_mood_and_modules_practiced);

//         $module_percentages = [];
//         $total_practice_time = 0;
//         foreach ($module_totals as $moduleName => $totalDuration) {
//             $total_practice_time += $totalDuration;
//             $percentage_of_total = $total_practice_time > 0 ? round(($totalDuration / $total_practice_time) * 100) : 0;
//             $module_percentages[] = [
//                 'module_name' => $moduleName,
//                 'total_time_spent' => $totalDuration,
//                 'percentage_of_total' => $percentage_of_total
//             ];
//         }

//         $average_practice_time = $wpdb->get_var($wpdb->prepare(
//             "SELECT AVG(total_practice_time) FROM pr_practice_time_and_mood WHERE user_id = %d AND LOWER(skill) = %s" . $dateCondition,
//             $user_id,
//             $db_skill
//         ));

//         $number_of_days = $wpdb->get_var($wpdb->prepare(
//             "SELECT COUNT(DISTINCT DATE(date)) FROM pr_practice_time_and_mood WHERE user_id = %d AND LOWER(skill) = %s" . $dateCondition,
//             $user_id,
//             $db_skill
//         ));

//         $total_sessions = $wpdb->get_var($wpdb->prepare(
//             "SELECT COUNT(*) FROM pr_practice_time_and_mood WHERE user_id = %d AND LOWER(skill) = %s" . $dateCondition,
//             $user_id,
//             $db_skill
//         ));

//         $total_practice_time = $total_practice_time ? $total_practice_time : 0;
//         $average_practice_time = $average_practice_time ? round($average_practice_time) : 0;
//         $number_of_days = $number_of_days ? $number_of_days : 0;
//         $total_sessions = $total_sessions ? $total_sessions : 0;

//         $practice_data_by_skill[$db_skill] = [
//             'total_practice_time' => (int)$total_practice_time,
//             'average_practice_time' => $average_practice_time,
//             'number_of_days_practice_sessions_occurred' => $number_of_days,
//             'total_number_of_practice_sessions' => $total_sessions,
//             'practice_dates_with_time_and_mood_and_modules_practiced' => $practice_dates_with_time_and_mood_and_modules_practiced,
//             'module_percentages' => $module_percentages,
//             'all_tools_used' => $all_tools_used
//         ];
//     }

//     if (empty($practice_data_by_skill)) {
//         $practice_data_by_skill = null;
//     }

//     return new WP_REST_Response([
//         'practice_data_by_skill' => $practice_data_by_skill,
//     ], 200);
// }



//////////////////////////////////////////////Retrieve all users practice data from the database for leaderboard


// add_action('rest_api_init', function () {
//     register_rest_route('jhg-apps/v1', '/getAllUsersPracticeData', array(
//         'methods' => 'GET',
//         'callback' => 'get_all_users_practice_data',
//         'args' => [
//             'start_date' => [
//                 'required' => false, // Make it optional
//                 'type' => 'string',
//             ],
//             'end_date' => [
//                 'required' => false, // Make it optional
//                 'type' => 'string',
//             ],
//             'skill' => [
//                 'required' => true,
//                 'type' => 'string',
//             ],
//         ],
//         'permission_callback' => 'jwt_permission_callback'
//     ));
// });

// function get_all_users_practice_data($request) {
//     global $wpdb;
//     $startDate = $request->get_param('start_date');
//     $endDate = $request->get_param('end_date');
//     $skill_param = strtolower($request->get_param('skill'));

//     // First, get the skill_id for the provided skill name
//     $skill_id = $wpdb->get_var($wpdb->prepare(
//         "SELECT id FROM pr_skills WHERE LOWER(skill_name) = %s",
//         $skill_param
//     ));

//     if (!$skill_id) {
//         return new WP_REST_Response(['message' => 'Skill not found'], 404);
//     }

//     $dateCondition = '';
//     if ($startDate && $endDate) {
//         $dateCondition = $wpdb->prepare(" AND DATE(pr.date) BETWEEN %s AND %s", $startDate, $endDate);
//     }

//     // Modify the query to filter by skill_id
//     $all_users_practice_data = $wpdb->get_results($wpdb->prepare(
//         "SELECT u.ID as user_id, u.user_login, SUM(pr.total_practice_time) as total_practice_time, pr.date as date 
//          FROM pr_practice_time_and_mood pr
//          INNER JOIN wp_users u ON pr.user_id = u.ID
//          WHERE pr.skill_id = %d" . $dateCondition . "
//          GROUP BY u.ID",
//         $skill_id // Use skill_id to filter
//     ), ARRAY_A);

//     // Modify the stats query to also filter by skill_id
//     $practice_time_stats = $wpdb->get_row($wpdb->prepare(
//         "SELECT AVG(total_practice_time) as average_practice_time,
//                 MIN(total_practice_time) as min_practice_time,
//                 MAX(total_practice_time) as max_practice_time
//          FROM (SELECT SUM(pr.total_practice_time) as total_practice_time
//                FROM pr_practice_time_and_mood pr
//                INNER JOIN wp_users u ON pr.user_id = u.ID
//                WHERE pr.skill_id = %d" . $dateCondition . " // Use skill_id to filter
//                GROUP BY pr.user_id) as user_practice_times",
//         $skill_id
//     ), ARRAY_A);

//     // Calculate the average, minimum, and maximum practice times
//     $average_practice_time = $practice_time_stats['average_practice_time'] ? (int) round($practice_time_stats['average_practice_time']) : 0;
//     $min_practice_time = $practice_time_stats['min_practice_time'] ? (int) $practice_time_stats['min_practice_time'] : 0;
//     $max_practice_time = $practice_time_stats['max_practice_time'] ? (int) $practice_time_stats['max_practice_time'] : 0;

//     // Create the response data
//     $response_data = [
//         'all_users_practice_data' => $all_users_practice_data,
//         'average_total_practice_time' => $average_practice_time,
//         'minimum_total_practice_time' => $min_practice_time,
//         'maximum_total_practice_time' => $max_practice_time,
//     ];

//     return new WP_REST_Response($response_data, 200);
// }





add_action( 'rest_api_init', function () {
    register_rest_route( 'jhg-apps/v1', '/getAllUsersPracticeData', array(
        'methods'  => 'GET',
        'callback' => 'get_all_users_practice_data',
        'args'     => array(
            'skill' => array(
                'required'    => true,
                'description' => 'The skill for which practice data is requested',
                'type'        => 'string',
            ),
        ),
        'permission_callback' => 'jwt_permission_callback',
    ) );
} );

function get_all_users_practice_data( $request ) {
    global $wpdb;

    $skill = $request->get_param( 'skill' );
    $start_date = $request->get_param( 'start_date' );
    $end_date = $request->get_param( 'end_date' );

    // Query to retrieve skill ID based on skill name
    $skill_id = $wpdb->get_var( $wpdb->prepare(
        "SELECT id FROM pr_skills WHERE skill_name = %s",
        $skill
    ) );

    if ( ! $skill_id ) {
        return new WP_Error( 'invalid_skill', 'Invalid skill provided', array( 'status' => 400 ) );
    }

    // Prepare the WHERE clause for date filtering
    $date_filter = '';
    if ( !empty( $start_date ) && !empty( $end_date ) ) {
        $date_filter = $wpdb->prepare(
            "AND date BETWEEN %s AND %s",
            $start_date,
            $end_date
        );
    }

    // Query to get user practice data with matching skill ID and optional date filtering
    $user_practice_data = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_id, SUM(total_practice_time) AS total_practice_time 
        FROM pr_practice_time_and_mood 
        WHERE skill_id = %d 
        $date_filter
        GROUP BY user_id",
        $skill_id
    ) );

    if ( empty( $user_practice_data ) ) {
        // No practice data found for this skill
        return array( 'all_users_practice_data' => array() );
    }

    // Calculate minimum, maximum, and average total practice time
    $total_practice_times = wp_list_pluck( $user_practice_data, 'total_practice_time' );
    $minimum_practice_time = min( $total_practice_times );
    $maximum_practice_time = max( $total_practice_times );
    $average_practice_time = array_sum( $total_practice_times ) / count( $total_practice_times );

    // Construct response
    $response = array(
        'all_users_practice_data' => array(),
        'minimum_total_practice_time' => (int) $minimum_practice_time,
        'maximum_total_practice_time' => (int) $maximum_practice_time,
        'average_total_practice_time' => $average_practice_time,
    );

    foreach ( $user_practice_data as $data ) {
        // Query to get user_login from wp_users table
        $user_login = $wpdb->get_var( $wpdb->prepare(
            "SELECT user_login FROM wp_users WHERE ID = %d",
            $data->user_id
        ) );

        // Add user data to the response
        $response['all_users_practice_data'][] = array(
            'user_id'            => $data->user_id,
            'user_login'         => $user_login,
            'total_practice_time' => $data->total_practice_time,
        );
    }

    return $response;
}