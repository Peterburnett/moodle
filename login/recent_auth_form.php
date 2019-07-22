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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir.'/formslib.php');
require_once($CFG->dirroot.'/user/lib.php');

/**
 * Recent Authentication form class
 */
class login_recent_auth_form extends moodleform {
    /**
     * Definition function for form class
     */
    public function definition() {
        global $USER, $CFG;
        $mform = $this->_form;

        $mform->addElement('header', 'recentauth', get_string('recentauth'), '');
        $mform->addElement('static', 'recentauthdesc', get_string('recentauthdesc'));
        $mform->addElement('password', 'password', get_string('password'));

        $this->add_action_buttons(true, get_string('recentauthsubmit'));
    }
}

