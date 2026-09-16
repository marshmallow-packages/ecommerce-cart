<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Events\CustomerCreated;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\Prospect;

it('creates a customer from a prospect and fires CustomerCreated', function (): void {
    Event::fake([CustomerCreated::class]);
    $prospect = Prospect::factory()->create(['email' => 'new@example.com']);

    $customer = $prospect->convertToCustomer();

    expect($customer)->toBeInstanceOf(Customer::class)
        ->and($customer->email)->toBe('new@example.com')
        ->and($prospect->fresh()->converted_at)->not->toBeNull();
    Event::assertDispatched(CustomerCreated::class);
});

it('reuses a customer already linked to the prospect', function (): void {
    $prospect = Prospect::factory()->create();
    $existing = Customer::factory()->create(['prospect_id' => $prospect->id]);

    expect($prospect->convertToCustomer()->id)->toBe($existing->id);
});

it('reuses a customer matched by email', function (): void {
    $prospect = Prospect::factory()->create(['email' => 'shared@example.com']);
    $existing = Customer::factory()->create(['email' => 'shared@example.com']);

    expect($prospect->convertToCustomer()->id)->toBe($existing->id);
});

it('returns null when looking up a customer for a prospect with no email or match', function (): void {
    $prospect = Prospect::factory()->create(['email' => null]);

    expect($prospect->getCustomer())->toBeNull();
});

it('builds a full name from its parts', function (): void {
    $prospect = Prospect::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

    expect($prospect->getFullName())->toBe('Ada Lovelace');
});
