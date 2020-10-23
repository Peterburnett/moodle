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
 * A factory class to create email manager classes. This can be swapped out
 */
class factory {

    /**
     * An instance of the cache_factory class created upon the first request.
     * @var factory
     */
    protected static $instance;

    /**
     * Get an instance of the email factory.
     *
     * @return void
     */
    public static function get_factory(): factory {
        global $CFG;

        if (empty(self::$instance)) {

            // If email should be overridden at the factory level, instantiate the overridden factory.
            if (!empty($CFG->alternative_mail_factory_class)) {
                $factoryclass = $CFG->alternative_mail_factory_class;
                self::$instance = new $factoryclass();
            } else {
                self::$instance = new factory();
            }
        }

        return self::$instance;
    }

    /**
     * Get a new mail manager.
     *
     * @return manager
     */
    public function get_mail_manager(): manager {
        return new manager();
    }
}