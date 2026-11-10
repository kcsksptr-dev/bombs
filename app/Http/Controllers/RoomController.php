<?php

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Games\GameRegistry;
use App\Models\Room;
use App\Models\RoomPlayer;
use App\Models\User;
use App\Services\LobbyService;
use App\Support\PublicUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RoomController extends Controller
{
    public function __construct(
        private readonly LobbyService $lobby,
        private readonly GameRegistry $games,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'capacity' => ['required', 'integer'],
            'mission' => ['sometimes', 'integer'],
            'game' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        // Without a game the room opens on the game picker
        $room = $this->lobby->create($request->user(), (int) $validated['capacity'], (int) ($validated['mission'] ?? 1), $validated['game'] ?? null);

        return to_route('rooms.show', $room);
    }

    /**
     * "Join with code" form on the home screen.
     */
    public function find(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:8'],
        ]);

        $room = Room::where('code', strtoupper(trim($validated['code'])))
            ->where('status', '!=', RoomStatus::Finished)
            ->first();

        if (! $room) {
            return back()->withErrors(['code' => __('lobby.errors.room_not_found')]);
        }

        return to_route('rooms.show', $room);
    }

    public function show(Request $request, Room $room): Response|RedirectResponse
    {
        $room->load('players.user');
        $user = $request->user();
        $me = $room->playerFor($user);

        if ($me && $room->status === RoomStatus::Playing) {
            return to_route('rooms.game', $room);
        }

        $definition = $this->games->has($room->game_key) ? $this->games->get($room->game_key) : null;

        return Inertia::render('Room/Lobby', [
            'room' => self::roomProps($room),
            'me' => [
                'id' => $user->id,
                'isMember' => (bool) $me,
                'isHost' => $room->isHost($user),
            ],
            // Phones can't open bombbusters.localhost, so the QR code always points at the LAN address
            'joinUrl' => $this->joinUrl($request, $room),
            'capacity' => [
                'min' => $definition?->minPlayers() ?? config('games.min_players'),
                'max' => $definition?->maxPlayers() ?? config('games.max_players'),
            ],
            'games' => $this->games->options(),
            // The chosen game's settings (Bomb Busters: the mission picker)
            'settings' => $definition && $me ? $definition->lobbyProps($room, $user) : (object) [],
        ]);
    }

    public function join(Request $request, Room $room): RedirectResponse
    {
        $this->lobby->join($room, $request->user());

        return to_route('rooms.show', $room);
    }

    public function seat(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['seat' => ['required', 'integer']]);
        $this->lobby->takeSeat($room, $request->user(), (int) $validated['seat']);

        return back();
    }

    public function ready(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['ready' => ['required', 'boolean']]);
        $this->lobby->setReady($room, $request->user(), (bool) $validated['ready']);

        return back();
    }

    public function capacity(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['capacity' => ['required', 'integer']]);
        $this->lobby->setCapacity($room, $request->user(), (int) $validated['capacity']);

        return back();
    }

    /**
     * Host picks the game on the picker; an empty game goes back to the picker.
     */
    public function chooseGame(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['game' => ['present', 'nullable', 'string', 'max:32']]);
        $this->lobby->chooseGame($room, $request->user(), $validated['game']);

        return back();
    }

    public function mission(Request $request, Room $room): RedirectResponse
    {
        $validated = $request->validate(['mission' => ['required', 'integer']]);
        $this->lobby->setMission($room, $request->user(), (int) $validated['mission']);

        return back();
    }

    public function kick(Request $request, Room $room, User $user): RedirectResponse
    {
        $this->lobby->kick($room, $request->user(), $user);

        return back();
    }

    public function transferHost(Request $request, Room $room, User $user): RedirectResponse
    {
        $this->lobby->transferHost($room, $request->user(), $user);

        return back();
    }

    public function leave(Request $request, Room $room): RedirectResponse
    {
        $this->lobby->leave($room, $request->user());

        return to_route('home');
    }

    public function start(Request $request, Room $room): RedirectResponse
    {
        $this->lobby->start($room, $request->user());

        return to_route('rooms.game', $room);
    }

    /**
     * Lobby data is public to everyone in the room; nothing hidden belongs here.
     *
     * @return array<string, mixed>
     */
    public static function roomProps(Room $room): array
    {
        return [
            'code' => $room->code,
            'status' => $room->status,
            'capacity' => $room->capacity,
            'game' => $room->game_key,
            'mission' => $room->mission,
            'hostId' => $room->host_user_id,
            'players' => $room->players->map(fn (RoomPlayer $player) => [
                'id' => $player->user_id,
                'name' => $player->user->name,
                'seat' => $player->seat,
                'ready' => $player->is_ready,
            ])->values(),
        ];
    }

    /**
     * The link the QR code and "copy link" share: the public tunnel address while the game is online
     * (works for everyone, on the Wi-Fi or not), otherwise this machine's LAN address, because
     * phones can't open bombbusters.localhost.
     */
    private function joinUrl(Request $request, Room $room): string
    {
        $path = route('rooms.show', $room, absolute: false);
        $lanIp = config('bombbusters.lan_ip');

        return match (true) {
            PublicUrl::current() !== null => PublicUrl::current().$path,
            (bool) $lanIp => "http://{$lanIp}{$path}",
            default => $request->getSchemeAndHttpHost().$path,
        };
    }
}
