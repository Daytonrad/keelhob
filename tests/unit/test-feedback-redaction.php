<?php
/**
 * Redaction — the file that makes the privacy claim checkable.
 *
 * PRIVACY.md tells people that a feedback report carries no site address, no
 * content and no keys. A sentence in a document is not a guarantee; this is.
 * Every case below is a planted failure: the thing on the left is what somebody
 * will eventually type into a note by accident, and none of it may survive.
 *
 * If a rule here is ever relaxed, the sentence in PRIVACY.md has to change in
 * the same commit. That is the whole point of pairing them.
 */

CP_Test::group('Feedback redaction — nothing that identifies anyone survives');

$mask = Claudpress_Feedback::MASK;

$leaks = [
    // what somebody types                                        why it must go
    'the site at https://acme-dental.com/hero broke'           => 'a full URL identifies the site',
    'check www.acme-dental.co.uk before you ship'              => 'a bare www host is still an address',
    'email sam@acme-dental.com about it'                       => 'an email address is personal data',
    'reach me at sam.arthur+wp@gmail.com'                      => 'a plus-addressed email is still an email',
    'the server is on 203.0.113.44'                            => 'an IPv4 address identifies the host',
    'bound to 2001:0db8:85a3:0000:0000:8a2e:0370:7334 there'   => 'an IPv6 address identifies the host',
    'it wrote to /home/flintsbwoy/public_html/wp-config.php'   => 'an absolute path leaks the username',
    'failed under /var/www/vhosts/acme/httpdocs'               => 'a server path leaks the host layout',
    'copied from C:\\Users\\Sam\\Documents\\site'              => 'a Windows path leaks the username',
    'used key sk-ant-api03-AbCdEf0123456789xyz'                => 'that is the shape of a vendor API key',
    'token ghp_AbCdEf0123456789AbCdEf0123456789'               => 'a GitHub token is a credential',
    'password: hunter2correcthorse'                            => 'anything announced as a password goes',
    'the value was a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6'           => 'a long hex run is key-shaped',
];

foreach ($leaks as $input => $why) {
    $out = Claudpress_Feedback::redact($input);
    CP_Test::ok(strpos($out, $mask) !== false, $why . ' — something was redacted');
}

// Named individually, because "something was redacted" would also pass if the
// wrong half went. These are the exact substrings that must be gone.
foreach ([
    'acme-dental.com'        => 'https://acme-dental.com/hero is gone',
    'sam@acme-dental.com'    => 'the email address is gone',
    '203.0.113.44'           => 'the IPv4 address is gone',
    'flintsbwoy'             => 'the username in the path is gone',
    'sk-ant-api03'           => 'the API key prefix is gone',
    'ghp_AbCdEf0123456789'   => 'the GitHub token is gone',
    'hunter2correcthorse'    => 'the password value is gone, not just the word "password"',
] as $secret => $why) {
    $joined = Claudpress_Feedback::redact(implode(' ', array_keys($leaks)));
    CP_Test::ok(stripos($joined, $secret) === false, $why);
}

CP_Test::group('Feedback redaction — the site\'s own name and host');

// The likeliest accidental leak of all: somebody typing the name of their own
// site into a note by hand, where no generic pattern would catch it.
$ids = Claudpress_Feedback::own_identifiers();
CP_Test::ok(!empty($ids), 'the site names itself, so redaction has something to strip');

$host = (string) wp_parse_url((string) home_url(), PHP_URL_HOST);
if ($host !== '' && strlen($host) >= 4) {
    $out = Claudpress_Feedback::redact('the hero on ' . $host . ' rebuilt three times');
    CP_Test::ok(stripos($out, $host) === false, 'this site\'s own hostname does not survive redaction');
}

CP_Test::group('Feedback redaction — but a real bug report still reads');

// Over-redaction is the correct failure mode, and it is still a failure. A
// report that arrives as five rows of [removed] tells the project nothing, so
// the ordinary vocabulary of a bug report has to come through untouched.
$useful = 'elementor_page should return the IDs of sections it creates, so the next edit does not have to re-read the page to find them.';
$kept   = Claudpress_Feedback::redact($useful);
CP_Test::same($useful, $kept, 'a well-written note with no secrets in it is passed through unchanged');

foreach ([
    'seo_meta returned an empty title for post.title'  => 'post.title is not mistaken for a domain',
    'the failure was in index.php on the front end'    => 'index.php is not mistaken for a domain',
    'upload_media timed out after 30 seconds'          => 'plain numbers are not mistaken for an address',
    'repeated_correction happened three times'         => 'a long snake_case word is not mistaken for a key',
] as $ordinary => $why) {
    CP_Test::same($ordinary, Claudpress_Feedback::redact($ordinary), $why);
}

CP_Test::group('Feedback redaction — heavy redaction is visible, not silent');

$before = 'it broke on https://acme-dental.com';
$after  = Claudpress_Feedback::redact($before);
CP_Test::ok(Claudpress_Feedback::was_redacted($before, $after), 'a note that lost something is flagged as redacted');
CP_Test::ok(!Claudpress_Feedback::was_redacted($useful, $useful), 'a clean note is not flagged');
CP_Test::same('', Claudpress_Feedback::redact(''), 'an empty note stays empty rather than becoming a mask');
