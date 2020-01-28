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
 * Module to display password policy validation live when changing password.
 *
 * @module     core/check_password_policy
 * @copyright  2020 Peter Burnett <peterburnett@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define(['core/ajax', 'core/utils'], function(ajax, utils) {
    return {
        init: function(username, elementname) {
            // Select only the first input element with matching name.
            document.querySelector("input[id*=" + elementname).addEventListener('keyup', utils.throttle(function() {
                // Store element for later use.
                var password = this;
                // Make AJAX call to the webservice.
                var promises = ajax.call([{
                    methodname: 'core_check_password_policy',
                    args: {
                        username: username,
                        password: this.value
                    }
                }]);

                // Resolve the promise, and use the error div to display as if it was form validation.
                promises[0].then(function(data) {
                    // Select the generated error div for form element.
                    var lookupId = '#id_error_' + password.name;
                    var error = document.querySelector(lookupId);

                    if (data.errmsg != '') {
                        error.style.display = 'block';
                        error.innerHTML = data.errmsg;
                    } else {
                        // If error message is clean hide the div.
                        error.style.display = 'none';
                        error.innerHTML = '';
                    }

                    return;
                }).fail();
            }, 100));
        }
    };
});
