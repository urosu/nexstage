<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     *
     * Passes the invitation token (if present in the URL) as a prop so the
     * form can include it as a hidden field and we can store it in session.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/Register', [
            'invitation_token' => $request->query('invitation'),
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * If an invitation token is submitted, store it in session so that
     * VerifyEmailController can accept it after email verification.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name'             => 'required|string|max:255',
            'email'            => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password'         => ['required', 'confirmed', Rules\Password::defaults()],
            'invitation_token' => 'nullable|string|max:255',
        ], [
            // Generic message avoids revealing whether the email already has an account.
            'email.unique' => 'Unable to complete registration. If you already have an account, please log in.',
        ]);

        // Store invitation token before email verification so VerifyEmailController can use it
        if ($request->filled('invitation_token')) {
            $request->session()->put('invitation_token', $request->input('invitation_token'));
        }

        $user = User::create([
            'name'     => $request->name,
            'email'    => $request->email,
            'password' => Hash::make($request->password),
        ]);

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('verification.notice', absolute: false));
    }
}
