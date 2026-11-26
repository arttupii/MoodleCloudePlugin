<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Web service definitions.
 *
 * @package    local_claudeapi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_claudeapi_execute' => [
        'classname'    => 'local_claudeapi\external\execute',
        'description'  => 'Run a course authoring action (JSON in, JSON out). Site admins only.',
        'type'         => 'write',
        'capabilities' => 'moodle/site:config',
        'ajax'         => false,
    ],
];

$services = [
    'Claude API' => [
        'functions'       => ['local_claudeapi_execute', 'core_webservice_get_site_info'],
        'restrictedusers' => 1,
        'enabled'         => 1,
        'shortname'       => 'local_claudeapi',
        'downloadfiles'   => 1,
        'uploadfiles'     => 1,
    ],
];
