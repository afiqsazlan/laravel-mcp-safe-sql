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
| The middleware is yours: the package enforces read-only SQL and
| pseudonymization, it does not decide who may connect.
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
