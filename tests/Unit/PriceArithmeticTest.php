<?php

declare(strict_types=1);

use Marshmallow\Ecommerce\Cart\Support\Price;

/*
|--------------------------------------------------------------------------
| Invariants
|--------------------------------------------------------------------------
|
| The value object promises `net + vat === gross` for every price it can
| produce. These datasets sweep awkward amounts and rates through every
| constructor and operation to make sure rounding never breaks that promise.
|
*/

dataset('gross amounts', [0, 1, 7, 99, 999, 1234, 12100, 99999, 1000001, -999, -12100]);

dataset('vat rates', [0.0, 6.0, 9.0, 19.5, 21.0, 25.0]);

it('reconciles net plus vat to gross for any amount and rate', function (int $gross, float $rate): void {
    $price = Price::fromGross($gross, $rate, 'EUR');

    expect($price->amountExcludingVat + $price->vatAmount())->toBe($gross)
        ->and($price->amountExcludingVat)->toBe((int) round($gross / (1 + $rate / 100)));
})->with('gross amounts')->with('vat rates');

it('reconciles a price built from its net side', function (int $net, float $rate): void {
    $price = Price::fromNet($net, $rate, 'EUR');

    expect($price->amountIncludingVat)->toBe((int) round($net * (1 + $rate / 100)))
        ->and($price->amountExcludingVat + $price->vatAmount())->toBe($price->amountIncludingVat);
})->with('gross amounts')->with('vat rates');

it('reconciles a multiplied price without accumulating rounding drift', function (int $gross, float $rate): void {
    $line = Price::fromGross($gross, $rate)->multiply(7);

    expect($line->amountIncludingVat)->toBe($gross * 7)
        ->and($line->amountExcludingVat + $line->vatAmount())->toBe($gross * 7);
})->with('gross amounts')->with('vat rates');

it('reconciles a negated price so discount lines book cleanly', function (int $gross, float $rate): void {
    $negative = Price::fromGross($gross, $rate)->negate();

    expect($negative->amountIncludingVat)->toBe(-$gross)
        ->and($negative->amountExcludingVat + $negative->vatAmount())->toBe(-$gross);
})->with('gross amounts')->with('vat rates');

/*
|--------------------------------------------------------------------------
| Construction
|--------------------------------------------------------------------------
*/

it('keeps net equal to gross at a zero rate', function (): void {
    $price = Price::fromGross(999, 0.0);

    expect($price->amountExcludingVat)->toBe(999)
        ->and($price->vatAmount())->toBe(0);
});

it('may lose a cent on the net side when the net amount does not round-trip', function (): void {
    // 999 net at 21% is 1208.79 gross -> 1209; re-deriving net from 1209 gives 999.17 -> 999.
    $price = Price::fromNet(999, 21.0);

    expect($price->amountIncludingVat)->toBe(1209)
        ->and($price->amountExcludingVat)->toBe(999);

    // 1 net at 21% is 1.21 -> 1 gross; the net re-derives to 1 as well.
    expect(Price::fromNet(1, 21.0)->amountIncludingVat)->toBe(1);
});

it('builds a zero price with an explicit rate and currency', function (): void {
    $zero = Price::zero(9.0, 'usd');

    expect($zero->amountIncludingVat)->toBe(0)
        ->and($zero->amountExcludingVat)->toBe(0)
        ->and($zero->vatAmount())->toBe(0)
        ->and($zero->vatPercentage)->toBe(9.0)
        ->and($zero->currency)->toBe('USD');
});

it('defaults a zero price to a zero rate and the configured currency', function (): void {
    config()->set('cart.currency', 'gbp');

    $zero = Price::zero();

    expect($zero->vatPercentage)->toBe(0.0)
        ->and($zero->currency)->toBe('GBP');
});

it('accepts a zero vat percentage but not a negative one', function (): void {
    expect(Price::fromGross(100, 0.0)->vatPercentage)->toBe(0.0)
        ->and(fn () => Price::fromNet(100, -0.01))->toThrow(InvalidArgumentException::class, 'cannot be negative');
});

/*
|--------------------------------------------------------------------------
| Arithmetic
|--------------------------------------------------------------------------
*/

it('rounds a percentage half up at the cents level', function (int $gross, float $percent, int $expected): void {
    expect(Price::fromGross($gross, 21.0)->percentage($percent)->amountIncludingVat)->toBe($expected);
})->with([
    '10% of 9.99 rounds up' => [999, 10.0, 100],
    '33.33% of 10.00' => [1000, 33.33, 333],
    '12.5% of 10.01 rounds down' => [1001, 12.5, 125],
    '50% of 0.05 rounds half up' => [5, 50.0, 3],
    '100% is the price itself' => [4321, 100.0, 4321],
    '0% is nothing' => [4321, 0.0, 0],
]);

it('keeps the rate and currency when taking a percentage or multiplying', function (): void {
    $price = Price::fromGross(1000, 9.0, 'USD');

    expect($price->percentage(10)->vatPercentage)->toBe(9.0)
        ->and($price->percentage(10)->currency)->toBe('USD')
        ->and($price->multiply(3)->vatPercentage)->toBe(9.0)
        ->and($price->multiply(3)->currency)->toBe('USD');
});

it('multiplies by zero to a zero price', function (): void {
    expect(Price::fromGross(1000, 21.0)->multiply(0)->isZero())->toBeTrue();
});

it('sums the gross amounts when adding', function (): void {
    $sum = Price::fromGross(999, 21.0)->add(Price::fromGross(1, 21.0));

    expect($sum->amountIncludingVat)->toBe(1000)
        ->and($sum->amountExcludingVat)->toBe(826)
        ->and($sum->vatAmount())->toBe(174);
});

it('adds a negative price as a subtraction', function (): void {
    $net = Price::fromGross(10000, 21.0)->add(Price::fromGross(2500, 21.0)->negate());

    expect($net->amountIncludingVat)->toBe(7500);
});

it('negating twice returns to the original', function (): void {
    $price = Price::fromGross(1234, 21.0, 'EUR');

    expect($price->negate()->negate()->equals($price))->toBeTrue();
});

it('is immutable: every operation returns a new instance', function (): void {
    $price = Price::fromGross(1000, 21.0, 'EUR');

    $price->multiply(2);
    $price->percentage(50);
    $price->negate();
    $price->add($price);

    expect($price->amountIncludingVat)->toBe(1000)
        ->and(fn () => $price->amountIncludingVat = 1)->toThrow(Error::class);
});

/*
|--------------------------------------------------------------------------
| Comparison & presentation
|--------------------------------------------------------------------------
*/

it('compares unequal when the amount or currency differs', function (): void {
    $price = Price::fromGross(1000, 21.0, 'EUR');

    expect($price->equals(Price::fromGross(1001, 21.0, 'EUR')))->toBeFalse()
        ->and($price->equals(Price::fromGross(1000, 21.0, 'USD')))->toBeFalse();
});

it('reports sign correctly', function (): void {
    expect(Price::fromGross(-1, 21.0)->isNegative())->toBeTrue()
        ->and(Price::fromGross(-1, 21.0)->isZero())->toBeFalse()
        ->and(Price::fromGross(1, 21.0)->isNegative())->toBeFalse()
        ->and(Price::fromGross(1, 21.0)->isZero())->toBeFalse();
});

it('formats in the configured locale by default', function (): void {
    config()->set('cart.locale', 'nl_NL');

    expect(Price::fromGross(123456, 21.0, 'EUR')->format())->toContain('1.234,56')->toContain('€');
});

it('formats another locale and currency on request', function (): void {
    expect(Price::fromGross(123456, 21.0, 'USD')->format('en_US'))->toContain('1,234.56')->toContain('$');
});

it('formats a negative amount with a sign', function (): void {
    expect(Price::fromGross(-2500, 21.0, 'EUR')->format('nl_NL'))->toContain('25,00')->toMatch('/-|−/');
});

it('json encodes through its array form', function (): void {
    $price = Price::fromGross(12100, 21.0, 'EUR');

    expect(json_encode($price))->toBe(json_encode($price->toArray()))
        ->and(json_encode(['price' => $price]))->toBe(json_encode(['price' => $price->toArray()]));
});
