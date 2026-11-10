<?php

namespace Tests\Feature;

use App\Events\RoomUpdated;
use App\Games\BombBusters\GameEngine;
use App\Models\MissionCompletion;
use App\Models\Room;
use App\Models\User;
use App\Services\LobbyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CampaignTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([RoomUpdated::class]);
    }

    public function test_every_mission_is_valid_and_translated(): void
    {
        $missions = config('missions');
        $this->assertSame(range(1, count($missions)), array_keys($missions), 'missions are numbered 1..n without gaps');

        $en = json_decode(file_get_contents(lang_path('en.json')), true);
        $hu = json_decode(file_get_contents(lang_path('hu.json')), true);

        foreach (array_keys($missions) as $number) {
            $mission = GameEngine::mission($number);

            foreach (['yellow', 'red'] as $color) {
                $this->assertLessThanOrEqual($mission[$color]['pool'], $mission[$color]['count'], "mission {$number}: {$color} count <= pool");
                $this->assertLessThanOrEqual(11, $mission[$color]['pool'], "mission {$number}: {$color} pool fits 1..11");
            }

            foreach ($mission['sequence'] as $kind) {
                $this->assertTrue($kind === 'yellow' || (is_int($kind) && $kind >= 1 && $kind <= 12), "mission {$number}: bad sequence step");
            }

            if (in_array('yellow', $mission['sequence'], true)) {
                $this->assertGreaterThan(0, $mission['yellow']['count'], "mission {$number}: sequence needs yellow wires");
            }

            foreach ($mission['equipment']['cards'] ?? [] as $card) {
                $this->assertArrayHasKey($card, config('bombbusters.equipment'), "mission {$number}: unknown card {$card}");
            }

            $this->assertContains($mission['equipment']['rule'], ['cut_pair', 'cut_all', 'after_mistake', 'from_start']);

            foreach (['name', 'briefing'] as $field) {
                $this->assertArrayHasKey("mission.{$number}.{$field}", $en);
                $this->assertArrayHasKey("mission.{$number}.{$field}", $hu);
            }

            // And it actually deals
            $state = (new GameEngine)->start([1, 2, 3], 1, $number);
            $this->assertSame('setup', $state['phase']);
        }
    }

    public function test_winning_ticks_the_mission_off_for_every_player_and_offers_the_next_one(): void
    {
        [$room, $anna, $bence] = $this->almostWonRoom(mission: 3);

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/dual-cut", ['target' => $bence->id, 'index' => 0, 'value' => 5])
            ->assertSessionHasNoErrors();

        $this->assertSame('won', $room->game->fresh()->state['phase']);
        $this->assertEqualsCanonicalizing([[$anna->id, 3], [$bence->id, 3]], MissionCompletion::all()->map(fn ($c) => [$c->user_id, $c->mission])->all());

        // A guest can't move the table on; the host can
        $this->actingAs($bence)->post("/rooms/{$room->code}/game/next-mission")->assertSessionHasErrors('game');
        $this->actingAs($anna)->post("/rooms/{$room->code}/game/next-mission")->assertRedirect("/rooms/{$room->code}");
        $this->assertSame(4, $room->fresh()->mission);

        $this->actingAs($bence)->get("/rooms/{$room->code}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('settings.missions.completed', [3])
            ->where('settings.missions.next', 1));
    }

    public function test_next_mission_does_not_skip_ahead_after_a_loss(): void
    {
        [$room, $anna, $bence] = $this->almostWonRoom(mission: 3);
        $state = $room->game->state;
        $state['detonator']['mistakes'] = $state['detonator']['limit'] - 1;
        // Anna also holds a 9, and names it for Bence's 5: the last mistake the detonator allows
        $state['hands'][$anna->id][] = ['color' => 'blue', 'value' => 9, 'rank' => 90, 'cut' => false, 'info' => null, 'revealed' => false];
        $room->game->update(['state' => $state]);

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/dual-cut", ['target' => $bence->id, 'index' => 0, 'value' => 9])
            ->assertSessionHasNoErrors();
        $this->assertSame('lost', $room->game->fresh()->state['phase']);

        $this->actingAs($anna)->post("/rooms/{$room->code}/game/next-mission");
        $this->assertSame(3, $room->fresh()->mission);
        $this->assertSame(0, MissionCompletion::count());
    }

    public function test_picking_bomb_busters_starts_at_the_hosts_first_unfinished_mission(): void
    {
        $user = User::factory()->create();
        MissionCompletion::create(['user_id' => $user->id, 'mission' => 1, 'completed_at' => now()]);
        MissionCompletion::create(['user_id' => $user->id, 'mission' => 2, 'completed_at' => now()]);

        $this->actingAs($user)->post('/rooms', ['capacity' => 4]);
        $room = Room::firstOrFail();
        $this->assertNull($room->game_key);

        $this->actingAs($user)->post("/rooms/{$room->code}/choose-game", ['game' => 'bombbusters'])->assertSessionHasNoErrors();
        $this->assertSame(3, $room->fresh()->mission);

        $this->actingAs($user)->get("/rooms/{$room->code}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('room.game', 'bombbusters')
            ->where('settings.missions.next', 3)
            ->where('settings.missions.completed', [1, 2])
            ->has('settings.missions.missions', count(config('missions'))));
    }

    /**
     * A two-player game where Anna is one correct cut (her 5 on Bence's 5) away from winning.
     *
     * @return array{Room, User, User}
     */
    private function almostWonRoom(int $mission): array
    {
        [$anna, $bence] = User::factory()->count(2)->create()->all();
        $lobby = app(LobbyService::class);
        $room = $lobby->create($anna, 2, $mission, game: 'bombbusters');
        $lobby->join($room, $bence);
        $lobby->setReady($room, $bence, true);
        $lobby->start($room, $anna);

        $wire = fn (int $value, bool $cut = false) => ['color' => 'blue', 'value' => $value, 'rank' => $value * 10, 'cut' => $cut, 'info' => null, 'revealed' => false];
        $state = $room->game->state;
        $state['phase'] = 'playing';
        $state['turn'] = $anna->id;
        $state['sequence'] = [];
        $state['hands'] = [$anna->id => [$wire(5), $wire(7, true)], $bence->id => [$wire(5), $wire(7, true)]];
        $state['info_placed'] = [$anna->id => true, $bence->id => true];
        $room->game->update(['state' => $state]);

        return [$room->fresh(), $anna, $bence];
    }
}
