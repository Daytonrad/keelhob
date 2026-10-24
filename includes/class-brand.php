<?php
/**
 * The Keelhob mark, in the few places WordPress gives a plugin to put one.
 *
 * One class so there is exactly one answer to "where does the logo live", and
 * so a future rename or redraw is a single edit rather than a grep. Everything
 * here is decoration: if a file is missing the admin still works, which is why
 * nothing in this class is allowed to throw or to gate a screen.
 *
 * The mark is never cropped. An earlier version cut it down to the C bowl to
 * survive a 20px square, and the result read as a broken image rather than a
 * logo — the whole point of the mark is the ribbon running out of the bowl.
 *
 * What changes with size is colour, not shape:
 *
 *   mark-mono-40.png  the whole mark, flat white. Below roughly 32px the copper
 *                     and teal average into brown mush; a single-colour
 *                     silhouette stays legible at 20px and at 16px. This is
 *                     also what WordPress itself expects a menu icon to be.
 *   icon-*.png        the whole mark in colour, centred on a square canvas,
 *                     because the update screen asks for a square.
 *   logo.png          the whole mark in colour at its natural width. Used
 *                     beside an <h1>, where there is room for it.
 *
 * Nothing here forces the mark into a square. The mark is wider than it is
 * tall and stays that way; the CSS sets a width and lets the height follow.
 *
 * The exact ratio is NOT written into the CSS. It is MARK_W and MARK_H below,
 * which are the real pixels of mark-mono-40.png, and tests/unit/test-brand.php
 * reads the file and fails if they drift apart. The previous version hardcoded
 * 23/40 in two places, and a redrawn mark then rendered squashed in the
 * sidebar while every test still passed.
 *
 * @package Keelhob
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Brand
{
    /** Where the files live, relative to the plugin root. */
    const PATH = 'assets/img/';

    /**
     * The real pixel size of mark-mono-40.png.
     *
     * Every height on screen is derived from this ratio, so re-exporting the
     * mark at a different shape is a two-number edit here and nowhere else.
     * test-brand.php opens the file and asserts these match it.
     */
    const MARK_W = 40;
    const MARK_H = 33;

    /** Menu-icon WIDTH in the admin sidebar, in CSS pixels. Height follows. */
    const MENU_PX = 20;

    /** Mark width in the admin toolbar, in CSS pixels. Height follows. */
    const BAR_PX = 18;

    /** WordPress's own sidebar row height. The mark is centred inside it. */
    const ROW_PX = 34;

    /** Wide-mark height beside an <h1>, in CSS pixels. */
    const HEADING_PX = 28;

    /** Registered from Keelhob_Admin::hooks(), so nothing loads on the front end. */
    public static function hooks(): void
    {
        add_action('admin_enqueue_scripts', [__CLASS__, 'styles']);
    }

    /**
     * Absolute URL of one brand file.
     *
     * Built from KEELHOB_URL rather than plugins_url() so it is correct for
     * a plugin installed under a renamed directory, and so it stays testable
     * without a WordPress install.
     */
    public static function url(string $file): string
    {
        return KEELHOB_URL . self::PATH . $file;
    }

    /**
     * The value handed to add_menu_page().
     *
     * 'none' means "I will draw it myself", and styles() then draws the mark as
     * a CSS mask filled with `currentColor`. That indirection buys the one
     * thing an <img> cannot do: the mark takes whatever colour WordPress has
     * given the menu text beside it, in every one of the nine admin colour
     * schemes and in every state.
     *
     * The alternative was a white PNG, and it was wrong twice over. Eight
     * schemes have a dark sidebar, but "Light" does not, so white vanished
     * there — and inverting it for that scheme then broke the *current* item,
     * whose background is mid-grey with white text. Each fix needed the next
     * one. A mask has no states to enumerate.
     *
     * This is also how WordPress draws its own menu icons: dashicons is a font,
     * so every core icon inherits `color` for free. This is that, for a shape
     * that is not in the font.
     */
    public static function menu_icon(): string
    {
        return 'none';
    }

    /**
     * Icons for the update screen and the "View details" panel.
     *
     * WordPress reads '1x' at 128px and '2x' at 256px. Without these the
     * Dashboard → Updates row shows a grey placeholder puzzle piece, which for
     * a plugin that updates itself from GitHub reads as "unknown source".
     *
     * @return array{1x:string, 2x:string, default:string}
     */
    public static function icons(): array
    {
        return [
            '1x'      => self::url('icon-128.png'),
            '2x'      => self::url('icon-256.png'),
            'default' => self::url('icon-128.png'),
        ];
    }

    /**
     * The wide mark, as markup to sit inside an <h1>.
     *
     * Returned rather than echoed so a caller can put it inside a heading it is
     * already building, and so the tests can assert on it without buffering.
     *
     * The alt is deliberately empty. The heading beside it already says
     * "Keelhob"; a screen reader announcing the word twice is worse than a
     * decorative image announcing nothing.
     */
    public static function heading_mark(): string
    {
        return sprintf(
            '<img src="%s" alt="" width="%d" height="%d" class="keelhob-brand-mark" '
                . 'style="height:%dpx;width:auto;vertical-align:-6px;margin-right:10px;" />',
            esc_url(self::url('logo.png')),
            (int) round(self::HEADING_PX * self::MARK_W / self::MARK_H),
            self::HEADING_PX,
            self::HEADING_PX
        );
    }

    /** Mark width in the redesigned panel header, in CSS pixels. Height follows. */
    const PANEL_PX = 34;

    /**
     * The wide mark for the redesigned panel header.
     *
     * The same image as heading_mark(), with two differences, and both are the
     * reason this is a second method rather than an argument on the first.
     *
     * It carries NO inline style. heading_mark() has to, because it sits inside
     * a core <h1> on a screen with no stylesheet of its own; this one sits inside
     * .kh-u-head, where assets/css/admin.css owns the layout, and an inline
     * `vertical-align` and `margin-right` would fight the flexbox doing the
     * alignment. The width and height attributes stay — they are not styling,
     * they reserve the box before the image loads and stop the header jumping.
     *
     * The ratio is still taken from MARK_W / MARK_H rather than written down, so
     * a re-exported mark cannot render squashed here either.
     *
     * The alt is empty for the same reason as heading_mark(): the <h1> beside it
     * already says "Keelhob", and a screen reader announcing the word twice is
     * worse than a decorative image announcing nothing.
     */
    public static function panel_mark(): string
    {
        return sprintf(
            '<img src="%s" alt="" width="%d" height="%d" class="kh-u-mark" />',
            esc_url(self::url('logo.png')),
            (int) round(self::PANEL_PX * self::MARK_W / self::MARK_H),
            self::PANEL_PX
        );
    }

    /**
     * The square mark, sized for the admin bar.
     *
     * Styled inline rather than from styles(). Today the node is registered
     * from Keelhob_Admin::hooks(), which only runs under is_admin(), so a
     * class would work — but an admin-bar item is the single most likely thing
     * to be moved to the front end later, and styles() would not follow it
     * there. Inline sizing costs forty bytes and cannot come apart.
     */
    public static function bar_mark(): string
    {
        // A span, not an <img>, for the same reason as the menu icon: the
        // toolbar is light in the "Light" scheme and dark in the other eight,
        // and a mask filled with currentColor is right in all of them without
        // being told which one it is in. styles() draws it.
        return '<span class="keelhob-bar-mark" aria-hidden="true"></span>';
    }

    /**
     * Draws the sidebar icon and the toolbar mark, as masks.
     *
     * The mask takes the shape from the PNG's alpha channel and throws its
     * colours away; `background-color: currentColor` then fills that shape with
     * whatever colour WordPress gave the surrounding text. That is the whole
     * trick, and it is why there is not one rule here mentioning a colour
     * scheme, a hover state, or a current item.
     *
     * The mark is never squashed: the box is sized from MENU_PX and the real
     * ratio, so 20px wide gives 11.5px tall, which is what the logo is.
     *
     * `@supports` matters. Without a mask, `background-color` alone would paint
     * a solid rectangle in the sidebar — worse than no icon. The guard means an
     * ancient browser gets an empty space instead, and the menu still reads.
     */
    public static function styles(): void
    {
        $url = "url('" . esc_url_raw(self::url('mark-mono-40.png')) . "')";

        /*
         * THE BOX IS THE WHOLE ROW, AND THE MASK IS CENTRED IN IT.
         *
         * Measured in a real browser, not reasoned about: WordPress ships
         * `padding: 7px 0` on #adminmenu .wp-menu-image::before, for its own
         * dashicons. This rule never reset it, so the painted box was 17px of
         * content plus 14px of inherited padding — 31px — and a margin-top
         * calculated as if the padding were not there put the mark's centre
         * 7.5px BELOW the centre of the 34px row. It read as a logo sitting
         * low against its own label.
         *
         * Rather than subtract the padding and keep the arithmetic, the box is
         * now the full row height and `center` does the centring. There is no
         * offset to get wrong, and `MENU_PX auto` takes the height from the
         * image's own proportions, so a re-exported mark cannot be squashed.
         */
        $menu_mask = $url . ' no-repeat center / ' . self::MENU_PX . 'px auto';
        $bar_mask  = $url . ' no-repeat center / ' . self::BAR_PX . 'px auto';

        // The toolbar mark is an inline-block of its own, with no inherited
        // padding, so its height is still derived from the mark's ratio.
        $bar_h = (int) round(self::BAR_PX * self::MARK_H / self::MARK_W);

        $css = '@supports ((-webkit-mask-image:url("")) or (mask-image:url(""))){'
            . '#adminmenu #toplevel_page_keelhob .wp-menu-image::before{'
            . 'content:"";display:block;'
            // padding:0 is the fix. WordPress sets 7px 0 here for dashicons.
            . 'padding:0;'
            . 'width:' . self::MENU_PX . 'px;height:' . self::ROW_PX . 'px;'
            . 'margin:0 auto;'
            . 'background-color:currentColor;'
            . '-webkit-mask:' . $menu_mask . ';mask:' . $menu_mask . ';}'
            . '#wp-admin-bar-keelhob .keelhob-bar-mark{'
            . 'display:inline-block;vertical-align:middle;'
            . 'padding:0;'
            . 'width:' . self::BAR_PX . 'px;height:' . $bar_h . 'px;'
            . 'margin:0 6px 2px 0;'
            . 'background-color:currentColor;'
            . '-webkit-mask:' . $bar_mask . ';mask:' . $bar_mask . ';}'
            . '}';

        // wp-admin is always present in the admin, so there is no stylesheet to
        // register first and nothing to break if a theme dequeues its own.
        wp_add_inline_style('wp-admin', $css);
    }
}
