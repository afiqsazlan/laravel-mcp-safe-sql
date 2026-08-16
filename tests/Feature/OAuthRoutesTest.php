<?php

declare(strict_types=1);

use Afiqsazlan\SafeSql\Http\OAuthRoutes;
use Afiqsazlan\SafeSql\SafeSqlServiceProvider;

describe('authorization server metadata', function () {
    it('omits the registration endpoint', function () {
        // The whole reason this exists rather than deferring to
        // Mcp::oauthRoutes(): omitting registration_endpoint is what tells a
        // conforming client not to attempt dynamic registration.
        expect(OAuthRoutes::authorizationServerMetadata())
            ->not->toHaveKey('registration_endpoint');
    });

    it('advertises PKCE and the authorization code flow', function () {
        expect(OAuthRoutes::authorizationServerMetadata())
            ->code_challenge_methods_supported->toBe(['S256'])
            ->response_types_supported->toBe(['code'])
            ->grant_types_supported->toBe(['authorization_code', 'refresh_token']);
    });

    it('advertises the same scope Laravel MCP uses', function () {
        expect(OAuthRoutes::authorizationServerMetadata()['scopes_supported'])
            ->toBe(['mcp:use']);
    });
});

describe('protected resource metadata', function () {
    it('reports the resource and its authorization server', function () {
        expect(OAuthRoutes::protectedResourceMetadata('mcp/research'))
            ->resource->toBe(url('/mcp/research'))
            ->scopes_supported->toBe(['mcp:use']);
    });

    it('honours a configured authorization server', function () {
        config()->set('mcp.authorization_server', 'https://auth.example.test');

        expect(OAuthRoutes::protectedResourceMetadata())
            ->authorization_servers->toBe(['https://auth.example.test']);
    });
});

describe('published stubs', function () {
    it('publishes the route file and server stub under their own tags', function () {
        foreach (['safe-sql-config', 'safe-sql-routes', 'safe-sql-server'] as $tag) {
            expect(SafeSqlServiceProvider::pathsToPublish(
                SafeSqlServiceProvider::class,
                $tag
            ))->toHaveCount(1);
        }
    });

    it('ships a route stub that registers discovery without dynamic registration', function () {
        $stub = (string) file_get_contents(__DIR__.'/../../routes/safe-sql.php');

        // Mcp::oauthRoutes() is mentioned in a comment as the alternative, so
        // assert on what the stub actually executes.
        $executable = implode("\n", array_filter(
            explode("\n", $stub),
            static fn (string $line) => ! str_starts_with(ltrim($line), '|')
                && ! str_starts_with(ltrim($line), '*')
                && ! str_starts_with(ltrim($line), '//'),
        ));

        expect($executable)->toContain('OAuthRoutes::register();')
            ->and($executable)->not->toContain('Mcp::oauthRoutes(');
    });

    it('falls back to conventional passport URIs when its routes are absent', function () {
        // Passport is not installed in the test harness, which is exactly the
        // load-order case the fallback exists for.
        expect(OAuthRoutes::authorizationServerMetadata())
            ->authorization_endpoint->toBe(url('/oauth/authorize'))
            ->token_endpoint->toBe(url('/oauth/token'));
    });
});
