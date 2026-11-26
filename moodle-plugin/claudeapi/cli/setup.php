<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * One-shot setup: enable web services + REST, authorise a site admin for the Claude API service, print a token.
 *
 *   sudo -u www-data php local/claudeapi/cli/setup.php --username=admin
 *
 * @package    local_claudeapi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(['username' => 'admin', 'help' => false], ['u' => 'username', 'h' => 'help']);
if ($options['help']) {
    cli_writeln("Usage: php local/claudeapi/cli/setup.php --username=<site admin username>");
    exit(0);
}

$user = $DB->get_record('user', ['username' => $options['username'], 'deleted' => 0], '*', MUST_EXIST);
if (!is_siteadmin($user)) {
    cli_error("User '{$user->username}' is not a site administrator. Make it one first (Site administration > Users > Site administrators).");
}
\core\session\manager::set_user(get_admin());

set_config('enablewebservices', 1);
$protocols = empty($CFG->webserviceprotocols) ? [] : explode(',', $CFG->webserviceprotocols);
if (!in_array('rest', $protocols)) {
    $protocols[] = 'rest';
    set_config('webserviceprotocols', implode(',', $protocols));
}

$service = $DB->get_record('external_services', ['shortname' => 'local_claudeapi'], '*', MUST_EXIST);
if (!$service->enabled) {
    $DB->set_field('external_services', 'enabled', 1, ['id' => $service->id]);
}
if (!$DB->record_exists('external_services_users', ['externalserviceid' => $service->id, 'userid' => $user->id])) {
    $DB->insert_record('external_services_users', (object)[
        'externalserviceid' => $service->id, 'userid' => $user->id, 'timecreated' => time(),
    ]);
}

$token = \core_external\util::generate_token(0 /* EXTERNAL_TOKEN_PERMANENT */, $service, $user->id,
    context_system::instance(), 0, '', 'Claude MCP');

cli_writeln("Web services + REST enabled, service 'Claude API' authorised for {$user->username}.");
cli_writeln("MOODLE_URL=" . $CFG->wwwroot);
cli_writeln("MOODLE_TOKEN=" . $token);
