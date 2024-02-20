<?php


///////////////////////////////////////////// Include WordPress functionalities

require_once($_SERVER['DOCUMENT_ROOT'].'/wp-load.php');


/////////////////////////////////////////////////Use the Firebase JWT Library


use Firebase\JWT\JWT;
use Firebase\JWT\Key;


/////////////////////////////////////////////////////// Callback function to check JWT permissions


function jwt_permission_callback($request) {
    $jwt_token = $request->get_header('Authorization');

    if (empty($jwt_token) || !preg_match('/Bearer\s(\S+)/', $jwt_token, $matches)) {
        return new WP_Error('jwt_auth_bad_auth_header', __('Authorization header malformed.', 'jwt-auth'), array('status' => 401));
    }
    
    $jwt_token = $matches[1];
    $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : false;

    if (!$secret_key) {
        return new WP_Error('jwt_auth_bad_config', __('JWT is not configured properly, please contact the admin', 'wp-api-jwt-auth'), array('status' => 403));
    }

    try {
        $decoded_token = JWT::decode($jwt_token, new Key($secret_key, 'HS256'));
        $user_id = $decoded_token->data->user->id;

        // Set the user ID in the request for later use
        $request->set_param('user_id', $user_id);

        $user = get_user_by('id', $user_id);

        if ($user && in_array('administrator', $user->roles)) {
            return true; // Allow administrators to perform all actions
        } else {
            return true; // Allow non-admin users to create modules and add tags
        }
    } catch (Exception $e) {
        return new WP_Error('jwt_auth_invalid_token', __('Invalid token', 'jwt-auth'), array('status' => 401));
    }
}


function jwt_permission_callback_no_user_id($request) {
    $jwt_token = $request->get_header('Authorization');

    if (empty($jwt_token) || !preg_match('/Bearer\s(\S+)/', $jwt_token, $matches)) {
        return new WP_Error('jwt_auth_bad_auth_header', __('Authorization header malformed.', 'jwt-auth'), array('status' => 401));
    }
    
    $jwt_token = $matches[1];
    $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : false;

    if (!$secret_key) {
        return new WP_Error('jwt_auth_bad_config', __('JWT is not configured properly, please contact the admin', 'wp-api-jwt-auth'), array('status' => 403));
    }

    try {
        // Perform JWT token validation without extracting the user_id.
        
        $user = get_user_by('id', $user_id);

        if ($user && in_array('administrator', $user->roles)) {
            return true; // Allow administrators to perform all actions
        } else {
            return true; // Allow non-admin users to create modules and add tags
        }
    } catch (Exception $e) {
        return new WP_Error('jwt_auth_invalid_token', __('Invalid token', 'jwt-auth'), array('status' => 401));
    }
}





