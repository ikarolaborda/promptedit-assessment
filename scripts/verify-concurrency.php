<?php

use App\Http\Controllers\GenerationController;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pixelforge;
use App\Services\Quote;
use App\Services\SettleGeneration;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Run only against the disposable PostgreSQL test database documented in README. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'frame_studio_test') {
    throw new RuntimeException('Refusing to alter any database other than disposable frame_studio_test in testing.');
}
config(['queue.default' => 'database', 'cache.default' => 'array']);
Artisan::call('migrate:fresh', ['--force' => true]);
Http::preventStrayRequests();
Http::fake(['*/pricing' => Http::response(json_decode(file_get_contents(__DIR__.'/../tests/Fixtures/pricing.json'), true))]);
$user = User::factory()->create(['balance_pico' => 600000000000]);
function race(array $callbacks): array
{
    $dir = sys_get_temp_dir().'/frame-race-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    DB::disconnect();
    $pids = [];
    foreach ($callbacks as $i => $callback) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                DB::reconnect();
                file_put_contents("$dir/ready-$i", '1');
                while (! file_exists("$dir/go")) {
                    usleep(1000);
                }$result = $callback();
            } catch (Throwable $e) {
                $result = ['error' => get_class($e), 'message' => $e->getMessage()];
            }
            file_put_contents("$dir/result-$i", json_encode($result));
            DB::disconnect();
            exit(0);
        }
        $pids[] = $pid;
    }
    $limit = microtime(true) + 10;
    while (count(glob("$dir/ready-*")) < count($callbacks)) {
        if (microtime(true) > $limit) {
            throw new RuntimeException('Barrier timeout');
        }usleep(1000);
    }
    touch("$dir/go");
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    DB::reconnect();
    $results = [];
    foreach ($callbacks as $i => $c) {
        $results[] = json_decode(file_get_contents("$dir/result-$i"), true);
    }
    foreach (glob("$dir/*") as $f) {
        unlink($f);
    }rmdir($dir);

    return $results;
}
function submit(int $userId, string $requestId): array
{
    $r = Request::create('/generations', 'POST', ['prompt' => str_repeat('x', 400), 'size' => '1536x1024', 'quality' => 'high', 'reference_images' => ['https://example.com/a.jpg', 'https://example.com/b.jpg'], 'client_request_id' => $requestId]);
    $r->setUserResolver(fn () => User::findOrFail($userId));
    app(GenerationController::class)->store($r, app(Pixelforge::class), app(Quote::class));

    return ['ok' => true];
}
function check(bool $ok, string $message): void
{
    if (! $ok) {
        throw new RuntimeException($message);
    }echo "PASS $message\n";
}
$r = race([fn () => submit($user->id, (string) Str::uuid()), fn () => submit($user->id, (string) Str::uuid())]);
check(count(array_filter($r, fn ($x) => $x['ok'] ?? false)) === 1 && Generation::count() === 1, 'simultaneous submissions cannot overspend');
check(DB::table('jobs')->count() === 1, 'accepted generation and durable queue job commit together');
$g = Generation::first();
$remote = ['id' => 'gen_concurrency', 'status' => 'succeeded', 'metadata' => ['app_generation_id' => $g->id], 'completed_at' => now()->toIso8601String(), 'usage' => ['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184], 'rates' => json_decode(file_get_contents(__DIR__.'/../tests/Fixtures/pricing.json'), true)['rates']];
$r = race(array_fill(0, 4, fn () => [app(SettleGeneration::class)->apply($g->id, $remote), 'ok' => true]));
check(count(array_filter($r, fn ($x) => $x['ok'] ?? false)) === 4, 'four simultaneous settlement paths finish without error');
check(DB::table('charges')->count() === 1 && User::find($user->id)->balance_pico === 218112500000, 'concurrent settlement charges and debits exactly once');
$requestId = (string) Str::uuid();
$user->refresh()->forceFill(['balance_pico' => 5000000000000])->save();
$r = race(array_fill(0, 3, fn () => submit($user->id, $requestId)));
check(count(array_filter($r, fn ($x) => $x['ok'] ?? false)) === 3 && Generation::count() === 2 && DB::table('jobs')->count() === 2, 'concurrent identical submissions create one record and one job');
echo "PostgreSQL process-concurrency checks passed.\n";
