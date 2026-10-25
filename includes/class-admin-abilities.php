<?php
/**
 * The owner's side of agent-written abilities: the review queue, the code, and
 * the only three buttons on this site that can make model-written PHP live.
 *
 * NOTHING HERE DEPENDS ON JAVASCRIPT, and on this screen that is not a
 * preference. Approval is the human action the entire feature rests on, so it
 * is a real form posting to admin-post.php, with `manage_options` and a nonce
 * checked on every handler. The `onclick` confirm is an enhancement over a
 * checkbox the browser itself enforces and the server re-checks — with scripts
 * off, the checkbox is still the thing that has to be ticked.
 *
 * Its own file, and its own admin-post targets, for the reason
 * class-admin-ratings.php gives for the same choice: this is a safety surface,
 * and a safety surface is worth being able to read in one sitting.
 *
 * THE CODE ON THIS SCREEN IS DISPLAYED, NEVER EXECUTED. It goes out through
 * esc_html() inside a <pre>, and the only thing this file does with the bytes
 * otherwise is hand them to Keelhob_Abilities::lint_code(), which parses
 * without running. There is no preview, no "try it", and no dry run, because
 * every one of those is a path by which pending code would execute.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Admin_Abilities
{
    const NONCE = 'keelhob_ability_review';

    /** The sentence the owner has to agree with before Approve will post. */
    const CONFIRM = 'Approve this code? It will run on your site whenever the AI uses this ability.';

    public function hooks(): void
    {
        add_action('admin_post_keelhob_ability_approve', [$this, 'handle_approve']);
        add_action('admin_post_keelhob_ability_reject', [$this, 'handle_reject']);
        add_action('admin_post_keelhob_ability_rollback', [$this, 'handle_rollback']);
    }

    // ---- handlers ---------------------------------------------------------

    /**
     * Make a pending version live.
     *
     * Three gates, all server-side: the capability, the nonce, and the owner's
     * own confirmation. The third is not decoration — it is the record that a
     * human read the code, and it is checked HERE rather than only in the
     * browser so that a hand-made POST cannot skip it.
     */
    public function handle_approve(): void
    {
        $this->guard();

        if (empty($_POST['kh_ability_confirm'])) {
            $this->back('ability_unconfirmed');
        }

        $res = Keelhob_Abilities::approve((int) ($_POST['ability_id'] ?? 0), (int) get_current_user_id());
        $this->back(empty($res['success']) ? 'ability_failed' : 'ability_approved');
    }

    public function handle_reject(): void
    {
        $this->guard();
        $res = Keelhob_Abilities::reject((int) ($_POST['ability_id'] ?? 0));
        $this->back(empty($res['success']) ? 'ability_failed' : 'ability_rejected');
    }

    public function handle_rollback(): void
    {
        $this->guard();
        $res = Keelhob_Abilities::rollback((int) ($_POST['ability_id'] ?? 0), (int) get_current_user_id());
        $this->back(empty($res['success']) ? 'ability_failed' : 'ability_rolled_back');
    }

    /**
     * One guard, used by all three handlers, so there is no handler on which
     * the capability check or the nonce could have been forgotten.
     */
    private function guard(): void
    {
        if (!current_user_can('manage_options') || !check_admin_referer(self::NONCE)) {
            wp_die(esc_html__('You do not have permission to do that.', 'keelhob'), '', ['response' => 403]);
        }
    }

    private function back(string $flag): void
    {
        wp_safe_redirect(
            add_query_arg(['page' => 'keelhob', $flag => 1], admin_url('admin.php')) . '#kh-abilities'
        );
        exit;
    }

    // ---- the screen -------------------------------------------------------

    /**
     * The whole area, rendered on the Connect screen.
     *
     * WHY IT IS NOT A SEVENTH TAB. The same tie-breaker the Overview block was
     * placed by, written up at length above Keelhob_Admin_Screens::overview():
     * no new page slug, no new menu entry, no change to Keelhob_Admin::tabs(),
     * nothing to add to DOCUMENTATION.md — which is mirrored to the live site's
     * /docs — and nothing for a bookmark or a notifier link to miss. It is also
     * the right place on the merits: a pending ability is code waiting to run on
     * the owner's site, and burying that one click away from the first screen is
     * how it goes unread.
     *
     * Pending versions come first, always. Everything else on this screen can
     * wait; a queue of model-written PHP cannot.
     */
    public static function section(): void
    {
        $pending = Keelhob_Abilities::pending_rows();
        $live    = Keelhob_Abilities::active_rows();

        // Nothing proposed, nothing live, and authoring never switched on: the
        // owner has not asked for this feature, so the screen does not grow a
        // section explaining it to them.
        if (!$pending && !$live && !Keelhob_Abilities::authoring_enabled()) {
            return;
        }
        ?>
        <div class="kh-u-sec" id="kh-abilities">
            <?php self::notices(); ?>

            <?php if ($pending) : ?>
                <div class="kh-u-sec-head" id="pending">
                    <h2><?php esc_html_e('Waiting for your review', 'keelhob'); ?></h2>
                    <p class="kh-u-sub"><?php esc_html_e('Your AI wrote these. Nothing runs until you approve it. Read the code, or ask your AI in chat to explain it.', 'keelhob'); ?></p>
                </div>
                <?php foreach ($pending as $row) : ?>
                    <?php self::review_card($row); ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="kh-u-sec-head">
                <h2><?php esc_html_e('Abilities the AI wrote', 'keelhob'); ?></h2>
                <p class="kh-u-sub"><?php esc_html_e('Each one is a tool you approved, and it runs with the full power of your site. Every version keeps its own rating, earned from real use.', 'keelhob'); ?></p>
            </div>

            <?php if (!$live) : ?>
                <p class="kh-u-empty"><?php esc_html_e('None yet. Ask your AI in chat for something none of its tools can do — for example, "write an ability that lists my scheduled posts" — and it will show up above for you to approve.', 'keelhob'); ?></p>
            <?php else : ?>
                <?php foreach ($live as $row) : ?>
                    <?php self::live_card($row); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** The result of the owner's last action, in words. */
    private static function notices(): void
    {
        $map = [
            'ability_approved'    => ['is-good', __('Approved. It is live now, and your AI can use it.', 'keelhob')],
            'ability_rejected'    => ['is-info', __('Rejected. Nothing was installed, and the code is kept so you can look at it again.', 'keelhob')],
            'ability_rolled_back' => ['is-good', __('Rolled back. That version is live again.', 'keelhob')],
            'ability_unconfirmed' => ['is-bad', __('Nothing was approved: the confirmation box was not ticked.', 'keelhob')],
            'ability_failed'      => ['is-bad', __('That did not work, and nothing was changed. Reload this page and look at the version status.', 'keelhob')],
        ];
        foreach ($map as $flag => $pair) {
            if (!isset($_GET[$flag])) {
                continue;
            }
            printf(
                '<div class="kh-u-note %s"><p>%s</p></div>',
                esc_attr($pair[0]),
                esc_html($pair[1])
            );
        }
    }

    /**
     * One pending version, with everything the owner needs in order to say yes
     * or no and nothing they have to click to reach.
     *
     * @param array<string, mixed> $row
     */
    private static function review_card(array $row): void
    {
        $name    = (string) $row['name'];
        $version = (int) $row['version'];
        $current = Keelhob_Abilities::active_row($name);
        $inputs  = self::input_lines($row['input_schema'] ?? '');
        ?>
        <div class="kh-u-review">
            <div class="kh-u-review-top">
                <span class="kh-u-badge is-pending">
                    <?php
                    echo $version === 1
                        ? esc_html__('New ability', 'keelhob')
                        /* translators: %d: version number. */
                        : esc_html(sprintf(__('New version %d', 'keelhob'), $version));
                    ?>
                </span>
                <span class="kh-u-review-id">
                    <strong class="kh-u-review-label"><?php echo esc_html((string) $row['label']); ?></strong>
                    <code class="kh-u-mono"><?php echo esc_html($name); ?></code>
                </span>
                <span class="kh-u-flags">
                    <?php if (!empty($row['writes'])) : ?>
                        <span class="kh-u-badge is-writes"><?php esc_html_e('Changes the site', 'keelhob'); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($row['risky'])) : ?>
                        <span class="kh-u-badge is-risky"><?php esc_html_e('Risky', 'keelhob'); ?></span>
                    <?php endif; ?>
                </span>
            </div>

            <p class="kh-u-review-desc"><?php echo esc_html((string) $row['description']); ?></p>

            <?php if (trim((string) $row['change_note']) !== '') : ?>
                <p class="kh-u-review-note">
                    <strong><?php esc_html_e('What changed:', 'keelhob'); ?></strong>
                    <?php echo esc_html((string) $row['change_note']); ?>
                </p>
            <?php endif; ?>

            <p class="kh-u-review-inputs">
                <strong><?php esc_html_e('Inputs:', 'keelhob'); ?></strong>
                <?php if (!$inputs) : ?>
                    <?php esc_html_e('none', 'keelhob'); ?>
                <?php else : ?>
                    <?php foreach ($inputs as $line) : ?>
                        <code class="kh-u-mono"><?php echo esc_html($line); ?></code>
                    <?php endforeach; ?>
                <?php endif; ?>
            </p>

            <?php
            /*
             * OPEN, not collapsed. PLAN §2.7 allows moving explanation into a
             * closed details panel; it does not allow hiding the thing the owner
             * is being asked to approve. An approval screen whose code is one
             * click away is an approval screen where nobody reads the code.
             */
            ?>
            <details class="kh-u-code-wrap" open>
                <summary><?php esc_html_e('The code this will run', 'keelhob'); ?></summary>
                <pre class="kh-u-code"><code><?php echo esc_html((string) $row['code']); ?></code></pre>
            </details>

            <?php if ($current !== null) : ?>
                <details class="kh-u-code-wrap">
                    <summary>
                        <?php
                        /* translators: %d: version number. */
                        echo esc_html(sprintf(__('The version running now, for comparison (v%d)', 'keelhob'), (int) $current['version']));
                        ?>
                    </summary>
                    <pre class="kh-u-code is-old"><code><?php echo esc_html((string) $current['code']); ?></code></pre>
                </details>
            <?php endif; ?>

            <form class="kh-u-review-act" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field(self::NONCE); ?>
                <input type="hidden" name="action" value="keelhob_ability_approve">
                <input type="hidden" name="ability_id" value="<?php echo esc_attr((string) $row['id']); ?>">
                <?php
                /*
                 * The confirm, as a real checkbox the browser enforces with
                 * `required` and the server re-checks in handle_approve(). The
                 * onclick confirm() below says the same sentence for anyone with
                 * scripts on; with scripts off this box is still the gate, which
                 * is why the wording lives here and not only in the dialog.
                 */
                ?>
                <label class="kh-u-check kh-u-confirm">
                    <input type="checkbox" name="kh_ability_confirm" value="1" required>
                    <span><?php echo esc_html__(self::CONFIRM, 'keelhob'); ?></span>
                </label>
                <p class="kh-u-actions">
                    <button type="submit" class="button button-primary" onclick="return confirm('<?php echo esc_js(self::CONFIRM); ?>');">
                        <?php esc_html_e('Approve &amp; go live', 'keelhob'); ?>
                    </button>
                    <button type="submit" class="button" name="action" value="keelhob_ability_reject" formnovalidate>
                        <?php esc_html_e('Reject', 'keelhob'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * One live ability: what it is, how it has gone, and every version with its
     * own rating and a way back to it.
     *
     * @param array<string, mixed> $row
     */
    private static function live_card(array $row): void
    {
        $name    = (string) $row['name'];
        $version = (int) $row['version'];
        $stats   = Keelhob_Tool_Stats::get(Keelhob_Abilities::stats_key($name, $version));
        $history = Keelhob_Abilities::versions($name);
        $tampered = !Keelhob_Abilities::hash_ok($row);
        ?>
        <div class="kh-u-ability<?php echo $tampered ? ' is-blocked' : ''; ?>">
            <div class="kh-u-review-top">
                <span class="kh-u-badge is-live"><?php esc_html_e('Live', 'keelhob'); ?></span>
                <span class="kh-u-review-id">
                    <strong class="kh-u-review-label"><?php echo esc_html((string) $row['label']); ?></strong>
                    <code class="kh-u-mono"><?php echo esc_html($name . ' · v' . $version); ?></code>
                </span>
                <span class="kh-u-flags">
                    <?php if (!empty($row['writes'])) : ?>
                        <span class="kh-u-badge is-writes"><?php esc_html_e('Changes the site', 'keelhob'); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($row['risky'])) : ?>
                        <span class="kh-u-badge is-risky"><?php esc_html_e('Risky', 'keelhob'); ?></span>
                    <?php endif; ?>
                    <span class="kh-u-badge"><?php esc_html_e('Written by your AI', 'keelhob'); ?></span>
                </span>
            </div>

            <p class="kh-u-review-desc"><?php echo esc_html((string) $row['description']); ?></p>

            <?php if ($tampered) : ?>
                <div class="kh-u-note is-bad">
                    <p><strong><?php esc_html_e('Blocked.', 'keelhob'); ?></strong>
                    <?php esc_html_e('This ability\'s code does not match what you approved, so it will not run. Nothing has changed on your site. Ask your AI to submit it again, and reject this version.', 'keelhob'); ?></p>
                </div>
            <?php endif; ?>

            <p class="kh-u-score">
                <?php
                echo esc_html(
                    $stats['score'] === null
                        ? __('Rating: new — not used yet', 'keelhob')
                        : sprintf(
                            /* translators: 1: score, 2: word for the score, 3: run count. */
                            __('Rating: %1$d/100 · %2$s · %3$d runs', 'keelhob'),
                            (int) $stats['score'],
                            (string) $stats['label'],
                            (int) $stats['runs']
                        )
                );
                ?>
            </p>

            <?php if (trim((string) ($row['last_error'] ?? '')) !== '') : ?>
                <p class="kh-u-review-note"><strong><?php esc_html_e('Last failure:', 'keelhob'); ?></strong>
                <?php echo esc_html(mb_substr((string) $row['last_error'], 0, 300)); ?></p>
            <?php endif; ?>

            <details class="kh-u-code-wrap">
                <summary><?php esc_html_e('The code that is running', 'keelhob'); ?></summary>
                <pre class="kh-u-code"><code><?php echo esc_html((string) $row['code']); ?></code></pre>
            </details>

            <details class="kh-u-code-wrap">
                <summary><?php esc_html_e('Versions', 'keelhob'); ?></summary>
                <table class="kh-u-table kh-u-vers">
                    <thead>
                        <tr>
                            <th scope="col"><?php esc_html_e('Version', 'keelhob'); ?></th>
                            <th scope="col"><?php esc_html_e('Status', 'keelhob'); ?></th>
                            <th scope="col"><?php esc_html_e('Rating', 'keelhob'); ?></th>
                            <th scope="col"><?php esc_html_e('What changed', 'keelhob'); ?></th>
                            <th scope="col"><span class="kh-u-sr"><?php esc_html_e('Action', 'keelhob'); ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $ver) : ?>
                        <?php $vstats = Keelhob_Tool_Stats::get(Keelhob_Abilities::stats_key($name, (int) $ver['version'])); ?>
                        <tr>
                            <td><code class="kh-u-mono">v<?php echo esc_html((string) $ver['version']); ?></code></td>
                            <td>
                                <span class="kh-u-badge<?php echo (string) $ver['status'] === 'active' ? ' is-live' : ''; ?>">
                                    <?php echo esc_html(Keelhob_Abilities::status_label((string) $ver['status'])); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($vstats['score'] === null ? __('New', 'keelhob') : $vstats['score'] . '/100 · ' . $vstats['label']); ?></td>
                            <td><?php echo esc_html(mb_substr((string) ($ver['change_note'] ?? ''), 0, 200)); ?></td>
                            <td>
                                <?php if ((string) $ver['status'] === 'retired') : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field(self::NONCE); ?>
                                        <input type="hidden" name="action" value="keelhob_ability_rollback">
                                        <input type="hidden" name="ability_id" value="<?php echo esc_attr((string) $ver['id']); ?>">
                                        <button type="submit" class="button button-small"><?php esc_html_e('Roll back to this', 'keelhob'); ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        </div>
        <?php
    }

    /**
     * The inputs, as `name (type)` lines.
     *
     * Pure and static so the display of a model-supplied schema can be tested
     * without rendering a screen — this is one of the two places on the card
     * where the model controls the strings.
     *
     * @param mixed $schema the stored JSON, or an already-decoded array
     * @return array<int, string>
     */
    public static function input_lines($schema): array
    {
        if (is_string($schema)) {
            $schema = json_decode($schema, true);
        }
        if (!is_array($schema)) {
            return [];
        }
        $props = $schema['properties'] ?? [];
        if (!is_array($props)) {
            return [];
        }
        $required = [];
        if (isset($schema['required']) && is_array($schema['required'])) {
            $required = array_map('strval', $schema['required']);
        }

        $out = [];
        foreach ($props as $key => $spec) {
            $type = is_array($spec) ? (string) ($spec['type'] ?? 'any') : 'any';
            $out[] = sprintf(
                '%s (%s)%s',
                (string) $key,
                $type,
                in_array((string) $key, $required, true) ? ' *' : ''
            );
            if (count($out) >= 30) {
                break;
            }
        }

        return $out;
    }
}
