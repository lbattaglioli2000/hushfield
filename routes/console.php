<?php

use App\Actions\Presence\SweepGhosts;
use App\Actions\World\CloseEmptyInstances;
use App\Models\ChatMessage;
use Illuminate\Support\Facades\Schedule;

Schedule::call(fn () => app(SweepGhosts::class)())->name('sweep-ghosts')->everyThirtySeconds()->withoutOverlapping()->onOneServer();
Schedule::call(fn () => app(CloseEmptyInstances::class)())->name('close-empty-instances')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('model:prune', ['--model' => [ChatMessage::class]])->daily()->onOneServer();
Schedule::command('horizon:snapshot')->everyFiveMinutes();
Schedule::command('hushfield:prune-assets')->daily()->onOneServer();
