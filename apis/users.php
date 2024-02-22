<?php

// // user_data_api.php

///////////////////////////////////////////// Include WordPress functionalities

require_once($_SERVER['DOCUMENT_ROOT'].'/wp-load.php');


///////////////////////////////////////Include JWT Authentication functions///////////
if (file_exists(__DIR__ . '/../functions/jwt-functions.php')) {
  include_once __DIR__ . '/../functions/jwt-functions.php';
}


// // ///////////////////////////////////////Retrieve User Information for profile


add_action('rest_api_init', function () {
    register_rest_route('jhg-apps/v1', '/getUserData', array(
        'methods' => 'GET',
        'callback' => 'get_user_data',
        'permission_callback' => 'jwt_permission_callback' // Apply JWT authentication
    ));
});

function get_user_data($request) {
    $countries = include('country_code_mapping.php');
    global $wpdb;

    // Extract the user_id from the JWT token
    $user_id = get_current_user_id();

    // Initialize variables
    $countryName = '';
    $profile_picture_url = '';
    $full_name = '';
    $username = '';

    // Query the wp_edd_customers table to retrieve the customer ID
    $customer_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM wp_edd_customers WHERE user_id = %d",
        $user_id
    ));

    if ($customer_id) {
        // Query the wp_edd_customer_addresses table to get the address data, including the country
        $address_row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM wp_edd_customer_addresses WHERE customer_id = %d",
            $customer_id
        ));

        if ($address_row) {
            // Extract the country from the address row
            $countryCode = isset($address_row->country) ? $address_row->country : '';
            // Convert country code to full country name or set a default message if not found
            $countryName = isset($countries[$countryCode]) ? $countries[$countryCode] : 'Country not found';
        } else {
            // If no address data is found, explicitly set the country name to indicate it was not found
            $countryName = 'Country not found';
        }
    } else {
        // Handle case when customer ID is not found similarly
        $countryName = 'Country information unavailable';
    }

// Retrieve the profile picture URL
$meta_key = 'profile-picture';
$profile_picture_id = get_user_meta($user_id, $meta_key, true);
$profile_picture_url = $profile_picture_id ? wp_get_attachment_url($profile_picture_id) : '';

if (!$profile_picture_url) {
    $profile_picture_url = ''; // Ensure it returns an empty string if false
}

    // Get the user's full name
    $full_name = get_user_full_name($user_id);
    // Check if full name is empty and set to 'Name not found' if true
    if (empty($full_name)) {
        $full_name = 'Name not found';
    }

    // Get the user's username
    $username = get_user_username($user_id);
    // Check if username is empty and set to 'Username not found' if true
    if (empty($username)) {
        $username = 'Username not found';
    }

    // Build the response data including the user's full name, country name, profile picture URL, and username
    $user_data = array(
        'full_name' => $full_name,
        'country' => $countryName,
        'username' => $username,
        'profile_picture_url' => $profile_picture_url
    );

    // Check if customer ID was not found and add a message, but do not fail the entire request
    if (!$customer_id) {
        $user_data['error'] = 'Customer not found. Some information may be missing.';
    }

    // Respond with the user data
    return new WP_REST_Response($user_data, 200);
}




// Helper function to get the full name of a user
function get_user_full_name($user_id) {
    $user_data = get_userdata($user_id); // Retrieve user data
    if ($user_data) {
        $first_name = $user_data->first_name;
        $last_name = $user_data->last_name;
        $full_name = trim($first_name . ' ' . $last_name); // Concatenate first and last name
        return $full_name;
    } else {
        return ''; // Return empty string if user data is not found
    }
}

// Helper function to get the username of a user
function get_user_username($user_id) {
    $user_data = get_userdata($user_id); // Retrieve user data
    if ($user_data) {
        return $user_data->user_login;
    } else {
        return ''; // Return empty string if user data is not found
    }
}




/////////////////////////////////////////////Create new user


/**
 * Custom WordPress REST API Endpoint for User Registration.
 * Validates user inputs, prevents duplication, and creates new user accounts.
 * Incorporates special handling for WooCommerce user roles.
 */

add_action('rest_api_init', 'wp_rest_user_endpoints');
function wp_rest_user_endpoints($request) {
register_rest_route('custom/v1', 'users/register', array(
    'methods' => 'POST',
    'callback' => 'wc_rest_user_endpoint_handler',
));
}

function wc_rest_user_endpoint_handler($request = null) {
    $response = array();
    // Use ->get_param() instead of accessing the array directly.
    $username = sanitize_text_field($request->get_param('username'));
    $email = sanitize_text_field($request->get_param('email'));
    $password = sanitize_text_field($request->get_param('password'));



  // $role = sanitize_text_field($parameters['role']);
  $error = new WP_Error();
  if (empty($username)) {
    $error->add(0, __("Username field 'username' is required.", 'wp-rest-user'), array('status' => 400));
    $response['code'] = 0;
    $response['message'] = __("Username field 'username' is required.", "wp-rest-user");
    return $response;
  }
  if (empty($email)) {
    $error->add(0, __("Email field 'email' is required.", 'wp-rest-user'), array('status' => 400));
    $response['code'] = 0;
    $response['message'] = __("Email field 'email' is required.", "wp-rest-user");
    return $response;
  }
  if (empty($password)) {
    $error->add(0, __("Password field 'password' is required.", 'wp-rest-user'), array('status' => 400));
    $response['code'] = 0;
    $response['message'] = __("Password field 'password' is required.", "wp-rest-user");
    return $response;
  }
	

  $user_id = username_exists($username);
  if (!$user_id && email_exists($email) == false) {
    $user_id = wp_create_user($username, $password, $email);
    if (!is_wp_error($user_id)) {
      // Ger User Meta Data (Sensitive, Password included. DO NOT pass to front end.)
      $user = get_user_by('id', $user_id);
      // $user->set_role($role);
      $user->set_role('subscriber');
      // WooCommerce specific code
      if (class_exists('WooCommerce')) {
        $user->set_role('customer');
      }
      // Ger User Data (Non-Sensitive, Pass to front end.)
      $response['code'] = 1;
      $response['message'] = __("User '" . $username . "' Registration was Successful", "wp-rest-user");
    } else {
      return $user_id;
    }
  } else {
    $error->add(0, __("Email already exists, please try 'Reset Password'", 'wp-rest-user'), array('status' => 400));
    $response['code'] = 0;
    $response['message'] = __("Email already exists, please try 'Reset Password'", "wp-rest-user");
    return $response;
  }
  return new WP_REST_Response($response, 123);
}


//// Delete User


function custom_delete_user_endpoint($request) {
    	 register_rest_route('wp/v2', '/user-delete/', array(
        'methods' => 'DELETE',
        'callback' => 'custom_delete_user_callback'
	));
}
add_action('rest_api_init', 'custom_delete_user_endpoint');


function custom_delete_user_callback($request) {
	
	 $jwt_token = $request->get_header('Authorization');
 if ( empty( $jwt_token ) || ! preg_match( '/Bearer\s(\S+)/', $jwt_token, $matches ) ) {
       return new WP_Error( 'jwt_auth_bad_auth_header', __( 'Authorization header malformed.', 'jwt-auth' ), array( 'status' => 401 ) );
   }
    $jwt_token = $matches[1];
            $secret_key = defined('JWT_AUTH_SECRET_KEY') ? JWT_AUTH_SECRET_KEY : false;

	// If the token is present, verify it
    if ($jwt_token) {
        try {
            // Verify the JWT token
         	$decoded_token = json_decode(base64_decode(str_replace('_', '/', str_replace('-','+',explode('.', $jwt_token)[1]))));
			require_once(ABSPATH.'wp-admin/includes/user.php');
			if (wp_delete_user($decoded_token->data->user->id)) {
    			return array('message' => 'User deleted successfully.');
					}else{
					  return array('message' => 'Couldn\'t delete.');
			}
        } catch (Exception $e) {
    			return array('message' => 'Deletion not allowed');
        }
    }
}



/////////////////////////////////////////////////////////Get User Profile Picture////////////////////////////////////////////////////////////////////


// Define endpoint
add_action( 'rest_api_init', function () {
  register_rest_route( 'custom/v1', '/profile-picture', array(
    'methods' => 'GET',
    'callback' => 'get_profile_picture',
  ) );
} );

// Callback function
function get_profile_picture( $request ) {
  $user_id = $request->get_param( 'user_id' );
  $meta_key = 'profile-picture';
  $url = wp_get_attachment_url( get_user_meta( $user_id, $meta_key, true ) );
  return $url;
}






/////////////////////////////////////////////////////////////Edit Profile//////////////////////////////////////////////////


add_action( 'rest_api_init', 'edit_profile_func' );   

function edit_profile_func() {
    register_rest_route( 'custom/v1', '/edit_profile', array(
        'methods' => 'GET',
        'callback' => 'edit_profile_funccallback'
    ));
}
function edit_profile_funccallback($request)
{
  $id = $request['id'];
  $pw = $request['pass'];
  $fname = $request['fname'];
  $lname = $request['lname'];
  update_user_meta( $id, 'first_name', $fname);
  update_user_meta( $id, 'last_name', $lname);
  $user_info = get_user_meta($id);
  //print_r($user_info);
  if(!empty($pw))
  {
    wp_set_password( $pw, $id );
  }
  $array  = $user_info;
    return $array;

}



/////////////////////////////////////////////////// Reset password////////////////////////////////////////////////////////




add_action( 'rest_api_init', 'reset_passwrord_func' );   

function reset_passwrord_func() {
    register_rest_route( 'custom/v1', '/reset_passwrord', array(
        'methods' => 'GET',
        'callback' => 'reset_passwrord_func_callback'
    ));
}
function reset_passwrord_func_callback($request)
{
  $email = $request['email'];
  $user = get_user_by('email', $email );
  if($user)
  {
    
    $firstname = $user->data->first_name;
    $email = $user->data->user_email;
    $adt_rp_key = get_password_reset_key( $user );
    $user_login = $user->user_login;
    $rp_link = '<a href="' . wp_login_url()."?action=rp&key=$adt_rp_key&login=" . rawurlencode($user_login) . '">' . wp_login_url()."/resetpass/?key=$adt_rp_key&login=" . rawurlencode($user_login) . '</a>';

    if ($firstname == "") $firstname = "gebruiker";
    $message = "Someone has requested a password reset for the following account:";
    $message = "Hi ".$user_login.",<br>";
    $message .= "Click here to set the password for your account: <br>";
    $message .= $rp_link.'<br>';

	  
    $subject = 'Reset Password';
    $headers = array();

    add_filter( 'wp_mail_content_type', function( $content_type ) {return 'text/html';});
    $headers[] = 'From: Jamie Harrison Guitar <info@jamieharrisonguitar.com>'."\r\n";
    wp_mail( $email, $subject, $message, $headers);

    // Reset content-type to avoid conflicts -- http://core.trac.wordpress.org/ticket/23578
    remove_filter( 'wp_mail_content_type', 'set_html_content_type' );
    $array  = array(
        'status' => 1
    );
    return $array;
  }
  else
  {
    $array  = array(
        'status' => 0
    );
    return $array;
  }
}

