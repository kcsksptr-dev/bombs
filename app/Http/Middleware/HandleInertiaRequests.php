<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user()?->only('id', 'name'),
            ],
            'locale' => $locale,
            'locales' => config('bombbusters.locales'),
            'pushKey' => config('bombbusters.push.public_key'),
            // Every equipment card in the game with its unlock number, for the equipment guide
            'equipmentCatalog' => fn () => array_map(fn (array $card) => $card['unlock'], config('bombbusters.equipment')),
            // The whole UI dictionary for the active language; the frontend t() helper reads from it
            'translations' => fn () => $this->translations($locale),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function translations(string $locale): array
    {
        $path = lang_path("{$locale}.json");

        return File::exists($path) ? File::json($path) : [];
    }
}
