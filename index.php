<?php

class VG_Gmail_Forwarder {

	var $hostname = null;
	var $username = null;
	var $password = null;
	var $inbox = null;
	var $message_callback = null;
	var $args = array();

	function __construct($args = array()) {
		$defaults = array(
			'gmail_label' => null,
			'username' => null,
			'password' => null,
			'message_callback' => null,
			'debug_mode' => false,
		);
		$args = array_merge($defaults, $args);
		$this->args = $args;
		$this->hostname = '{imap.gmail.com:993/imap/ssl}' . $args['gmail_label'];
		$this->username = $args['username'];
		$this->password = $args['password'];
		$this->message_callback = $args['message_callback'];
	}

	function connect() {

		$this->inbox = imap_open($this->hostname, $this->username, $this->password) or die('Cannot connect to Gmail: ' . imap_last_error());
	}

	/**
	 * Get all Gmail labels available
	 * @return array
	 */
	function get_mailboxes() {
		$mailboxes = imap_list($this->inbox, '{imap.gmail.com:993/imap/ssl}', '*');
		return $mailboxes;
	}

	/**
	 * Get emails from remote inbox
	 * @param integer $latest_hours
	 * @return array
	 */
	function get_latest_emails($latest_hours = 24) {

		$since = date('d F Y', strtotime('-' . (int) $latest_hours . ' hours'));
		$emails = imap_search($this->inbox, 'SINCE "' . $since . '"');
		rsort($emails);
		return $emails;
	}

	/**
	 * Check if the email was already processed
	 * @param string $from Email address
	 * @param integer $timestamp
	 * @return boolean
	 */
	function _is_email_processed($from, $timestamp) {

		$processed = $this->_get_processed_emails();
		return in_array($from . '-' . $timestamp, $processed);
	}

	/**
	 * Get previous processed emails
	 * @return array
	 */
	function _get_processed_emails() {
		$processed = array();
		if ($this->args['debug_mode']) {
			return $processed;
		}
		$processed_file = __DIR__ . '/processed.json';
		if (file_exists($processed_file)) {
			$file_contents = file_get_contents($processed_file);
			if (empty($file_contents)) {
				$file_contents = '[]';
			}
			$processed = json_decode($file_contents, true);
		}
		return $processed;
	}

	/**
	 * We save each email in a local file so we guarantee that we don't process it again,
	 * unless debug_mode is active, in that case we will always process every email
	 * 
	 * @param string $from
	 * @param integer $timestamp
	 * @return null
	 */
	function _mark_processed_email($from, $timestamp) {
		if ($this->args['debug_mode']) {
			return;
		}
		$processed_file = __DIR__ . '/processed.json';
		$processed = $this->_get_processed_emails();
		$processed[] = $from . '-' . $timestamp;

		file_put_contents($processed_file, json_encode($processed));
	}

	/**
	 * Convert email to plain text and remove unwanted content
	 * @param integer $email_number
	 * @param object $structure
	 * @return string
	 */
	function _get_message($email_number, $structure) {

		$message = $this->_prepare_message($this->inbox, $email_number, $structure);
		$message = strip_tags(str_replace(array('</td>', '</h3>', '</h2>', '<br>'), '==', $this->_cleanup_html($message)));
		$message = str_replace('==', "\n", preg_replace('!\s+!', ' ', preg_replace('/Sent by Freemius on behalf of .+$/s', '', $message)));
		return html_entity_decode($message);
	}

	/**
	 * Remove unwanted tags from the html
	 * @param string $myHtml
	 * @return string
	 */
	function _cleanup_html($myHtml) {
		$myHtml = str_replace(array('<nobr>', '</nobr>'), '', $myHtml);
		// create a new DomDocument object
		$doc = new DOMDocument();

// load the HTML into the DomDocument object (this would be your source HTML)
		$doc->loadHTML($myHtml);

		$this->_remove_elements_by_tag('script', $doc);
		$this->_remove_elements_by_tag('style', $doc);
		$this->_remove_elements_by_tag('link', $doc);
		$this->_remove_elements_by_tag('head', $doc);

// output cleaned html
		return $doc->saveHtml();
	}

	/**
	 * Remove tags from DOM object
	 * @param string $tagName
	 * @param object $document
	 */
	function _remove_elements_by_tag($tagName, $document) {
		$nodeList = $document->getElementsByTagName($tagName);
		for ($nodeIdx = $nodeList->length; --$nodeIdx >= 0;) {
			$node = $nodeList->item($nodeIdx);
			$node->parentNode->removeChild($node);
		}
	}

	/**
	 * Decode message because remote inboxes return emails with different encoding,
	 * each sender might use different encoding
	 * 
	 * @param string $message
	 * @param object $structure
	 * @return string
	 */
	function _decode_message($message, $structure) {
		if (isset($structure->parts) && is_array($structure->parts) && isset($structure->parts[1])) {
			$part = $structure->parts[1];

			if ($part->encoding == 3) {
				$message = imap_base64($message);
			} else if ($part->encoding == 1) {
				$message = imap_8bit($message);
			} else {
				$message = imap_qprint($message);
			}
		}
		return $message;
	}

	/**
	 * Get email message from remote inbox
	 * @param object $inbox
	 * @param integer $email_number
	 * @param object $structure
	 * @return string
	 */
	function _prepare_message($inbox, $email_number, $structure) {


		if (isset($structure->parts) && is_array($structure->parts) && isset($structure->parts[1])) {
			$message = $this->_decode_message(imap_fetchbody($inbox, $email_number, 2), $structure);
		}
		return $message;
	}

	/**
	 * Get first email address found in the body
	 * @param string $message
	 * @return string
	 */
	function _get_email($message) {

		// Extract email from message
		$pattern = '/[a-z0-9_\-\+\.]+@[a-z0-9\-]+\.([a-z]{2,4})(?:\.[a-z]{2})?/i';
		preg_match_all($pattern, $message, $matches);
		return current($matches[0]);
	}

	function process_emails($emails) {
		if (!$emails) {
			return;
		}

		/* for every email... */
		foreach ($emails as $email_number) {
			$structure = imap_fetchstructure($this->inbox, $email_number);

			/* get information specific to this email */
			$overview = imap_fetch_overview($this->inbox, $email_number, 0);
			$message = $this->_get_message($email_number, $structure);
			$email = $this->_get_email($message);

			$new_message = array(
				'Subject' => $this->_decode_message($overview[0]->subject, $structure),
				// This is the first email address found in the email body
				'From' => $email,
				'Date' => $this->_decode_message($overview[0]->date, $structure),
				// Plain text body
				'Body' => $message
			);
			// Stop the loop when we reach one email that was processed previously
			if ($this->_is_email_processed($email, $overview[0]->udate)) {
				var_dump('$skipped by check 4: ' . $new_message['Subject']);
				break;
			}

			$this->_mark_processed_email($email, $overview[0]->udate);

			if (is_callable($this->message_callback)) {
				call_user_func($this->message_callback, $new_message);
			}
		}
	}

	function close_inbox() {


		/* close the connection */
		imap_close($this->inbox);
	}

}

// Initialize the reader
$inbox = new VG_Gmail_Forwarder(array(
	// You should create a filter on Gmail to apply a label to the emails that you want to 
	// process with this class, so the class won't go through your entire inbox. 
	// If you want to iterate the entire inbox, leave this empty
	'gmail_label' => 'My gmail label',
	'username' => 'user@gmail.com',
	// Regular Gmail password or Google App Password if using 2fa
	// https://support.google.com/mail/answer/185833?hl=en-GB
	'password' => 'xxxxxxxxxxxxxxxxxx',
	'message_callback' => function($new_message) {
		// Do something with each email
		// For example, we can modify the body and forward it with elasticemail
		elastic_send_email(array(
			'from' => $new_message['From'],
			'fromName' => $new_message['From'],
			'apikey' => 'xxxxxxxxxxxxxxxxxxxxxx',
			'subject' => $new_message['Subject'],
			'to' => 'recipient@test.com',
			'bodyHtml' => $new_message['Body'],
			'bodyText' => $new_message['Body'],
			'isTransactional' => true
		));
	},
		));


// Connect, get emails, and process them.
$inbox->connect();
$emails = $inbox->get_latest_emails(10);
$inbox->process_emails($emails);
$inbox->close_inbox();

function elastic_send_email($post) {

	$url = 'https://api.elasticemail.com/v2/email/send';

	try {
		$defaults = array(
			'from' => 'youremail@yourdomain.com',
			'fromName' => 'Your Company Name',
			'apikey' => '00000000-0000-0000-0000-000000000000',
			'subject' => 'Your Subject',
			'to' => 'recipient1@gmail.com;recipient2@gmail.com',
			'bodyHtml' => '<h1>Html Body</h1>',
			'bodyText' => 'Text Body',
			'isTransactional' => false
		);
		$post = array_merge($defaults, $post);

		$ch = curl_init();
		curl_setopt_array($ch, array(
			CURLOPT_URL => $url,
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $post,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HEADER => false,
			CURLOPT_SSL_VERIFYPEER => false
		));

		$result = curl_exec($ch);
		curl_close($ch);
		var_dump('$post', $post, '$result', $result);
	} catch (Exception $ex) {
		echo $ex->getMessage();
	}
}
