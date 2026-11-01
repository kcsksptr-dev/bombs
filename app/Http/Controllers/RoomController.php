<?php

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
use App\Game\GameEngine;
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
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'capacity' => ['required', 'integer'],
            'mission' => ['required', 'integer'],
        ]);

        $room = $this->lobby->create($request->user(), (int) $validated['capacity'], (int) $validated['mission']);

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
                'min' => config('bombbusters.min_players'),
                'max' => config('bombbusters.max_players'),
            ],
            'missions' => self::missionOptions($user),
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
     * The campaign as the mission picker shows it: every mission's rules, plus this player's progress.
     *
     * @return array{missions: list<array<string, mixed>>, detonator: array<int, int>, completed: list<int>, next: int}
     */
    public static function missionOptions(User $viewer): array
    {
        $completed = $viewer->completedMissions();
        $numbers = array_keys(config('missions'));

        return [
            'missions' => array_map(function (int $number) {
                $mission = GameEngine::mission($number);

                return [
                    'number' => $number,
                    'detonatorBonus' => $mission['detonator_bonus'],
                    'yellow' => $mission['yellow'],
                    'red' => $mission['red'],
                    'sequence' => $mission['sequence'],
                    'equipment' => [
                        'count' => count($mission['equipment']['cards'] ?? []) ?: $mission['equipment']['count'],
                        'cards' => $mission['equipment']['cards'] ?? null,
                        'rule' => $mission['equipment']['rule'],
                    ],
                ];
            }, $numbers),
            'detonator' => config('bombbusters.detonator'),
            'completed' => $completed,
            // First mission this player hasn't won yet (or the last one, once the campaign is done)
            'next' => collect($numbers)->first(fn (int $number) => ! in_array($number, $completed, true)) ?? max($numbers),
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
