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
 * Email manager class. This class provides a method to send email after initialisation with required params.
 */
class manager {

    /**
     * The target user for the email.
     * @var \stdClass
     */
    protected $user;

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
     * Is the mail initialised?
     * @var bool
     */
    protected $initialised = false;

    /**
     * Initialises the email manager with the parameters required to send an email to a user.
     *
     * @param \stdClass $user  A user object
     * @param \stdClass $from A user object
     * @param string $subject plain text subject line of the email
     * @param string $messagetext plain text version of the message
     * @param string $messagehtml complete html version of the message (optional)
     * @param string $attachment a file on the filesystem, either relative to $CFG->dataroot or a full path to a file in one of
     *          the following directories: $CFG->cachedir, $CFG->dataroot, $CFG->dirroot, $CFG->localcachedir, $CFG->tempdir
     * @param string $attachname the name of the file (extension indicates MIME)
     * @param bool $usetrueaddress determines whether $from email address should
     *          be sent out. Will be overruled by user profile setting for maildisplay
     * @param string $replyto Email address to reply to
     * @param string $replytoname Name of reply to recipient
     * @param int $wordwrapwidth custom word wrap width, default 79
     * @return void
     */
    public function init($user, $from, $subject, $messagetext, $messagehtml = '', $attachment = '', $attachname = '',
    $usetrueaddress = true, $replyto = '', $replytoname = '', $wordwrapwidth = 79) {
        $this->user = $user;
        $this->from = $from;
        $this->subject = $subject;
        $this->messagetext = $messagetext;
        $this->messagehtml = $messagehtml;
        $this->attachment = $attachment;
        $this->attachname = $attachname;
        $this->usetrueaddress = $usetrueaddress;
        $this->replyto = $replyto;
        $this->replytoname = $replytoname;
        $this->wordwrapwidth = $wordwrapwidth;

        $this->initialised = true;
    }

    /**
     * Send mail to the user based on
     *
     * @return boolean
     */
    public function mail_to_user(): bool {
        global $CFG;

        if (!$this->initialised) {
            // Not initialised. Do nothing.
            return false;
        }

        // Setup mail diversion if required.
        if (email_should_be_diverted($this->user->email)) {
            $this->subject = "[DIVERTED {$this->user->email}] $this->subject";
            $this->user->email = $CFG->divertallemailsto;
        }

        // Sanity checks for user and data validity.
        $exitstatus = $this->mail_validity_checks();
        if (!empty($exitstatus)) {
            return $exitstatus;
        }

        $this->mail_mnet_setup();

        $mail = get_mailer();

        if (!empty($mail->SMTPDebug)) {
            echo '<pre>' . "\n";
        }

        // Setup the mail correspondents.
        $this->mail_correspondents($mail);

        // Add email headers.
        $this->mail_headers($mail);

        // Setup mail metadata and info.
        $this->mail_info($mail);

        // Add body content.
        $this->mail_content($mail);

        // Conditionally add attachments.
        if ($this->attachment && $this->attachname) {
            $this->mail_attachment($mail);
        }

        // Check if the email should be sent in an other charset then the default UTF-8.
        if ((!empty($CFG->sitemailcharset) || !empty($CFG->allowusermailcharset))) {
            $this->mail_alternate_charset($mail);
        }

        // Write in the recipients now they are finalised.
        foreach ($this->temprecipients as $values) {
            $mail->addAddress($values[0], $values[1]);
        }
        foreach ($this->tempreplyto as $values) {
            $mail->addReplyTo($values[0], $values[1]);
        }

        // Add DKIM headers immediately before sending.
        $this->mail_dkim($mail);
        $send = $this->mail_send($mail);

        if (!empty($mail->SMTPDebug)) {
            echo '</pre>';
        }

        return $send;
    }

    /**
     * Performs mnet rewriting of URLs in email.
     *
     * @return void
     */
    protected function mail_mnet_setup() {
        // If the user is a remote mnet user, parse the email text for URL to the
        // wwwroot and modify the url to direct the user's browser to login at their
        // home site (identity provider - idp) before hitting the link itself.
        if (is_mnet_remote_user($this->user)) {
            require_once($CFG->dirroot.'/mnet/lib.php');

            $jumpurl = mnet_get_idp_jump_url($user);
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
     * This function checks if the email should actually be sent. Boolean results are both exit codes for the mail function.
     * Null means that there is no action to be taken.
     *
     * @return boolean|null
     */
    protected function mail_validity_checks(): ?bool {
        global $CFG;

        $user = $this->user;

        if (empty($user) or empty($user->id)) {
            debugging('Can not send email to null user', DEBUG_DEVELOPER);
            return false;
        }

        if (empty($user->email)) {
            debugging('Can not send email to user without email: '.$user->id, DEBUG_DEVELOPER);
            return false;
        }

        if (!empty($user->deleted)) {
            debugging('Can not send email to deleted user: '.$user->id, DEBUG_DEVELOPER);
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
        if ((isset($user->auth) && $user->auth == 'nologin') or (isset($user->suspended) && $user->suspended)) {
            return true;
        }

        if (!validate_email($user->email)) {
            // We can not send emails to invalid addresses - it might create security issue or confuse the mailer.
            debugging("email_to_user: User $user->id (".fullname($user).") email ($user->email) is invalid! Not sending.");
            return false;
        }

        if (over_bounce_threshold($user)) {
            debugging("email_to_user: User $user->id (".fullname($user).") is over bounce threshold! Not sending.");
            return false;
        }

        // TLD .invalid  is specifically reserved for invalid domain names.
        // For More information, see {@link http://tools.ietf.org/html/rfc2606#section-2}.
        if (substr($user->email, -8) == '.invalid') {
            debugging("email_to_user: User $user->id (".fullname($user).") email domain ($user->email) is invalid! Not sending.");
            return true; // This is not an error.
        }

        // We should continue on to send mail.
        return null;
    }

    /**
     * Set up the mail correspondents (to and from).
     *
     * @param \moodle_phpmailer $mail the mail object.
     * @return void
     */
    protected function mail_correspondents(\moodle_phpmailer $mail) {
        global $CFG, $SITE;

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
            $modargs = 'B'.base64_encode(pack('V', $this->user->id)).substr(md5($this->user->email), 0, 16);
            $mail->Sender = generate_email_processing_address(0, $modargs);
        } else {
            $mail->Sender = $noreplyaddress;
        }

        // Make sure that the explicit replyto is valid, fall back to the implicit one.
        if (!empty($this->replyto) && !validate_email($this->replyto)) {
            debugging('email_to_user: Invalid replyto-email '.s($this->replyto));
            $replyto = $noreplyaddress;
        } else {
            $replyto = $this->replyto;
        }

        if (is_string($from)) { // So we can pass whatever we want if there is need.
            $mail->From     = $noreplyaddress;
            $mail->FromName = $from;
            // Check if using the true address is true, and the email is in the list of allowed domains for sending email,
            // and that the senders email setting is either displayed to everyone, or display to only other users that are enrolled
            // in a course with the sender.
        } else if ($this->usetrueaddress && can_send_from_real_email_address($from, $this->user)) {
            if (!validate_email($from->email)) {
                debugging('email_to_user: Invalid from-email '.s($from->email).' - not sending');
                // Better not to use $noreplyaddress in this case.
                return false;
            }
            $mail->From = $from->email;
            $fromdetails = new \stdClass();
            $fromdetails->name = fullname($from);
            $fromdetails->url = preg_replace('#^https?://#', '', $CFG->wwwroot);
            $fromdetails->siteshortname = format_string($SITE->shortname);
            $fromstring = $fromdetails->name;
            if ($CFG->emailfromvia == EMAIL_VIA_ALWAYS) {
                $fromstring = get_string('emailvia', 'core', $fromdetails);
            }
            $mail->FromName = $fromstring;
            if (empty($replyto)) {
                $tempreplyto[] = array($from->email, fullname($from));
            }
        } else {
            $mail->From = $noreplyaddress;
            $fromdetails = new \stdClass();
            $fromdetails->name = fullname($from);
            $fromdetails->url = preg_replace('#^https?://#', '', $CFG->wwwroot);
            $fromdetails->siteshortname = format_string($SITE->shortname);
            $fromstring = $fromdetails->name;
            if ($CFG->emailfromvia != EMAIL_VIA_NEVER) {
                $fromstring = get_string('emailvia', 'core', $fromdetails);
            }
            $mail->FromName = $fromstring;
            if (empty($replyto)) {
                $tempreplyto[] = array($noreplyaddress, get_string('noreplyname'));
            }
        }

        if (!empty($replyto)) {
            $tempreplyto[] = array($replyto, $this->replytoname);
        }

        $temprecipients[] = array($this->user->email, fullname($this->user));

        // Write temp correspondents to class fields.
        $this->temprecipients = $temprecipients;
        $this->tempreplyto = $tempreplyto;
    }

    /**
     * Add all mail headers to the mail object.
     *
     * @param \moodle_phpmailer $mail the mail object.
     * @return void
     */
    protected function mail_headers(\moodle_phpmailer $mail) {
        global $CFG;

        // First add hardcoded headers.
        if (!empty($CFG->emailheaders)) {
            $headers = array_map('trim', explode("\n", $CFG->emailheaders));
            // Append to the mail.
            foreach ($headers as $header) {
                if (!empty($header)) {
                    $mail->addCustomHeader($header);
                }
            }
        }

        if (!empty($this->from->customheaders)) {
            // Add custom headers.
            if (is_array($this->from->customheaders)) {
                foreach ($this->from->customheaders as $customheader) {
                    $mail->addCustomHeader($customheader);
                }
            } else {
                $mail->addCustomHeader($this->from->customheaders);
            }
        }

        // If the X-PHP-Originating-Script email header is on then also add an additional
        // header with details of where exactly in moodle the email was triggered from,
        // either a call to message_send() or to email_to_user().
        if (ini_get('mail.add_x_header')) {

            $stack = debug_backtrace(false);
            $origin = $stack[0];

            foreach ($stack as $depth => $call) {
                if ($call['function'] == 'message_send') {
                    $origin = $call;
                }
            }

            $originheader = $CFG->wwwroot . ' => ' . gethostname() . ':'
                . str_replace($CFG->dirroot . '/', '', $origin['file']) . ':' . $origin['line'];
            $mail->addCustomHeader('X-Moodle-Originating-Script: ' . $originheader);
        }
    }

    /**
     * Adds the metadata fields to the mail object.
     *
     * @param \moodle_phpmailer $mail the mail object.
     * @return void
     */
    protected function mail_info(\moodle_phpmailer $mail) {
        global $CFG, $PAGE;

        $renderer = $PAGE->get_renderer('core');

        // Setup subject template context.
        $subjectcontext = [
            'subject' => $this->subject,
            'prefix' => $CFG->emailsubjectprefix
        ];

        // Fromname template context.
        $fromcontext = [
            'fromname' => $mail->FromName
        ];

        // Now write them into the mail.
        $mail->Subject = $renderer->render_from_template('core/email_subject', $subjectcontext);
        $mail->FromName = $renderer->render_from_template('core/email_fromname', $fromcontext);

        // Autogenerate a MessageID if it's missing.
        if (empty($mail->MessageID)) {
            $mail->MessageID = generate_email_messageid();
        }

        // Set word wrap.
        $mail->WordWrap = $this->wordwrapwidth;

        // Setup priority.
        if (!empty($this->from->priority)) {
            $mail->Priority = $this->from->priority;
        }
    }

    /**
     * Adds the content fields into the body of the mail.
     *
     * @param \moodle_phpmailer $mail the mail object.
     * @return void
     */
    protected function mail_content(\moodle_phpmailer $mail) {
        global $PAGE;

        // Generate the context for the email templates.
        $renderer = $PAGE->get_renderer('core');

        // Plain message template context.
        $messagecontext = [
            'body' => html_to_text(nl2br($this->messagetext))
        ];

        // Decide whether HTML content needs to be handled.
        if (!empty($this->user->mailformat) && $this->user->mailformat == 1) {
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

        if ($this->messagehtml && !empty($this->user->mailformat) && $this->user->mailformat == 1) {
            // Don't ever send HTML to users who don't want it.
            $mail->isHTML(true);
            $mail->Encoding = 'quoted-printable';
            $mail->Body    = $this->messagehtml;
            $mail->AltBody = "\n$messagetext\n";
        } else {
            $mail->IsHTML(false);
            $mail->Body = "\n$messagetext\n";
        }
    }

    /**
     * Adds the attachment object to the mail object.
     *
     * @param \moodle_phpmailer $mail the mail object.
     * @return void
     */
    protected function mail_attachment(\moodle_phpmailer $mail) {
        global $CFG;

        if (preg_match( "~\\.\\.~" , $this->attachment )) {
            // Security check for ".." in dir path.
            $supportuser = \core_user::get_support_user();
            $temprecipients[] = array($supportuser->email, fullname($supportuser, true));
            $mail->addStringAttachment('Error in attachment.  User attempted to attach a filename with a unsafe name.',
                'error.txt', '8bit', 'text/plain');
        } else {
            $mimetype = mimeinfo('type', $this->attachname);
            $attachmentpath = $this->attachment;

            // Before doing the comparison, make sure that the paths are correct (Windows uses slashes in the other direction).
            $attachpath = str_replace('\\', '/', $attachmentpath);

            // Add allowed paths to an array (also check if it's not empty).
            $allowedpaths = array_filter([
                $CFG->cachedir,
                $CFG->dataroot,
                $CFG->dirroot,
                $CFG->localcachedir,
                $CFG->tempdir
            ]);
            // Set addpath to true.
            $addpath = true;
            // Check if attachment includes one of the allowed paths.
            foreach ($allowedpaths as $tmpvar) {
                // Make sure both variables are normalised before comparing.
                $temppath = str_replace('\\', '/', realpath($tmpvar));
                // Set addpath to false if the attachment includes one of the allowed paths.
                if (strpos($attachpath, $temppath) === 0) {
                    $addpath = false;
                    break;
                }
            }

            // If the attachment is a full path to a file in the multiple allowed paths, use it as is,
            // otherwise assume it is a relative path from the dataroot (for backwards compatibility reasons).
            if ($addpath == true) {
                $attachmentpath = $CFG->dataroot . '/' . $attachmentpath;
            }

            $mail->addAttachment($attachmentpath, $this->attachname, 'base64', $mimetype);
        }
    }

    /**
     * Performs in place charset mutation of required fields if a different charset is needed.
     *
     * @param \moodle_phpmailer $mail the mail object.
     * @return void
     */
    protected function mail_alternate_charset(\moodle_phpmailer $mail) {
        global $CFG;

        // Use the defined site mail charset or eventually the one preferred by the recipient.
        $charset = $CFG->sitemailcharset;
        if (!empty($CFG->allowusermailcharset)) {
            if ($useremailcharset = get_user_preferences('mailcharset', '0', $this->user->id)) {
                $charset = $useremailcharset;
            }
        }

        // Convert all the necessary strings if the charset is supported.
        $charsets = get_list_of_charsets();
        unset($charsets['UTF-8']);
        if (in_array($charset, $charsets)) {
            $mail->CharSet  = $charset;
            $mail->FromName = \core_text::convert($mail->FromName, 'utf-8', strtolower($charset));
            $mail->Subject  = \core_text::convert($mail->Subject, 'utf-8', strtolower($charset));
            $mail->Body     = \core_text::convert($mail->Body, 'utf-8', strtolower($charset));
            $mail->AltBody  = \core_text::convert($mail->AltBody, 'utf-8', strtolower($charset));

            foreach ($this->temprecipients as $key => $values) {
                $temprecipients[$key][1] = \core_text::convert($values[1], 'utf-8', strtolower($charset));
            }
            foreach ($this->tempreplyto as $key => $values) {
                $tempreplyto[$key][1] = \core_text::convert($values[1], 'utf-8', strtolower($charset));
            }
        }
    }

    /**
     * Adds DKIM headers to mail object.
     *
     * @param \moodle_phpmailer $mail the mail object.
     * @return void
     */
    protected function mail_dkim(\moodle_phpmailer $mail) {
        global $CFG;

        if (!empty($CFG->emaildkimselector)) {
            $domain = substr(strrchr($mail->From, "@"), 1);
            $pempath = "{$CFG->dataroot}/dkim/{$domain}/{$CFG->emaildkimselector}.private";
            if (file_exists($pempath)) {
                $mail->DKIM_domain      = $domain;
                $mail->DKIM_private     = $pempath;
                $mail->DKIM_selector    = $CFG->emaildkimselector;
                $mail->DKIM_identity    = $mail->From;
            } else {
                debugging("Email DKIM selector chosen due to {$mail->From} but no certificate found at $pempath", DEBUG_DEVELOPER);
            }
        }
    }

    /**
     * Sends the given mail object. Logs an event if sending fails.
     *
     * @param \moodle_phpmailer $mail the mail object.
     * @return boolean the status of the mail send.
     */
    protected function mail_send(\moodle_phpmailer $mail): bool {
        if ($mail->send()) {
            set_send_count($this->user);
            return true;
        } else {
            // Trigger event for failing to send email.
            $event = \core\event\email_failed::create(array(
                'context' => \context_system::instance(),
                'userid' => $this->from->id,
                'relateduserid' => $this->user->id,
                'other' => array(
                    'subject' => $this->subject,
                    'message' => $this->messagetext,
                    'errorinfo' => $mail->ErrorInfo
                )
            ));
            $event->trigger();
            if (CLI_SCRIPT) {
                mtrace('Error: lib/moodlelib.php email_to_user(): '.$mail->ErrorInfo);
            }
            if (!empty($mail->SMTPDebug)) {
                echo '</pre>';
            }
            return false;
        }
    }
}
