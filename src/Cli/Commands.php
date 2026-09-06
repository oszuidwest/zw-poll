<?php
/**
 * Defines WP-CLI maintenance commands.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Cli;

use ZuidWest\Poll\Cron\PollCloseSweep;
use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Vote\AggregateCache;
use ZuidWest\Poll\Vote\PollReset;
use WP_CLI;
use WP_Post;

/**
 * `wp zw-poll` maintenance commands over the same cache/reset services
 * used by REST, keeping web and CLI behavior aligned.
 */
final class Commands
{
    /**
     * Stores collaborators for CLI maintenance actions.
     *
     * @param AggregateCache $cache    Aggregate cache service.
     * @param PollReset      $resetter Vote reset service.
     * @param PollCloseSweep $sweep    Expired-poll close service.
     */
    public function __construct(
        private readonly AggregateCache $cache,
        private readonly PollReset $resetter,
        private readonly PollCloseSweep $sweep,
    ) {}

    /**
     * Registers the command namespace when WP-CLI is active.
     *
     * @param AggregateCache $cache    Aggregate cache service.
     * @param PollReset      $resetter Vote reset service.
     * @param PollCloseSweep $sweep    Expired-poll close service.
     */
    public static function register(AggregateCache $cache, PollReset $resetter, PollCloseSweep $sweep): void
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }
        WP_CLI::add_command('zw-poll', new self($cache, $resetter, $sweep));
    }

    /**
     * Recomputes the cached aggregate for a poll from the votes table.
     *
     * Use this after a manual database change or if the cached counts ever
     * drift from the source-of-truth votes table.
     *
     * ## OPTIONS
     *
     * <id>
     * : The poll (zw_poll) post ID.
     *
     * ## EXAMPLES
     *
     *     wp zw-poll rebuild 42
     *
     * @param array<int, string> $args Positional command arguments.
     */
    public function rebuild(array $args): void
    {
        $poll = $this->requirePoll($args[0] ?? '');
        $aggregate = $this->cache->rebuild($poll->ID);

        WP_CLI::success(sprintf(
            /* translators: 1: poll ID, 2: total votes, 3: number of options. */
            __('Aggregaat herbouwd voor poll %1$d: %2$d stemmen over %3$d opties.', 'zw-poll'),
            $poll->ID,
            $aggregate['total'],
            count($aggregate['counts'])
        ));
    }

    /**
     * Lists all polls with their status and vote totals.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Render output in a particular format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - yaml
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp zw-poll list
     *     wp zw-poll list --format=json
     *
     * @subcommand list
     *
     * @param array<int, string>    $args       Positional command arguments.
     * @param array<string, string> $assoc_args Associative command arguments.
     */
    public function list_polls(array $args, array $assoc_args): void
    {
        $format = $assoc_args['format'] ?? 'table';

        $polls = get_posts([
            'post_type' => PollPostType::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => false,
        ]);

        $rows = [];
        foreach ($polls as $poll) {
            $aggregate = AggregateCache::projectAggregate(
                $this->cache->get($poll->ID),
                PollPostType::options($poll->ID)
            );
            $rows[] = [
                'id' => $poll->ID,
                'title' => $poll->post_title,
                'status' => PollPostType::status($poll->ID),
                'votes' => $aggregate['total'],
                'updated' => $aggregate['updated_at'],
                'closes_at' => PollPostType::formatClosesAt(PollPostType::closesAt($poll->ID)),
            ];
        }

        if ($rows === [] && !in_array($format, ['json', 'csv'], true)) {
            WP_CLI::log(__('Geen polls gevonden.', 'zw-poll'));

            return;
        }

        WP_CLI\Utils\format_items(
            $format,
            $rows,
            ['id', 'title', 'status', 'votes', 'updated', 'closes_at']
        );
    }

    /**
     * Closes published polls whose configured deadline has passed.
     *
     * Uses the same idempotent sweep as WP-Cron. A system cron may invoke
     * this command more frequently when precise closing is required.
     *
     * ## EXAMPLES
     *
     *     wp zw-poll close-expired
     *
     * @subcommand close-expired
     */
    public function close_expired(): void
    {
        $closed = $this->sweep->sweep();

        WP_CLI::success(sprintf(
            /* translators: %d: number of expired polls closed. */
            _n('%d verlopen poll gesloten.', '%d verlopen polls gesloten.', $closed, 'zw-poll'),
            $closed
        ));
    }

    /**
     * Deletes all votes for a poll and resets its aggregate to zero.
     *
     * Destructive and irreversible. The poll itself and its options stay intact.
     *
     * ## OPTIONS
     *
     * <id>
     * : The poll (zw_poll) post ID.
     *
     * [--yes]
     * : Skip the confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp zw-poll reset 42 --yes
     *
     * @param array<int, string>    $args       Positional command arguments.
     * @param array<string, string> $assoc_args Associative command arguments.
     */
    public function reset(array $args, array $assoc_args): void
    {
        $poll = $this->requirePoll($args[0] ?? '');

        WP_CLI::confirm(
            sprintf(
                /* translators: 1: poll ID, 2: poll title. */
                __('Alle stemmen voor poll %1$d (%2$s) verwijderen?', 'zw-poll'),
                $poll->ID,
                $poll->post_title
            ),
            $assoc_args
        );

        $result = $this->resetter->reset($poll->ID);

        WP_CLI::success(sprintf(
            /* translators: 1: number of deleted votes, 2: poll ID. */
            __('%1$d stemmen verwijderd voor poll %2$d.', 'zw-poll'),
            $result['deleted'],
            $poll->ID
        ));
    }

    /**
     * Resolves a poll ID argument or aborts the command.
     *
     * @param string $raw Raw poll ID argument.
     */
    private function requirePoll(string $raw): WP_Post
    {
        $poll_id = (int) $raw;
        $poll = $poll_id > 0 ? get_post($poll_id) : null;

        if (!($poll instanceof WP_Post) || $poll->post_type !== PollPostType::POST_TYPE) {
            WP_CLI::error(sprintf(
                /* translators: %d: the post ID that was supplied. */
                __('Geen poll gevonden met ID %d.', 'zw-poll'),
                $poll_id
            ));
            exit; // WP_CLI::error() halts; exit makes the return type explicit for analysers.
        }

        return $poll;
    }
}
