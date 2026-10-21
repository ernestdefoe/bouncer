<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

namespace ErnestDefoe\Bouncer\Api;

use ErnestDefoe\Bouncer\Log;
use Flarum\Http\RequestUtil;
use Illuminate\Database\ConnectionInterface;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Empties the staff log; admins only.
 */
class ClearLogController implements RequestHandlerInterface
{
    public function __construct(
        protected ConnectionInterface $db
    ) {
    }

    public function handle(Request $request): Response
    {
        RequestUtil::getActor($request)->assertAdmin();

        $this->db->table(Log::TABLE)->delete();

        return new EmptyResponse(204);
    }
}
