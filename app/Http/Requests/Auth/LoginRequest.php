<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * Phase 3: after login.max_attempts wrong passwords the sign-in is locked for login.lock_minutes
     * (config/school.php). While locked, even the right password is refused. Every lock is written to the audit log.
     *
     * @throws ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();
        $this->ensureIsApproved();

        if (! Auth::attempt($this->only('email', 'password') + ['is_active' => true, 'approval_status' => User::STATUS_APPROVED], $this->boolean('remember'))) {
            $max = $this->maxAttempts();
            $lockSeconds = $this->lockMinutes() * 60;

            RateLimiter::hit($this->throttleKey(), $lockSeconds);
            $used = RateLimiter::attempts($this->throttleKey());

            if ($used >= $max) {
                RateLimiter::clear($this->throttleKey());
                RateLimiter::hit($this->lockKey(), $lockSeconds);

                $email = mb_substr(trim($this->string('email')->toString()), 0, 100);
                AuditLogger::log(
                    'account_locked',
                    "Sign-in locked for {$this->lockMinutes()} minutes after {$max} failed attempts for {$email}",
                    meta: ['email' => $email, 'minutes' => $this->lockMinutes()],
                );

                throw ValidationException::withMessages(['email' => $this->lockedMessage($lockSeconds)]);
            }

            $left = $max - $used;

            throw ValidationException::withMessages([
                'email' => trans('auth.failed')." {$left} ".($left === 1 ? 'attempt' : 'attempts')
                    ." left before sign-in is locked for {$this->lockMinutes()} minutes.",
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Account approval: a person whose registration is still pending (or was rejected) gets a clear message instead of
     * the generic "credentials do not match". Only someone who typed the RIGHT password sees it, so nobody can use
     * this page to find out which e-mail addresses are registered or what state they are in.
     *
     * @throws ValidationException
     */
    private function ensureIsApproved(): void
    {
        $user = User::query()->where('email', Str::lower($this->string('email')->toString()))->first();

        if ($user === null || $user->isApproved()) {
            return;
        }

        if (! Hash::check($this->string('password')->toString(), (string) $user->password)) {
            return;   // wrong password: carry on, the normal failed-sign-in rules (and the lock) apply
        }

        $message = $user->isPending()
            ? 'Your account is waiting for approval by a Maintenance Admin. You will get an email when it is decided.'
            : 'Your registration was not approved.'.(filled($user->rejection_reason) ? ' Reason: '.$user->rejection_reason : '').' Please contact the Maintenance Office.';

        throw ValidationException::withMessages(['email' => $message]);
    }

    /**
     * Ensure the login request is not locked.
     *
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->lockKey(), 1)) {
            return;
        }

        event(new Lockout($this));

        throw ValidationException::withMessages([
            'email' => $this->lockedMessage(RateLimiter::availableIn($this->lockKey())),
        ]);
    }

    private function lockedMessage(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return "Too many failed sign-in attempts. Sign-in is locked for now. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.';
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('school.login.max_attempts', 5));
    }

    private function lockMinutes(): int
    {
        return max(1, (int) config('school.login.lock_minutes', 15));
    }

    private function lockKey(): string
    {
        return 'lock|'.$this->throttleKey();
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
