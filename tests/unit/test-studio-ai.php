<?php
/**
 * The Studio AI helpers: reading a model reply safely.
 *
 * These are the parts most likely to be quietly wrong, because a mistake here
 * does not throw - it produces a snippet that looks fine and behaves like
 * something else. Two of the three were wrong when first written, and neither
 * failure was visible from the screen.
 */

KH_Test::group('Studio AI — stripping the PHP tag');

// Keelhob stores snippets as BARE PHP: lint_php() prepends its own opening
// tag and run_php() hands the body to eval(). A snippet that arrives with its
// own tag becomes "<?php <?php ..." at lint time and can never go live. The
// system prompt asks the model not to include one; this strips it anyway,
// because an instruction in a prompt is a request, not a guarantee.
KH_Test::same('echo 1;', Keelhob_Studio_AI::strip_php_tags('<?php echo 1;', 'php'), 'a leading tag is removed');
KH_Test::same('echo 2;', Keelhob_Studio_AI::strip_php_tags('  <?php  echo 2; ?>  ', 'php'), 'so is a closing tag, and surrounding space');
KH_Test::same('echo 3;', Keelhob_Studio_AI::strip_php_tags('echo 3;', 'php'), 'code without a tag is left alone');
KH_Test::same('.a{color:red}', Keelhob_Studio_AI::strip_php_tags('.a{color:red}', 'css'), 'non-PHP is never touched');

// Only the FIRST tag goes. A tag in the middle of the body is a string or a
// template fragment, and removing it would corrupt working code.
KH_Test::contains('<?php', Keelhob_Studio_AI::strip_php_tags("<?php \$a = '<?php';", 'php'), 'a tag inside the code survives');

KH_Test::group('Studio AI — working out the type from the code');

// The model is asked to say what type it wrote, and its answer is ignored.
// A PHP snippet mislabelled as CSS would skip the parse gate ENTIRELY, because
// CSS is not parsed - so this reads the code instead.
KH_Test::same('php', Keelhob_Studio_AI::guess_type('<?php echo 1;'), 'a tagged snippet is PHP');

// The hard case, and the one that was wrong: bare PHP is the normal stored
// shape, and to a loose CSS pattern it looks like a selector followed by braces.
KH_Test::same('php', Keelhob_Studio_AI::guess_type("add_filter('the_content', function (\$c) { return \$c; });"), 'bare add_filter() is PHP, not CSS');
KH_Test::same('php', Keelhob_Studio_AI::guess_type("add_action('init', 'foo');"), 'bare add_action() is PHP');
KH_Test::same('php', Keelhob_Studio_AI::guess_type('\$x = 1;'), 'a bare variable assignment is PHP');
KH_Test::same('php', Keelhob_Studio_AI::guess_type('WC()->cart->get_total();'), 'method chaining is PHP');
KH_Test::same('php', Keelhob_Studio_AI::guess_type('MyClass::run();'), 'a static call is PHP');

KH_Test::same('css', Keelhob_Studio_AI::guess_type('.banner { color: red; }'), 'a class selector with a declaration is CSS');
KH_Test::same('css', Keelhob_Studio_AI::guess_type('#id, .cls { background: #fff; padding: 4px; }'), 'so is a grouped selector');
KH_Test::same('js', Keelhob_Studio_AI::guess_type('document.querySelector("a").click();'), 'DOM access is JS');
KH_Test::same('js', Keelhob_Studio_AI::guess_type('(function () { console.log(1); }());'), 'an IIFE is JS');
KH_Test::same('html', Keelhob_Studio_AI::guess_type('<div class="x">hi</div>'), 'markup is HTML');

// When nothing matches, guess PHP. It is the only type that gets parsed, so an
// unknown blob is safer treated as code that must pass a gate than as CSS that
// never will.
KH_Test::same('php', Keelhob_Studio_AI::guess_type('nothing recognisable here'), 'an unrecognisable blob defaults to PHP, which is the type that gets checked');

KH_Test::group('Studio AI — reading a model reply');

// Models are asked for bare JSON and mostly comply. "Here is the JSON:" happens
// often enough that failing on it would make the feature feel flaky for a
// reason the user can neither see nor fix.
KH_Test::same(1, (Keelhob_Studio_AI::json('{"a":1}') ?? [])['a'], 'clean JSON parses');
KH_Test::same(1, (Keelhob_Studio_AI::json('Here you go: {"a":1} hope that helps') ?? [])['a'], 'JSON wrapped in prose is found');
KH_Test::same(null, Keelhob_Studio_AI::json('no json at all'), 'a reply with no JSON returns null rather than guessing');
KH_Test::same(null, Keelhob_Studio_AI::json('{ broken'), 'malformed JSON returns null');

KH_Test::same('<?php echo 1;', Keelhob_Studio_AI::strip_fences("```php
<?php echo 1;
```"), 'markdown fences are removed');
KH_Test::same('<?php echo 1;', Keelhob_Studio_AI::strip_fences('<?php echo 1;'), 'unfenced code is untouched');
