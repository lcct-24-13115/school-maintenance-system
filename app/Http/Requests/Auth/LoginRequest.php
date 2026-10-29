<?php

namespace App\Http\Requests\Auth;

use App\Services\AuditLogger;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
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

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
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
