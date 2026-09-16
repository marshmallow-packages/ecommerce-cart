<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Marshmallow\Ecommerce\Cart\Events\CustomerCreated;
use Marshmallow\Ecommerce\Cart\Models\Customer;
use Marshmallow\Ecommerce\Cart\Models\Prospect;

it('copies every identifying field onto the new customer', function (): void {
    $prospect = Prospect::factory()->create([
        'first_name' => 'Ada',
        'last_name' => 'Lovelace',
        'company_name' => 'Analytical Engines BV',
        'email' => 'ada@example.com',
        'phone_number' => '0612345678',
        'country_id' => 528,
    ]);

    $customer = $prospect->convertToCustomer();

    expect($customer->first_name)->toBe('Ada')
        ->and($customer->last_name)->toBe('Lovelace')
        ->and($customer->company_name)->toBe('Analytical Engines BV')
        ->and($customer->email)->toBe('ada@example.com')
        ->and($customer->phone_number)->toBe('0612345678')
        ->and($customer->country_id)->toBe(528)
        ->and($customer->prospect_id)->toBe($prospect->id)
        ->and($customer->getFullName())->toBe('Ada Lovelace');
});

it('does not announce a customer it merely reused', function (): void {
    Event::fake([CustomerCreated::class]);
    $prospect = Prospect::factory()->create(['email' => 'known@example.com']);
    Customer::factory()->create(['email' => 'known@example.com']);

    $prospect->convertToCustomer();

    Event::assertNotDispatched(CustomerCreated::class);
    expect(Customer::count())->toBe(1)
        ->and($prospect->fresh()->converted_at)->not->toBeNull();
});

it('prefers the customer linked by prospect over one matched by e-mail', function (): void {
    $prospect = Prospect::factory()->create(['email' => 'shared@example.com']);
    Customer::factory()->create(['email' => 'shared@example.com']);
    $linked = Customer::factory()->create(['prospect_id' => $prospect->id, 'email' => 'other@example.com']);

    expect($prospect->getCustomer()->is($linked))->toBeTrue()
        ->and($prospect->convertToCustomer()->is($linked))->toBeTrue();
});

it('converts twice to the same customer', function (): void {
    $prospect = Prospect::factory()->create(['email' => 'twice@example.com']);

    $first = $prospect->convertToCustomer();
    $second = $prospect->fresh()->convertToCustomer();

    expect($second->is($first))->toBeTrue()
        ->and(Customer::count())->toBe(1);
});

it('creates a customer for a prospect without an e-mail', function (): void {
    $prospect = Prospect::factory()->create(['email' => null, 'first_name' => 'Anon']);

    $customer = $prospect->convertToCustomer();

    expect($customer->email)->toBeNull()
        ->and($customer->prospect_id)->toBe($prospect->id);
});

it('soft deletes a prospect', function (): void {
    $prospect = Prospect::factory()->create();

    $prospect->delete();

    expect(Prospect::find($prospect->id))->toBeNull()
        ->and(Prospect::withTrashed()->find($prospect->id))->not->toBeNull();
});
