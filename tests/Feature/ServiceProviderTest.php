<?php

declare(strict_types=1);
use Afiqsazlan\SafeSql\SafeSqlServiceProvider;

it('boots the package service provider', function () {
    expect(app()->getLoadedProviders())
        ->toHaveKey(SafeSqlServiceProvider::class);
});
