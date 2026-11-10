<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Events\RoomUpdated;
use App\Models\MissionCompletion;
use App\Models\Room;
use App\Models\User;
use App\Services\LobbyService;
use App\Support\PublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class OnlineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([RoomUpdated::class]);

        // Never the live storage/app/public_url: the running stack shares this storage folder
        config(['bombbusters.public_url_file' => sys_get_temp_dir().'/bombbusters-test-public-url-'.getmypid()]);
        @unlink(PublicUrl::path());
    }

    protected function tearDown(): void
    {
        @unlink(PublicUrl::path());

        parent::tearDown();
    }

    public function test_room_links_use_the_tunnel_while_the_game_is_online(): void
    {
        config(['bombbusters.lan_ip' => '192.168.1.20']);
        $host = User::factory()->create();
        $room = app(LobbyService::class)->create($host, 4, game: 'bombbusters');

        $this->actingAs($host)->get("/rooms/{$room->code}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('joinUrl', "http://192.168.1.20/rooms/{$room->code}"));

        file_put_contents(PublicUrl::path(), "https://happy-wires-demo.trycloudflare.com\n");

        $this->actingAs($host)->get("/rooms/{$room->code}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('joinUrl', "https://happy-wires-demo.trycloudflare.com/rooms/{$room->code}"));
    }

    public function test_a_garbled_public_url_file_is_ignored(): void
    {
        file_put_contents(PublicUrl::path(), "javascript:alert(1)\n");

        $this->assertNull(PublicUrl::current());
    }

    public function test_hammering_room_codes_gets_a_friendly_slow_down_message(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->actingAs($user)->post('/rooms/find', ['code' => 'ZZZZ'])->assertSessionHasErrors(['code' => 'No open room with that code.']);
        }

        $this->actingAs($user)
            ->withHeader('X-Inertia', 'true')
            ->from('/')
            ->post('/rooms/find', ['code' => 'ZZZZ'])
            ->assertRedirect('/')
            ->assertSessionHasErrors(['code' => 'Whoa, slow down a little! Try again in a moment.']);
    }

    public function test_pruning_removes_abandoned_rooms_and_long_gone_guests_only(): void
    {
        $lobby = app(LobbyService::class);
        [$fresh, $stale, $keeper, $gone] = User::factory()->count(4)->create()->all();

        $activeRoom = $lobby->create($fresh, 4, game: 'bombbusters');
        $staleRoom = $lobby->create($stale, 4, game: 'bombbusters');
        Room::whereKey($staleRoom->id)->update(['updated_at' => now()->subDays(2), 'status' => RoomStatus::Lobby]);

        // Campaign progress is worth keeping even for a guest who's been gone for ages
        MissionCompletion::create(['user_id' => $keeper->id, 'mission' => 1, 'completed_at' => now()->subYear()]);
        User::whereKey([$keeper->id, $gone->id, $stale->id])->update(['updated_at' => now()->subDays(60)]);

        $this->artisan('bombbusters:prune')->assertSuccessful();

        $this->assertModelExists($activeRoom);
        $this->assertModelMissing($staleRoom);
        $this->assertModelExists($keeper);
        $this->assertModelMissing($gone);
        $this->assertModelExists($fresh);
    }
}
