<?php

use App\Models\User;

test('authenticated users reach the home route successfully', function () {
    $user = User::factory()->create();
    $response = $this->actingAs($user)->get(route('home'));

    $response->assertOk();
});
