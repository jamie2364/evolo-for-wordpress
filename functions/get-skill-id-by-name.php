<?php


//////////// Get Skill id from skill name

function get_skill_id_by_name($skill_name) {
    global $wpdb;
    $skills_table = 'pr_skills'; // Assuming the table where skills are stored is named 'pr_skills'

    $skill_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $skills_table WHERE skill_name = %s",
        $skill_name
    ));

    return $skill_id ? $skill_id : null; // Return the skill_id if found, otherwise return null
}