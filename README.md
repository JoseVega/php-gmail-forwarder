# php-gmail-forwarder
Get emails from Gmail using the IMAP protocol and do something with them.

This is great when you need to do something with emails sent by external platforms.

# Use cases

- You receive an email from Freemius, WooCommerce, or any checkout system and you want to extract information and forward it to your helpdesk team. This is perfect to modify the email body before forwarding the email in case you don't want your support team to view the full client information.
- You receive an email from an external platform and the email headers are not compatible with your helpdesk system, so you can use this class to change the from and reply-to addresses and forward to your helpdesk address.

# Example

Here we get all the emails received in the last 24 hours, process only emails that we haven't processed before, and forward it using elasticemail's api.

```
// Include the class
require index.php;


// Initialize the reader
$inbox = new VG_Gmail_Forwarder(array(
	// IMPORTANT. You should create a filter on Gmail to apply a label to the emails that you want to 
	// process with this class, so the class won't go through your entire inbox for performance reasons.
	// If you want to check the entire inbox, leave this empty but it's not recommended
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

// Send an email using the elasticemail's rest api
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

```

# How do I run this?

You can create a cron job and execute the php file every hour. It will automatically process only the new messages received in the last 24 hours that haven't been processed before.
