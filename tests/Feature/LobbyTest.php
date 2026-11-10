<?php

namespace Tests\Feature;

use App\Enums\RoomStatus;
use App\Events\RoomUpdated;
use App\Models\Room;
use App\Models\User;
use App\Services\LobbyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class LobbyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([RoomUpdated::class]);
    }

    public function test_choosing_a_name_creates_a_remembered_guest_player(): void
    {
        $this->post('/player', ['name' => '  Kata  '])->assertRedirect('/');

        $this->assertAuthenticated();
        $this->assertSame('Kata', User::sole()->name);
    }

    public function test_guests_opening_a_room_link_are_sent_to_pick_a_name_first(): void
    {
        $room = $this->roomWithPlayers(1);

        $this->get("/rooms/{$room->code}")->assertRedirect('/');
        $this->post('/player', ['name' => 'Bence'])->assertRedirect("/rooms/{$room->code}");
    }

    public function test_host_creates_a_room_and_takes_seat_one(): void
    {
        $host = User::factory()->create();

        $response = $this->actingAs($host)->post('/rooms', ['capacity' => 6, 'mission' => 3, 'game' => 'bombbusters']);

        $room = Room::sole();
        $response->assertRedirect("/rooms/{$room->code}");
        $this->assertSame(6, $room->capacity);
        $this->assertSame(3, $room->mission);
        $this->assertSame(RoomStatus::Lobby, $room->status);
        $this->assertSame([1], $room->players()->pluck('seat')->all());
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z]{4}$/', $room->code);
    }

    public function test_capacity_scales_to_eight_but_no_further(): void
    {
        $host = User::factory()->create();

        $this->actingAs($host)->post('/rooms', ['capacity' => 8, 'mission' => 1, 'game' => 'bombbusters'])->assertSessionHasNoErrors();
        $this->actingAs($host)->post('/rooms', ['capacity' => 9, 'mission' => 1, 'game' => 'bombbusters'])->assertSessionHasErrors('lobby');
        $this->actingAs($host)->post('/rooms', ['capacity' => 1, 'mission' => 1, 'game' => 'bombbusters'])->assertSessionHasErrors('lobby');
        $this->actingAs($host)->post('/rooms', ['capacity' => 4, 'mission' => 999, 'game' => 'bombbusters'])->assertSessionHasErrors('lobby');
    }

    public function test_joining_takes_the_first_free_seat_and_notifies_the_room(): void
    {
        $room = $this->roomWithPlayers(1, capacity: 4);
        $player = User::factory()->create();

        $this->actingAs($player)->post("/rooms/{$room->code}/join")->assertRedirect("/rooms/{$room->code}");

        $this->assertSame(2, $room->fresh()->playerFor($player)->seat);
        Event::assertDispatched(RoomUpdated::class, fn (RoomUpdated $event) => $event->code === $room->code);
    }

    public function test_room_codes_are_case_insensitive(): void
    {
        $room = $this->roomWithPlayers(1);
        $player = User::factory()->create();

        $this->actingAs($player)->get('/rooms/'.strtolower($room->code))->assertOk();
        $this->actingAs($player)->post('/rooms/find', ['code' => ' '.strtolower($room->code)])
            ->assertRedirect("/rooms/{$room->code}");
    }

    public function test_a_full_room_rejects_new_players(): void
    {
        $room = $this->roomWithPlayers(2, capacity: 2);

        $this->actingAs(User::factory()->create())
            ->post("/rooms/{$room->code}/join")
            ->assertSessionHasErrors(['lobby' => 'This room is full.']);
    }

    public function test_players_can_move_to_a_free_seat_but_not_a_taken_one(): void
    {
        $room = $this->roomWithPlayers(2, capacity: 6);
        [$host, $guest] = $room->players->pluck('user')->all();

        $this->actingAs($guest)->post("/rooms/{$room->code}/seat", ['seat' => 5])->assertSessionHasNoErrors();
        $this->actingAs($guest)->post("/rooms/{$room->code}/seat", ['seat' => 1])->assertSessionHasErrors('lobby');
        $this->actingAs($guest)->post("/rooms/{$room->code}/seat", ['seat' => 7])->assertSessionHasErrors('lobby');

        $this->assertSame(5, $room->fresh()->playerFor($guest)->seat);
    }

    public function test_the_game_starts_only_when_everyone_but_the_host_is_ready(): void
    {
        $room = $this->roomWithPlayers(3);
        [$host, $second, $third] = $room->players->pluck('user')->all();

        $this->actingAs($host)->post("/rooms/{$room->code}/start")->assertSessionHasErrors('lobby');

        $this->actingAs($second)->post("/rooms/{$room->code}/ready", ['ready' => true]);
        $this->actingAs($third)->post("/rooms/{$room->code}/ready", ['ready' => true]);
        $this->actingAs($third)->post("/rooms/{$room->code}/start")->assertSessionHasErrors(['lobby' => 'Only the host can do that.']);

        $this->actingAs($host)->post("/rooms/{$room->code}/start")->assertRedirect("/rooms/{$room->code}/game");
        $this->assertSame(RoomStatus::Playing, $room->fresh()->status);
    }

    public function test_a_lone_host_cannot_start(): void
    {
        $room = $this->roomWithPlayers(1);

        $this->actingAs($room->host)->post("/rooms/{$room->code}/start")->assertSessionHasErrors('lobby');
    }

    public function test_members_of_a_running_game_land_on_the_game_screen(): void
    {
        $room = $this->roomWithPlayers(2);
        $room->update(['status' => RoomStatus::Playing]);

        $this->actingAs($room->host)->get("/rooms/{$room->code}")->assertRedirect("/rooms/{$room->code}/game");
        $this->actingAs(User::factory()->create())->post("/rooms/{$room->code}/join")->assertSessionHasErrors('lobby');
    }

    public function test_host_can_remove_a_player_and_hand_over_hosting(): void
    {
        $room = $this->roomWithPlayers(3);
        [$host, $second, $third] = $room->players->pluck('user')->all();

        $this->actingAs($host)->post("/rooms/{$room->code}/kick/{$third->id}")->assertSessionHasNoErrors();
        $this->assertNull($room->fresh()->playerFor($third));
        Event::assertDispatched(RoomUpdated::class, fn (RoomUpdated $event) => $event->meta === ['kicked_user_id' => $third->id]);

        $this->actingAs($host)->post("/rooms/{$room->code}/host/{$second->id}")->assertSessionHasNoErrors();
        $this->assertTrue($room->fresh()->isHost($second));

        $this->actingAs($host)->post("/rooms/{$room->code}/kick/{$second->id}")->assertSessionHasErrors('lobby');
    }

    public function test_when_the_host_leaves_the_next_seat_becomes_host_and_an_empty_room_closes(): void
    {
        $room = $this->roomWithPlayers(2);
        [$host, $second] = $room->players->pluck('user')->all();

        $this->actingAs($host)->post("/rooms/{$room->code}/leave")->assertRedirect('/');
        $this->assertTrue($room->fresh()->isHost($second));

        $this->actingAs($second)->post("/rooms/{$room->code}/leave");
        $this->assertSame(RoomStatus::Finished, $room->fresh()->status);
    }

    public function test_only_the_host_changes_the_mission_and_the_game_uses_it(): void
    {
        $room = $this->roomWithPlayers(2);
        [$host, $guest] = $room->players->pluck('user')->all();

        $this->actingAs($guest)->post("/rooms/{$room->code}/mission", ['mission' => 16])->assertSessionHasErrors('lobby');
        $this->actingAs($host)->post("/rooms/{$room->code}/mission", ['mission' => 16])->assertSessionHasNoErrors();

        $this->actingAs($guest)->post("/rooms/{$room->code}/ready", ['ready' => true]);
        $this->actingAs($host)->post("/rooms/{$room->code}/start");

        $state = $room->fresh()->game->state;
        $this->assertSame(16, $state['mission']);
        $this->assertSame(3, $state['detonator']['limit']);
    }

    public function test_shrinking_the_table_moves_players_into_remaining_seats(): void
    {
        $room = $this->roomWithPlayers(2, capacity: 8);
        [$host, $guest] = $room->players->pluck('user')->all();
        $room->playerFor($guest)->update(['seat' => 8]);

        $this->actingAs($host)->post("/rooms/{$room->code}/capacity", ['capacity' => 3])->assertSessionHasNoErrors();
        $this->actingAs($host)->post("/rooms/{$room->code}/capacity", ['capacity' => 1])->assertSessionHasErrors('lobby');

        $room = $room->fresh();
        $this->assertSame(3, $room->capacity);
        $this->assertSame(2, $room->playerFor($guest)->seat);
    }

    public function test_the_lobby_shows_hungarian_text_to_hungarian_players(): void
    {
        $room = $this->roomWithPlayers(2, capacity: 2);
        $hungarian = User::factory()->create(['locale' => 'hu']);

        $this->actingAs($hungarian)
            ->post("/rooms/{$room->code}/join")
            ->assertSessionHasErrors(['lobby' => 'Ez a szoba megtelt.']);

        $this->actingAs($hungarian)->get('/')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', 'hu')
            ->where('translations', fn ($translations) => $translations['home.create'] === 'Szoba létrehozása'));
    }

    public function test_phone_language_picks_the_locale_for_new_visitors(): void
    {
        $this->withHeader('Accept-Language', 'hu-HU,hu;q=0.9,en;q=0.8')
            ->get('/')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('locale', 'hu'));
    }

    public function test_switching_language_is_saved_for_the_player(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/locale', ['locale' => 'hu'])->assertSessionHasNoErrors();
        $this->assertSame('hu', $user->fresh()->locale);

        $this->actingAs($user)->post('/locale', ['locale' => 'de'])->assertSessionHasErrors('locale');
    }

    public function test_english_and_hungarian_dictionaries_have_the_same_keys(): void
    {
        $en = array_keys(json_decode(file_get_contents(lang_path('en.json')), true));
        $hu = array_keys(json_decode(file_get_contents(lang_path('hu.json')), true));

        $this->assertSame([], array_values(array_diff($en, $hu)), 'Missing in hu.json');
        $this->assertSame([], array_values(array_diff($hu, $en)), 'Missing in en.json');
    }

    public function test_only_room_members_may_join_the_presence_channel(): void
    {
        // Tests boot with the null broadcaster; register the channel callbacks on a real one
        config(['broadcasting.default' => 'reverb']);
        require base_path('routes/channels.php');
        $room = $this->roomWithPlayers(1);
        $channel = 'presence-room.'.$room->code;

        $this->actingAs($room->host)
            ->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1.1'])
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->post('/broadcasting/auth', ['channel_name' => $channel, 'socket_id' => '1.1'])
            ->assertForbidden();
    }

    private function roomWithPlayers(int $count, int $capacity = 4): Room
    {
        $users = User::factory()->count($count)->create();
        $room = app(LobbyService::class)->create($users->first(), $capacity, game: 'bombbusters');

        foreach ($users->skip(1) as $user) {
            app(LobbyService::class)->join($room, $user);
        }

        return $room->fresh()->load('players.user', 'host');
    }
}
