<?php

namespace Tests\Feature\OpenData;

use App\Models\DataSnapshot;
use App\Services\OpenData\FixtureRepository;
use App\Services\OpenData\OpenDataRefresher;
use App\Services\OpenData\OpenDataRegistry;
use App\Services\OpenData\Snapshot\SnapshotQuality;
use App\Services\OpenData\SnapshotRepository;
use App\Services\OpenData\SnapshotResolver;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenDataRefreshTest extends TestCase
{
    use InteractsWithOpenDataFixtures;
    use RefreshDatabase;

    private const SOURCE_ID = 'wra.reservoir_conditions';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_it_publishes_a_snapshot_and_marks_it_as_the_only_current_one(): void
    {
        $this->fakeUpstream($this->fixture('wra_reservoir_conditions.sample.json'));

        $first = $this->refresher()->refresh(self::SOURCE_ID);
        $second = $this->refresher()->refresh(self::SOURCE_ID);

        $this->assertTrue($first->published);
        $this->assertTrue($second->published);
        $this->assertSame(2, DataSnapshot::query()->where('source_id', self::SOURCE_ID)->count());
        $this->assertSame(1, DataSnapshot::query()->where('source_id', self::SOURCE_ID)->current()->count());
        $this->assertSame(
            $second->snapshot->snapshotId,
            app(SnapshotRepository::class)->current(self::SOURCE_ID)->snapshot_id,
        );
    }

    public function test_a_failed_refresh_keeps_the_last_good_snapshot(): void
    {
        $this->fakeUpstreamThenFailure(Http::response('service unavailable', 503));

        $good = $this->refresher()->refresh(self::SOURCE_ID);
        $failed = $this->refresher()->refresh(self::SOURCE_ID);

        $this->assertFalse($failed->published);
        $this->assertSame('http_status_503', $failed->reasonCode);
        $this->assertSame($good->snapshot->snapshotId, $failed->keptSnapshotId);
        $this->assertSame(
            $good->snapshot->snapshotId,
            app(SnapshotRepository::class)->current(self::SOURCE_ID)->snapshot_id,
        );
        $this->assertSame(1, DataSnapshot::query()->where('source_id', self::SOURCE_ID)->count());
    }

    public function test_an_html_masquerade_response_never_replaces_a_good_snapshot(): void
    {
        $this->fakeUpstreamThenFailure(Http::response(
            "<script>alert('找不到檔案');window.close();</script>",
            200,
            ['Content-Type' => 'application/json'],
        ));

        $good = $this->refresher()->refresh(self::SOURCE_ID);
        $failed = $this->refresher()->refresh(self::SOURCE_ID);

        $this->assertSame('html_masquerade', $failed->reasonCode);
        $this->assertSame(
            $good->snapshot->snapshotId,
            app(SnapshotRepository::class)->current(self::SOURCE_ID)->snapshot_id,
        );
    }

    public function test_it_falls_back_to_a_versioned_demo_fixture_when_no_snapshot_exists(): void
    {
        $resolver = app(SnapshotResolver::class);
        $set = $resolver->resolveSet();

        $this->assertSame(app(OpenDataRegistry::class)->sourceIds(), array_keys($set));

        foreach ($set as $snapshot) {
            $this->assertSame(SnapshotQuality::Demo, $snapshot->quality);
            $this->assertFalse($snapshot->quality->isRealData());
        }

        $this->assertTrue($resolver->containsDemoData($set));
    }

    public function test_it_prefers_a_stored_snapshot_over_the_demo_fixture(): void
    {
        $this->fakeUpstream($this->fixture('wra_reservoir_conditions.sample.json'));
        $published = $this->refresher()->refresh(self::SOURCE_ID);

        $resolved = app(SnapshotResolver::class)->resolve(self::SOURCE_ID);

        $this->assertSame($published->snapshot->snapshotId, $resolved->snapshotId);
        $this->assertSame(SnapshotQuality::Fresh, $resolved->quality);
    }

    public function test_every_configured_source_ships_a_demo_fixture(): void
    {
        $fixtures = app(FixtureRepository::class);

        foreach (app(OpenDataRegistry::class)->sourceIds() as $sourceId) {
            $this->assertTrue($fixtures->has($sourceId), "{$sourceId} 缺少示範情境 fixture");
            $this->assertSame(SnapshotQuality::Demo, $fixtures->load($sourceId)->quality);
        }
    }

    public function test_the_status_report_marks_sources_without_a_snapshot_as_unavailable(): void
    {
        $this->fakeUpstream($this->fixture('wra_reservoir_conditions.sample.json'));
        $this->refresher()->refresh(self::SOURCE_ID);

        $status = app(SnapshotRepository::class)->status(app(OpenDataRegistry::class)->sourceIds());

        $this->assertSame('fresh', $status[self::SOURCE_ID]['quality']);
        $this->assertSame('unavailable', $status['moi.land_use']['quality']);
        $this->assertNull($status['moi.land_use']['snapshot_id']);
    }

    public function test_the_refresh_command_reports_failure_and_names_the_snapshot_it_kept(): void
    {
        $this->fakeUpstreamThenFailure(Http::response('nope', 503));
        $good = $this->refresher()->refresh(self::SOURCE_ID);

        $this->artisan('opendata:refresh', ['--source' => [self::SOURCE_ID]])
            ->expectsOutputToContain('http_status_503')
            ->expectsOutputToContain($good->snapshot->snapshotId)
            ->assertFailed();
    }

    public function test_the_refresh_command_rejects_an_unknown_source(): void
    {
        $this->artisan('opendata:refresh', ['--source' => ['nope.nope']])
            ->expectsOutputToContain('未知的資料來源')
            ->assertFailed();
    }

    public function test_pruning_keeps_the_most_recent_snapshots(): void
    {
        $this->fakeUpstream($this->fixture('wra_reservoir_conditions.sample.json'));

        foreach (range(1, 3) as $ignored) {
            $this->refresher()->refresh(self::SOURCE_ID);
        }

        $current = app(SnapshotRepository::class)->current(self::SOURCE_ID);
        $deleted = app(SnapshotRepository::class)->prune(self::SOURCE_ID, 2);

        $this->assertSame(1, $deleted);
        $this->assertSame(2, DataSnapshot::query()->where('source_id', self::SOURCE_ID)->count());
        $this->assertNotNull(app(SnapshotRepository::class)->find($current->snapshot_id));
    }

    public function test_pruning_never_deletes_the_current_snapshot(): void
    {
        $this->fakeUpstream($this->fixture('wra_reservoir_conditions.sample.json'));

        foreach (range(1, 3) as $ignored) {
            $this->refresher()->refresh(self::SOURCE_ID);
        }

        $current = app(SnapshotRepository::class)->current(self::SOURCE_ID);

        // keep=0 會被夾成 1；即使排序把生效快照排到後面也不能刪掉它。
        app(SnapshotRepository::class)->prune(self::SOURCE_ID, 0);

        $this->assertNotNull(app(SnapshotRepository::class)->current(self::SOURCE_ID));
        $this->assertSame(
            $current->snapshot_id,
            app(SnapshotRepository::class)->current(self::SOURCE_ID)->snapshot_id,
        );
    }

    public function test_pruning_a_source_with_no_snapshots_deletes_nothing(): void
    {
        $this->assertSame(0, app(SnapshotRepository::class)->prune('moi.land_use', 5));
    }

    private function refresher(): OpenDataRefresher
    {
        return app(OpenDataRefresher::class);
    }

    private function fakeUpstream(string $body): void
    {
        Http::fake(['opendata.wra.gov.tw/*' => Http::response($body, 200, ['Content-Type' => 'application/json'])]);
    }

    /**
     * 第一次抓取成功，之後每一次（含重試）都回傳同一個失敗回應。
     */
    private function fakeUpstreamThenFailure(PromiseInterface|Response $failure): void
    {
        Http::fake(['opendata.wra.gov.tw/*' => Http::sequence()
            ->push($this->fixture('wra_reservoir_conditions.sample.json'), 200, ['Content-Type' => 'application/json'])
            ->whenEmpty($failure),
        ]);
    }
}
