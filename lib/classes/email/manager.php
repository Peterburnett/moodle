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
 * Email management entry point. Performs all of the functions that are not specific to one email.
 *
 * Any function that should be applied non-specifically to all emails should be performed here.
 *
 * @package   core
 * @since     Moodle 3.11
 * @author    Peter Burnett <peterburnett@catalyst-au.net>
 * @copyright 2020 Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core\email;

defined('MOODLE_INTERNAL') || die();

use coding_exception;

/**
 * A factory class to create email handler classes.
 *
 * This can be swapped out using the
 * $CFG->alternative_email_manager_class. This allows for overriding of the various subfunctions
 * in the mail send process, or a complete override of the entire mail process.
 */
class manager {

    /**
     * The email object passed to the send function.
     *
     * @var email
     */
    protected $email;

    /**
     * Get a new email manager. This will be the entry point for alternative email handler.
     * This should be the only access point to instantiate a new email handler.
     *
     * @return manager
     */
    protected static function get_email_manager(): manager {
        global $CFG;

        if (!empty($CFG->alternative_email_manager_class)) {
            $class = $CFG->alternative_email_manager_class;
            if (class_exists($class)) {
                return new $class();
            }
        }

        return new manager();
    }

    /**
     * Send the provided email.
     *
     * Wrapper function to encapsulate the mutation API. Gets the current email manager
     * based on CFG settings, and then sends the email through it using internal methods.
     * These can be discretely overridden to allow for additional funcions in the email API.
     *
     * @param email $email the email object to send.
     * @return void
     */
    public static function send(email $email) {
        $manager = self::get_email_manager();
        return $manager->send_email($email);
    }

    /**
     * Internal send function. Performs all of the steps to send the email class;
     *
     * @param email $email the email object to send
     * @return boolean the status of the mail send.
     */
    protected function send_email(email $email): bool {
        // We can only send correctly setup emails.
        if (!$email->initialised()) {
            throw new coding_exception('Provided \core\email object is not correctly setup');
        }

        $this->email = $email;

        // Start outputting SMTP debugging if enabled.
        if (!empty($this->email->SMTPDebug)) {
            echo '<pre>' . "\n";
        }

        // Sanity checks for user and data validity.
        // This returns here to match the legacy API return status.
        $exitstatus = $this->email_validity_checks();
        if (!empty($exitstatus)) {
            unset($this->email);
            return $exitstatus;
        }

        // If mail should be diverted for this user, do it now.
        $this->configure_email_diversion();

        // Check if the email should be sent in an other charset then the default UTF-8.
        // This will return the mutated correspondents, to be finalised now that charset transformation is complete.
        $correspondents = $this->configure_alternate_charset();

        // Setup the email headers from provided information.
        $this->configure_email_headers();

        // Now finalise the correspondents with the altered charset version.
        $this->finalise_correspondents($correspondents);

        // DKIM configuration should be the very last action before sending.
        $this->configure_dkim();

        // Now everything is setup and ready, send the email.
        $send = $this->send_mailer();

        // End the SMTP debugging.
        if (!empty($this->email->SMTPDebug)) {
            echo '</pre>';
        }

        // Destroy the internal email object and return.
        unset($this->email);
        return $send;
    }

    /**
     * If email diversion is setup, change the mailer object fields to reflect.
     *
     * @return void
     */
    protected function configure_email_diversion() {
        global $CFG;
        $recipient = $this->email->get_recipient();
        $mailer = $this->email->get_mailer();

        // Setup mail diversion if required.
        if (email_should_be_diverted($recipient->email)) {
            $prevsubject = $mailer->Subject;
            $mailer->Subject = "[DIVERTED {$recipient->email}] $prevsubject";
            $recipient->email = $CFG->divertallemailsto;
        }
    }

    /**
     * This function checks if the email should actually be sent. Boolean results are both exit codes for the email function.
     * Null means that there is no action to be taken.
     *
     * @return boolean|null
     */
    protected function email_validity_checks(): ?bool {
        global $CFG;

        $recipient = $this->email->get_recipient();

        if (empty($recipient) or empty($recipient->id)) {
            debugging('Can not send email to null user', DEBUG_DEVELOPER);
            return false;
        }

        if (empty($recipient->email)) {
            debugging('Can not send email to user without email: '.$recipient->id, DEBUG_DEVELOPER);
            return false;
        }

        if (!empty($recipient->deleted)) {
            debugging('Can not send email to deleted user: '.$recipient->id, DEBUG_DEVELOPER);
            return false;
        }

        if (defined('BEHAT_SITE_RUNNING')) {
            // Fake email sending in behat.
            return true;
        }

        if (!empty($CFG->noemailever)) {
            // Hidden setting for development sites, set in config.php if needed.
            debugging('Not sending email due to $CFG->noemailever config setting', DEBUG_NORMAL);
            return true;
        }

        // Skip mail to suspended users.
        if ((isset($recipient->auth) && $recipient->auth == 'nologin') or (isset($recipient->suspended) && $recipient->suspended)) {
            return true;
        }

        if (!validate_email($recipient->email)) {
            // We can not send emails to invalid addresses - it might create security issue or confuse the mailer.
            debugging("email_to_user: User $recipient->id (".fullname($recipient).")
                 email ($recipient->email) is invalid! Not sending.");
            return false;
        }

        if (over_bounce_threshold($recipient)) {
            debugging("email_to_user: User $recipient->id (".fullname($recipient).") is over bounce threshold! Not sending.");
            return false;
        }

        // TLD .invalid  is specifically reserved for invalid domain names.
        // For More information, see {@link http://tools.ietf.org/html/rfc2606#section-2}.
        if (substr($recipient->email, -8) == '.invalid') {
            debugging("email_to_user     * @return void: User $recipient->id (".fullname($recipient).")
                 email domain ($recipient->email) is invalid! Not sending.");
            return true; // This is not an error.
        }

        // We should continue on to send mail.
        return null;
    }

    /**
     * Performs in place charset mutation of required fields if a different charset is needed.
     *
     * @return array an array of the correspondents post change.
     */
    protected function configure_alternate_charset(): array {
        global $CFG;
        $mailer = $this->email;
        list($temprecipients, $tempreplyto) = $this->email->get_correspondents();

        // If both alternate charset configs are empty, do nothing.
        if ((empty($CFG->sitemailcharset) && empty($CFG->allowusermailcharset))) {
            return [$temprecipients, $tempreplyto];
        }

        // Use the defined site mail charset or eventually the one preferred by the recipient.
        $charset = $CFG->sitemailcharset;
        if (!empty($CFG->allowusermailcharset)) {
            if ($recipientemailcharset = get_user_preferences('mailcharset', '0', $this->user->id)) {
                $charset = $recipientemailcharset;
            }
        }

        // Convert all the necessary strings if the charset is supported.
        $charsets = get_list_of_charsets();
        unset($charsets['UTF-8']);
        if (in_array($charset, $charsets)) {
            $mailer->CharSet  = $charset;
            $mailer->FromName = \core_text::convert($mailer->FromName, 'utf-8', strtolower($charset));
            $mailer->Subject  = \core_text::convert($mailer->Subject, 'utf-8', strtolower($charset));
            $mailer->Body     = \core_text::convert($mailer->Body, 'utf-8', strtolower($charset));
            $mailer->AltBody  = \core_text::convert($mailer->AltBody, 'utf-8', strtolower($charset));

            foreach ($temprecipients as $key => $values) {
                $temprecipients[$key][1] = \core_text::convert($values[1], 'utf-8', strtolower($charset));
            }
            foreach ($tempreplyto as $key => $values) {
                $tempreplyto[$key][1] = \core_text::convert($values[1], 'utf-8', strtolower($charset));
            }
        }

        return [$temprecipients, $tempreplyto];
    }

    /**
     * Add all email headers to the email object. This combines hardcoded mail headers, custom headers defined
     * in the $from object, and originating script, and writes them into the $email object.
     */
    protected function configure_email_headers() {
        global $CFG;
        $mailer = $this->email->get_mailer();
        $sender = $this->email->get_sender();

        // First add hardcoded headers.
        if (!empty($CFG->emailheaders)) {
            $headers = array_map('trim', explode("\n", $CFG->emailheaders));
            // Append to the email.
            foreach ($headers as $header) {
                if (!empty($header)) {
                    $mailer->addCustomHeader($header);
                }
            }
        }

        if (!empty($sender->customheaders)) {
            // Add custom headers.
            if (is_array($sender->customheaders)) {
                foreach ($sender->customheaders as $customheader) {
                    $mailer->addCustomHeader($customheader);
                }
            } else {
                $mailer->addCustomHeader($sender->customheaders);
            }
        }

        // If the X-PHP-Originating-Script email header is on then also add an additional
        // header with details of where exactly in moodle the email was triggered from,
        // either a call to message_send() or to email_to_user().
        if (ini_get('mail.add_x_header')) {

            $stack = debug_backtrace(false);
            // Stack[3] is email_to_user if called from there.
            $origin = $stack[3];

            foreach ($stack as $depth => $call) {
                if ($call['function'] == 'message_send') {
                    $origin = $call;
                }
            }

            $originheader = $CFG->wwwroot . ' => ' . gethostname() . ':'
                . str_replace($CFG->dirroot . '/', '', $origin['file']) . ':' . $origin['line'];
            $mailer->addCustomHeader('X-Moodle-Originating-Script: ' . $originheader);
        }
    }

    /**
     * Takes the temp correspondents that have been setup, and writes them into the mailer.
     *
     * @param array $correspondents the array of temp correspondents
     * @return void
     */
    protected function finalise_correspondents(array $correspondents) {
        list($temprecipients, $tempreplyto) = $correspondents;
        $mailer = $this->email->get_mailer();

        // Write in the recipients now they are finalised.
        foreach ($temprecipients as $values) {
            $mailer->addAddress($values[0], $values[1]);
        }
        foreach ($tempreplyto as $values) {
            $mailer->addReplyTo($values[0], $values[1]);
        }
    }

    /**
     * Adds DKIM headers to the current email object.
     */
    protected function configure_dkim() {
        global $CFG;

        $mailer = $this->email->get_mailer();

        if (!empty($CFG->emaildkimselector)) {
            $domain = substr(strrchr($mailer->From, "@"), 1);
            $pempath = "{$CFG->dataroot}/dkim/{$domain}/{$CFG->emaildkimselector}.private";
            if (file_exists($pempath)) {
                $mailer->DKIM_domain      = $domain;
                $mailer->DKIM_private     = $pempath;
                $mailer->DKIM_selector    = $CFG->emaildkimselector;
                $mailer->DKIM_identity    = $mailer->From;
            } else {
                debugging("Email DKIM selector chosen due to {$mailer->From} but no certificate found at $pempath",
                    DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Sends the current constructed email object. Logs an event if sending fails.
     *
     * @return boolean the status of the mail send.
     */
    protected function send_mailer(): bool {
        $mailer = $this->email->get_mailer();
        $recipient = $this->email->get_recipient();

        if ($mailer->send()) {
            set_send_count($recipient);
            return true;
        } else {
            // Trigger event for failing to send email.
            $sender = $this->email->get_sender();
            $event = \core\event\email_failed::create(array(
                'context' => \context_system::instance(),
                'userid' => $sender->id,
                'relateduserid' => $recipient->id,
                'other' => array(
                    'subject' => $mailer->Subject,
                    'message' => $mailer->Body,
                    'errorinfo' => $mailer->ErrorInfo
                )
            ));
            $event->trigger();
            if (CLI_SCRIPT) {
                mtrace('Error: lib/moodlelib.php email_to_user(): '.$mailer->ErrorInfo);
            }
            return false;
        }
    }
}
