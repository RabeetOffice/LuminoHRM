<?php

namespace App\Http\Controllers;

use App\Actions\CreateUserAccount;
use App\Actions\SetUserActive;
use App\Actions\UpdateUserAccount;
use App\Exceptions\AccountRuleViolation;
use App\Http\Requests\UserRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Users and roles. Every route is behind the can:users.manage middleware.
 * Accounts are disabled, never deleted, so there is no destroy action.
 */
class UserController extends Controller
{
    public const PER_PAGE_OPTIONS = [10, 25, 50];

    public const STATUSES = ['all', 'active', 'disabled'];

    private const ROLE_LABELS = ['admin' => 'Admin', 'hr' => 'HR', 'manager' => 'Manager', 'employee' => 'Employee'];

    public function index(Request $request): Response|RedirectResponse
    {
        $status = $this->status($request);
        $role = $this->role($request);
        $search = $this->searchTerm($request);
        $perPage = $this->perPage($request);

        $query = User::query()
            // Explicit columns: password hashes, remember tokens and two-factor secrets never leave the database.
            ->select(['id', 'name', 'email', 'is_active', 'last_login_at'])
            ->with('roles')
            ->when($status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($status === 'disabled', fn (Builder $q) => $q->where('is_active', false))
            ->when($role !== '', fn (Builder $q) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', $role)))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $w) use ($search) {
                $pattern = $this->likePattern($search);
                $w->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern]);
            }))
            ->orderBy('name')
            ->orderBy('id');

        $requested = $request->query('page');
        // Very long digit strings would overflow the int / the SQL offset: treat them as "far past the end".
        $page = is_scalar($requested) && ctype_digit((string) $requested)
            ? max(1, strlen((string) $requested) > 9 ? 1_000_000_000 : (int) $requested)
            : 1;
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        // A page past the end (for example after the last row of the last page was filtered out):
        // send the browser to the real page so the address is right.
        if ($page > $paginator->lastPage()) {
            return redirect()->to(rtrim($request->fullUrlWithQuery([
                'page' => $paginator->lastPage() === 1 ? null : $paginator->lastPage(),
            ]), '?'));
        }

        $me = $request->user()->getKey();

        return Inertia::render('users/index', [
            'users' => [
                'data' => $paginator->getCollection()->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'role' => $u->roles->first()?->name,
                    'is_active' => $u->is_active,
                    'last_login_at' => $u->last_login_at?->toIso8601String(),
                    'is_self' => $u->id === $me,
                ])->values()->all(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            // Totals for the tab badges: never narrowed by search or filters.
            'counts' => [
                'all' => User::count(),
                'active' => User::where('is_active', true)->count(),
                'disabled' => User::where('is_active', false)->count(),
            ],
            'roleOptions' => collect(config('hrm.roles'))
                ->map(fn (string $name) => ['value' => $name, 'label' => self::ROLE_LABELS[$name] ?? ucfirst($name)])
                ->values()
                ->all(),
            'filters' => [
                'status' => $status,
                'role' => $role,
                'search' => $search,
                'per_page' => $perPage,
            ],
        ]);
    }

    public function store(UserRequest $request, CreateUserAccount $create): RedirectResponse
    {
        try {
            $user = $create->handle(
                $request->validated('name'),
                $request->validated('email'),
                $request->validated('role'),
            );
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['email' => 'A user with this email already exists.']);
        }

        if (! $create->linkSent()) {
            return back()->with('error', 'Account created, but the link could not be sent. Use the reset icon.');
        }

        return back()->with('success', "Account created for {$user->email}. A link to set a password has been emailed.");
    }

    public function update(UserRequest $request, User $user, UpdateUserAccount $update): RedirectResponse
    {
        try {
            $update->handle(
                $user,
                $request->validated('name'),
                $request->validated('email'),
                $request->validated('role'),
                actor: $request->user(),
            );
        } catch (AccountRuleViolation $e) {
            return back()->with('error', $e->getMessage());
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors(['email' => 'A user with this email already exists.']);
        }

        return back()->with('success', 'User updated.');
    }

    /** Disable an active account or enable a disabled one. */
    public function toggle(Request $request, User $user, SetUserActive $setActive): RedirectResponse
    {
        // The caller says which state it wants, so a stale tab or two admins at once cannot flip it the wrong way.
        $enable = (bool) $request->validate(['active' => ['required', 'boolean']])['active'];

        try {
            $setActive->handle($user, $enable, actor: $request->user());
        } catch (AccountRuleViolation $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $enable ? 'Account enabled.' : 'Account disabled. Any open sessions were ended.');
    }

    public function sendReset(User $user): RedirectResponse
    {
        if (! $user->is_active) {
            return back()->with('error', 'This account is disabled. Enable it before sending a password reset link.');
        }

        // The broker applies its own throttle (config auth.passwords.users.throttle).
        try {
            $status = Password::sendResetLink(['email' => $user->email]);
        } catch (Throwable $e) {
            // Class only: the message can carry addresses or tokens.
            Log::warning('Password reset link could not be sent.', ['exception' => $e::class]);

            return back()->with('error', 'The password reset link could not be sent. Please try again later.');
        }

        return match ($status) {
            Password::RESET_LINK_SENT => back()->with('success', "Password reset link sent to {$user->email}."),
            Password::RESET_THROTTLED => back()->with('error', 'A reset link was sent to this user a moment ago. Please wait a minute and try again.'),
            default => back()->with('error', 'The password reset link could not be sent. Please try again later.'),
        };
    }

    private function status(Request $request): string
    {
        $value = $request->query('status');

        return is_string($value) && in_array($value, self::STATUSES, true) ? $value : 'all';
    }

    private function role(Request $request): string
    {
        $value = $request->query('role');

        return is_string($value) && in_array($value, config('hrm.roles'), true) ? $value : '';
    }

    /** Rows per page: 10, 25 or 50; anything else falls back to 10. */
    private function perPage(Request $request): int
    {
        $value = $request->query('per_page');

        if (is_scalar($value) && ctype_digit((string) $value) && in_array((int) $value, self::PER_PAGE_OPTIONS, true)) {
            return (int) $value;
        }

        return self::PER_PAGE_OPTIONS[0];
    }

    private function searchTerm(Request $request): string
    {
        $value = $request->query('search');

        return is_string($value) ? mb_substr(trim($value), 0, 100) : '';
    }

    /** A LIKE "contains" pattern (escape character !) in which % and _ in the term are literal. */
    private function likePattern(string $term): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term)).'%';
    }
}
