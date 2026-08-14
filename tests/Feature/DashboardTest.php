<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login.index'));
});

test('authenticated admin can visit the dashboard', function () {
    $user = User::factory()->create(['role' => 'admin']);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('authenticated unverified admin can visit the dashboard', function () {
    $user = User::factory()->unverified()->create(['role' => 'admin']);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('authenticated instructor can visit the dashboard', function () {
    $user = User::factory()->create(['role' => 'instructor']);
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
