<?php
/**
 * Pushing snippets up to the private library and pulling them down.
 *
 * TWO RULES GOVERN EVERY METHOD HERE, and both exist because the alternative
 * loses somebody's work:
 *
 *   1. THIS FILE CANNOT DELETE FROM THE LIBRARY. There is no method for it, and
 *      the API has no route a site token can reach. Deleting happens on the
 *      website, by a person, who is asked for their password again.
 *
 *   2. A PULL NEVER REMOVES OR SWITCHES OFF ANYTHING LOCAL. If a snippet was
 *      deleted from the library, this site stops OFFERING it — the copy already
 *      running here keeps running, untouched. Losing a library entry must never
 *      silently change what a live site does.
 *
 * The site's snippets and the library are two separate collections you push and
 * pull between. Neither is a mirror of the other, and neither can wipe the other.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Claudpress_Pro_Cloud_Library
{
    const MAP_OPTION = 'claudpress_cloud_map';

    /**
     * What this site knows about each library row: the rev it last saw, and the
     * hash the two agreed on. Kept in the add-on's own option so uninstalling
     * Pro leaves the free plugin's tables exactly as they were.
     *
     * @return array<string, array{rev:int, synced_hash:string}>
     */
    public static function map(): array
    {
        $map = get_option(self::MAP_OPTION, []);
        return is_array($map) ? $map : [];
    }

    private static function remember(string $uid, int $rev, string $hash): void
    {
        $map = self::map();
        $map[$uid] = ['rev' => $rev, 'synced_hash' => $hash];
        update_option(self::MAP_OPTION, $map, false);
    }

    /**
     * The content hash.
     *
     * MUST match src/domain/sync.ts on the server, field for field and in this
     * order. If the two ever disagree, every snippet looks permanently modified
     * and every site shows a Sync button that never goes out.
     *
     * Title and description are deliberately excluded: renaming a snippet is not
     * a code change, and hashing the title would report drift for a rename.
     */
    public static function content_hash(array $row): string
    {
        // chr(0), not a space. With a space the fields could in principle run
        // together ambiguously - a snippet whose CODE ends in the same text as
        // the following field values would hash identically to a different
        // snippet. Vanishingly unlikely, and a collision between two different
        // snippets is not a bug anybody would ever diagnose. A null byte cannot
        // occur in any of these fields, so the encoding is unambiguous.
        //
        // src/domain/sync.ts uses the same separator, and
        // test/cross-language-hash.mjs asserts the two agree.
        $canonical = implode(chr(0), [
            (string) ($row['code'] ?? ''),
            (string) ($row['snippet_type'] ?? 'php'),
            (string) ($row['location'] ?? 'everywhere'),
            (string) (int) ($row['priority'] ?? 10),
            self::conditions_json($row),
        ]);

        return hash('sha256', $canonical);
    }

    /**
     * Conditions, encoded the way JSON.stringify() would.
     *
     * PHP's json_encode and JavaScript's JSON.stringify agree on everything
     * here except the empty case: PHP gives "[]" for an empty array where JS
     * gives "null". One character, and it would make every conditionless
     * snippet — which is most of them — hash differently on the two sides.
     */
    private static function conditions_json(array $row): string
    {
        $raw = $row['conditions_json'] ?? null;

        if ($raw === null || $raw === '' || $raw === '[]' || $raw === '{}') {
            return 'null';
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!$decoded) {
            return 'null';
        }

        return (string) wp_json_encode($decoded);
    }

    /** Push one local snippet up. */
    public static function push(int $snippet_id): array
    {
        $row = Claudpress_Snippets::get($snippet_id);
        if (!$row) {
            return ['ok' => false, 'error' => __('That snippet no longer exists.', 'claudpress-pro')];
        }

        $uid  = (string) $row['uid'];
        $map  = self::map();
        $hash = self::content_hash($row);

        $response = Claudpress_Pro_Cloud::post('/v1/library', [
            'uid'          => $uid,
            'title'        => (string) $row['name'],
            'description'  => (string) ($row['description'] ?? ''),
            'code'         => (string) $row['code'],
            'snippet_type' => (string) $row['snippet_type'],
            'location'     => (string) $row['location'],
            'priority'     => (int) $row['priority'],
            'tags'         => Claudpress_Snippets::tags_array($row['tags'] ?? ''),
            'conditions'   => json_decode((string) ($row['conditions_json'] ?? 'null'), true),
            // What this site last saw. The server refuses the push if the
            // library has moved past it, rather than discarding another site's
            // work without telling anybody.
            'seen_rev'     => $map[$uid]['rev'] ?? null,
        ]);

        if (!$response['ok']) {
            if (($response['status'] ?? 0) === 409) {
                return [
                    'ok'       => false,
                    'conflict' => true,
                    'error'    => __('Someone changed this in your library from another site. Pull first, then decide.', 'claudpress-pro'),
                ];
            }
            return ['ok' => false, 'error' => $response['error']];
        }

        self::remember($uid, (int) ($response['data']['rev'] ?? 0), $hash);

        return ['ok' => true, 'status' => (string) ($response['data']['status'] ?? 'saved')];
    }

    /**
     * Pull the library.
     *
     * Returns what is THERE. It writes nothing to the snippets table on its own
     * — importing a specific snippet is a separate, deliberate act. A pull that
     * silently installed things would mean opening a screen changed the site.
     */
    public static function pull(): array
    {
        $response = Claudpress_Pro_Cloud::get('/v1/library');

        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error']];
        }

        $snippets = (array) ($response['data']['snippets'] ?? []);
        $map      = self::map();
        $local    = self::local_by_uid();
        $out      = [];

        foreach ($snippets as $remote) {
            $uid = (string) ($remote['uid'] ?? '');
            if ($uid === '') {
                continue;
            }

            $here    = $local[$uid] ?? null;
            $deleted = !empty($remote['deleted']);

            $out[] = [
                'uid'         => $uid,
                'title'       => (string) ($remote['title'] ?? ''),
                'description' => (string) ($remote['description'] ?? ''),
                'tags'        => (array) ($remote['tags'] ?? []),
                'rev'         => (int) ($remote['rev'] ?? 0),
                'deleted'     => $deleted,
                'on_this_site' => $here !== null,
                // A snippet removed from the library that is still RUNNING here
                // is the case worth surfacing. The site is told; the site is not
                // changed. Whether to remove it is the owner's call.
                'still_active_here' => $here !== null && !empty($here['active']),
                'state'       => self::state_for($uid, $here, $remote, $map),
            ];
        }

        return ['ok' => true, 'snippets' => $out, 'max_rev' => (int) ($response['data']['max_rev'] ?? 0)];
    }

    /**
     * Where this snippet stands, from three hashes.
     *
     * Mirrors syncState() in src/domain/sync.ts. Hashes, never timestamps:
     * updated_at moves when somebody saves without editing, and a timestamp
     * comparison would call that drift and offer a conflict over identical code.
     */
    private static function state_for(string $uid, ?array $here, array $remote, array $map): string
    {
        $local_hash  = $here ? self::content_hash($here) : null;
        $synced_hash = $map[$uid]['synced_hash'] ?? null;
        $cloud_hash  = !empty($remote['deleted']) ? null : (string) ($remote['content_hash'] ?? '');

        if ($local_hash !== null && ($cloud_hash === null || $cloud_hash === '')) {
            return 'new-locally';
        }
        if ($local_hash === null && $cloud_hash) {
            return 'new-in-cloud';
        }
        if ($local_hash === null) {
            return 'up-to-date';
        }

        $local_moved = $local_hash !== $synced_hash;
        $cloud_moved = $cloud_hash !== $synced_hash;

        if (!$local_moved && !$cloud_moved) {
            return 'up-to-date';
        }
        if ($local_moved && !$cloud_moved) {
            return 'local-changed';
        }
        if (!$local_moved && $cloud_moved) {
            return 'cloud-changed';
        }

        // Both moved. There is no safe automatic answer: a three-way merge of
        // PHP that "succeeds" can fail to parse on a live site, or parse and be
        // subtly wrong. A person decides.
        return $local_hash === $cloud_hash ? 'up-to-date' : 'conflict';
    }

    /**
     * Bring one library snippet onto this site.
     *
     * Lands INACTIVE, always, and goes through the same parse gate as anything
     * else. Code arriving from elsewhere is exactly the code somebody should
     * read before it executes.
     */
    public static function import(string $uid): array
    {
        $response = Claudpress_Pro_Cloud::get('/v1/library');
        if (!$response['ok']) {
            return ['ok' => false, 'error' => $response['error']];
        }

        $match = null;
        foreach ((array) ($response['data']['snippets'] ?? []) as $remote) {
            if ((string) ($remote['uid'] ?? '') === $uid) {
                $match = $remote;
                break;
            }
        }

        if (!$match || !empty($match['deleted'])) {
            return ['ok' => false, 'error' => __('That snippet is not in your library any more.', 'claudpress-pro')];
        }

        $saved = Claudpress_Snippets::save([
            'uid'          => $uid,
            'name'         => (string) ($match['title'] ?? __('Untitled', 'claudpress-pro')),
            'description'  => (string) ($match['description'] ?? ''),
            'code'         => (string) ($match['code'] ?? ''),
            'snippet_type' => (string) ($match['snippet_type'] ?? 'php'),
            'location'     => (string) ($match['location'] ?? 'everywhere'),
            'priority'     => (int) ($match['priority'] ?? 10),
            'tags'         => implode(',', (array) ($match['tags'] ?? [])),
            'source'       => 'cloud',
            'active'       => 0,
        ]);

        self::remember($uid, (int) ($match['rev'] ?? 0), (string) ($match['content_hash'] ?? ''));

        return ['ok' => true, 'id' => (int) ($saved['id'] ?? 0)];
    }

    /** @return array<string, array<string,mixed>> */
    private static function local_by_uid(): array
    {
        $out = [];
        foreach (Claudpress_Snippets::query([]) as $row) {
            $uid = trim((string) ($row['uid'] ?? ''));
            if ($uid !== '') {
                $out[$uid] = $row;
            }
        }
        return $out;
    }
}
