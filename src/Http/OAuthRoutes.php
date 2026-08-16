<?php

declare(strict_types=1);

namespace Afiqsazlan\SafeSql\Http;

use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Server\Registrar;

/**
 * OAuth discovery metadata without Dynamic Client Registration.
 *
 * Laravel MCP ships Mcp::oauthRoutes(), which registers the scope and both
 * .well-known endpoints — and also exposes POST /oauth/register and advertises
 * a registration_endpoint. There is no flag to turn that off: the built-in
 * method skips its own .well-known routes if you defined them first, but
 * registers the DCR endpoint unconditionally.
 *
 * Dynamic Client Registration lets any client that can reach the endpoint mint
 * its own OAuth client. That is the right default for a public MCP service and
 * the wrong one for a server sitting in front of a production database, where
 * the set of legitimate clients is small, known, and better provisioned by hand:
 *
 *     php artisan passport:client --public --name="Claude"
 *
 * Use this instead of Mcp::oauthRoutes() when you want discovery to work for
 * well-behaved clients while keeping client provisioning a deliberate act.
 * Use the built-in method if you actually want open registration.
 */
class OAuthRoutes
{
    public static function register(): void
    {
        // The same scope Laravel MCP uses, registered the same way, so tokens
        // issued either way are interchangeable.
        Registrar::ensureMcpScope();

        Route::get('/.well-known/oauth-protected-resource/{path?}', static fn (?string $path = '') => response()->json(
            self::protectedResourceMetadata((string) $path)
        ))->where('path', '.*')->name('mcp.oauth.protected-resource');

        Route::get('/.well-known/oauth-authorization-server/{path?}', static fn (?string $path = '') => response()->json(
            self::authorizationServerMetadata()
        ))->where('path', '.*')->name('mcp.oauth.authorization-server');
    }

    /**
     * @return array<string, mixed>
     */
    public static function protectedResourceMetadata(string $path = ''): array
    {
        return [
            'resource' => url('/'.$path),
            'authorization_servers' => [config('mcp.authorization_server') ?? url('/')],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
        ];
    }

    /**
     * Note the absence of "registration_endpoint". Omitting it is what tells a
     * conforming client not to attempt dynamic registration.
     *
     * @return array<string, mixed>
     */
    public static function authorizationServerMetadata(): array
    {
        return [
            'issuer' => config('mcp.authorization_server') ?? url('/'),
            'authorization_endpoint' => self::endpoint('passport.authorizations.authorize', '/oauth/authorize'),
            'token_endpoint' => self::endpoint('passport.token', '/oauth/token'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => [Registrar::OAUTH_SCOPE],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
        ];
    }

    /**
     * Resolve a Passport route, falling back to its conventional URI.
     *
     * Discovery metadata is often built before Passport's routes are
     * registered — or in an application that maps them itself. Calling route()
     * unguarded turns that into a 500 on the one endpoint a client hits first,
     * which is a confusing way to discover a load-order problem.
     */
    protected static function endpoint(string $name, string $fallback): string
    {
        return Route::has($name) ? route($name) : url($fallback);
    }
}
