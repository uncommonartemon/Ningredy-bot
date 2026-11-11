<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerGroup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The shop's accounts (operator, 2026-11-11): there is no open sign-up. A customer signs up
 * through the invitation link of their group, signs in from the link in the footer, and from
 * then on sees the group's prices.
 */
class AccountController extends Controller
{
    private const int ATTEMPTS_PER_MINUTE = 8;

    private const int ATTEMPTS_DECAY_SECONDS = 60;

    public function show(): Response|RedirectResponse
    {
        $customer = Auth::guard('customer')->user();
        if (! $customer instanceof Customer) {
            return redirect()->route('account.login');
        }

        return Inertia::render('Account/Index', [
            'account' => [
                'name' => $customer->name,
                'email' => $customer->email,
                'company' => $customer->company,
                'group' => $customer->group?->is_active ? $customer->group->name : null,
            ],
        ]);
    }

    public function loginForm(): Response|RedirectResponse
    {
        return Auth::guard('customer')->check() ? redirect()->route('account') : Inertia::render('Account/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'string', 'email', 'max:255'], 'password' => ['required', 'string', 'max:255']]);
        $attempts = 'account-login:'.$request->ip();
        $this->refuseWhenTooMany($attempts);
        $signedIn = Auth::guard('customer')->attempt([
            'email' => mb_strtolower(trim($credentials['email'])), 'password' => $credentials['password'], 'is_active' => true,
        ], remember: true);
        if (! $signedIn) {
            RateLimiter::hit($attempts, self::ATTEMPTS_DECAY_SECONDS);
            // One answer for a wrong address, a wrong password and a closed account.
            throw ValidationException::withMessages(['email' => 'credentials']);
        }
        RateLimiter::clear($attempts);
        $request->session()->regenerate();
        Auth::guard('customer')->user()->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->route('catalog');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('catalog');
    }

    public function joinForm(string $code): Response|RedirectResponse
    {
        if (Auth::guard('customer')->check()) {
            return redirect()->route('account');
        }
        $group = $this->groupByInvitation($code);

        return Inertia::render('Account/Join', ['invitation' => ['code' => $group->invite_code, 'group' => $group->name]]);
    }

    public function join(Request $request, string $code): RedirectResponse
    {
        $group = $this->groupByInvitation($code);
        $attempts = 'account-join:'.$request->ip();
        $this->refuseWhenTooMany($attempts);
        RateLimiter::hit($attempts, self::ATTEMPTS_DECAY_SECONDS);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'company' => ['nullable', 'string', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)],
        ]);
        $email = mb_strtolower(trim($data['email']));
        if (Customer::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'taken']);
        }
        $customer = Customer::query()->create([
            'customer_group_id' => $group->id, 'name' => $data['name'], 'email' => $email,
            'company' => $data['company'] ?? null, 'phone' => $data['phone'] ?? null,
            'password' => $data['password'], 'last_login_at' => now(),
        ]);
        Auth::guard('customer')->login($customer, remember: true);
        $request->session()->regenerate();

        return redirect()->route('account');
    }

    /** The form answers in its own words; a bare «429» page would be the framework's. */
    private function refuseWhenTooMany(string $key): void
    {
        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
            throw ValidationException::withMessages(['email' => 'throttled']);
        }
    }

    /** A link that is unknown, closed, or of a group no longer in use leads nowhere. */
    private function groupByInvitation(string $code): CustomerGroup
    {
        $group = CustomerGroup::query()->where('invite_code', $code)->first();
        abort_unless($group?->acceptsRegistration(), 404);

        return $group;
    }
}
