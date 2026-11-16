<?php

use App\Jobs\PollGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

Artisan::command('studio:demo-user', function () {
    $user = User::firstOrCreate(['email' => 'reviewer@example.test'], ['name' => 'Demo reviewer', 'password' => Str::random(64)]);
    $user->forceFill(['is_admin' => true])->save();
    $this->info('Public demo reviewer is ready; no password is published or required.');
});
Artisan::command('studio:recover', function () {
    Generation::whereIn('status', ['queued', 'processing'])->where('needs_attention', false)
        ->where('updated_at', '<', now()->subMinute())->each(function (Generation $g) {
            $g->provider_id ? PollGeneration::dispatch($g->id) : SubmitGeneration::dispatch($g->id);
        });
});
Schedule::command('studio:recover')->everyMinute()->withoutOverlapping();
