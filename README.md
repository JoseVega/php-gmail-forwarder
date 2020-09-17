# php-gmail-forwarder
Get emails from Gmail using the IMAP protocol and do something with them.

This is great when you need to do something with emails sent by external platforms.

For example, you receive an email from Freemius and you want to extract information and forward it to your helpdesk.

The index.php file contains an example where we process each email and forward it using elasticemail's api.

# How do I run this?

You can create a cron job and execute the php file every hour. It will automatically process only the new messages received in the last 24 hours.
