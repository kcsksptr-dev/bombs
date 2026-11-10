<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Events\RoomUpdated;
use App\Games\Moonwell\MoonwellEngine;
use App\Models\Room;
use App\Models\User;
use App\Services\LobbyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MoonwellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([RoomUpdated::class]);
    }

    public function test_a_new_room_opens_on_the_game_picker(): void
    {
        $host = User::factory()->create();

        $this->actingAs($host)->post('/rooms', ['capacity' => 6])->assertSessionHasNoErrors();
        $room = Room::firstOrFail();

        $this->assertNull($room->game_key);
        $this->actingAs($host)->get("/rooms/{$room->code}")->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Room/Lobby')
            ->where('room.game', null)
            ->where('games.0.key', 'bombbusters')
            ->where('games.1.key', 'moonwell')
            ->where('games.1.max', 8));
    }

    public function test_only_the_host_picks_the_game_and_it_can_be_changed(): void
    {
        [$host, $guest] = User::factory()->count(2)->create()->all();
        $lobby = app(LobbyService::class);
        $room = $lobby->create($host, 4);
        $lobby->join($room, $guest);

        $this->actingAs($guest)->post("/rooms/{$room->code}/choose-game", ['game' => 'moonwell'])->assertSessionHasErrors('lobby');
        $this->actingAs($host)->post("/rooms/{$room->code}/choose-game", ['game' => 'chess'])->assertSessionHasErrors('lobby');

        $this->actingAs($host)->post("/rooms/{$room->code}/choose-game", ['game' => 'moonwell'])->assertSessionHasNoErrors();
        $this->assertSame('moonwell', $room->fresh()->game_key);
        Event::assertDispatched(RoomUpdated::class);

        $this->actingAs($host)->get("/rooms/{$room->code}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('room.game', 'moonwell')
            ->has('settings.tables'));

        $this->actingAs($host)->post("/rooms/{$room->code}/choose-game", ['game' => null])->assertSessionHasNoErrors();
        $this->assertNull($room->fresh()->game_key);
    }

    public function test_a_room_cannot_start_before_a_game_is_picked(): void
    {
        [$host, $guest] = User::factory()->count(2)->create()->all();
        $lobby = app(LobbyService::class);
        $room = $lobby->create($host, 2);
        $lobby->join($room, $guest);
        $lobby->setReady($room, $guest, true);

        $this->actingAs($host)->post("/rooms/{$room->code}/start")->assertSessionHasErrors(['lobby' => __('lobby.errors.choose_game')]);
        $this->assertSame(RoomStatus::Lobby, $room->fresh()->status);
    }

    public function test_eight_players_get_the_scaled_table(): void
    {
        [$room] = $this->startedRoom(8);
        $state = $room->game->state;

        $this->assertSame('moonwell', $state['game']);
        $this->assertCount(15, $state['ring']);
        $this->assertSame(4, $state['choices']);
        $this->assertSame(config('moonwell.tables.8.fireflies'), $state['fireflies']);
    }

    public function test_the_view_shows_the_table_but_not_the_bag(): void
    {
        [$room, $users] = $this->startedRoom(3);

        $this->actingAs($users[1])->get("/rooms/{$room->code}/game")->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Games/Moonwell/Game')
            ->where('game.bag', count($room->game->state['bag']))
            ->has('game.ring', 12)
            ->has('game.players', 3)
            ->where('game.me.myTurn', false)
            ->where('game.me.openCells', [[0, 0]])
            ->missing('game.order'));

        // The next tile out of the bag appears nowhere in what the phone gets
        $next = $room->game->state['bag'][0]['id'];
        $json = json_encode($this->actingAs($users[1])->get("/rooms/{$room->code}/game")->viewData('page')['props']['game']);
        $this->assertStringNotContainsString('"id":'.$next.',"color"', $json);
    }

    public function test_a_turn_takes_a_tile_and_plants_it(): void
    {
        [$room, $users] = $this->startedRoom(2);
        $host = $users[0];
        $slot = MoonwellEngine::available($room->game->state)[0];
        $tile = $room->game->state['ring'][$slot];

        $this->actingAs($users[1])->post("/rooms/{$room->code}/game/take", ['slot' => $slot, 'x' => 0, 'y' => 0])
            ->assertSessionHasErrors(['game' => __('moonwell.errors.not_your_turn')]);

        $this->actingAs($host)->post("/rooms/{$room->code}/game/take", ['slot' => $slot, 'x' => 0, 'y' => 0])->assertSessionHasNoErrors();

        $state = $room->game->fresh()->state;
        $this->assertSame($tile['id'], $state['players'][0]['garden'][0]['tile']['id']);
        $this->assertSame($tile['cost'], $state['players'][0]['pos']);
        $this->assertSame($users[1]->id, $state['turn']);
        $this->assertSame(1, $room->game->snapshots()->count());

        // A Bomb Busters endpoint can't touch a Moonwell game
        $this->actingAs($users[1])->post("/rooms/{$room->code}/game/pass")->assertSessionHasErrors('game');
    }

    public function test_the_host_can_undo_and_go_back_to_the_lobby(): void
    {
        [$room, $users] = $this->startedRoom(2);
        $slot = MoonwellEngine::available($room->game->state)[0];
        $this->actingAs($users[0])->post("/rooms/{$room->code}/game/take", ['slot' => $slot, 'x' => 0, 'y' => 0]);

        $this->actingAs($users[0])->post("/rooms/{$room->code}/game/undo")->assertSessionHasNoErrors();
        $state = $room->game->fresh()->state;
        $this->assertSame([], $state['players'][0]['garden']);
        $this->assertSame('undo', end($state['log'])['type']);

        $this->actingAs($users[0])->post("/rooms/{$room->code}/game/lobby")->assertRedirect("/rooms/{$room->code}");
        $room->refresh();
        $this->assertSame(RoomStatus::Lobby, $room->status);
        $this->assertSame('moonwell', $room->game_key);
        $this->assertSame(1, $room->last_captain_seat, 'the next game starts one seat on');
    }

    /**
     * @return array{Room, Collection<int, User>}
     */
    private function startedRoom(int $players): array
    {
        $users = User::factory()->count($players)->create();
        $lobby = app(LobbyService::class);
        $room = $lobby->create($users->first(), $players, game: 'moonwell');

        foreach ($users->skip(1) as $user) {
            $lobby->join($room, $user);
            $lobby->setReady($room, $user, true);
        }

        $lobby->start($room, $users->first());

        return [$room->fresh()->load('players.user', 'game'), $users->values()];
    }
}
