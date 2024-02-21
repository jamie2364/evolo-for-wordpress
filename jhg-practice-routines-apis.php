<?php
/*
Plugin Name: Evolo for Wordpress
Plugin URI: https://github.com/jamie2364/evolo-for-wordpress
Description: All API functionality for practice routines app.
Version: 1.0.13
Author: Jamie Harrison
GitHub Plugin URI: https://github.com/jamie2364/evolo-for-wordpress
Primary Branch: main
*/


// Include all necessary files from the 'apis' folder
$api_files = array(
    'buddies.php',
    'goals.php',
    'learner-type-quiz.php',
    'modules.php',
    'motivational-quote.php',
    'practice-routines.php',
    'sample-routines.php',
    'skill-quotes.php',
    'skills.php',
    'tags.php',
    'templates.php',
    'tools.php',
    'product-ids.php'
);

// Include add-product-ids.php from the 'functions' folder
$functions_folder = plugin_dir_path(__FILE__) . 'functions/';
$add_product_ids_file = $functions_folder . 'add-product-ids.php';
if (file_exists($add_product_ids_file)) {
    require_once $add_product_ids_file;
} else {
    // Handle the case if the file doesn't exist
    echo "Error: File $add_product_ids_file not found!";
}

foreach ($api_files as $file) {
    // Construct the full path to each file
    $file_path = plugin_dir_path(__FILE__) . 'apis/' . $file;
    
    // Check if the file exists before including it
    if (file_exists($file_path)) {
        require_once $file_path;
    } else {
        // Handle the case if the file doesn't exist
        echo "Error: File $file_path not found!";
    }
}

