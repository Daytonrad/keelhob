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

final class Claudpress_Tools_Feedback
{
    public static function register(Claudpress_Tool_Registry $r): void
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
            'Send the feedback report the user has just approved, or record that they declined. Only call this after showing the full draft from feedback_status and getting an explicit yes or no. Pass approved=false when they say no — that records the refusal so they are not asked again for another week, and sends nothing.',
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
        if (!Claudpress_Feedback::enabled()) {
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
            Claudpress_Feedback::record([
                'friction' => 'wrong_visual_result',
                'note'     => $add,
                'source'   => 'agent',
            ]);
        }

        if (!Claudpress_Feedback::should_offer()) {
            return [
                'success' => true,
                'due'     => false,
                'reason'  => Claudpress_Feedback::count_unsent() < 1
                    ? 'Nothing has gone wrong since the last report. Say nothing.'
                    : 'A report was already offered within the last 7 days. Say nothing.',
            ];
        }

        $report = Claudpress_Feedback::build_report();
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
            'next'      => 'Show the user show_user exactly as written. Then ask: "Send this to the Claudpress project? Or is there anything you would add or change?" If they add something, call feedback_status again with add_note. Call feedback_send only after a clear yes or no.',
        ];
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
        Claudpress_Feedback::mark_offered();

        if (!$approved) {
            return [
                'success' => true,
                'sent'    => false,
                'message' => 'Nothing was sent. Recorded. Do not raise it again in this session.',
            ];
        }

        if (!Claudpress_Feedback::enabled()) {
            return ['success' => false, 'error' => 'The feedback log is switched off on this site.'];
        }

        $report = Claudpress_Feedback::build_report();
        $ok     = Claudpress_Feedback::send($report);

        // A failure is reported as a fact and then dropped. No retry, no queue:
        // a failed report is worth far less than a session derailed by an error
        // about a report.
        return [
            'success' => true,
            'sent'    => $ok,
            'events'  => count($report['events']),
            'message' => $ok
                ? 'Sent. Thank them briefly and move on.'
                : 'The send did not go through. Say so in one line and move on — do not retry.',
        ];
    }

    /** The draft as the user should see it. Plain text, every field, nothing folded. */
    public static function draft_text(array $report): string
    {
        $lines = [
            'A few things went wrong while working on this site. I can send this to the Claudpress project. This is all of it:',
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
