<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Claude API – admin-level course authoring web service for a local development Moodle.
 *
 * @package    local_claudeapi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'local_claudeapi';
$plugin->version   = 2026112600;
$plugin->requires  = 2025041400; // Moodle 5.0.
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';
