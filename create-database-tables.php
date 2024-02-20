<?php

//////////////////Register database tables on plugin activation 


register_activation_hook(__FILE__, 'custom_database_schema_activation');

function custom_database_schema_activation() {
    global $wpdb;

    $charset_collate = $wpdb->get_charset_collate();

    $sql_queries = "
        SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
        SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
        SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

        CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}pr_buddies` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `skill_id` INT(11) NULL DEFAULT NULL,
          `received_by` BIGINT(20) NOT NULL,
          `requested_by` BIGINT(20) NOT NULL,
          `note` TEXT NULL DEFAULT NULL,
          `status` VARCHAR(255) NOT NULL,
          `date_created` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
          `date_updated` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB $charset_collate;

        CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}pr_goals` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `user_id` INT(11) NULL DEFAULT NULL,
          `goal_name` VARCHAR(255) NOT NULL,
          `how_long` VARCHAR(255) NULL DEFAULT NULL,
          `module` VARCHAR(255) NOT NULL,
          `frequency` VARCHAR(255) NOT NULL,
          `goal_end_date` DATETIME NOT NULL,
          `skill_id` INT(11) NULL DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB $charset_collate;

        CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}pr_goalmeta` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `goal_id` INT(11) NOT NULL,
          `start_date` DATETIME NOT NULL,
          `end_date` DATETIME NOT NULL,
          `complete` TINYINT(1) NOT NULL,
          PRIMARY KEY (`id`),
          FOREIGN KEY (`goal_id`) REFERENCES `{$wpdb->prefix}pr_goals` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB $charset_collate;

        CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}pr_modules` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `skill_id` INT(11) NOT NULL,
          `module_name` VARCHAR(255) NULL DEFAULT NULL,
          `description` TEXT NULL DEFAULT NULL,
          `images` TEXT NULL DEFAULT NULL,
          `user_id` BIGINT(20) UNSIGNED NULL DEFAULT '0',
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB $charset_collate;

        CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}pr_practice_time_and_mood` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `name` VARCHAR(255) NULL DEFAULT NULL,
          `user_id` INT(11) NOT NULL,
          `total_practice_time` INT(11) NULL DEFAULT NULL,
          `notes` TEXT NULL DEFAULT NULL,
          `mood` VARCHAR(50) NULL DEFAULT NULL,
          `date` DATETIME NULL DEFAULT NULL,
          `skill_id` INT(11) NULL DEFAULT NULL,
          `location` VARCHAR(255) NULL DEFAULT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB $charset_collate;

        CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}pr_module_distribution` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `practice_routine_id` INT(11) NOT NULL,
          `duration` INT(11) NULL DEFAULT NULL,
          `module_id` INT(11) NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          FOREIGN KEY (`module_id`) REFERENCES `{$wpdb->prefix}pr_modules` (`id`) ON DELETE CASCADE,
          FOREIGN KEY (`practice_routine_id`) REFERENCES `{$wpdb->prefix}pr_practice_time_and_mood` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB $charset_collate;

        CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}pr_skills` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `skill_name` VARCHAR(255) NOT NULL,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB $charset_collate;

        CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}pr_tag_connectors` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `tag_name` VARCHAR(255) NOT NULL,
          `skill_id` INT(11) NULL DEFAULT NULL,
          `user_id` INT(11) NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          FOREIGN KEY (`skill_id`) REFERENCES `{$wpdb->prefix}pr_skills` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB $charset_collate;

        CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}pr_module_tags` (
          `module_id` INT(11) NOT NULL,
          `tag_id` INT(11) NOT NULL,
          PRIMARY KEY (`module_id`, `tag_id`),
          FOREIGN KEY (`module_id`) REFERENCES `{$wpdb->prefix}pr_modules` (`id`) ON DELETE CASCADE,
          FOREIGN KEY (`tag_id`) REFERENCES `{$wpdb->prefix}pr_tag_connectors` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB $charset_collate;

        CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}pr_tools` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `skill_id` INT(11) NOT NULL,
          `tool_name` VARCHAR(255) NOT NULL,
          `user_id` INT(11) NULL DEFAULT NULL,
          `description` VARCHAR(255) NULL DEFAULT NULL,
          PRIMARY KEY (`id`),
          UNIQUE KEY `unique_tool_skill` (`tool_name`, `skill_id`)
        ) ENGINE=InnoDB $charset_collate;

        CREATE TABLE IF NOT EXISTS `{$wpdb->prefix}pr_practice_routine_tools` (
          `practice_routine_id` INT(11) NULL DEFAULT NULL,
          `tool_id` INT(11) NOT NULL,
          PRIMARY KEY (`practice_routine_id`, `tool_id`),
          FOREIGN KEY (`tool_id`) REFERENCES `{$wpdb->prefix}pr_tools` (`id`) ON DELETE CASCADE,
          FOREIGN KEY (`practice_routine_id`) REFERENCES `{$wpdb->prefix}pr_practice_time_and_mood` (`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB $charset_collate;

        SET SQL_MODE=@OLD_SQL_MODE;
        SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
        SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
    ";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_queries);
}
