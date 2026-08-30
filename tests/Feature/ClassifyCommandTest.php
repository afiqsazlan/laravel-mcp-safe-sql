<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config()->set('safe-sql.profiles.research', [
        'connection' => 'testing',
        'anonymize' => true,
        'tools' => ['sql', 'schema'],
    ]);

    Schema::create('customers', function (Blueprint $table) {
        $table->id();
        $table->string('email');                 // already mapped
        $table->string('status');                // already safe
        $table->string('whatsapp_id');           // phone hiding under an id name
        $table->string('display_name');          // PII by name
        $table->string('product_name');          // NOT PII, despite _name
        $table->string('external_ref');           // opaque name, holds emails
        $table->text('notes');                   // free text
        $table->timestamps();
    });

    DB::table('customers')->insert([
        ['email' => 'a@b.co', 'status' => 'active', 'whatsapp_id' => '60123456789',
            'display_name' => 'Alice Tan', 'product_name' => 'Widget', 'external_ref' => 'x1@ext.co', 'notes' => 'x'],
        ['email' => 'c@d.co', 'status' => 'active', 'whatsapp_id' => '60198887777',
            'display_name' => 'Bob Lim', 'product_name' => 'Gadget', 'external_ref' => 'x2@ext.co', 'notes' => 'y'],
        ['email' => 'e@f.co', 'status' => 'churned', 'whatsapp_id' => '60112223333',
            'display_name' => 'Cara Ng', 'product_name' => 'Doohickey', 'external_ref' => 'x3@ext.co', 'notes' => 'z'],
        ['email' => 'g@h.co', 'status' => 'active', 'whatsapp_id' => '60177778888',
            'display_name' => 'Dee Ooi', 'product_name' => 'Thing', 'external_ref' => 'x4@ext.co', 'notes' => 'w'],
    ]);
});

it('suggests a config from a real schema', function () {
    $this->artisan('safe-sql:classify --profile=research')
        ->assertSuccessful();
});

it('catches PII that only sampling can find', function () {
    $path = sys_get_temp_dir().'/safe-sql-classified.php';

    $this->artisan("safe-sql:classify --profile=research --write={$path}")->assertSuccessful();

    $config = require $path;

    // The whole point of sampling: no name-based map would flag whatsapp_id,
    // and the runtime anonymizer passes it raw because the value is numeric.
    expect($config['anonymizer']['pii_columns'])
        ->toHaveKey('whatsapp_id')
        ->and($config['anonymizer']['pii_columns']['whatsapp_id'])->toBe('phone');

    unlink($path);
});

it('separates person-names from thing-names', function () {
    $path = sys_get_temp_dir().'/safe-sql-classified2.php';

    $this->artisan("safe-sql:classify --profile=research --write={$path}")->assertSuccessful();

    $config = require $path;

    expect($config['anonymizer']['pii_columns'])->toHaveKey('display_name')
        ->and($config['anonymizer']['pii_columns'])->not->toHaveKey('product_name')
        ->and($config['anonymizer']['safe_columns'])->toContain('product_name');

    unlink($path);
});

it('skips columns the current config already resolves', function () {
    $path = sys_get_temp_dir().'/safe-sql-classified3.php';

    $this->artisan("safe-sql:classify --profile=research --write={$path}")->assertSuccessful();

    $config = require $path;

    // "email" is already mapped and "status" already safe; re-suggesting them
    // would bury the columns that actually need a decision.
    expect($config['anonymizer']['pii_columns'])->not->toHaveKey('email')
        ->and($config['anonymizer']['safe_columns'])->not->toContain('status');

    unlink($path);
});

it('never prints sampled values', function () {
    // A classifier that echoed PII to a terminal would undermine the package.
    $this->artisan('safe-sql:classify --profile=research')
        ->doesntExpectOutputToContain('60123456789')
        ->doesntExpectOutputToContain('Alice Tan')
        ->doesntExpectOutputToContain('a@b.co')
        ->assertSuccessful();
});

it('finds PII no name would reveal', function () {
    $path = sys_get_temp_dir().'/safe-sql-classified5.php';

    $this->artisan("safe-sql:classify --profile=research --write={$path}")->assertSuccessful();

    $config = require $path;

    // "external_ref" says nothing; only its contents do.
    expect($config['anonymizer']['pii_columns']['external_ref'])->toBe('email');

    unlink($path);
});

it('can classify without reading any data, at a cost', function () {
    $path = sys_get_temp_dir().'/safe-sql-classified4.php';

    $this->artisan("safe-sql:classify --profile=research --no-sample --write={$path}")
        ->assertSuccessful();

    $config = require $path;

    // --no-sample is the option for someone unwilling to let the command read
    // production rows. It costs exactly this: opaque columns stay invisible.
    expect($config['anonymizer']['pii_columns'])->not->toHaveKey('external_ref')
        ->and($config['anonymizer']['pii_columns'])->toHaveKey('whatsapp_id');

    unlink($path);
});

it('fails clearly on an unknown profile', function () {
    $this->artisan('safe-sql:classify --profile=nope')->assertFailed();
});
