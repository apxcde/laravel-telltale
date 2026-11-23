<?php

namespace Apxcde\LaravelTelltale\Support;

use Apxcde\LaravelTelltale\AttributesProviders\LaravelUserAttributesProvider;
use Illuminate\Http\Request;
use Throwable;
use WeakMap;

/**
 * Knows who the application's user is in the places a request cannot say.
 *
 * A request names its own user when it ends. Three places need more than that:
 *
 * - A queued job runs in a worker where nobody is signed in. The id of the user who dispatched
 *   it travels in the job's payload and is carried for as long as the job runs.
 * - A log record and a dispatched job are stamped at any moment, so the user is only read from
 *   a guard that already looked it up. Loading it here would add a query to every one of them.
 * - A request that signs the user out ends with nobody signed in. The user is remembered for
 *   that request.
 */
class CurrentUser
{
    /**
     * The key in a job's payload that holds the id of the user who dispatched it.
     */
    public const PAYLOAD_KEY = '_telltale_user_id';

    /**
     * The ids carried by the jobs that are running, the innermost last. A job dispatched
     * by nobody carries null.
     *
     * @var array<int, int|string|null>
     */
    protected array $carried = [];

    /**
     * The users who signed out, by the request they signed out in. A request that is gone
     * takes its entry with it, so nothing has to be reset between requests.
     *
     * @var WeakMap<Request, object>
     */
    protected WeakMap $signedOut;

    protected bool $resolving = false;

    public function __construct()
    {
        $this->signedOut = new WeakMap();
    }

    /**
     * The id of the user behind what is running now: the one a running job carries, else the
     * signed-in user, else the user who signed out during this request.
     */
    public function id(): int|string|null
    {
        $carried = $this->carried();

        if ($carried !== null) {
            return $carried;
        }

        $user = $this->user();

        if ($user === null) {
            return null;
        }

        return self::normalizeId((new LaravelUserAttributesProvider($user))->id());
    }

    /**
     * The signed-in user, or the one who signed out during this request. A guard that has not
     * looked its user up yet is left alone.
     */
    public function user(): ?object
    {
        return $this->signedIn() ?? $this->signedOutIn($this->request());
    }

    /**
     * The user a request ends with. Unlike user(), this asks the request, which loads the user
     * when a guard has not done so yet.
     */
    public function forRequest(Request $request): ?LaravelUserAttributesProvider
    {
        $provider = LaravelUserAttributesProvider::fromRequest($request);

        if ($provider !== null) {
            return $provider;
        }

        $signedOut = $this->signedOutIn($request);

        return $signedOut === null ? null : new LaravelUserAttributesProvider($signedOut);
    }

    /**
     * The id carried by the innermost running job, or null when no job runs or it carries none.
     */
    public function carried(): int|string|null
    {
        if ($this->carried === []) {
            return null;
        }

        return $this->carried[array_key_last($this->carried)];
    }

    /**
     * Carry the id a job arrived with for as long as the job runs.
     */
    public function enterJob(mixed $id): int|string|null
    {
        return $this->carried[] = self::normalizeId($id);
    }

    public function leaveJob(): void
    {
        array_pop($this->carried);
    }

    /**
     * Forget everything a worker's previous job left behind. A job that ended without its
     * closing event would otherwise hand its user to the next one.
     */
    public function startOver(): void
    {
        $this->carried = [];
        $this->signedOut = new WeakMap();
    }

    /**
     * A guard announces a sign-out even when nobody was signed in, with no user.
     */
    public function rememberSignedOut(?object $user): void
    {
        $request = $this->request();

        if ($user !== null && $request !== null) {
            $this->signedOut[$request] = $user;
        }
    }

    /**
     * An id is sent as the number or the string it is. Anything else is not an id.
     */
    public static function normalizeId(mixed $id): int|string|null
    {
        if (is_int($id)) {
            return $id;
        }

        if (is_string($id) && $id !== '') {
            return $id;
        }

        return null;
    }

    protected function signedIn(): ?object
    {
        // Looking the user up can itself write a log record, which asks for the user again.
        if ($this->resolving) {
            return null;
        }

        $this->resolving = true;

        try {
            if (! app()->resolved('auth')) {
                return null;
            }

            $auth = app('auth');

            if (! $auth->hasResolvedGuards() || ! $auth->hasUser()) {
                return null;
            }

            $user = $auth->user();

            return is_object($user) ? $user : null;
        } catch (Throwable) {
            return null;
        } finally {
            $this->resolving = false;
        }
    }

    protected function signedOutIn(?Request $request): ?object
    {
        if ($request === null) {
            return null;
        }

        return $this->signedOut[$request] ?? null;
    }

    protected function request(): ?Request
    {
        try {
            $request = app()->bound('request') ? app('request') : null;
        } catch (Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }
}
