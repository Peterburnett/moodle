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
 * Email object class.
 *
 * This class provides a method to set all of the required fields for the email,
 * and performs email setup that is specific to this exact email. This will then be sent
 * by an email manager class.
 *
 * @package   core
 * @since     Moodle 3.11
 * @author    Peter Burnett <peterburnett@catalyst-au.net>
 * @copyright 2020 Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace core\email;

defined('MOODLE_INTERNAL') || die();

use coding_exception, stdClass, moodle_phpmailer;
/**
 * Email object class.
 *
 * This class provides a method to set all of the required fields for the email,
 * and performs email setup that is specific to this exact email. This will then be sent
 * by an email manager class.

 */
class email {

    /**
     * The target user for the email.
     * @var \stdClass
     */
    protected $recipient;

    /**
     * The target user that sent the email.
     * @var \stdClass
     */
    protected $from;

    /**
     * The email subject.
     * @var string
     */
    protected $subject;

    /**
     * The email text.
     * @var string
     */
    protected $messagetext;

    /**
     * The email HTML message.
     * @var string
     */
    protected $messagehtml;

    /**
     * The email attachment.
     * @var string
     */
    protected $attachment;

    /**
     * The email attachment name.
     * @var string
     */
    protected $attachname;

    /**
     * The target user for the email.
     * @var bool
     */
    protected $usetrueaddress;

    /**
     * The email address to reply to.
     * @var string
     */
    protected $replyto;

    /**
     * The email reply address name.
     * @var string
     */
    protected $replytoname;

    /**
     * The word wrap width.
     * @var int
     */
    protected $wordwrapwidth;

    /**
     * The email object.
     * @var \moodle_phpmailer
     */
    protected $email;

    // Flags to track setup status.
    /**
     * set_replyto completed.
     * @var bool
     */
    protected $flagreplyto;

    /**
     * set_subject completed.
     * @var bool
     */
    protected $flagsubject;

    /**
     * set_content completed.
     * @var bool
     */
    protected $flagcontent;

    /**
     * set_attachment completed.
     * @var bool
     */
    protected $flagattachment;

    /**
     * Setup completed.
     * @var bool
     */
    protected $flagcompleted;

    /**
     * Construct the new email message. Bare minimum is the sender and the target users.
     * Sender can be a string for legacy compatability, however a user is preferred.
     *
     * @param stdClass $recipient the recipient user object
     * @param stdClass|string $sender the sender user object, or a string.
     * @param array $options options to be used in email constructions.
     */
    public function __construct(stdClass $recipient, $sender, array $options = []) {
        $this->email = get_mailer();

        $this->recipient = $recipient;
        $this->from = $sender;

        $this->wordwrapwidth = $options['wordwrapwidth'] ?? 79;
        $this->usetrueaddress = $options['usertrueaddress'] ?? true;

        // Set the process flags to track object construction.
        $this->flagreplyto = false;
        $this->flagsubject = false;
        $this->flagcontent = false;
        $this->flagattachment = false;
        $this->flagcompleted = false;
    }

    /**
     * Set the reply details for the email.
     *
     * @param string $replyto the email address to reply to.
     * @param string $replytoname the display name to  show to reply to.
     */
    public function set_replyto(string $replyto = '', string $replytoname = '') {
        $this->replyto = $replyto;
        $this->replytoname = $replytoname;

        // Now we can configure correspondents.
        $this->configure_correspondents();

        // Set the status flag to mark this step as completed.
        $this->flagreplyto = true;
    }

    /**
     * Sets the subject line for the email.
     *
     * @param string $subject the subject line to be set.
     */
    public function set_subject(string $subject) {
        if (!$this->flagreplyto) {
            throw new coding_exception('set_replyto() must be called before set_subject');
        }

        $this->subject = $subject;

        // Setup metadata here. This configures the sender information.
        $this->configure_email_metadata();

        // Set the status flag to mark this step as completed.
        $this->flagsubject = true;
    }

    /**
     * Sets the body content for the email.
     *
     * @param string $messagetext the plaintext content to set in the body.
     * @param string $messagehtml HTML content to set in the body.
     */
    public function set_content(string $messagetext, string $messagehtml = '') {
        if (!$this->flagsubject) {
            throw new coding_exception('set_subject() must be called before set_content');
        }

        $this->messagetext = $messagetext;
        $this->messagehtml = $messagehtml;

        // Apply mnet filters now that we have some content.
        // This should be done before the content is rendered.
        $this->apply_mnet_filters();

        // Now we can safely render the content into the email.
        $this->configure_email_content();

        // Set the status flag to mark this step as completed.
        $this->flagcontent = true;
    }

    /**
     * Sets the attachment for this email.
     *
     * @param string $attachment the path to the attachment.
     * @param string $attachname the name to attach the file under.
     */
    public function set_attachment(string $attachment = '', string $attachname = '') {
        if (!$this->flagcontent) {
            throw new coding_exception('set_content() must be called before set_attachment');
        }

        $this->attachment = $attachment;
        $this->attachname = $attachname;

        // Attachment sanity checks here.
        $this->configure_email_attachment();

        // Set the status flag to mark this step as completed.
        $this->flagcompleted = true;
    }

    /**
     * Performs mnet rewriting of URLs in email.
     */
    protected function apply_mnet_filters() {
        // If the user is a remote mnet user, parse the email text for URL to the
        // wwwroot and modify the url to direct the user's browser to login at their
        // home site (identity provider - idp) before hitting the link itself.
        if (is_mnet_remote_user($this->recipient)) {
            require_once($CFG->dirroot.'/mnet/lib.php');

            $jumpurl = mnet_get_idp_jump_url($recipient);
            $callback = partial('mnet_sso_apply_indirection', $jumpurl);

            $this->messagetext = preg_replace_callback("%($CFG->wwwroot[^[:space:]]*)%",
                    $callback,
                    $this->messagetext);
            $this->messagehtml = preg_replace_callback("%href=[\"'`]($CFG->wwwroot[\w_:\?=#&@/;.~-]*)[\"'`]%",
                    $callback,
                    $this->messagehtml);
        }
    }

    /**
     * Set up the email correspondents (to and from), and write them into class config.
     * They are set as class field arrays here, and are finalised and decided just prior to sending.
     */
    protected function configure_correspondents() {
        global $CFG, $SITE;

        $email = $this->email;
        $from = $this->from;
        $temprecipients = array();
        $tempreplyto = array();

        // Make sure that we fall back onto some reasonable no-reply address.
        $noreplyaddressdefault = 'noreply@' . get_host_from_url($CFG->wwwroot);
        $noreplyaddress = empty($CFG->noreplyaddress) ? $noreplyaddressdefault : $CFG->noreplyaddress;

        if (!validate_email($noreplyaddress)) {
            debugging('email_to_user: Invalid noreply-email '.s($noreplyaddress));
            $noreplyaddress = $noreplyaddressdefault;
        }

        // Make up an email address for handling bounces.
        if (!empty($CFG->handlebounces)) {
            $modargs = 'B'.base64_encode(pack('V', $this->recipient->id)).substr(md5($this->recipient->email), 0, 16);
            $email->Sender = generate_email_processing_address(0, $modargs);
        } else {
            $email->Sender = $noreplyaddress;
        }

        // Make sure that the explicit replyto is valid, fall back to the implicit one.
        if (!empty($this->replyto) && !validate_email($this->replyto)) {
            debugging('email_to_user: Invalid replyto-email '.s($this->replyto));
            $replyto = $noreplyaddress;
        } else {
            $replyto = $this->replyto;
        }

        if (is_string($from)) { // So we can pass whatever we want if there is need.
            $email->From     = $noreplyaddress;
            $email->FromName = $from;
            // Check if using the true address is true, and the email is in the list of allowed domains for sending email,
            // and that the senders email setting is either displayed to everyone, or display to only other users that are enrolled
            // in a course with the sender.
        } else if ($this->usetrueaddress && can_send_from_real_email_address($from, $this->recipient)) {
            if (!validate_email($from->email)) {
                debugging('email_to_user: Invalid from-email '.s($from->email).' - not sending');
                // Better not to use $noreplyaddress in this case.
                return false;
            }
            $email->From = $from->email;
            $fromdetails = new \stdClass();
            $fromdetails->name = fullname($from);
            $fromdetails->url = preg_replace('#^https?://#', '', $CFG->wwwroot);
            $fromdetails->siteshortname = format_string($SITE->shortname);
            $fromstring = $fromdetails->name;
            if ($CFG->emailfromvia == EMAIL_VIA_ALWAYS) {
                $fromstring = get_string('emailvia', 'core', $fromdetails);
            }
            $email->FromName = $fromstring;
            if (empty($replyto)) {
                $tempreplyto[] = array($from->email, fullname($from));
            }
        } else {
            $email->From = $noreplyaddress;
            $fromdetails = new \stdClass();
            $fromdetails->name = fullname($from);
            $fromdetails->url = preg_replace('#^https?://#', '', $CFG->wwwroot);
            $fromdetails->siteshortname = format_string($SITE->shortname);
            $fromstring = $fromdetails->name;
            if ($CFG->emailfromvia != EMAIL_VIA_NEVER) {
                $fromstring = get_string('emailvia', 'core', $fromdetails);
            }
            $email->FromName = $fromstring;
            if (empty($replyto)) {
                $tempreplyto[] = array($noreplyaddress, get_string('noreplyname'));
            }
        }

        if (!empty($replyto)) {
            $tempreplyto[] = array($replyto, $this->replytoname);
        }

        $temprecipients[] = array($this->recipient->email, fullname($this->recipient));

        // Write temp correspondents to class fields.
        $this->temprecipients = $temprecipients;
        $this->tempreplyto = $tempreplyto;
    }

    /**
     * Adds the metadata fields to the email object. The contains Subject, display name of sender,
     * messageid, word wrap width and email priority. Additional miscellanous metadata extensions
     * should be added in this method.
     */
    protected function configure_email_metadata() {
        global $CFG, $PAGE;
        $email = $this->email;

        $renderer = $PAGE->get_renderer('core');

        // Setup subject template context.
        $subjectcontext = [
            'subject' => $this->subject,
            'prefix' => $CFG->emailsubjectprefix
        ];

        // Fromname template context.
        $fromcontext = [
            'fromname' => $email->FromName
        ];

        // Now write them into the email.
        $email->Subject = $renderer->render_from_template('core/email_subject', $subjectcontext);
        $email->FromName = $renderer->render_from_template('core/email_fromname', $fromcontext);

        // Autogenerate a MessageID if it's missing.
        if (empty($email->MessageID)) {
            $email->MessageID = generate_email_messageid();
        }

        // Set word wrap.
        $email->WordWrap = $this->wordwrapwidth;

        // Setup priority.
        if (!empty($this->from->priority)) {
            $email->Priority = $this->from->priority;
        }
    }

    /**
     * Adds the content fields into the body of the email. This generates the plaintext
     * and HTML email bodies, and writes them into the mail object.
     *
     * @return void
     */
    protected function configure_email_content() {
        global $PAGE;
        $email = $this->email;

        // Generate the context for the email templates.
        $renderer = $PAGE->get_renderer('core');

        // Plain message template context.
        $messagecontext = [
            'body' => html_to_text(nl2br($this->messagetext))
        ];

        // Decide whether HTML content needs to be handled.
        if (!empty($this->recipient->mailformat) && $this->recipient->mailformat == 1) {
            // Only process html templates if the user preferences allow html email.

            if (!$this->messagehtml) {
                // If no html has been given, BUT there is an html wrapping template then
                // auto convert the text to html and then wrap it.
                $this->messagehtml = trim(text_to_html($this->messagetext));
            }
            // Now setup the template context.
            $messagehtmlcontext = [
                'body' => $this->messagehtml
            ];
        }

        // Render the body text.
        $messagetext = $renderer->render_from_template('core/email_text', $messagecontext);
        if (!empty($messagehtmlcontext)) {
            $this->messagehtml = $renderer->render_from_template('core/email_html', $messagehtmlcontext);
        }

        if ($this->messagehtml && !empty($this->recipient->mailformat) && $this->recipient->mailformat == 1) {
            // Don't ever send HTML to users who don't want it.
            $email->isHTML(true);
            $email->Encoding = 'quoted-printable';
            $email->Body    = $this->messagehtml;
            $email->AltBody = "\n$messagetext\n";
        } else {
            $email->IsHTML(false);
            $email->Body = "\n$messagetext\n";
        }
    }

    /**
     * Adds the attachment object to the email object.
     */
    protected function configure_email_attachment() {
        global $CFG;
        $email = $this->email;

        // If there isnt both an attachment and name, return early.
        if (!($this->attachment && $this->attachname)) {
            return;
        }

        if (preg_match( "~\\.\\.~" , $this->attachment )) {
            // Security check for ".." in dir path.
            $supportuser = \core_user::get_support_user();
            $temprecipients[] = array($supportuser->email, fullname($supportuser, true));
            $email->addStringAttachment('Error in attachment.  User attempted to attach a filename with a unsafe name.',
                'error.txt', '8bit', 'text/plain');
        } else {
            require_once($CFG->libdir.'/filelib.php');
            $mimetype = mimeinfo('type', $this->attachname);

            // Before doing the comparison, make sure that the paths are correct (Windows uses slashes in the other direction).
            // The absolute (real) path is also fetched to ensure that comparisons to allowed paths are compared equally.
            $attachpath = str_replace('\\', '/', realpath($this->attachment));

            // Add allowed paths to an array (also check if it's not empty).
            $allowedpaths = array_filter([
                $CFG->cachedir,
                $CFG->dataroot,
                $CFG->dirroot,
                $CFG->localcachedir,
                $CFG->tempdir,
                $CFG->localrequestdir,
            ]);
            // Set addpath to true.
            $addpath = true;
            // Check if attachment includes one of the allowed paths.
            foreach ($allowedpaths as $allowedpath) {
                // Make sure both variables are normalised before comparing.
                $allowedpath = str_replace('\\', '/', realpath($allowedpath));
                // Set addpath to false if the attachment includes one of the allowed paths.
                if (strpos($attachpath, $allowedpath) === 0) {
                    $addpath = false;
                    break;
                }
            }

            // If the attachment is a full path to a file in the multiple allowed paths, use it as is,
            // otherwise assume it is a relative path from the dataroot (for backwards compatibility reasons).
            if ($addpath == true) {
                $this->attachment = $CFG->dataroot . '/' . $this->attachment;
            }

            $email->addAttachment($this->attachment, $this->attachname, 'base64', $mimetype);
        }
    }

    /**
     * Getter method for recipient. This should have no setter field, and only be set from the constructor.
     *
     * @return stdClass
     */
    public function get_recipient(): stdClass {
        return $this->recipient;
    }

    /**
     * Getter method for sender. This should have no setter field, and only be set from the constructor.
     *
     * @return stdClass|string
     */
    public function get_sender() {
        return $this->from;
    }

    /**
     * Getter method for the mailer object which is actually sent.
     *
     * This is mutated in the manager during the send process, where alternate charsets, headers, etc. are added.
     *
     * @return moodle_phpmailer
     */
    public function get_mailer(): moodle_phpmailer {
        return $this->email;
    }

    /**
     * Getter method for the temprecipients. Once the recipients are configured, they will be finalised in the manager.
     *
     * @return array
     */
    public function get_correspondents(): array {
        return [$this->temprecipients, $this->tempreplyto];
    }

    /**
     * Is the email correctly initialised for sending?
     *
     * @return boolean
     */
    public function initialised(): bool {
        return $this->flagcompleted;
    }
}
