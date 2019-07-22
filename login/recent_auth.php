<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Recent Authentication page
 *
 * @package    core
 * @subpackage auth
 * @copyright  2019 Peter Burnett <peterburnett@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require('../config.php');
require_login();

require_once('recent_auth_form.php');

global $PAGE, $USER, $DB, $SESSION;

$id = optional_param('id', SITEID, PARAM_INT);

$systemcontext = context_system::instance();

$PAGE->set_url('/login/recent_auth.php');
$PAGE->set_context($systemcontext);

if (!$course = $DB->get_record('course', array('id' => $id))) {
    print_error('invalidcourseid');
}

$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_pagelayout('admin');
$PAGE->set_course($course);

if (empty($SESSION->wantsurl) || $SESSION->wantsurl == $PAGE->url) {
    // If no redirect URL found, redir to dashboard.
    $successurl = new moodle_url('/my/');
} else {
    $successurl = $SESSION->wantsurl;
}

$mform = new login_recent_auth_form();

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/my/'));

} else if ($data = $mform->get_data()) {
    
    // Get password, and validate it.
    $pw = $data->password;
    $user = $DB->get_record('user', array('id' => $USER->id));
    $auth = validate_internal_user_password($user, $pw);

    if ($auth) {
        // If password successfully validated, redirect to where user was trying to go.
        $DB->set_field('user', 'currentlogin', time(), array('id' => $USER->id));

        // Clear wantsurl.
        unset($SESSION->wantsurl);

        redirect($successurl);
    }
}
// Build page output.
$PAGE->set_title(get_string('recentauth'));
$PAGE->set_heading('HEADER');
echo $OUTPUT->header();
$mform->display();
echo $OUTPUT->footer();

