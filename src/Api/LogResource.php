<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Api;

use ErnestDefoe\Bouncer\LogEntry;
use ErnestDefoe\Bouncer\Search\LogSearcher;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\AbstractDatabaseResource;
use Flarum\Api\Schema;
use Flarum\Api\Sort\SortColumn;
use Laminas\Diactoros\Response\EmptyResponse;

/**
 * The staff log as JSON:API type `bouncer-log` (/api/bouncer-log), admins
 * only: newest first, 50 a page, searched by {@see LogSearcher}
 * (filter[action], filter[q]). DELETE /api/bouncer-log empties it.
 *
 * @extends AbstractDatabaseResource<LogEntry>
 */
class LogResource extends AbstractDatabaseResource
{
    public function type(): string
    {
        return 'bouncer-log';
    }

    public function model(): string
    {
        return LogEntry::class;
    }

    public function endpoints(): array
    {
        return [
            Endpoint\Index::make()
                ->admin()
                ->defaultSort('-lastSeen')
                ->paginate(50, 50),
            Endpoint\Endpoint::make('clear')
                ->route('DELETE', '/')
                ->admin()
                ->action(fn () => LogEntry::query()->delete())
                ->response(fn () => new EmptyResponse(204)),
        ];
    }

    public function sorts(): array
    {
        return [
            SortColumn::make('lastSeen'),
        ];
    }

    public function fields(): array
    {
        return [
            Schema\Str::make('ip'),
            Schema\Str::make('userAgent')->property('user_agent'),
            Schema\Str::make('action'),
            Schema\Str::make('reason'),
            Schema\Str::make('bot')->nullable(),
            Schema\Str::make('path'),
            Schema\Integer::make('hits'),
            Schema\DateTime::make('firstSeen')->property('first_seen'),
            Schema\DateTime::make('lastSeen')->property('last_seen'),
        ];
    }
}
