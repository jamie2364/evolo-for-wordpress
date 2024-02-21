<?php

///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists(__DIR__ . '/../functions/jwt-functions.php')) {
    include_once __DIR__ . '/../functions/jwt-functions.php';
}

require_once(plugin_dir_path(__FILE__) . '../functions/get-skill-id-by-name.php');


////////////// Get practice count and goal completion status for timeframe given


add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/practice-count', array(
        'methods' => 'GET',
        'callback' => 'get_practice_count',
        'permission_callback' => 'evolo_jwt_permission_callback',
        'args' => array(
            'start_date' => array(
                'required' => true,
                'validate_callback' => 'validate_date'
            ),
            'end_date' => array(
                'required' => true,
                'validate_callback' => 'validate_date'
            ),
            'frequency' => array(
                'required' => false,
                'validate_callback' => function ($param, $request, $key) {
                    $valid_values = ['everyday', 'once-a-week', 'twice-a-week', 'thrice-a-week', 'once-every-30-days', 'twice-every-30-days', 'thrice-every-30-days', 'once-a-year', 'twice-a-year', 'thrice-a-year'];
                    return in_array($param, $valid_values);
                }
            ),
            'how_long' => array(
                'required' => false,
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
        ),
    ));
});


function get_practice_count($data) {
    global $wpdb;

    // [Existing code for parameter checking remains the same]

    $user_id = get_current_user_id();
    if (!$user_id) {
        return new WP_Error('jwt_auth_failed', 'User authentication failed.', array('status' => 403));
    }

    if (!isset($data['start_date']) || !isset($data['end_date']) || (!isset($data['frequency']) && !isset($data['how_long']) && !isset($data['module']))) {
        return new WP_Error('missing_parameters', 'Required parameters are missing.', array('status' => 400));
    }

    if (isset($data['frequency']) && !validate_date_interval($data['start_date'], $data['end_date'], $data['frequency'])) {
        return new WP_Error('invalid_date_interval', 'The interval between start and end date does not match the frequency value.', array('status' => 400));
    }

    $start_date = $data['start_date'];
    $end_date = $data['end_date'];
    $module = isset($data['module']) ? $data['module'] : null;

    $table_name_practice = 'pr_practice_time_and_mood';
    $table_name_module = 'pr_module_distribution';

    if ($module !== null) {
        // Sum only the duration from module_distribution table
        $query = $wpdb->prepare("SELECT SUM(m.duration) FROM $table_name_module m WHERE m.module_name = %s AND EXISTS (SELECT 1 FROM $table_name_practice p WHERE p.id = m.practice_routine_id AND p.user_id = %d AND p.`date` BETWEEN %s AND %s)", $module, $user_id, $start_date, $end_date);
        $total_duration = $wpdb->get_var($query);
        $response_key = 'total_module_duration';
        $total = $total_duration;
    } else {
        // Sum only the total_practice_time from practice_time_and_mood table
        $query = $wpdb->prepare("SELECT SUM(p.total_practice_time) FROM $table_name_practice p WHERE p.user_id = %d AND p.`date` BETWEEN %s AND %s", $user_id, $start_date, $end_date);
        $total_practice_time = $wpdb->get_var($query);
        $response_key = 'total_practice_time';
        $total = $total_practice_time;
    }

    if ($total === null) {
        return new WP_Error('db_query_failed', 'Database query failed.', array('status' => 500));
    }

    $how_long = isset($data['how_long']) ? intval($data['how_long']) : null;
    $goal_complete = $how_long !== null ? ($total >= $how_long) : false;

    return new WP_REST_Response(array($response_key => $total, 'goal_complete' => $goal_complete), 200);
}

function validate_date_interval($start_date, $end_date, $frequency) {
    $start = new DateTime($start_date);
    $end = new DateTime($end_date);
    $interval = $start->diff($end)->days;

    switch ($frequency) {
        case 'everyday':
            return $interval == 0; // 0 days difference for the same day
        case 'once-a-week':
        case 'twice-a-week':
        case 'thrice-a-week':
            // Adjusting for a week to include the start date
            return $interval == 6; // 6 days difference for a week including start date
        case 'once-every-30-days':
        case 'twice-every-30-days':
        case 'thrice-every-30-days':
            // 30 days should include the start date
            return $interval == 29; // 29 days difference for 30 days including start date
        case 'once-a-year':
        case 'twice-a-year':
        case 'thrice-a-year':
            // Adjust for one year to include the start date
            return $interval == 364; // 364 days difference for a year including start date
        default:
            return false;
    }
}


function is_goal_complete($count, $frequency) {
    switch ($frequency) {
        case 'everyday':
            return $count >= 1;
        case 'once-a-week':
            return $count >= 1;
        case 'twice-a-week':
            return $count >= 2;
        case 'thrice-a-week':
            return $count >= 3;
        case 'once-every-30-days':
            return $count >= 1;
        case 'twice-every-30-days':
            return $count >= 2;
        case 'thrice-every-30-days':
            return $count >= 3;
        case 'once-a-year':
            return $count >= 1;
        case 'twice-a-year':
            return $count >= 2;
        case 'thrice-a-year':
            return $count >= 3;
        default:
            return false;
    }
}









/////////////////////////////Add new goal to pr_goals


add_action( 'rest_api_init', function () {
    register_rest_route( 'jhg-apps/v1', '/add-goal', array(
        'methods' => 'POST',
        'callback' => 'add_new_goal',
        'permission_callback' => 'evolo_jwt_permission_callback',
        'args' => array(
            'skill' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return is_string($param);
                }
            ),
            'goal_name' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return is_string($param);
                }
            ),
            'module' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return is_string($param);
                }
            ),
            'how_long' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
            'frequency' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    $valid_frequencies = ['everyday', 'once-a-week', 'twice-a-week', 'thrice-a-week', 'once-every-30-days', 'twice-every-30-days', 'thrice-every-30-days', 'once-a-year', 'twice-a-year', 'thrice-a-year'];
                    return in_array($param, $valid_frequencies);
                }
            ),
            'goal_end_date' => array(
                'required' => true,
                'validate_callback' => function($param, $request, $key) {
                    return strtotime($param) > 0; 
                }
            )
        )
    ));
});



function add_new_goal($request) {
    global $wpdb;
    $table_name = 'pr_goals';

    $user_id = get_current_user_id(); 
    if (!$user_id) {
        return new WP_Error('jwt_auth_failed', 'User authentication failed', array('status' => 403));
    }

    // Retrieve parameters from the request
    $goal_name = sanitize_text_field($request['goal_name']);
    $module = sanitize_text_field($request['module']);
    $how_long = intval($request['how_long']);
    $frequency = sanitize_text_field($request['frequency']);
    $skill_name = sanitize_text_field($request['skill']); // Extract skill name from the request

    // Convert skill name to skill_id
    $skill_id = get_skill_id_by_name($skill_name); 
    if (!$skill_id) {
        return new WP_Error('invalid_skill', 'Skill not found', array('status' => 404));
    }
    
    $goal_end_date = new DateTime($request['goal_end_date']);
    $current_time = new DateTime(); 
    $goal_end_date->setTime($current_time->format('H'), $current_time->format('i'), $current_time->format('s'));

    if (!is_end_date_valid($frequency, $goal_end_date->format('Y-m-d H:i:s'))) {
        return new WP_Error('invalid_end_date', 'The end date does not meet the minimum timeframe for the selected frequency.', array('status' => 400));
    }

    $result = $wpdb->insert($table_name, array(
        'user_id' => $user_id,
        'goal_name' => $goal_name,
        'module' => $module,
        'how_long' => $how_long,
        'frequency' => $frequency,
        'goal_end_date' => $goal_end_date->format('Y-m-d H:i:s'),
        'skill_id' => $skill_id // Use skill_id for the insertion
    ));

    if ($result) {
        $goal_id = $wpdb->insert_id;
        return new WP_REST_Response(array(
            'message' => 'Goal added successfully',
            'id' => $goal_id
        ), 200);
    } else {
        return new WP_Error('db_error', 'Error adding goal to the database: ' . $wpdb->last_error, array('status' => 500)); // Include specific DB error
    }
}


function is_end_date_valid($frequency, $end_date) {
    $today = new DateTime();
    $end = new DateTime($end_date);
    $interval = $today->diff($end)->days;

    switch ($frequency) {
        case 'everyday':
            return $interval >= 1;
        case 'once-a-week':
        case 'twice-a-week':
        case 'thrice-a-week':
            return $interval >= 7;
        case 'once-every-30-days':
        case 'twice-every-30-days':
        case 'thrice-every-30-days':
            return $interval >= 30;
        case 'once-a-year':
        case 'twice-a-year':
        case 'thrice-a-year':
            return $interval >= 365;
        default:
            return false;
    }
}



////////////////////////Add goal steps to pr_goalmeta



// function add_goal_steps_to_goalmeta($goal_id, $data) {
//     global $wpdb;
//     $table_name = 'pr_goalmeta';
//     $frequency = $data['frequency'];
//     $goal_end_date = new DateTime($data['goal_end_date']);
//     $today = new DateTime();

//     $interval = get_date_interval($frequency);
//     $period = new DatePeriod($today, $interval, $goal_end_date);

//     $interval_ids = [];

//     foreach ($period as $date) {
//         $start_date = $date->format('Y-m-d H:i:s');
//         $end_date = clone $date;
//         $end_date->add($interval)->modify('-1 day');

//         if ($end_date > $goal_end_date) {
//             break;
//         }

//         $insert_result = $wpdb->insert($table_name, array(
//             'goal_id' => $goal_id,
//             'start_date' => $start_date,
//             'end_date' => $end_date->format('Y-m-d H:i:s'),
//             'complete' => 0
//         ));

//         if ($insert_result) {
//             $interval_ids[] = array(
//                 'id' => $wpdb->insert_id,
//                 'start_date' => $start_date,
//                 'end_date' => $end_date->format('Y-m-d H:i:s')
//             );
//         }
//     }

//     return $interval_ids;
// }


function add_goal_steps_to_goalmeta($goal_id, $data) {
    global $wpdb;
    $table_name = 'pr_goalmeta';
    $frequency = $data['frequency'];
    $goal_end_date = new DateTime($data['goal_end_date']);
    $current_start_date = new DateTime(); // Start from today

    $interval = get_date_interval($frequency);
    $interval_ids = [];

    while ($current_start_date < $goal_end_date) {
        $end_date = clone $current_start_date;

        // Calculate the end date based on the frequency
        if (in_array($frequency, ['once-a-week', 'twice-a-week', 'thrice-a-week'])) {
            $end_date->modify('+6 days');
        } else {
            $end_date->add($interval)->modify('-1 day');
        }

        // Check if the end date exceeds the goal end date
        if ($end_date >= $goal_end_date) {
            // Break the loop if the full interval cannot fit before the goal end date
            break;
        }

        $insert_result = $wpdb->insert($table_name, array(
            'goal_id' => $goal_id,
            'start_date' => $current_start_date->format('Y-m-d H:i:s'),
            'end_date' => $end_date->format('Y-m-d H:i:s'),
            'complete' => 0
        ));

        if ($insert_result) {
            $interval_ids[] = array(
                'id' => $wpdb->insert_id,
                'start_date' => $current_start_date->format('Y-m-d H:i:s'),
                'end_date' => $end_date->format('Y-m-d H:i:s')
            );
        }

        // Set the start of the next interval as the end date of the current interval
        $current_start_date = clone $end_date;
    }

    return $interval_ids;
}









function get_date_interval($frequency) {
    switch ($frequency) {
        case 'everyday':
            return new DateInterval('P1D');
        case 'once-a-week':
        case 'twice-a-week':
        case 'thrice-a-week':
            // The interval is still one week
            return new DateInterval('P7D');
        case 'once-every-30-days':
        case 'twice-every-30-days':
        case 'thrice-every-30-days':
            // The interval is 30 days
            return new DateInterval('P30D');
        case 'once-a-year':
        case 'twice-a-year':
        case 'thrice-a-year':
            // The interval is one year
            return new DateInterval('P1Y');
        default:
            return new DateInterval('P1D'); // Default interval
    }
}




///////////////////////////Update goal complete status



add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/update-goal-status', array(
        'methods' => 'PUT',
        'callback' => 'update_goal_status',
        'permission_callback' => 'evolo_jwt_permission_callback',
        'args' => array(
            'id' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return is_numeric($param);
                }
            ),
            'complete' => array(
                'required' => true,
                'validate_callback' => function ($param, $request, $key) {
                    return in_array($param, [0, 1]);
                }
            ),
        ),
    ));
});

function update_goal_status($data) {
    global $wpdb;
    $table_name = 'pr_goalmeta';

    $id = intval($data['id']);
    $complete = intval($data['complete']);

    $result = $wpdb->update($table_name, array('complete' => $complete), array('id' => $id));

    if (false === $result) {
        return new WP_Error('db_error', 'Error updating goal status in the database', array('status' => 500));
    }

    return new WP_REST_Response(array('message' => 'Goal status updated successfully', 'id' => $id, 'complete' => $complete), 200);
}