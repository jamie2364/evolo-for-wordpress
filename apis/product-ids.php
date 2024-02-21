<?php

// Create API Endpoint
function get_product_ids_endpoint() {
    $product_ids = get_option('product_ids_option');
    $product_ids_array = explode(',', $product_ids);
    $response = array(
        'product_ids' => $product_ids_array
    );
    return rest_ensure_response($response);
}

// Register API Endpoint
function register_product_ids_endpoint() {
    register_rest_route('jhg-apps/v1', '/product-ids', array(
        'methods' => 'GET',
        'callback' => 'get_product_ids_endpoint',
    ));
}
add_action('rest_api_init', 'register_product_ids_endpoint');
