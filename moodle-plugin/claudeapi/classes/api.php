<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_claudeapi;

use context_course;
use context_module;
use context_user;
use moodle_exception;
use invalid_parameter_exception;
use stdClass;

/**
 * Course authoring actions. Intended ONLY for a local development Moodle:
 * every action runs with site administrator rights.
 *
 * @package    local_claudeapi
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {

    /** @var string[] Actions that can be called. */
    const ACTIONS = [
        'ping', 'list_categories', 'list_courses', 'get_course_structure', 'resolve_cm',
        'create_course', 'update_course', 'delete_course',
        'create_section', 'update_section', 'delete_section', 'move_section',
        'add_module', 'update_module', 'delete_module', 'move_module', 'duplicate_module',
        'upload_file', 'list_files', 'read_file', 'delete_file', 'add_image',
        'add_book_chapter', 'update_book_chapter', 'delete_book_chapter',
        'import_questions', 'remove_quiz_slot', 'repaginate_quiz',
        'backup_course', 'begin_upload', 'append_upload', 'restore_course',
    ];

    /** @var \testing_data_generator|null */
    protected static $generator = null;

    /**
     * Dispatch an action.
     *
     * @param string $action
     * @param array $args
     * @return array
     */
    public static function run(string $action, array $args): array {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/course/modlib.php');
        if (!in_array($action, self::ACTIONS, true)) {
            throw new invalid_parameter_exception("Unknown action '$action'. Available: " . implode(', ', self::ACTIONS));
        }
        return self::$action($args);
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers.
    // ---------------------------------------------------------------------------------------------

    /**
     * Required argument.
     * @param array $args
     * @param string $key
     * @return mixed
     */
    protected static function req(array $args, string $key) {
        if (!array_key_exists($key, $args) || $args[$key] === null || $args[$key] === '') {
            throw new invalid_parameter_exception("Missing argument '$key'");
        }
        return $args[$key];
    }

    /**
     * Data generator (the same machinery admin/tool/generator uses on live sites).
     * @return \testing_data_generator
     */
    protected static function generator() {
        global $CFG;
        if (self::$generator === null) {
            require_once($CFG->libdir . '/testing/generator/lib.php');
            self::$generator = new \testing_data_generator();
        }
        return self::$generator;
    }

    /**
     * Course module record (with modname) by cmid.
     * @param int $cmid
     * @param string $modname optional expected module
     * @return stdClass
     */
    protected static function cm(int $cmid, string $modname = ''): stdClass {
        $cm = get_coursemodule_from_id($modname, $cmid, 0, false, MUST_EXIST);
        return $cm;
    }

    /**
     * Section record by course + section number.
     * @param int $courseid
     * @param int $sectionnum
     * @return stdClass
     */
    protected static function section(int $courseid, int $sectionnum): stdClass {
        global $DB;
        return $DB->get_record('course_sections', ['course' => $courseid, 'section' => $sectionnum], '*', MUST_EXIST);
    }

    /**
     * Decode base64 content.
     * @param string $b64
     * @return string
     */
    protected static function b64(string $b64): string {
        $data = base64_decode($b64, true);
        if ($data === false) {
            throw new invalid_parameter_exception('content_b64 is not valid base64');
        }
        return $data;
    }

    /**
     * Store a file (replacing an existing one with the same name).
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @param string $filepath
     * @param string $filename
     * @param string $content file content (ignored when $pathname is given)
     * @param string|null $pathname local file to store instead of $content (e.g. a chunked upload)
     * @return \stored_file
     */
    protected static function store_file(int $contextid, string $component, string $filearea, int $itemid,
            string $filepath, string $filename, string $content, ?string $pathname = null): \stored_file {
        global $USER;
        $fs = get_file_storage();
        $filename = clean_param($filename, PARAM_FILE);
        if ($filename === '') {
            throw new invalid_parameter_exception('Invalid filename');
        }
        $filepath = '/' . trim($filepath, '/') . '/';
        $filepath = str_replace('//', '/', $filepath);
        if ($old = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename)) {
            $old->delete();
        }
        $record = [
            'contextid' => $contextid, 'component' => $component, 'filearea' => $filearea,
            'itemid' => $itemid, 'filepath' => $filepath, 'filename' => $filename,
            'userid' => $USER->id,
        ];
        if ($pathname !== null) {
            return $fs->create_file_from_pathname($record, $pathname);
        }
        return $fs->create_file_from_string($record, $content);
    }

    /**
     * File content from args: either inline content_b64 or a finished chunked upload (upload_id).
     * @param array $f
     * @return array [content, pathname|null]
     */
    protected static function file_source(array $f): array {
        if (!empty($f['upload_id'])) {
            return ['', self::upload_path($f['upload_id'])];
        }
        return [self::b64(self::req($f, 'content_b64')), null];
    }

    /**
     * Remove the temp file of a chunked upload once it has been stored.
     * @param string|null $pathname
     */
    protected static function cleanup_upload(?string $pathname): void {
        if ($pathname !== null) {
            @unlink($pathname);
        }
    }

    /**
     * Put files into a fresh draft area of the current user and return its itemid.
     * @param array $files list of {filename, content_b64, filepath?}
     * @return int
     */
    protected static function make_draft(array $files): int {
        global $USER;
        $draftid = file_get_unused_draft_itemid();
        $usercontext = context_user::instance($USER->id);
        foreach ($files as $f) {
            [$content, $pathname] = self::file_source($f);
            self::store_file($usercontext->id, 'user', 'draft', $draftid, $f['filepath'] ?? '/',
                self::req($f, 'filename'), $content, $pathname);
            self::cleanup_upload($pathname);
        }
        return $draftid;
    }

    /**
     * Pluginfile placeholder URL for HTML.
     * @param string $filepath
     * @param string $filename
     * @return string
     */
    protected static function placeholder(string $filepath, string $filename): string {
        $filepath = trim($filepath, '/');
        $parts = $filepath === '' ? [] : explode('/', $filepath);
        $parts[] = $filename;
        return '@@PLUGINFILE@@/' . implode('/', array_map('rawurlencode', $parts));
    }

    /**
     * File records of a context.
     * @param int $contextid
     * @param string|null $component
     * @return array
     */
    protected static function files_in_context(int $contextid, ?string $component = null): array {
        global $DB;
        $params = ['contextid' => $contextid];
        $where = "contextid = :contextid AND filename <> '.'";
        if ($component) {
            $where .= ' AND component = :component';
            $params['component'] = $component;
        }
        $recs = $DB->get_records_select('files', $where, $params, 'component, filearea, itemid, filepath, filename',
            'id, component, filearea, itemid, filepath, filename, mimetype, filesize');
        return array_values(array_map(fn($r) => (array)$r, $recs));
    }

    /**
     * Clear caches after edits.
     * @param int $courseid
     */
    protected static function rebuild(int $courseid): void {
        rebuild_course_cache($courseid, true);
    }

    /**
     * Default file area for the main HTML text of a module.
     * @param string $modname
     * @return array [field, filearea]
     */
    protected static function main_text_area(string $modname): array {
        return $modname === 'page' ? ['content', 'content'] : ['intro', 'intro'];
    }

    // ---------------------------------------------------------------------------------------------
    // Info.
    // ---------------------------------------------------------------------------------------------

    /**
     * Health check.
     * @param array $args
     * @return array
     */
    protected static function ping(array $args): array {
        global $CFG, $USER;
        return ['ok' => true, 'release' => $CFG->release, 'version' => $CFG->version,
            'user' => $USER->username, 'wwwroot' => $CFG->wwwroot, 'actions' => self::ACTIONS];
    }

    /**
     * Course categories.
     * @param array $args
     * @return array
     */
    protected static function list_categories(array $args): array {
        global $DB;
        $cats = $DB->get_records('course_categories', null, 'sortorder', 'id, name, parent, path, coursecount, visible');
        return ['categories' => array_values(array_map(fn($c) => (array)$c, $cats))];
    }

    /**
     * All courses.
     * @param array $args
     * @return array
     */
    protected static function list_courses(array $args): array {
        global $DB;
        $courses = $DB->get_records_select('course', 'id <> :site', ['site' => SITEID], 'category, sortorder',
            'id, category, fullname, shortname, format, visible, startdate, enablecompletion, timemodified');
        return ['courses' => array_values(array_map(fn($c) => (array)$c, $courses))];
    }

    /**
     * Find course of a course module.
     * @param array $args
     * @return array
     */
    protected static function resolve_cm(array $args): array {
        $cm = self::cm((int)self::req($args, 'cmid'));
        return ['cmid' => (int)$cm->id, 'courseid' => (int)$cm->course, 'modname' => $cm->modname,
            'instance' => (int)$cm->instance, 'name' => $cm->name];
    }

    /**
     * Full course structure: sections, activities, settings, content, files.
     * args: courseid, include_content (bool, default true)
     * @param array $args
     * @return array
     */
    protected static function get_course_structure(array $args): array {
        global $DB, $CFG;
        $courseid = (int)self::req($args, 'courseid');
        $withcontent = $args['include_content'] ?? true;
        $course = get_course($courseid);
        $modinfo = get_fast_modinfo($course);
        $coursectx = context_course::instance($courseid);

        $out = [
            'course' => [
                'id' => (int)$course->id, 'fullname' => $course->fullname, 'shortname' => $course->shortname,
                'category' => (int)$course->category, 'format' => $course->format, 'visible' => (int)$course->visible,
                'summary' => $course->summary, 'enablecompletion' => (int)$course->enablecompletion,
                'startdate' => (int)$course->startdate, 'enddate' => (int)$course->enddate,
                'url' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            ],
            'sections' => [],
        ];

        $sectionfiles = [];
        foreach (self::files_in_context($coursectx->id, 'course') as $f) {
            if ($f['filearea'] === 'section') {
                $sectionfiles[$f['itemid']][] = $f['filepath'] . $f['filename'];
            }
        }

        foreach ($modinfo->get_section_info_all() as $si) {
            $section = [
                'id' => (int)$si->id, 'section' => (int)$si->section, 'name' => $si->name,
                'displayname' => get_section_name($course, $si), 'visible' => (int)$si->visible,
                'availability' => $si->availability, 'component' => $si->component,
                'modules' => [],
            ];
            if ($withcontent) {
                $section['summary'] = $si->summary;
                $section['files'] = $sectionfiles[$si->id] ?? [];
            }
            foreach ($modinfo->sections[$si->section] ?? [] as $cmid) {
                $cm = $modinfo->get_cm($cmid);
                if (!empty($cm->deletioninprogress)) {
                    continue;
                }
                $m = [
                    'cmid' => (int)$cm->id, 'modname' => $cm->modname, 'name' => $cm->name,
                    'instance' => (int)$cm->instance, 'visible' => (int)$cm->visible, 'indent' => (int)$cm->indent,
                    'availability' => $cm->availability, 'completion' => (int)$cm->completion,
                    'completionview' => (int)$cm->completionview, 'completionexpected' => (int)$cm->completionexpected,
                    'completionpassgrade' => (int)$cm->completionpassgrade,
                    'url' => $cm->url ? $cm->url->out(false) : null,
                ];
                if ($withcontent) {
                    $instance = $DB->get_record($cm->modname, ['id' => $cm->instance]);
                    $m['settings'] = $instance ? (array)$instance : null;
                    $m['files'] = array_map(fn($f) => $f['filearea'] . ':' . $f['itemid'] . $f['filepath'] . $f['filename'],
                        self::files_in_context(context_module::instance($cm->id)->id));
                    if ($cm->modname === 'book') {
                        $m['chapters'] = array_values(array_map(fn($c) => (array)$c, $DB->get_records('book_chapters',
                            ['bookid' => $cm->instance], 'pagenum',
                            'id, pagenum, subchapter, title, hidden, content, contentformat')));
                    }
                    if ($cm->modname === 'quiz') {
                        $m['questions'] = self::quiz_questions((int)$cm->instance);
                    }
                }
                $section['modules'][] = $m;
            }
            $out['sections'][] = $section;
        }
        return $out;
    }

    /**
     * Questions in a quiz (latest versions).
     * @param int $quizid
     * @return array
     */
    protected static function quiz_questions(int $quizid): array {
        global $DB;
        $sql = "SELECT s.id AS slotid, s.slot, s.page, s.maxmark, q.id AS questionid, q.name, q.qtype, q.questiontext
                  FROM {quiz_slots} s
                  JOIN {question_references} qr ON qr.itemid = s.id AND qr.component = 'mod_quiz'
                       AND qr.questionarea = 'slot'
                  JOIN {question_versions} qv ON qv.questionbankentryid = qr.questionbankentryid
                  JOIN {question} q ON q.id = qv.questionid
                 WHERE s.quizid = :quizid
                   AND qv.version = (SELECT MAX(v.version) FROM {question_versions} v
                                      WHERE v.questionbankentryid = qr.questionbankentryid)
              ORDER BY s.slot";
        return array_values(array_map(fn($r) => (array)$r, $DB->get_records_sql($sql, ['quizid' => $quizid])));
    }

    // ---------------------------------------------------------------------------------------------
    // Courses.
    // ---------------------------------------------------------------------------------------------

    /**
     * Create course.
     * args: fullname, shortname, categoryid?, summary?, format? (topics), numsections?, plus any course field.
     * @param array $args
     * @return array
     */
    protected static function create_course(array $args): array {
        $data = (object)$args;
        $data->fullname = self::req($args, 'fullname');
        $data->shortname = self::req($args, 'shortname');
        $data->category = (int)($args['categoryid'] ?? $args['category'] ?? \core_course_category::get_default()->id);
        unset($data->categoryid);
        $data->summary = $args['summary'] ?? '';
        $data->summaryformat = $args['summaryformat'] ?? FORMAT_HTML;
        $data->format = $args['format'] ?? 'topics';
        $data->numsections = (int)($args['numsections'] ?? 0);
        $data->enablecompletion = $args['enablecompletion'] ?? 1;
        $data->showcompletionconditions = $args['showcompletionconditions'] ?? 1;
        $data->startdate = $args['startdate'] ?? usergetmidnight(time());
        $course = create_course($data);
        // Make sure the requested number of sections exists regardless of format defaults.
        if ($data->numsections > 0) {
            course_create_sections_if_missing($course, range(0, $data->numsections));
        }
        return ['courseid' => (int)$course->id, 'shortname' => $course->shortname,
            'url' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false)];
    }

    /**
     * Update course fields.
     * args: courseid, fields {fullname, summary, visible, ...}
     * @param array $args
     * @return array
     */
    protected static function update_course(array $args): array {
        $data = (object)($args['fields'] ?? []);
        $data->id = (int)self::req($args, 'courseid');
        update_course($data);
        return ['courseid' => $data->id, 'updated' => array_keys((array)($args['fields'] ?? []))];
    }

    /**
     * Delete course (irreversible).
     * @param array $args
     * @return array
     */
    protected static function delete_course(array $args): array {
        $courseid = (int)self::req($args, 'courseid');
        if (empty($args['confirm'])) {
            throw new invalid_parameter_exception('Set confirm=true to delete a course');
        }
        ob_start();
        $ok = delete_course($courseid, false);
        ob_end_clean();
        fix_course_sortorder();
        return ['deleted' => (bool)$ok, 'courseid' => $courseid];
    }

    // ---------------------------------------------------------------------------------------------
    // Sections.
    // ---------------------------------------------------------------------------------------------

    /**
     * Apply section field changes.
     * @param int $courseid
     * @param stdClass $section
     * @param array $fields
     */
    protected static function apply_section_fields(int $courseid, stdClass $section, array $fields): void {
        $data = [];
        foreach (['name', 'summary', 'summaryformat', 'availability'] as $k) {
            if (array_key_exists($k, $fields)) {
                $data[$k] = is_array($fields[$k]) ? json_encode($fields[$k]) : $fields[$k];
            }
        }
        if (isset($data['summary']) && !isset($data['summaryformat'])) {
            $data['summaryformat'] = FORMAT_HTML;
        }
        if ($data) {
            course_update_section($courseid, $section, (object)$data);
        }
        if (array_key_exists('visible', $fields)) {
            set_section_visible($courseid, $section->section, (int)$fields['visible']);
        }
    }

    /**
     * Create section.
     * args: courseid, position? (0 = end), name?, summary?, visible?, availability?
     * @param array $args
     * @return array
     */
    protected static function create_section(array $args): array {
        $courseid = (int)self::req($args, 'courseid');
        $section = course_create_section($courseid, (int)($args['position'] ?? 0));
        self::apply_section_fields($courseid, $section, $args);
        self::rebuild($courseid);
        return ['sectionid' => (int)$section->id, 'section' => (int)$section->section];
    }

    /**
     * Update section.
     * args: courseid, section (number), fields {name, summary, visible, availability}
     * @param array $args
     * @return array
     */
    protected static function update_section(array $args): array {
        $courseid = (int)self::req($args, 'courseid');
        $section = self::section($courseid, (int)self::req($args, 'section'));
        self::apply_section_fields($courseid, $section, $args['fields'] ?? []);
        self::rebuild($courseid);
        return ['sectionid' => (int)$section->id, 'section' => (int)$section->section];
    }

    /**
     * Delete section and its activities.
     * args: courseid, section
     * @param array $args
     * @return array
     */
    protected static function delete_section(array $args): array {
        $courseid = (int)self::req($args, 'courseid');
        $num = (int)self::req($args, 'section');
        if ($num === 0) {
            throw new invalid_parameter_exception('Section 0 cannot be deleted');
        }
        $ok = course_delete_section($courseid, $num, true);
        self::rebuild($courseid);
        return ['deleted' => (bool)$ok];
    }

    /**
     * Move section.
     * args: courseid, section, destination
     * @param array $args
     * @return array
     */
    protected static function move_section(array $args): array {
        $courseid = (int)self::req($args, 'courseid');
        $ok = move_section_to(get_course($courseid), (int)self::req($args, 'section'),
            (int)self::req($args, 'destination'));
        self::rebuild($courseid);
        return ['moved' => (bool)$ok];
    }

    // ---------------------------------------------------------------------------------------------
    // Activities.
    // ---------------------------------------------------------------------------------------------

    /**
     * Add an activity/resource of any type.
     * args: courseid, section, modname, fields {name, intro, content, ... any module/cm field},
     *       draft_files? {field: [{filename, content_b64}]} for modules that take a draft area (resource/folder: 'files'),
     *       files? [{filearea?, itemid?, filepath?, filename, content_b64}] stored into the module's areas after creation.
     * @param array $args
     * @return array
     */
    protected static function add_module(array $args): array {
        global $DB;
        $courseid = (int)self::req($args, 'courseid');
        $modname = clean_param(self::req($args, 'modname'), PARAM_PLUGIN);
        if (!$DB->record_exists('modules', ['name' => $modname, 'visible' => 1])) {
            throw new invalid_parameter_exception("Module '$modname' is not installed or not enabled");
        }
        $record = $args['fields'] ?? [];
        foreach ($record as $k => $v) {
            if (is_array($v) && in_array($k, ['availability'], true)) {
                $record[$k] = json_encode($v);
            }
        }
        $record['course'] = $courseid;
        $record['section'] = (int)($args['section'] ?? 0);
        if (isset($record['intro']) && !isset($record['introformat'])) {
            $record['introformat'] = FORMAT_HTML;
        }
        if ($modname === 'page' && isset($record['content']) && !isset($record['contentformat'])) {
            $record['contentformat'] = FORMAT_HTML;
        }
        if (empty($record['intro']) && empty($record['introeditor'])) {
            $record['intro'] = ' '; // Avoid generator placeholder text.
            $record['introformat'] = FORMAT_HTML;
        }
        if ($modname === 'assign') {
            // Submission plugin settings the edit form would normally supply.
            $record += [
                'assignsubmission_file_maxfiles' => get_config('assignsubmission_file', 'maxfiles') ?: 20,
                'assignsubmission_file_maxsizebytes' => 0,
                'assignsubmission_file_filetypes' => '',
                'assignsubmission_onlinetext_wordlimit' => 0,
                'assignsubmission_onlinetext_wordlimit_enabled' => 0,
            ];
        }
        foreach (($args['draft_files'] ?? []) as $field => $files) {
            $record[$field] = self::make_draft($files);
        }
        self::section($courseid, $record['section']); // Validate.

        $instance = self::generator()->get_plugin_generator('mod_' . $modname)->create_instance((object)$record);
        $cmid = (int)$instance->cmid;

        $stored = [];
        if (!empty($args['files'])) {
            $ctx = context_module::instance($cmid);
            [, $defaultarea] = self::main_text_area($modname);
            foreach ($args['files'] as $f) {
                [$content, $pathname] = self::file_source($f);
                $file = self::store_file($ctx->id, 'mod_' . $modname, $f['filearea'] ?? $defaultarea,
                    (int)($f['itemid'] ?? 0), $f['filepath'] ?? '/', self::req($f, 'filename'),
                    $content, $pathname);
                self::cleanup_upload($pathname);
                $stored[] = self::placeholder($file->get_filepath(), $file->get_filename());
            }
        }
        self::rebuild($courseid);
        return ['cmid' => $cmid, 'instance' => (int)$instance->id, 'modname' => $modname,
            'name' => $instance->name ?? '', 'files' => $stored,
            'url' => (new \moodle_url("/mod/$modname/view.php", ['id' => $cmid]))->out(false)];
    }

    /**
     * Update activity settings directly.
     * args: cmid, instance {column: value} (module table), cm {visible, availability, completion, completionview,
     *       completionexpected, completionpassgrade, completiongradeitemnumber, indent, idnumber, showdescription, groupmode}
     * @param array $args
     * @return array
     */
    protected static function update_module(array $args): array {
        global $DB, $CFG;
        $cm = self::cm((int)self::req($args, 'cmid'));
        $updated = [];
        $ignored = [];

        $fields = $args['instance'] ?? [];
        if ($fields) {
            $columns = $DB->get_columns($cm->modname);
            $rec = ['id' => $cm->instance];
            foreach ($fields as $k => $v) {
                if (isset($columns[$k]) && !in_array($k, ['id', 'course'], true)) {
                    $rec[$k] = is_array($v) ? json_encode($v) : $v;
                    $updated[] = $k;
                } else {
                    $ignored[] = $k;
                }
            }
            if (isset($columns['timemodified'])) {
                $rec['timemodified'] = time();
            }
            if (isset($rec['intro']) && !isset($rec['introformat'])) {
                $rec['introformat'] = FORMAT_HTML;
            }
            $DB->update_record($cm->modname, (object)$rec);
        }

        $cmfields = $args['cm'] ?? [];
        $allowed = ['availability', 'completion', 'completionview', 'completionexpected', 'completionpassgrade',
            'completiongradeitemnumber', 'indent', 'idnumber', 'showdescription', 'groupmode', 'groupingid'];
        $cmrec = ['id' => $cm->id];
        foreach ($cmfields as $k => $v) {
            if ($k === 'visible') {
                set_coursemodule_visible($cm->id, (int)$v);
                $updated[] = 'cm.visible';
            } else if (in_array($k, $allowed, true)) {
                $cmrec[$k] = is_array($v) ? json_encode($v) : $v;
                $updated[] = 'cm.' . $k;
            } else {
                $ignored[] = 'cm.' . $k;
            }
        }
        if (count($cmrec) > 1) {
            $DB->update_record('course_modules', (object)$cmrec);
        }

        // Keep gradebook and calendar in sync.
        $instance = $DB->get_record($cm->modname, ['id' => $cm->instance]);
        $cm = self::cm($cm->id);
        $warnings = [];
        if ($fields) {
            require_once($CFG->dirroot . "/mod/{$cm->modname}/lib.php");
            $instance->cmidnumber = $cm->idnumber;
            $instance->courseid = $cm->course;
            $instance->coursemodule = $cm->id;
            $fn = $cm->modname . '_grade_item_update';
            if (function_exists($fn) && plugin_supports('mod', $cm->modname, FEATURE_GRADE_HAS_GRADE, false)) {
                try {
                    $fn($instance);
                } catch (\Throwable $e) {
                    $warnings[] = 'grade item: ' . $e->getMessage();
                }
            }
            try {
                course_module_calendar_event_update_process($instance, $cm);
            } catch (\Throwable $e) {
                $warnings[] = 'calendar: ' . $e->getMessage();
            }
        }
        self::rebuild($cm->course);
        return ['cmid' => (int)$cm->id, 'updated' => $updated, 'ignored' => $ignored, 'warnings' => $warnings];
    }

    /**
     * Delete activity.
     * @param array $args
     * @return array
     */
    protected static function delete_module(array $args): array {
        $cm = self::cm((int)self::req($args, 'cmid'));
        course_delete_module($cm->id);
        self::rebuild($cm->course);
        return ['deleted' => (int)$cm->id, 'name' => $cm->name];
    }

    /**
     * Move activity.
     * args: cmid, section (number), before_cmid?
     * @param array $args
     * @return array
     */
    protected static function move_module(array $args): array {
        $cm = self::cm((int)self::req($args, 'cmid'));
        $section = self::section((int)$cm->course, (int)self::req($args, 'section'));
        $before = !empty($args['before_cmid']) ? self::cm((int)$args['before_cmid']) : null;
        moveto_module($cm, $section, $before);
        self::rebuild($cm->course);
        return ['moved' => (int)$cm->id, 'section' => (int)$section->section];
    }

    /**
     * Duplicate activity (placed right after the original).
     * @param array $args
     * @return array
     */
    protected static function duplicate_module(array $args): array {
        $cm = self::cm((int)self::req($args, 'cmid'));
        $course = get_course($cm->course);
        $cminfo = get_fast_modinfo($course)->get_cm($cm->id);
        $new = duplicate_module($course, $cminfo);
        self::rebuild($cm->course);
        return ['cmid' => $new ? (int)$new->id : null, 'name' => $new ? $new->name : null];
    }

    // ---------------------------------------------------------------------------------------------
    // Files & images.
    // ---------------------------------------------------------------------------------------------

    /**
     * Resolve target context + default component.
     * @param array $args cmid | courseid
     * @return array [context, component]
     */
    protected static function target_context(array $args): array {
        if (!empty($args['cmid'])) {
            $cm = self::cm((int)$args['cmid']);
            return [context_module::instance($cm->id), 'mod_' . $cm->modname];
        }
        if (!empty($args['courseid'])) {
            return [context_course::instance((int)$args['courseid']), 'course'];
        }
        throw new invalid_parameter_exception('Give cmid or courseid');
    }

    /**
     * Upload any file into any file area.
     * args: cmid|courseid, component?, filearea, itemid?, filepath?, filename, content_b64 | upload_id
     * (upload_id = a file sent in chunks with begin_upload + append_upload, for large files)
     * @param array $args
     * @return array
     */
    protected static function upload_file(array $args): array {
        [$ctx, $component] = self::target_context($args);
        [$content, $pathname] = self::file_source($args);
        $file = self::store_file($ctx->id, $args['component'] ?? $component, self::req($args, 'filearea'),
            (int)($args['itemid'] ?? 0), $args['filepath'] ?? '/', self::req($args, 'filename'),
            $content, $pathname);
        self::cleanup_upload($pathname);
        return ['fileid' => (int)$file->get_id(), 'size' => (int)$file->get_filesize(),
            'mimetype' => $file->get_mimetype(),
            'placeholder' => self::placeholder($file->get_filepath(), $file->get_filename())];
    }

    /**
     * List files of a module or course context.
     * @param array $args cmid|courseid
     * @return array
     */
    protected static function list_files(array $args): array {
        [$ctx] = self::target_context($args);
        return ['files' => self::files_in_context($ctx->id)];
    }

    /**
     * Read a stored file (base64), optionally a byte range for large files.
     * args: fileid, offset?, length?
     * @param array $args
     * @return array
     */
    protected static function read_file(array $args): array {
        $file = get_file_storage()->get_file_by_id((int)self::req($args, 'fileid'));
        if (!$file || $file->is_directory()) {
            throw new invalid_parameter_exception('File not found');
        }
        $size = (int)$file->get_filesize();
        $offset = max(0, (int)($args['offset'] ?? 0));
        $length = (int)($args['length'] ?? 8 * 1024 * 1024);
        $fh = $file->get_content_file_handle();
        fseek($fh, $offset);
        $chunk = $length > 0 ? (string)fread($fh, $length) : '';
        fclose($fh);
        return ['fileid' => (int)$file->get_id(), 'filename' => $file->get_filename(), 'size' => $size,
            'offset' => $offset, 'length' => strlen($chunk), 'eof' => ($offset + strlen($chunk)) >= $size,
            'content_b64' => base64_encode($chunk)];
    }

    /**
     * Delete a stored file.
     * @param array $args fileid
     * @return array
     */
    protected static function delete_file(array $args): array {
        $file = get_file_storage()->get_file_by_id((int)self::req($args, 'fileid'));
        if (!$file) {
            throw new invalid_parameter_exception('File not found');
        }
        $name = $file->get_filename();
        $file->delete();
        return ['deleted' => $name];
    }

    /**
     * Upload an image and insert it into HTML content.
     * Targets: {cmid[, field]} module text (page content / intro), {cmid, chapterid} book chapter,
     *          {courseid, section} section summary.
     * args: filename, content_b64, alt, caption?, width?, position? (end|start)
     * @param array $args
     * @return array
     */
    protected static function add_image(array $args): array {
        global $DB;
        $filename = self::req($args, 'filename');
        $data = self::b64(self::req($args, 'content_b64'));
        $alt = (string)self::req($args, 'alt');

        if (!empty($args['cmid'])) {
            $cm = self::cm((int)$args['cmid']);
            $ctx = context_module::instance($cm->id);
            $courseid = (int)$cm->course;
            if (!empty($args['chapterid'])) {
                $chapter = $DB->get_record('book_chapters', ['id' => (int)$args['chapterid'], 'bookid' => $cm->instance],
                    '*', MUST_EXIST);
                $file = self::store_file($ctx->id, 'mod_book', 'chapter', (int)$chapter->id, '/', $filename, $data);
                $chapter->content = self::insert_html($chapter->content, $file, $alt, $args);
                $chapter->contentformat = FORMAT_HTML;
                $chapter->timemodified = time();
                $DB->update_record('book_chapters', $chapter);
                $DB->set_field('book', 'revision', $DB->get_field('book', 'revision', ['id' => $cm->instance]) + 1,
                    ['id' => $cm->instance]);
                $where = "book chapter {$chapter->id}";
            } else {
                [$deffield, $defarea] = self::main_text_area($cm->modname);
                $field = $args['field'] ?? $deffield;
                $area = $field === 'intro' ? 'intro' : ($args['filearea'] ?? $defarea);
                $file = self::store_file($ctx->id, 'mod_' . $cm->modname, $area, 0, '/', $filename, $data);
                $instance = $DB->get_record($cm->modname, ['id' => $cm->instance], '*', MUST_EXIST);
                if (!property_exists($instance, $field)) {
                    throw new invalid_parameter_exception("Module has no field '$field'");
                }
                $upd = ['id' => $instance->id, $field => self::insert_html((string)$instance->$field, $file, $alt, $args)];
                if (property_exists($instance, $field . 'format')) {
                    $upd[$field . 'format'] = FORMAT_HTML;
                }
                if (property_exists($instance, 'timemodified')) {
                    $upd['timemodified'] = time();
                }
                if ($cm->modname === 'page') {
                    $upd['revision'] = $instance->revision + 1;
                }
                $DB->update_record($cm->modname, (object)$upd);
                $where = "{$cm->modname} {$field}";
            }
        } else {
            $courseid = (int)self::req($args, 'courseid');
            $section = self::section($courseid, (int)self::req($args, 'section'));
            $ctx = context_course::instance($courseid);
            $file = self::store_file($ctx->id, 'course', 'section', (int)$section->id, '/', $filename, $data);
            course_update_section($courseid, $section, (object)[
                'summary' => self::insert_html((string)$section->summary, $file, $alt, $args),
                'summaryformat' => FORMAT_HTML,
            ]);
            $where = "section {$section->section} summary";
        }
        self::rebuild($courseid);
        return ['fileid' => (int)$file->get_id(), 'inserted_into' => $where,
            'placeholder' => self::placeholder('/', $file->get_filename())];
    }

    /**
     * Build <figure> and add it to HTML.
     * @param string $html
     * @param \stored_file $file
     * @param string $alt
     * @param array $args caption, width, position
     * @return string
     */
    protected static function insert_html(string $html, \stored_file $file, string $alt, array $args): string {
        $src = self::placeholder('/', $file->get_filename());
        $attrs = ['src' => $src, 'alt' => $alt, 'class' => 'img-fluid'];
        if (!empty($args['width'])) {
            $attrs['width'] = (int)$args['width'];
        }
        $img = \html_writer::empty_tag('img', $attrs);
        $caption = !empty($args['caption']) ? \html_writer::tag('figcaption', s($args['caption'])) : '';
        $figure = \html_writer::tag('figure', $img . $caption, ['class' => 'figure']);
        if (($args['position'] ?? 'end') === 'start') {
            return $figure . "\n" . $html;
        }
        if (!empty($args['after_text']) && ($pos = strpos($html, $args['after_text'])) !== false) {
            // Insert after the closing tag of the element that contains the given text.
            $close = strpos($html, '</', $pos + strlen($args['after_text']));
            $end = $close !== false ? strpos($html, '>', $close) : false;
            if ($end !== false) {
                return substr($html, 0, $end + 1) . "\n" . $figure . substr($html, $end + 1);
            }
        }
        return $html . "\n" . $figure;
    }

    // ---------------------------------------------------------------------------------------------
    // Book.
    // ---------------------------------------------------------------------------------------------

    /**
     * Add chapter to a book.
     * args: cmid, title, content, subchapter?, hidden?, pagenum? (default: append)
     * @param array $args
     * @return array
     */
    protected static function add_book_chapter(array $args): array {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/mod/book/locallib.php');
        $cm = self::cm((int)self::req($args, 'cmid'), 'book');
        $book = $DB->get_record('book', ['id' => $cm->instance], '*', MUST_EXIST);
        $max = (int)$DB->get_field_sql('SELECT MAX(pagenum) FROM {book_chapters} WHERE bookid = ?', [$book->id]);
        $pagenum = isset($args['pagenum']) ? max(1, min((int)$args['pagenum'], $max + 1)) : $max + 1;
        if ($pagenum <= $max) {
            $DB->execute('UPDATE {book_chapters} SET pagenum = pagenum + 1 WHERE bookid = ? AND pagenum >= ?',
                [$book->id, $pagenum]);
        }
        $now = time();
        $id = $DB->insert_record('book_chapters', (object)[
            'bookid' => $book->id, 'pagenum' => $pagenum, 'subchapter' => (int)!empty($args['subchapter']),
            'title' => self::req($args, 'title'), 'content' => $args['content'] ?? '', 'contentformat' => FORMAT_HTML,
            'hidden' => (int)!empty($args['hidden']), 'timecreated' => $now, 'timemodified' => $now, 'importsrc' => '',
        ]);
        $DB->set_field('book', 'revision', $book->revision + 1, ['id' => $book->id]);
        book_preload_chapters($book); // Normalises pagenum/subchapter structure.
        self::rebuild($cm->course);
        return ['chapterid' => (int)$id, 'pagenum' => $pagenum];
    }

    /**
     * Update a chapter.
     * args: cmid, chapterid, fields {title, content, hidden, subchapter}
     * @param array $args
     * @return array
     */
    protected static function update_book_chapter(array $args): array {
        global $DB;
        $cm = self::cm((int)self::req($args, 'cmid'), 'book');
        $chapter = $DB->get_record('book_chapters', ['id' => (int)self::req($args, 'chapterid'), 'bookid' => $cm->instance],
            '*', MUST_EXIST);
        $fields = array_intersect_key($args['fields'] ?? [], array_flip(['title', 'content', 'hidden', 'subchapter']));
        foreach ($fields as $k => $v) {
            $chapter->$k = $v;
        }
        $chapter->contentformat = FORMAT_HTML;
        $chapter->timemodified = time();
        $DB->update_record('book_chapters', $chapter);
        $DB->set_field('book', 'revision', $DB->get_field('book', 'revision', ['id' => $cm->instance]) + 1,
            ['id' => $cm->instance]);
        self::rebuild($cm->course);
        return ['chapterid' => (int)$chapter->id, 'updated' => array_keys($fields)];
    }

    /**
     * Delete a chapter (and its files).
     * args: cmid, chapterid
     * @param array $args
     * @return array
     */
    protected static function delete_book_chapter(array $args): array {
        global $DB;
        $cm = self::cm((int)self::req($args, 'cmid'), 'book');
        $chapter = $DB->get_record('book_chapters', ['id' => (int)self::req($args, 'chapterid'), 'bookid' => $cm->instance],
            '*', MUST_EXIST);
        get_file_storage()->delete_area_files(context_module::instance($cm->id)->id, 'mod_book', 'chapter', $chapter->id);
        $DB->delete_records('book_chapters', ['id' => $chapter->id]);
        $DB->execute('UPDATE {book_chapters} SET pagenum = pagenum - 1 WHERE bookid = ? AND pagenum > ?',
            [$cm->instance, $chapter->pagenum]);
        $DB->set_field('book', 'revision', $DB->get_field('book', 'revision', ['id' => $cm->instance]) + 1,
            ['id' => $cm->instance]);
        self::rebuild($cm->course);
        return ['deleted' => (int)$chapter->id];
    }

    // ---------------------------------------------------------------------------------------------
    // Questions / quiz.
    // ---------------------------------------------------------------------------------------------

    /**
     * Import questions (GIFT or Moodle XML) into a quiz's (or qbank's) own question bank,
     * and optionally add them to the quiz.
     * args: cmid, format (gift|xml), content (text) or content_b64, add_to_quiz? (true), maxmark?, page? (0=last),
     *       questions_per_page?
     * @param array $args
     * @return array
     */
    protected static function import_questions(array $args): array {
        global $CFG, $DB;
        require_once($CFG->libdir . '/questionlib.php');
        require_once($CFG->dirroot . '/question/format.php');
        $format = clean_param($args['format'] ?? 'gift', PARAM_PLUGIN);
        $formatfile = $CFG->dirroot . "/question/format/$format/format.php";
        if (!file_exists($formatfile)) {
            throw new invalid_parameter_exception("Unknown question format '$format'");
        }
        require_once($formatfile);

        $cm = self::cm((int)self::req($args, 'cmid'));
        if (!in_array($cm->modname, ['quiz', 'qbank'], true)) {
            throw new invalid_parameter_exception('cmid must be a quiz or a question bank (qbank) activity');
        }
        $course = get_course($cm->course);
        $context = context_module::instance($cm->id);
        $category = question_get_default_category($context->id, true);
        if (!$category) {
            throw new moodle_exception('Could not get/create default question category');
        }

        $content = isset($args['content_b64']) ? self::b64($args['content_b64']) : (string)self::req($args, 'content');
        $dir = make_request_directory();
        $tmpfile = $dir . '/import.' . ($format === 'xml' ? 'xml' : 'txt');
        file_put_contents($tmpfile, $content);

        $class = 'qformat_' . $format;
        $qformat = new $class();
        $qformat->setCategory($category);
        $qformat->setContexts([$context]);
        $qformat->setCourse($course);
        $qformat->setFilename($tmpfile);
        $qformat->setRealfilename(basename($tmpfile));
        $qformat->setMatchgrades('nearest');
        $qformat->setCatfromfile(false);
        $qformat->setContextfromfile(false);
        $qformat->setStoponerror(true);

        ob_start();
        $ok = $qformat->importpreprocess() && $qformat->importprocess() && $qformat->importpostprocess();
        $log = trim(html_to_text(ob_get_clean(), 0, false));
        if (!$ok) {
            throw new moodle_exception('importerror', 'question', '', null, \core_text::substr($log, 0, 3000));
        }
        $ids = array_map('intval', $qformat->questionids);

        $added = 0;
        if ($cm->modname === 'quiz' && ($args['add_to_quiz'] ?? true)) {
            require_once($CFG->dirroot . '/mod/quiz/locallib.php');
            $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
            foreach ($ids as $qid) {
                quiz_add_quiz_question($qid, $quiz, (int)($args['page'] ?? 0),
                    isset($args['maxmark']) ? (float)$args['maxmark'] : null);
                $added++;
            }
            if (!empty($args['questions_per_page'])) {
                quiz_repaginate_questions($quiz->id, (int)$args['questions_per_page']);
            }
            \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
        }
        self::rebuild($cm->course);
        $names = $ids ? $DB->get_records_list('question', 'id', $ids, 'id', 'id, name, qtype') : [];
        return ['imported' => count($ids), 'added_to_quiz' => $added, 'categoryid' => (int)$category->id,
            'questions' => array_values(array_map(fn($q) => (array)$q, $names))];
    }

    /**
     * Remove a question slot from a quiz.
     * args: cmid, slot
     * @param array $args
     * @return array
     */
    protected static function remove_quiz_slot(array $args): array {
        $cm = self::cm((int)self::req($args, 'cmid'), 'quiz');
        $settings = \mod_quiz\quiz_settings::create((int)$cm->instance);
        $settings->get_structure()->remove_slot((int)self::req($args, 'slot'));
        \mod_quiz\quiz_settings::create((int)$cm->instance)->get_grade_calculator()->recompute_quiz_sumgrades();
        self::rebuild($cm->course);
        return ['removed_slot' => (int)$args['slot']];
    }

    /**
     * Repaginate quiz.
     * args: cmid, questions_per_page (0 = all on one page)
     * @param array $args
     * @return array
     */
    protected static function repaginate_quiz(array $args): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $cm = self::cm((int)self::req($args, 'cmid'), 'quiz');
        $perpage = (int)($args['questions_per_page'] ?? 1);
        quiz_repaginate_questions($cm->instance, $perpage);
        $DB->set_field('quiz', 'questionsperpage', $perpage, ['id' => $cm->instance]);
        return ['questions_per_page' => $perpage];
    }

    // ---------------------------------------------------------------------------------------------
    // Backup / restore.
    // ---------------------------------------------------------------------------------------------

    /**
     * Create a .mbz course backup (without user data). Download with read_file.
     * args: courseid
     * @param array $args
     * @return array
     */
    protected static function backup_course(array $args): array {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $courseid = (int)self::req($args, 'courseid');
        get_course($courseid);
        $bc = new \backup_controller(\backup::TYPE_1COURSE, $courseid, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_YES, \backup::MODE_GENERAL, $USER->id);
        $plan = $bc->get_plan();
        foreach (['users' => false, 'role_assignments' => false, 'logs' => false, 'grade_histories' => false,
                'userscompletion' => false, 'comments' => false, 'badges' => true] as $name => $value) {
            if ($plan->setting_exists($name)) {
                $setting = $plan->get_setting($name);
                if ($setting->get_status() == \backup_setting::NOT_LOCKED) {
                    $setting->set_value($value);
                }
            }
        }
        $filename = \backup_plan_dbops::get_default_backup_filename($bc->get_format(), $bc->get_type(), $bc->get_id(),
            $plan->get_setting('users')->get_value(), $plan->get_setting('anonymize')->get_value());
        $plan->get_setting('filename')->set_value($filename);
        $bc->finish_ui();
        $bc->execute_plan();
        $results = $bc->get_results();
        $bc->destroy();
        /** @var \stored_file $file */
        $file = $results['backup_destination'] ?? null;
        if (!$file) {
            throw new moodle_exception('Backup finished but no file was produced');
        }
        return ['fileid' => (int)$file->get_id(), 'filename' => $file->get_filename(),
            'size' => (int)$file->get_filesize()];
    }

    /**
     * Start a chunked upload (for large .mbz files).
     * @param array $args
     * @return array
     */
    protected static function begin_upload(array $args): array {
        $id = random_string(24);
        $path = make_temp_directory('local_claudeapi') . '/' . $id . '.bin';
        file_put_contents($path, '');
        return ['upload_id' => $id];
    }

    /**
     * Append a base64 chunk.
     * args: upload_id, content_b64
     * @param array $args
     * @return array
     */
    protected static function append_upload(array $args): array {
        $path = self::upload_path(self::req($args, 'upload_id'));
        file_put_contents($path, self::b64(self::req($args, 'content_b64')), FILE_APPEND);
        clearstatcache(true, $path);
        return ['size' => filesize($path)];
    }

    /**
     * Path of an upload.
     * @param string $id
     * @return string
     */
    protected static function upload_path(string $id): string {
        $id = clean_param($id, PARAM_ALPHANUM);
        $path = make_temp_directory('local_claudeapi') . '/' . $id . '.bin';
        if ($id === '' || !file_exists($path)) {
            throw new invalid_parameter_exception('Unknown upload_id');
        }
        return $path;
    }

    /**
     * Restore an uploaded .mbz as a NEW course.
     * args: upload_id, categoryid?, fullname?, shortname?
     * @param array $args
     * @return array
     */
    protected static function restore_course(array $args): array {
        global $CFG, $USER, $DB;
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        $mbz = self::upload_path(self::req($args, 'upload_id'));
        $categoryid = (int)($args['categoryid'] ?? \core_course_category::get_default()->id);

        $backupdir = \restore_controller::get_tempdir_name(SITEID, $USER->id);
        $path = make_backup_temp_directory($backupdir);
        get_file_packer('application/vnd.moodle.backup')->extract_to_pathname($mbz, $path);

        [$fullname, $shortname] = \restore_dbops::calculate_course_names(0,
            get_string('restoringcourse', 'backup'), get_string('restoringcourseshortname', 'backup'));
        $courseid = \restore_dbops::create_new_course($fullname, $shortname, $categoryid);
        $rc = new \restore_controller($backupdir, $courseid, \backup::INTERACTIVE_NO, \backup::MODE_GENERAL,
            $USER->id, \backup::TARGET_NEW_COURSE);
        if (!$rc->execute_precheck()) {
            $results = $rc->get_precheck_results();
            if (!empty($results['errors'])) {
                $rc->destroy();
                delete_course($courseid, false);
                throw new moodle_exception('Restore precheck failed: ' . json_encode($results['errors']));
            }
        }
        $rc->execute_plan();
        $rc->destroy();
        @unlink($mbz);

        $upd = ['id' => $courseid];
        if (!empty($args['fullname'])) {
            $upd['fullname'] = $args['fullname'];
        }
        if (!empty($args['shortname'])) {
            $upd['shortname'] = $args['shortname'];
        }
        if (count($upd) > 1) {
            update_course((object)$upd);
        }
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname');
        return ['courseid' => (int)$course->id, 'fullname' => $course->fullname, 'shortname' => $course->shortname];
    }
}
