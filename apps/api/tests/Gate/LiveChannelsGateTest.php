<?php

declare(strict_types=1);

use App\Shared\Live\Channels;
use Tests\Support\OrderFloor;

/*
 * Phase 1 gate: live updates reach only the people they are for (ADR 0009 decision 5). A chef at the kitchen is refused
 * the bar's channel and the bar's tickets with 403, in both languages; their own station answers. Channel auth detail:
 * tests/Modules/Shared/BroadcastingAuthEndpointTest.php; tickets: StationTicketsEndpointTest.php.
 */

it('refuses a foreign station on the channel and on the tickets feed with 403, and serves the own station', function () {
    $f = OrderFloor::open($this);
    $m = $f->menu;
    $f->send([$m->line($m->sandwich), $m->line($m->latte)]);
    $this->travelTo(now()->addSeconds(10));
    loginAs($f->chef);

    $subscribe = fn (string $channel) => $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => 'private-'.$channel]);
    $subscribe(Channels::station($m->barStation->id))
        ->assertStatus(403)
        ->assertJsonPath('message_key', 'identity.channel.forbidden')
        ->assertJsonPath('message_en', trans('identity.channel.forbidden', [], 'en'))
        ->assertJsonPath('message_am', trans('identity.channel.forbidden', [], 'am'));
    $subscribe(Channels::station($m->kitchenStation->id))->assertOk();

    $this->getJson("/api/v1/stations/{$m->barStation->id}/tickets")->assertForbidden()->assertJsonPath('message_key', 'orders.station.forbidden');
    $own = $this->getJson("/api/v1/stations/{$m->kitchenStation->id}/tickets")->assertOk();
    expect($own->json('data'))->toHaveCount(1);
})->group('gate:p1', 'feature', 'module:orders');
