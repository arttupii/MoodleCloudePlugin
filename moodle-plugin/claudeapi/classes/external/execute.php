<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_claudeapi\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;

/**
 * Single entry point: local_claudeapi_execute(action, args) -> JSON string.
 *
 * @package    local_claudeapi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class execute extends external_api {

    /**
     * Parameters.
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'action' => new external_value(PARAM_ALPHANUMEXT, 'Action name, e.g. get_course_structure'),
            'args'   => new external_value(PARAM_RAW, 'JSON object with action arguments', VALUE_DEFAULT, '{}'),
        ]);
    }

    /**
     * Run an action.
     *
     * @param string $action
     * @param string $args
     * @return string JSON result
     */
    public static function execute(string $action, string $args = '{}'): string {
        $params = self::validate_parameters(self::execute_parameters(), ['action' => $action, 'args' => $args]);
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('moodle/site:config', $context);

        $decoded = json_decode($params['args'], true);
        if (!is_array($decoded)) {
            throw new \invalid_parameter_exception('args must be a JSON object');
        }

        // Some core code (question import, backup) prints progress output. Keep the WS response clean.
        ob_start();
        try {
            $result = \local_claudeapi\api::run($params['action'], $decoded);
        } finally {
            $printed = ob_get_clean();
        }
        if (is_array($result) && !empty(trim(strip_tags((string)$printed)))) {
            $result['_output'] = \core_text::substr(trim(strip_tags($printed)), 0, 4000);
        }
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Returns.
     * @return external_value
     */
    public static function execute_returns(): external_value {
        return new external_value(PARAM_RAW, 'JSON encoded result');
    }
}
