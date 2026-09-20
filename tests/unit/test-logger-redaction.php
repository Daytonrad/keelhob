<?php
/**
 * S3 - secret redaction in the activity log.
 *
 * wc_payments takes live gateway credentials as ordinary tool arguments. Before
 * 1.10.1 those landed in wp_keelhob_log.args_json in clear text and rendered
 * on the Activity screen for anyone who could see wp-admin.
 *
 * The false-positive cases are as important as the leak cases: matching "key"
 * as a substring also masks "monkey" and "keyword", which quietly makes the
 * log useless.
 */

KH_Test::group('Logger - secrets never reach the database');

$redact = new ReflectionMethod('Keelhob_Logger', 'redact');
@$redact->setAccessible(true); // no-op (and deprecated) on PHP 8.1+, still required on the 8.0 floor
$run = function (array $args) use ($redact) { return json_encode($redact->invoke(null, $args, 0)); };

$leaks = [
    ['a Stripe secret key',        ['secret_key' => 'sk_live_51ABCDEFGHIJKLMNOP'],                     'sk_live'],
    ['an api_key',                 ['api_key' => 'sk-ant-api03-REALKEYHERE'],                          'sk-ant'],
    ['a bearer token',             ['token' => 'kh_abcdefghijklmnop'],                                 'abcdefgh'],
    ['a password',                 ['password' => 'hunter2hunter2'],                                   'hunter2'],
    ['an Authorization header',    ['auth' => 'Basic dXNlcjpwYXNz'],                                   'dXNlcjpw'],
    ['camelCase apiKey',           ['apiKey' => 'sk-ant-CAMELCASEKEY'],                                'CAMELCASE'],
    ['one level of nesting',       ['gateway' => ['secret' => 'sk_live_XYZ123456']],               'sk_live'],
    ['three levels of nesting',    ['a' => ['b' => ['client_secret' => 'shhh_secret_value']]], 'shhh_'],
    ['a list of gateways',         ['gw' => [['api_key' => 'AAAA1111BBBB'], ['api_key' => 'CCCC2222DDDD']]], 'AAAA1111'],
    ['an oddly named field',       ['wc_gateway_thing' => 'sk_live_ODDLYNAMED9999'],                   'ODDLYNAMED'],
];
foreach ($leaks as $case) {
    list($label, $args, $needle) = $case;
    KH_Test::ok(strpos($run($args), $needle) === false, $label . ' is masked');
}

KH_Test::group('Logger - the log stays readable');

$kept = $redact->invoke(null, ['post_id' => 42, 'title' => 'Hello', 'monkey' => 'banana', 'keyword' => 'shoes', 'turkey' => 'roast'], 0);
KH_Test::same(42, $kept['post_id'], 'an ordinary integer survives');
KH_Test::same('Hello', $kept['title'], 'an ordinary string survives');
KH_Test::same('banana', $kept['monkey'], '"monkey" is not treated as a key');
KH_Test::same('shoes', $kept['keyword'], '"keyword" is not treated as a key');
KH_Test::same('roast', $kept['turkey'], '"turkey" is not treated as a key');

$tail = $redact->invoke(null, ['api_key' => 'aaaaaaaaaaaaWXYZ'], 0);
KH_Test::same('WXYZ', substr($tail['api_key'], -4), 'the last four characters survive so two keys stay distinguishable');
