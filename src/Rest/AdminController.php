<?php
/**
 * Registers admin-only REST routes.
 *
 * @package ZW_Poll
 */

declare(strict_types=1);

namespace ZuidWest\Poll\Rest;

use ZuidWest\Poll\PostType\PollPostType;
use ZuidWest\Poll\Vote\PollReset;
use WP_Error;
use WP_Post;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Handles authenticated poll maintenance endpoints.
 */
final class AdminController
{
    /**
     * Stores the shared reset sequence.
     *
     * @param PollReset $resetter Vote reset service.
     */
    public function __construct(private readonly PollReset $resetter) {}

    /**
     * Registers REST hooks.
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    /**
     * Registers admin maintenance routes.
     */
    public function registerRoutes(): void
    {
        register_rest_route(VoteController::NAMESPACE, '/poll/(?P<id>\d+)/reset', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'reset'],
            'permission_callback' => [$this, 'canManage'],
            'args' => [
                'id' => [
                    'type' => 'integer',
                    'required' => true,
                    'sanitize_callback' => 'absint',
                ],
            ],
        ]);
    }

    /**
     * Authorizes reset requests, including object-level edit checks for valid polls.
     *
     * @param WP_REST_Request $request REST request.
     */
    public function canManage(WP_REST_Request $request): bool
    {
        if (!current_user_can('manage_zw_polls')) {
            return false;
        }

        $poll = $this->findPoll($request);
        if ($poll === null) {
            // Let the route callback return the existing 404 for authorized managers.
            return true;
        }

        return current_user_can('edit_post', (int) $poll->ID);
    }

    /**
     * Returns the public reset endpoint URL for a poll.
     *
     * @param int $poll_id Poll post ID.
     */
    public static function resetUrl(int $poll_id): string
    {
        return rest_url(VoteController::NAMESPACE . '/poll/' . $poll_id . '/reset/');
    }

    /**
     * Deletes all votes for a poll and rebuilds its aggregate.
     *
     * @param WP_REST_Request $request REST request.
     */
    public function reset(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $poll = $this->findPoll($request);
        if ($poll === null) {
            return new WP_Error(
                'poll_not_found',
                __('Poll bestaat niet.', 'zw-poll'),
                ['status' => 404]
            );
        }

        $result = $this->resetter->reset((int) $poll->ID);

        return new WP_REST_Response([
            'ok' => true,
            'deleted' => $result['deleted'],
            'aggregate' => (object) $result['aggregate']['counts'],
            'total' => $result['aggregate']['total'],
        ], 200);
    }

    /**
     * Resolves the request's target poll, or null when missing or wrong type.
     *
     * @param WP_REST_Request $request REST request.
     */
    private function findPoll(WP_REST_Request $request): ?WP_Post
    {
        $poll = get_post((int) $request->get_param('id'));

        return ($poll instanceof WP_Post && $poll->post_type === PollPostType::POST_TYPE)
            ? $poll
            : null;
    }
}
