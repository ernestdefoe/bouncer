<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Api;

use ErnestDefoe\Bouncer\Log;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\JsonResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The staff log, newest first, 50 rows a page; admins only.
 */
class ListLogController implements RequestHandlerInterface
{
    public const ACTIONS = ['allowed', 'challenged', 'blocked', 'limited', 'solved', 'failed', 'would_challenge', 'would_block', 'would_limit'];

    public const PER_PAGE = 50;

    public function __construct(
        protected ConnectionInterface $db
    ) {
    }

    public function handle(Request $request): Response
    {
        RequestUtil::getActor($request)->assertAdmin();

        $params = $request->getQueryParams();
        $query = $this->db->table(Log::TABLE);

        if (is_string($params['action'] ?? null) && in_array($params['action'], self::ACTIONS, true)) {
            $query->where('action', $params['action']);
        }

        if (is_string($params['q'] ?? null) && ($q = trim($params['q'])) !== '') {
            // Case-insensitive on every database, with % and _ taken
            // literally: "!" is the escape character, as it means nothing
            // special in any of their string literals.
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower(mb_substr($q, 0, 100))).'%';
            $query->where(function ($w) use ($like) {
                foreach (['ip', 'user_agent', 'bot'] as $column) {
                    $w->orWhereRaw('lower('.$w->getGrammar()->wrap($column).") like ? escape '!'", [$like]);
                }
            });
        }

        $total = (clone $query)->count();
        $offset = max(0, (int) ($params['offset'] ?? 0));

        $rows = $query->orderByDesc('last_seen')->orderByDesc('id')
            ->offset($offset)->limit(self::PER_PAGE)
            ->get(['id', 'ip', 'user_agent', 'action', 'reason', 'bot', 'path', 'hits', 'first_seen', 'last_seen']);

        return new JsonResponse([
            'total' => $total,
            'offset' => $offset,
            'perPage' => self::PER_PAGE,
            'rows' => $rows->map(fn ($row) => [
                'id' => (int) $row->id,
                'ip' => $row->ip,
                'userAgent' => $row->user_agent,
                'action' => $row->action,
                'reason' => $row->reason,
                'bot' => $row->bot,
                'path' => $row->path,
                'hits' => (int) $row->hits,
                'firstSeen' => self::iso($row->first_seen),
                'lastSeen' => self::iso($row->last_seen),
            ])->all(),
        ]);
    }

    public static function iso(mixed $value): ?string
    {
        return $value ? (new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC')))->format(DATE_ATOM) : null;
    }
}
