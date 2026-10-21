<?php

/*
 * This file is part of ernestdefoe/bouncer.
 */

use Flarum\Database\Migration;
use Illuminate\Database\Schema\Blueprint;

return Migration::createTable('bouncer_log', function (Blueprint $table) {
    $table->id();
    $table->string('ip', 45);
    $table->string('user_agent', 255)->default('');
    $table->string('action', 16);
    $table->string('reason', 32);
    $table->string('bot', 64)->nullable();
    $table->string('path', 255)->default('');
    $table->unsignedInteger('hits')->default(1);
    $table->dateTime('first_seen');
    $table->dateTime('last_seen')->index();

    $table->index(['action', 'last_seen']);
});
