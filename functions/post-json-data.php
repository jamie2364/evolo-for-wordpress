<?php

//////////// Post json data to database when plugin is activated

function import_data_on_activation() {
    global $wpdb;
    
    // Correctly form the path to the JSON file
    $json_data = file_get_contents(dirname(__DIR__) . '/json/all-data.json');
    if ($json_data === false) {
        error_log('Unable to read JSON file.');
        return;
    }

    $data = json_decode($json_data, true);
    if ($data === null) {
        error_log('Invalid JSON format.');
        return;
    }

    // Skill Processing
    $skill_name = sanitize_text_field($data['skill_name']);
    $skill_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM pr_skills WHERE skill_name = %s", $skill_name
    ));

    if (!$skill_id) {
        $wpdb->insert('pr_skills', ['skill_name' => $skill_name]);
        $skill_id = $wpdb->insert_id;
    }

    $user_id = 1; // Adjust as necessary

    // Modules Processing
    foreach ($data['skill_modules'] as $module) {
        $module_name = sanitize_text_field($module['module_name']);
        $description = sanitize_text_field($module['description']);
        $images = maybe_serialize($module['images']); // Adjust according to your schema

        $existing_module_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM pr_modules WHERE skill_id = %d AND module_name = %s", $skill_id, $module_name
        ));

        if (!$existing_module_id) {
            $wpdb->insert('pr_modules', [
                'user_id' => $user_id,
                'skill_id' => $skill_id,
                'module_name' => $module_name,
                'description' => $description,
                'images' => $images,
            ]);
            $module_id = $wpdb->insert_id;
        } else {
            $module_id = $existing_module_id;
        }

        foreach ($module['tags'] as $tag) {
            $tag_name = sanitize_text_field($tag);
            $existing_tag_id = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM pr_tag_connectors WHERE tag_name = %s", $tag_name
            ));

            if (!$existing_tag_id) {
                $wpdb->insert('pr_tag_connectors', ['tag_name' => $tag_name]);
                $tag_id = $wpdb->insert_id;
            } else {
                $tag_id = $existing_tag_id;
            }

            $wpdb->insert('pr_module_tags', [
                'module_id' => $module_id,
                'tag_id' => $tag_id,
            ]);
        }
    }

    // Tools Processing
    foreach ($data['skill_tools'] as $tool) {
        $tool_name = sanitize_text_field($tool['tool_name']);
        $description = sanitize_text_field($tool['description']);

        $existing_tool_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM pr_tools WHERE tool_name = %s AND skill_id = %d", $tool_name, $skill_id
        ));

        if (!$existing_tool_id) {
            $wpdb->insert('pr_tools', [
                'user_id' => $user_id,
                'skill_id' => $skill_id,
                'tool_name' => $tool_name,
                'description' => $description,
            ]);
        }
    }
}
