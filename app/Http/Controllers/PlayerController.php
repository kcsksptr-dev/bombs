<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PlayerController extends Controller
{
    /**
     * Create the device's guest player on first visit, or rename it later.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:40'],
        ]);

        $name = trim($validated['name']);

        if ($user = $request->user()) {
            $user->update(['name' => $name]);

            return back();
        }

        $user = User::create([
            'name' => $name,
            'locale' => $request->session()->get('locale', app()->getLocale()),
        ]);

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }
}
