<?php
/**
 * Feedback tools — the two calls behind operating-protocol rule 15.
 *
 * There are exactly two, and the split between them is the consent boundary.
 * `feedback_status` reads and drafts; it can never send anything. `feedback_send`
 * is the only thing that talks to the network, and it refuses unless it is told
 * a person said yes.
 *
 * Both are always available. A feedback report the owner has to switch a
 * capability on to give is a report nobody gives — and neither tool can change
 * anything on the site, so there is nothing for a capability fence to protect.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Keelhob_Tools_Feedback
{
    public static function register(Keelhob_Tool_Registry $r): void
    {
        $r->add(
            'feedback_status',
            'Check whether a feedback report is due, and get the exact draft to show the user. Call this when you are writing up finished work. Returns due=false most of the time — say nothing at all then. When due=true, show the WHOLE draft to the user word for word and ask if they want to send it or add anything; pass their addition back as `add_note` to get a rewritten draft. Never send anything with this tool; it only reads. Read-only.',
            [
                'type' => 'object',
                'properties' => [
                    'add_note' => [
                        'type'        => 'string',
                        'description' => 'Something the user asked to add, in their words. Redacted before it is stored or shown back.',
                    ],
                ],
            ],
            [self::class, 'status'],
            ['readOnlyHint' => true, 'title' => 'Feedback: is a report due?']
        );

        $r->add(
            'feedback_send',
            'Send the feedback report the user has just approved, or record that they declined. Only call this after showing the full draft from feedback_status and getting an explicit yes or no. Pass approved=false when they say no — that records the refusal so they are not asked again for another week, and sends nothing. If the response comes back with throttled=true the report was NOT lost: the project\'s endpoint hit its daily limit for this server\'s network address, which happens when several sites share one. Say one line about it and move on; the plugin will offer it again tomorrow.',
            [
                'type' => 'object',
                'properties' => [
                    'approved' => [
                        'type'        => 'boolean',
                        'description' => 'true only if the user saw the whole draft and said yes.',
                    ],
                ],
                'required' => ['approved'],
            ],
            [self::class, 'send'],
            ['title' => 'Feedback: send the approved report']
        );
    }

    /**
     * Is a report due, and what exactly would it say?
     *
     * Returns the draft in full rather than a summary, because the agent is
     * required to show it in full and cannot show what it was not given.
     */
    public static function status(array $args): array
    {
        if (!Keelhob_Feedback::enabled()) {
            return [
                'success' => true,
                'due'     => false,
                'reason'  => 'The owner has the feedback log switched off. Nothing is being recorded. Do not mention it.',
            ];
        }

        // An addition from the user goes into the log as an agent-sourced note
        // before the draft is rebuilt, so what they are shown the second time
        // is the real thing and not a preview of it.
        $add = isset($args['add_note']) ? trim((string) $args['add_note']) : '';
        if ($add !== '') {
            Keelhob_Feedback::record([
                'friction' => 'wrong_visual_result',
                'note'     => $add,
                'source'   => 'agent',
            ]);
        }

        if (!Keelhob_Feedback::should_offer()) {
            return [
                'success' => true,
                'due'     => false,
                'reason'  => self::not_due_reason(),
            ];
        }

        $report = Keelhob_Feedback::build_report();
        if (empty($report['events'])) {
            return ['success' => true, 'due' => false, 'reason' => 'Nothing to report. Say nothing.'];
        }

        return [
            'success'  => true,
            'due'      => true,
            'events'   => $report['events'],
            'omitted'  => $report['omitted'],
            'assurance' => 'No site address, no page content, no keys. Names and addresses are stripped in PHP before this text existed.',
            'show_user' => self::draft_text($report),
            'next'      => 'Show the user show_user exactly as written. Then ask: "Send this to the Keelhob project? Or is there anything you would add or change?" If they add something, call feedback_status again with add_note. Call feedback_send only after a clear yes or no.',
        ];
    }

    /**
     * Why no report is due, in words the agent can act on.
     *
     * Three different "no" answers, and they are worth telling apart: nothing
     * to say, already asked, and asked-but-the-server-was-busy. Only the third
     * one comes back tomorrow rather than next week, and an agent that cannot
     * tell them apart will either nag or go quiet for a week over a problem
     * that cleared overnight.
     */
    public static function not_due_reason(?int $now = null): string
    {
        if (Keelhob_Feedback::count_unsent() < 1) {
            return 'Nothing has gone wrong since the last report. Say nothing.';
        }
        if (($now ?? time()) < Keelhob_Feedback::retry_after()) {
            return 'A report is ready, but the project\'s endpoint was at its daily limit. Holding until tomorrow. Say nothing — this is not a problem with this site.';
        }
        return 'A report was already offered within the last 7 days. Say nothing.';
    }

    /**
     * Record the answer.
     *
     * The clock is reset either way. A refusal resets it for the same reason an
     * acceptance does: the person has now been asked, and asking again next
     * session because they said no is precisely the behaviour that gets the
     * whole thing switched off.
     */
    public static function send(array $args): array
    {
        $approved = !empty($args['approved']);

        if (!$approved) {
            Keelhob_Feedback::mark_offered();
            return [
                'success' => true,
                'sent'    => false,
                'message' => 'Nothing was sent. Recorded. Do not raise it again in this session.',
            ];
        }

        if (!Keelhob_Feedback::enabled()) {
            Keelhob_Feedback::mark_offered();
            return ['success' => false, 'error' => 'The feedback log is switched off on this site.'];
        }

        $report = Keelhob_Feedback::build_report();
        $result = Keelhob_Feedback::send($report);

        // THE THROTTLED CASE IS NOT A FAILURE, and the difference is why the
        // clock is not touched here.
        //
        // The endpoint allows a limited number of reports a day per outbound
        // address, and an agency or a shared host has many sites behind one. So
        // a 429 does not mean "your report was bad", it means "somebody else on
        // your address got there first today". The report is still in the log
        // and still worth sending.
        //
        // Marking the offer as spent would burn this site's one chance a week
        // on a request that never arrived. Instead the offer stays unspent and
        // a short hold is set, so it comes back tomorrow. The person will be
        // asked again — deliberately. An approval stored on disk to be replayed
        // later is the first step toward "remember my answer and stop asking",
        // which is where this stops being consent.
        if (!empty($result['throttled'])) {
            Keelhob_Feedback::mark_retry_after();
            return [
                'success'   => true,
                'sent'      => false,
                'throttled' => true,
                'events'    => count($report['events']),
                'message'   => 'Not sent — the project\'s endpoint is at its daily limit for this server\'s network address, which happens when several sites share one address. Nothing is wrong with the report and nothing was lost; it is still saved here. Tell the user in ONE line that it will be offered again tomorrow, then move on. Do not retry in this session and do not treat this as an error on their site.',
            ];
        }

        // Every other failure is reported as a fact and then dropped. No retry,
        // no queue: a failed report is worth far less than a session derailed by
        // an error about a report.
        Keelhob_Feedback::mark_offered();

        return [
            'success' => true,
            'sent'    => (bool) $result['ok'],
            'events'  => count($report['events']),
            'message' => !empty($result['ok'])
                ? 'Sent. Thank them briefly and move on.'
                : 'The send did not go through. Say so in one line and move on — do not retry.',
        ];
    }

    /** The draft as the user should see it. Plain text, every field, nothing folded. */
    public static function draft_text(array $report): string
    {
        $lines = [
            'A few things went wrong while working on this site. I can send this to the Keelhob project. This is all of it:',
            '',
        ];
        $n = 0;
        foreach ($report['events'] as $e) {
            $n++;
            $head = sprintf(
                '  %d  %s  %s%s',
                $n,
                $e['friction'],
                $e['tool'] !== '' ? $e['tool'] : 'none',
                ((int) $e['attempts']) > 1 ? '  x' . (int) $e['attempts'] : ''
            );
            $lines[] = $head;
            if (!empty($e['skill'])) {
                $lines[] = '     skill: ' . $e['skill'];
            }
            if (!empty($e['note'])) {
                $lines[] = '     ' . $e['note'];
            }
            $lines[] = '';
        }
        if (!empty($report['omitted'])) {
            $lines[] = sprintf('  (%d more not included)', (int) $report['omitted']);
            $lines[] = '';
        }
        $lines[] = 'No site address, no content, no keys. Send it, or anything to add?';

        return implode("\n", $lines);
    }
}
