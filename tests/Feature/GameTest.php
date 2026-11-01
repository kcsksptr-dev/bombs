<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Events\RoomUpdated;
use App\Jobs\SendPush;
use App\Models\GameSnapshot;
use App\Models\MissionCompletion;
use App\Models\PushSubscription;
use App\Models\Room;
use App\Models\User;
use App\Services\LobbyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class GameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([RoomUpdated::class]);

        // HTTP tests use a predictable blue-only mission 1; coloured wires and equipment are covered in GameEngineTest
        config(['missions.1' => []]);
    }

    public function test_starting_the_lobby_deals_a_game_with_the_host_as_captain(): void
    {
        $room = $this->startedRoom(6);

        $state = $room->game->state;
        $this->assertSame('setup', $state['phase']);
        $this->assertSame($room->host_user_id, $state['captain']);
        $this->assertSame($room->players->pluck('user_id')->all(), $state['order']);
        $this->assertSame(5, $state['detonator']['limit']);
        $this->assertSame(1, $state['mission']);
    }

    public function test_players_only_ever_see_their_own_hidden_wires(): void
    {
        $room = $this->startedRoom(3);
        [$anna, $bence] = $room->players->pluck('user')->all();
        $hands = $room->game->state['hands'];

        $this->actingAs($anna)->get("/rooms/{$room->code}/game")->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Room/Game')
            ->where('game.stands.0.isMe', true)
            ->where('game.stands.0.wires', fn ($wires) => collect($wires)->pluck('value')->all() === array_column($hands[$anna->id], 'value'))
            ->where('game.stands.1.wires', fn ($wires) => collect($wires)->every(fn ($wire) => $wire['value'] === null && $wire['color'] === null))
            ->where('game.stands.2.wires', fn ($wires) => collect($wires)->every(fn ($wire) => $wire['value'] === null && $wire['color'] === null)));

        // Nothing else in the payload carries the full state
        $props = $this->actingAs($anna)->get("/rooms/{$room->code}/game")->viewData('page')['props'];
        $this->assertArrayNotHasKey('state', $props['game']);
        $this->assertArrayNotHasKey('hands', $props['game']);
    }

    public function test_a_full_round_over_http(): void
    {
        $room = $this->startedRoom(2);
        [$anna, $bence] = $room->players->pluck('user')->all();

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/info-token", ['index' => 0])->assertSessionHasNoErrors();
        $this->actingAs($bence)->post("/rooms/{$room->code}/game/info-token", ['index' => 0])->assertSessionHasNoErrors();

        $state = $room->game->fresh()->state;
        $this->assertSame('playing', $state['phase']);
        $this->assertSame($anna->id, $state['turn']);

        // Anna points at Bence's first wire, which carries a public info token: a guaranteed hit if she holds that value
        $value = $state['hands'][$bence->id][0]['value'];
        $annaHolds = collect($state['hands'][$anna->id])->contains('value', $value);

        $this->actingAs($bence)->post("/rooms/{$room->code}/game/dual-cut", ['target' => $anna->id, 'index' => 0, 'value' => 1])
            ->assertSessionHasErrors(['game' => "It's not your turn."]);

        $guess = $annaHolds ? $value : $state['hands'][$anna->id][0]['value'];
        $this->actingAs($anna)->post("/rooms/{$room->code}/game/dual-cut", ['target' => $bence->id, 'index' => 0, 'value' => $guess])
            ->assertSessionHasNoErrors();

        $state = $room->game->fresh()->state;
        $this->assertSame($bence->id, $state['turn']);
        $this->assertSame($annaHolds ? 0 : 1, $state['detonator']['mistakes']);
        Event::assertDispatched(RoomUpdated::class);
    }

    public function test_hidden_coloured_wires_stay_hidden(): void
    {
        $room = $this->startedRoom(3, 16);
        $anna = $room->players[0]->user;
        $state = $room->game->state;

        $props = $this->actingAs($anna)->get("/rooms/{$room->code}/game")->viewData('page')['props']['game'];

        // Only the candidate values and how many there are; not which ones were dealt
        $this->assertSame($state['candidates'], $props['candidates']);
        $this->assertSame(
            ['yellow' => 4, 'red' => 3],
            collect($props['cutBoard'])->whereIn('kind', ['yellow', 'red'])->pluck('total', 'kind')->all(),
        );
        $this->assertCount(2, $props['equipment']);
        $this->assertFalse(collect($props['stands'])->where('isMe', false)->flatMap->wires->contains(fn ($wire) => $wire['color'] !== null));
    }

    public function test_reveal_red_and_equipment_endpoints_report_rule_errors(): void
    {
        $room = $this->startedRoom(2);
        [$anna, $bence] = $room->players->pluck('user')->all();
        $this->actingAs($anna)->post("/rooms/{$room->code}/game/info-token", ['index' => 0]);
        $this->actingAs($bence)->post("/rooms/{$room->code}/game/info-token", ['index' => 0]);

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/reveal-red")
            ->assertSessionHasErrors(['game' => 'You can only reveal when nothing but red wires is left on your stand.']);
        $this->actingAs($anna)->post("/rooms/{$room->code}/game/equipment/radar", ['value' => 3])
            ->assertSessionHasErrors(['game' => "That equipment isn't part of this mission."]);
        $this->actingAs($anna)->post("/rooms/{$room->code}/game/dual-cut", ['target' => $bence->id, 'index' => 1, 'value' => 'purple'])
            ->assertSessionHasErrors('value');
    }

    public function test_only_the_targeted_player_answers_a_missed_double_detector(): void
    {
        $room = $this->startedRoom(3);
        [$anna, $bence, $csaba] = $room->players->pluck('user')->all();

        $wire = fn (int $value) => ['color' => 'blue', 'value' => $value, 'rank' => $value * 10, 'cut' => false, 'info' => null, 'revealed' => false];
        $state = $room->game->state;
        $state['phase'] = 'playing';
        $state['turn'] = $anna->id;
        $state['info_placed'] = array_fill_keys($state['order'], true);
        $state['hands'] = [$anna->id => [$wire(7)], $bence->id => [$wire(2), $wire(9)], $csaba->id => [$wire(7), $wire(2), $wire(9)]];
        $room->game->update(['state' => $state]);

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/double-detector", ['target' => $bence->id, 'indices' => [0, 1], 'value' => 7])
            ->assertSessionHasNoErrors();

        // Everyone sees who must reveal; only Bence's screen gets the prompt
        $this->actingAs($csaba)->get("/rooms/{$room->code}/game")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('game.pending.player', $bence->id)
            ->where('game.me.mustRevealForDetector', false)
            ->where('game.me.myTurn', false));
        $this->actingAs($bence)->get("/rooms/{$room->code}/game")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('game.me.mustRevealForDetector', true));

        $this->actingAs($csaba)->post("/rooms/{$room->code}/game/detector-reveal", ['index' => 0])
            ->assertSessionHasErrors(['game' => "There's nothing for you to reveal."]);
        $this->actingAs($bence)->post("/rooms/{$room->code}/game/detector-reveal", ['index' => 1])->assertSessionHasNoErrors();

        $state = $room->game->fresh()->state;
        $this->assertSame(9, $state['hands'][$bence->id][1]['info']);
        $this->assertSame($bence->id, $state['turn']);
        $this->assertTrue($state['detectors'][$anna->id]);
    }

    public function test_rule_errors_are_translated_for_the_player(): void
    {
        $room = $this->startedRoom(2);
        $bence = $room->players[1]->user;
        $bence->update(['locale' => 'hu']);

        $this->actingAs($bence)->post("/rooms/{$room->code}/game/solo-cut", ['value' => 3])
            ->assertSessionHasErrors(['game' => 'Várd meg, amíg mindenki elhelyezi az infójelzőjét.']);
    }

    public function test_outsiders_cannot_see_or_play_the_game(): void
    {
        $room = $this->startedRoom(2);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->get("/rooms/{$room->code}/game")->assertRedirect("/rooms/{$room->code}");
        $this->actingAs($outsider)->post("/rooms/{$room->code}/game/info-token", ['index' => 0])
            ->assertSessionHasErrors(['game' => "You're not playing in this game."]);
    }

    public function test_host_takes_everyone_back_to_the_lobby(): void
    {
        $room = $this->startedRoom(2);
        $guest = $room->players[1]->user;

        $this->actingAs($guest)->post("/rooms/{$room->code}/game/lobby")->assertSessionHasErrors('game');

        $this->actingAs($room->host)->post("/rooms/{$room->code}/game/lobby")->assertRedirect("/rooms/{$room->code}");

        $room->refresh();
        $this->assertSame(RoomStatus::Lobby, $room->status);
        $this->assertNull($room->game);
        $this->assertFalse($room->players()->where('is_ready', true)->exists());
        $this->actingAs($guest)->get("/rooms/{$room->code}/game")->assertRedirect("/rooms/{$room->code}");
    }

    public function test_the_host_undoes_moves_step_by_step(): void
    {
        $room = $this->startedRoom(2);
        [$anna, $bence] = $room->players->pluck('user')->all();
        $code = $room->code;
        $dealt = $room->game->state;

        $this->actingAs($anna)->post("/rooms/{$code}/game/info-token", ['index' => 0]);
        $afterFirst = $room->game->fresh()->state;
        $this->actingAs($bence)->post("/rooms/{$code}/game/info-token", ['index' => 0]);

        $this->actingAs($bence)->post("/rooms/{$code}/game/undo")->assertSessionHasErrors(['game' => 'Only the host can do that.']);
        $this->assertSame('playing', $room->game->fresh()->state['phase']);

        $this->actingAs($anna)->get("/rooms/{$code}/game")->assertInertia(fn (AssertableInertia $page) => $page->where('game.undoSteps', 2));

        $this->actingAs($anna)->post("/rooms/{$code}/game/undo")->assertSessionHasNoErrors();
        $state = $room->game->fresh()->state;
        $this->assertSame(['type' => 'undo', 'actor' => $anna->id], array_pop($state['log']));
        $this->assertEquals($afterFirst, $state);

        $this->actingAs($anna)->post("/rooms/{$code}/game/undo")->assertSessionHasNoErrors();
        $state = $room->game->fresh()->state;
        array_pop($state['log']);
        $this->assertEquals($dealt, $state);

        $this->actingAs($anna)->post("/rooms/{$code}/game/undo")->assertSessionHasErrors(['game' => 'There is no move to undo.']);
        Event::assertDispatched(RoomUpdated::class);
    }

    public function test_undoing_a_lost_mission_defuses_it_again(): void
    {
        $room = $this->startedRoom(2);
        [$anna, $bence] = $room->players->pluck('user')->all();
        $this->playingState($room, $anna, $bence, annaWires: [3, 7], benceWires: [5, 7], limit: 1);

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/dual-cut", ['target' => $bence->id, 'index' => 0, 'value' => 3]);
        $this->assertSame('lost', $room->game->fresh()->state['phase']);

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/undo")->assertSessionHasNoErrors();
        $state = $room->game->fresh()->state;
        $this->assertSame('playing', $state['phase']);
        $this->assertSame($anna->id, $state['turn']);
        $this->assertSame(0, $state['detonator']['mistakes']);
    }

    public function test_undoing_a_win_only_removes_the_completions_it_recorded(): void
    {
        $room = $this->startedRoom(2);
        [$anna, $bence] = $room->players->pluck('user')->all();
        $this->playingState($room, $anna, $bence, annaWires: [7], benceWires: [7]);
        MissionCompletion::create(['user_id' => $bence->id, 'mission' => 1, 'completed_at' => now()->subDay()]);

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/dual-cut", ['target' => $bence->id, 'index' => 0, 'value' => 7]);
        $this->assertSame('won', $room->game->fresh()->state['phase']);
        $this->assertSame(2, MissionCompletion::count());

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/undo")->assertSessionHasNoErrors();
        $this->assertSame('playing', $room->game->fresh()->state['phase']);
        $this->assertSame([$bence->id], MissionCompletion::pluck('user_id')->all());
    }

    public function test_snapshots_never_reach_the_client_and_go_with_the_game(): void
    {
        $room = $this->startedRoom(2);
        [$anna, $bence] = $room->players->pluck('user')->all();
        $this->actingAs($anna)->post("/rooms/{$room->code}/game/info-token", ['index' => 0]);
        $this->assertSame(1, GameSnapshot::count());

        $props = $this->actingAs($bence)->get("/rooms/{$room->code}/game")->viewData('page')['props'];
        $this->assertArrayNotHasKey('snapshots', $props['game']);
        $this->assertArrayNotHasKey('snapshots', $props['room']);

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/lobby");
        $this->assertSame(0, GameSnapshot::count());
    }

    public function test_the_captain_passes_to_the_next_seat_after_each_game(): void
    {
        $room = $this->startedRoom(3);
        [$anna, $bence, $csaba] = $room->players->pluck('user')->all();
        $captains = [$room->game->state['captain']];

        foreach (range(1, 3) as $game) {
            $this->actingAs($anna)->post("/rooms/{$room->code}/game/lobby");
            $this->actingAs($bence)->post("/rooms/{$room->code}/ready", ['ready' => true]);
            $this->actingAs($csaba)->post("/rooms/{$room->code}/ready", ['ready' => true]);
            $this->actingAs($anna)->post("/rooms/{$room->code}/start");
            $captains[] = $room->fresh()->game->state['captain'];
        }

        $this->assertSame([$anna->id, $bence->id, $csaba->id, $anna->id], $captains);
    }

    public function test_returning_to_the_lobby_twice_keeps_the_rotation(): void
    {
        $room = $this->startedRoom(2);
        [$anna] = $room->players->pluck('user')->all();

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/lobby")->assertRedirect();
        $this->actingAs($anna)->post("/rooms/{$room->code}/game/lobby")->assertRedirect();

        $this->assertSame(1, $room->fresh()->last_captain_seat);
    }

    public function test_the_rotation_skips_a_captain_who_left_the_table(): void
    {
        $room = $this->startedRoom(3);
        [$anna, $bence, $csaba] = $room->players->pluck('user')->all();

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/lobby");
        $this->actingAs($bence)->post("/rooms/{$room->code}/leave");
        $this->actingAs($csaba)->post("/rooms/{$room->code}/ready", ['ready' => true]);
        $this->actingAs($anna)->post("/rooms/{$room->code}/start");

        $this->assertSame($csaba->id, $room->fresh()->game->state['captain']);
    }

    public function test_a_turn_push_goes_to_the_next_player_only_when_the_turn_changes(): void
    {
        Bus::fake();
        $room = $this->startedRoom(2);
        [$anna, $bence] = $room->players->pluck('user')->all();

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/info-token", ['index' => 0]);
        Bus::assertNotDispatched(SendPush::class);

        $this->actingAs($bence)->post("/rooms/{$room->code}/game/info-token", ['index' => 0]);
        Bus::assertDispatched(SendPush::class, fn (SendPush $push) => $push->userId === $anna->id && $push->key === 'push.turn');

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/pass");
        Bus::assertDispatchedTimes(SendPush::class, 1);
    }

    public function test_a_nudge_pushes_the_teammate_with_a_cooldown(): void
    {
        Bus::fake();
        $room = $this->startedRoom(2);
        [$anna, $bence] = $room->players->pluck('user')->all();
        $stranger = User::factory()->create();

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/nudge/{$bence->id}")->assertSessionHasNoErrors();
        Bus::assertDispatched(SendPush::class, fn (SendPush $push) => $push->userId === $bence->id && $push->key === 'push.nudge' && $push->replace['name'] === $anna->name);
        Event::assertDispatched(RoomUpdated::class, fn (RoomUpdated $event) => ($event->meta['nudged_user_id'] ?? null) === $bence->id);

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/nudge/{$bence->id}")->assertSessionHasErrors('game');
        $this->actingAs($anna)->post("/rooms/{$room->code}/game/nudge/{$anna->id}")->assertSessionHasErrors('game');
        $this->actingAs($anna)->post("/rooms/{$room->code}/game/nudge/{$stranger->id}")->assertSessionHasErrors('game');
        $this->actingAs($stranger)->post("/rooms/{$room->code}/game/nudge/{$bence->id}")->assertSessionHasErrors('game');
        Bus::assertDispatchedTimes(SendPush::class, 1);
    }

    public function test_only_browser_push_services_can_be_subscribed(): void
    {
        $user = User::factory()->create();
        $keys = ['p256dh' => 'public', 'auth' => 'secret'];

        foreach (['http://db:5432/', 'https://169.254.169.254/latest', 'https://fcm.googleapis.com.evil.test/x'] as $endpoint) {
            $this->actingAs($user)->postJson('/push-subscription', ['endpoint' => $endpoint, 'keys' => $keys])->assertUnprocessable();
        }

        $this->actingAs($user)->postJson('/push-subscription', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc', 'keys' => $keys])->assertNoContent();
        $this->assertSame(1, PushSubscription::where('user_id', $user->id)->count());
    }

    /**
     * Puts the room's game mid-play with known stands: Anna's turn, no snapshots yet.
     *
     * @param  list<int>  $annaWires
     * @param  list<int>  $benceWires
     */
    private function playingState(Room $room, User $anna, User $bence, array $annaWires, array $benceWires, int $limit = 3): void
    {
        $hand = fn (array $values) => array_map(fn (int $value) => ['color' => 'blue', 'value' => $value, 'rank' => $value * 10, 'cut' => false, 'info' => null, 'revealed' => false], $values);

        $state = $room->game->state;
        $state['phase'] = 'playing';
        $state['turn'] = $anna->id;
        $state['hands'] = [$anna->id => $hand($annaWires), $bence->id => $hand($benceWires)];
        $state['detonator']['limit'] = $limit;
        $room->game->update(['state' => $state]);
    }

    private function startedRoom(int $players, int $mission = 1): Room
    {
        $users = User::factory()->count($players)->create();
        $lobby = app(LobbyService::class);
        $room = $lobby->create($users->first(), max($players, 2), $mission);

        foreach ($users->skip(1) as $user) {
            $lobby->join($room, $user);
            $lobby->setReady($room, $user, true);
        }

        $lobby->start($room, $users->first());

        return $room->fresh()->load('players.user', 'host', 'game');
    }
}
