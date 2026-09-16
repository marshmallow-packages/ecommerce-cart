<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Support\Price;

it('derives net and vat from a gross amount so they always reconcile', function (): void {
    $price = Price::fromGross(12100, 21.0, 'EUR');

    expect($price->amountIncludingVat)->toBe(12100)
        ->and($price->amountExcludingVat)->toBe(10000)
        ->and($price->vatAmount())->toBe(2100)
        ->and($price->amountExcludingVat + $price->vatAmount())->toBe($price->amountIncludingVat);
});

it('builds from a net amount and stays gross-canonical', function (): void {
    $price = Price::fromNet(10000, 21.0, 'EUR');

    expect($price->amountIncludingVat)->toBe(12100)
        ->and($price->amountExcludingVat)->toBe(10000);
});

it('keeps net plus vat equal to gross even on amounts that do not divide evenly', function (): void {
    $price = Price::fromGross(999, 21.0);

    expect($price->amountExcludingVat + $price->vatAmount())->toBe(999);
});

it('defaults the currency from config', function (): void {
    config()->set('cart.currency', 'USD');

    expect(Price::fromGross(100, 21.0)->currency)->toBe('USD');
});

it('uppercases an explicit currency', function (): void {
    expect(Price::fromGross(100, 21.0, 'eur')->currency)->toBe('EUR');
});

it('multiplies by a quantity', function (): void {
    $line = Price::fromGross(495, 21.0)->multiply(3);

    expect($line->amountIncludingVat)->toBe(1485);
});

it('takes a percentage of a price', function (): void {
    $part = Price::fromGross(10000, 21.0)->percentage(10);

    expect($part->amountIncludingVat)->toBe(1000);
});

it('adds two prices of the same currency and rate', function (): void {
    $sum = Price::fromGross(1000, 21.0)->add(Price::fromGross(500, 21.0));

    expect($sum->amountIncludingVat)->toBe(1500);
});

it('refuses to add across currencies', function (): void {
    Price::fromGross(1000, 21.0, 'EUR')->add(Price::fromGross(500, 21.0, 'USD'));
})->throws(InvalidArgumentException::class);

it('refuses to add across vat rates', function (): void {
    Price::fromGross(1000, 21.0)->add(Price::fromGross(500, 9.0));
})->throws(InvalidArgumentException::class);

it('rejects a negative vat percentage', function (): void {
    Price::fromGross(1000, -1.0);
})->throws(InvalidArgumentException::class);

it('negates a price for use as a discount line', function (): void {
    $discount = Price::fromGross(1000, 21.0)->negate();

    expect($discount->amountIncludingVat)->toBe(-1000)
        ->and($discount->isNegative())->toBeTrue();
});

it('reports a zero price', function (): void {
    expect(Price::zero()->isZero())->toBeTrue()
        ->and(Price::zero()->isNegative())->toBeFalse();
});

it('compares equality on amount, rate and currency', function (): void {
    expect(Price::fromGross(1000, 21.0, 'EUR')->equals(Price::fromGross(1000, 21.0, 'EUR')))->toBeTrue()
        ->and(Price::fromGross(1000, 21.0, 'EUR')->equals(Price::fromGross(1000, 9.0, 'EUR')))->toBeFalse();
});

it('formats as localised currency', function (): void {
    $formatted = Price::fromGross(123456, 21.0, 'EUR')->format('nl_NL');

    expect($formatted)->toContain('1.234,56');
});

it('serialises to an array and to json', function (): void {
    $price = Price::fromGross(12100, 21.0, 'EUR');

    expect($price->toArray())->toBe([
        'amount_including_vat' => 12100,
        'amount_excluding_vat' => 10000,
        'vat_amount' => 2100,
        'vat_percentage' => 21.0,
        'currency' => 'EUR',
    ])->and($price->jsonSerialize())->toBe($price->toArray());
});
