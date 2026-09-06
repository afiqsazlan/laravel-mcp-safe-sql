<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Safe SQL MCP routes
|--------------------------------------------------------------------------
|
| Published stub. Edit freely — this file belongs to your application, not
| the package. Load it from bootstrap/app.php:
|
|     ->withRouting(
|         web: __DIR__.'/../routes/web.php',
|         then: fn () => require __DIR__.'/../routes/safe-sql.php',
|     )
|
*/

use Afiqsazlan\SafeSql\Http\OAuthRoutes;
use App\Mcp\Servers\DebugServer;
use App\Mcp\Servers\ResearchServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
| OAuth discovery without Dynamic Client Registration.
|
| Swap this for Mcp::oauthRoutes() if you want clients to be able to register
| themselves. For a server in front of a production database, provisioning by
| hand is usually the safer choice:
|
|     php artisan passport:client --public --name="Claude"
*/
OAuthRoutes::register();

/*
| One endpoint per profile.
|
| Keep them separate. Anonymized production data and literal non-production
| data are different sensitivity tiers, and each needs to be grantable on its
| own — nobody debugging staging should acquire production access as a side
| effect. Server instructions also stay resident for the whole session, so a
| merged endpoint makes every conversation pay for both instruction sets.
|
|--------------------------------------------------------------------------
| Deciding who may connect
|--------------------------------------------------------------------------
|
| OAuth authenticates; your middleware authorizes. Someone adding this server
| in their client is sent here to log in as themselves and approve the
| "mcp:use" scope, so every request arrives as a real authenticated user. What
| they may then do is an ordinary Laravel authorization question.
|
| Pick whichever of these matches how your app already works:
|
|   // A gate — no extra packages needed
|   Gate::define('access-research', fn (User $user) => $user->hasRole('analyst'));
|   ->middleware(['auth:oauth', 'scope:mcp:use', 'can:access-research'])
|
|   // spatie/laravel-permission
|   ->middleware(['auth:oauth', 'scope:mcp:use', 'permission:access-research'])
|
|   // An explicit allowlist, while you are still deciding
|   Gate::define('access-research', fn (User $user) => in_array($user->id, [1, 5], true));
|
| To cut someone off: revoke their tokens with $user->tokens()->delete(). To
| cut everyone off, revoke the OAuth client — and because dynamic client
| registration is disabled above, nobody can mint a replacement.
|
| What this cannot do: restrict which *rows* a user sees. Authorization here is
| all-or-nothing per endpoint. If different people should see different rows,
| give them separate profiles reading connections with different grants, or
| point a profile at a database view that already filters.
|
| The last line of defence is not here at all — it is GRANT SELECT on the
| database user this profile connects as. Anything that user can read, an
| authorized person can ask about.
*/
Route::middleware(['auth:oauth', 'scope:mcp:use', 'can:access-research'])
    ->group(function () {
        Mcp::web('mcp/research', ResearchServer::class);
    });

/*
| Gate non-production endpoints on the environment in your own code. Deciding
| where a debug endpoint may exist is deployment policy, which is why the
| package takes no view on it.
*/
if (! app()->isProduction()) {
    Route::middleware(['auth:oauth', 'scope:mcp:use', 'can:access-debug'])
        ->group(function () {
            Mcp::web('mcp/debug', DebugServer::class);
        });
}
