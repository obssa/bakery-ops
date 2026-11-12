<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Contracts\Authenticate;
use App\Modules\Identity\Demo\DemoLogins;
use App\Modules\Identity\Exceptions\IdentityError;
use App\Modules\Identity\Http\Requests\DemoLoginRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;

/**
 * TEMPORARY (ADR 0025): the sign-in page's demo buttons. Both routes are public and throttled, and both answer a bare 404 while the
 * buttons are off (DemoLogins::enabled(): the demo branch's admin switch, PUT /brand/demo-login, unless BAKERY_DEMO_LOGIN=false forces
 * them off), as if they did not exist.
 *
 *   GET  /demo-logins  {data: [{role, name_en, name_am}]}: the demo people that can be signed in, no phone number or code
 *   POST /demo-login   {role}: signs that demo person in; the answer is POST /login's (token, token_type, user, permissions, locale)
 *                      and so is the audit row (auth.login, marked via=demo_button). A role with no active demo account: 422
 *                      identity.demo_login.unavailable. Like /login it takes no Idempotency-Key: a sign-in creates a token and
 *                      writes nothing else, and a repeat is a second sign-in.
 */
final class DemoLoginController
{
    public function __construct(
        private readonly DemoLogins $demo,
        private readonly Authenticate $auth,
    ) {}

    public function index(): JsonResponse
    {
        abort_unless($this->demo->enabled(), 404);

        return response()->json(['data' => $this->demo->people()]);
    }

    public function store(DemoLoginRequest $request): JsonResponse
    {
        abort_unless($this->demo->enabled(), 404);

        $user = $this->demo->userFor($request->role()) ?? throw IdentityError::demoAccountUnavailable();
        $result = $this->auth->signInDemo($user);

        return response()->json([
            'token' => $result->token,
            'token_type' => 'Bearer',
            'user' => (new UserResource($result->user))->resolve($request),
            'permissions' => $result->permissions,
            'locale' => $result->locale,
        ]);
    }
}
