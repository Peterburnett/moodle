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
 * Email management entry point.
 *
 * @package   core
 * @since     Moodle 3.11
 * @author    Peter Burnett <peterburnett@catalyst-au.net>
 * @copyright 2020 Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core\email;

/**
 * A factory class to create email handler classes. This can be swapped out.
 */
class factory {

    /**
     * Get a new email handler. This will be the entry point for alternative email handler.
     *
     * @return handler
     */
    public static function get_email_handler(): handler {
        global $CFG;

        if (!empty($CFG->alternative_email_handler_class)) {
            $class = $CFG->alternative_email_handler_class;
            if (class_exists($class)) {
                return new $class();
            }
        }

        return new handler();
    }
}
