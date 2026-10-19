<?php
/**
 * Exposes authenticated poll maintenance through the Abilities API.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Abilities;

use ZuidWest\Poll\Cron\PollCloseSweep;
use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Vote\AggregateCache;
use ZuidWest\Poll\Vote\PollReset;
use WP_Error;
use WP_Post;

/** Registers schemas, permissions and adapters for the shared domain services. */
final class PollAbilities
{
    /**
     * Stores the same maintenance services used by REST, cron and WP-CLI.
     *
     * @param AggregateCache $cache    Aggregate service.
     * @param PollReset      $resetter Reset service.
     * @param PollCloseSweep $sweep    Expiration service.
     */
    public function __construct(
        private readonly AggregateCache $cache,
        private readonly PollReset $resetter,
        private readonly PollCloseSweep $sweep,
    ) {}

    /** Registers both lazy registry hooks. */
    public function register(): void
    {
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
    }

    /** Registers the plugin category before abilities are initialized. */
    public function registerCategory(): void
    {
        wp_register_ability_category('zw-poll', [
            'label' => __('ZuidWest Poll', 'zw-poll'),
            'description' => __('Polls en stemmen beheren.', 'zw-poll'),
        ]);
    }

    /** Registers strict schemas and annotations for each operation. */
    public function registerAbilities(): void
    {
        $id = ['type' => 'integer', 'minimum' => 1];
        $status = ['type' => 'string', 'enum' => ['open', 'closed']];
        $poll_input = self::objectSchema(['id' => $id]);
        $results = self::objectSchema([
            'id' => $id,
            'counts' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer', 'minimum' => 0]],
            'total' => ['type' => 'integer', 'minimum' => 0],
            'updated_at' => ['type' => 'string'],
        ]);
        $this->add('list-polls', __('Polls opvragen', 'zw-poll'),
            __('Toont een pagina met polls die je mag bewerken.', 'zw-poll'),
            self::objectSchema([
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                'search' => ['type' => 'string', 'maxLength' => 200, 'default' => ''],
            ], []),
            ['type' => 'array', 'items' => self::objectSchema([
                'id' => $id,
                'title' => ['type' => 'string'],
                'status' => $status,
                'post_status' => ['type' => 'string'],
                'closes_at' => ['type' => 'integer', 'minimum' => 0],
            ])],
            [$this, 'listPolls'], [$this, 'canManage'], true, false, true
        );
        $this->add('get-results', __('Uitslag opvragen', 'zw-poll'),
            __('Leest de uitslag van een poll zonder gegevens te wijzigen.', 'zw-poll'),
            $poll_input, $results, [$this, 'getResults'], [$this, 'canEditPoll'], true, false, true
        );
        $this->add('set-status', __('Poll openen of sluiten', 'zw-poll'),
            __('Wijzigt de stemstatus; heropenen verwijdert een verlopen sluitingsdatum.', 'zw-poll'),
            self::objectSchema(['id' => $id, 'status' => $status]),
            self::objectSchema(['id' => $id, 'status' => $status]),
            [$this, 'setStatus'], [$this, 'canEditPoll'], false, false, true
        );
        $this->add('rebuild-aggregate', __('Aggregaat herbouwen', 'zw-poll'),
            __('Herberekent en bewaart de uitslag vanuit de opgeslagen stemmen.', 'zw-poll'),
            $poll_input, $results, [$this, 'rebuild'], [$this, 'canEditPoll'], false, false, false
        );
        $this->add('reset-votes', __('Stemmen verwijderen', 'zw-poll'),
            __('Verwijdert alle stemmen onomkeerbaar en maakt bestaande stemcookies ongeldig.', 'zw-poll'),
            self::objectSchema(['id' => $id, 'confirm' => ['type' => 'boolean', 'enum' => [true]]]),
            self::objectSchema(['deleted' => ['type' => 'integer', 'minimum' => 0], 'results' => $results]),
            [$this, 'resetVotes'], [$this, 'canEditPoll'], false, true, false
        );
        $this->add('close-expired', __('Verlopen polls sluiten', 'zw-poll'),
            __('Sluit verlopen gepubliceerde polls die je mag bewerken.', 'zw-poll'),
            self::objectSchema([]), self::objectSchema(['closed' => ['type' => 'integer', 'minimum' => 0]]),
            [$this, 'closeExpired'], [$this, 'canManage'], false, false, true
        );
    }

    /**
     * Builds an object contract with no unknown properties.
     *
     * @param array<string, mixed> $properties Property schemas.
     * @param list<string>|null    $required   Required names; all by default.
     * @return array<string, mixed>
     */
    private static function objectSchema(array $properties, ?array $required = null): array
    {
        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required ?? array_keys($properties),
            'additionalProperties' => false,
        ];
    }

    /**
     * Registers an externally discoverable, permission-checked operation.
     *
     * @param 'list-polls'|'get-results'|'set-status'|'rebuild-aggregate'|'reset-votes'|'close-expired' $name Operation suffix.
     * @param string                                                                                    $label       Translated label.
     * @param string                                                                                    $description Translated behavior.
     * @param array<string, mixed>                                                                      $input       Input schema.
     * @param array<string, mixed>                                                                      $output      Output schema.
     * @param callable                                                                                  $execute     Execution adapter.
     * @param callable                                                                                  $permission  Authorization callback.
     * @param bool                                                                                      $readonly    Whether the operation is read-only.
     * @param bool                                                                                      $destructive Whether data is irreversibly removed.
     * @param bool                                                                                      $idempotent  Whether repeating the call has no additional effects.
     */
    private function add(
        string $name,
        string $label,
        string $description,
        array $input,
        array $output,
        callable $execute,
        callable $permission,
        bool $readonly,
        bool $destructive,
        bool $idempotent,
    ): void {
        wp_register_ability('zw-poll/' . $name, [
            'label' => $label,
            'description' => $description,
            'category' => 'zw-poll',
            'input_schema' => $input,
            'output_schema' => $output,
            'execute_callback' => $execute,
            'permission_callback' => $permission,
            // These operations are intended for authenticated REST and automation clients.
            // Public exposure never bypasses the permission callback.
            'meta' => [
                'public' => true,
                'annotations' => compact('readonly', 'destructive', 'idempotent'),
            ],
        ]);
    }

    /** Checks the global maintenance capability. */
    public function canManage(): bool
    {
        return current_user_can('manage_zw_polls');
    }

    /**
     * Authorizes the target as well as the operation.
     *
     * @param array{id: int} $input Validated input.
     */
    public function canEditPoll(array $input): bool|WP_Error
    {
        if (!$this->canManage()) {
            return false;
        }
        $poll = get_post($input['id']);
        if (!$poll instanceof WP_Post || $poll->post_type !== PollPostType::POST_TYPE) {
            return new WP_Error('poll_not_found', __('Poll bestaat niet.', 'zw-poll'), ['status' => 404]);
        }
        return current_user_can('edit_post', $poll->ID);
    }

    /**
     * Lists only editable objects, including unpublished polls.
     *
     * @param array{page?: int, per_page?: int, search?: string} $input Validated pagination and search.
     * @return list<array{id: int, title: string, status: string, post_status: string, closes_at: int}>
     */
    public function listPolls(array $input): array
    {
        $polls = get_posts([
            'post_type' => PollPostType::POST_TYPE,
            'post_status' => ['publish', 'draft', 'pending', 'private', 'future'],
            'posts_per_page' => $input['per_page'] ?? 20,
            'paged' => $input['page'] ?? 1,
            's' => $input['search'] ?? '',
            'orderby' => 'ID',
            'order' => 'DESC',
            'suppress_filters' => false,
        ]);
        $rows = [];
        foreach ($polls as $poll) {
            if (current_user_can('edit_post', $poll->ID)) {
                $rows[] = [
                    'id' => $poll->ID,
                    'title' => $poll->post_title,
                    'status' => PollPostType::status($poll->ID),
                    'post_status' => $poll->post_status,
                    'closes_at' => PollPostType::closesAt($poll->ID),
                ];
            }
        }
        return $rows;
    }

    /**
     * Reads results without a cache write, even when the aggregate is absent.
     *
     * @param array{id: int} $input Validated target.
     * @return array<string, mixed>
     */
    public function getResults(array $input): array
    {
        $id = (int) $input['id'];
        return $this->results($id, $this->cache->read($id));
    }

    /**
     * Changes the poll's vote status through the existing status hooks.
     *
     * @param array{id: int, status: string} $input Validated target and status.
     * @return array{id: int, status: string}|WP_Error
     */
    public function setStatus(array $input): array|WP_Error
    {
        $id = (int) $input['id'];
        if (PollPostType::status($id) !== $input['status']
            && update_post_meta($id, PollPostType::META_STATUS, $input['status']) === false
        ) {
            return new WP_Error('poll_status_failed', __('Pollstatus kon niet worden opgeslagen.', 'zw-poll'));
        }
        return ['id' => $id, 'status' => PollPostType::status($id)];
    }

    /**
     * Rebuilds through the same service as WP-CLI.
     *
     * @param array{id: int} $input Validated target.
     * @return array<string, mixed>
     */
    public function rebuild(array $input): array
    {
        $id = (int) $input['id'];
        return $this->results($id, $this->cache->rebuild($id));
    }

    /**
     * Resets through the same service as the admin UI and WP-CLI.
     *
     * @param array{id: int, confirm: true} $input Validated confirmation and target.
     * @return array<string, mixed>|WP_Error
     */
    public function resetVotes(array $input): array|WP_Error
    {
        $id = (int) $input['id'];
        $result = $this->resetter->reset($id);
        if ($result instanceof WP_Error) {
            return $result;
        }
        return ['deleted' => $result['deleted'], 'results' => $this->results($id, $result['aggregate'])];
    }

    /**
     * Applies the shared sweep only to objects this user may edit.
     *
     * @return array{closed: int}
     */
    public function closeExpired(): array
    {
        return ['closed' => $this->sweep->sweep(static fn (int $id): bool => current_user_can('edit_post', $id))];
    }

    /**
     * Projects counts onto the current options and preserves an object for empty counts.
     *
     * @param int                                                               $id        Poll ID.
     * @param array{counts: array<string, int>, total: int, updated_at: string} $aggregate Full aggregate.
     * @return array<string, mixed>
     */
    private function results(int $id, array $aggregate): array
    {
        $projected = AggregateCache::projectAggregate($aggregate, PollPostType::options($id));
        return ['id' => $id, 'counts' => (object) $projected['counts'],
            'total' => $projected['total'], 'updated_at' => $projected['updated_at']];
    }
}
