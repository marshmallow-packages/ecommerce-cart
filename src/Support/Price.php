<?php

declare(strict_types=1);

namespace Marshmallow\Ecommerce\Cart\Support;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use NumberFormatter;

/**
 * An immutable monetary value expressed in integer cents.
 *
 * The gross amount (including VAT) is the single source of truth: the net
 * amount and the VAT amount are always derived from it so that
 * `net + vat === gross` holds exactly, with no rounding drift. Every
 * arithmetic operation rounds once, at the cents level, which matches the
 * per-line snapshot columns the cart stores and the way EU invoices round.
 *
 * @implements Arrayable<string, int|float|string>
 */
final readonly class Price implements Arrayable, JsonSerializable
{
    private function __construct(
        public int $amountIncludingVat,
        public int $amountExcludingVat,
        public float $vatPercentage,
        public string $currency,
    ) {}

    /**
     * Build a price from its gross (VAT-inclusive) amount in cents.
     */
    public static function fromGross(int $cents, float $vatPercentage, ?string $currency = null): self
    {
        self::guardVatPercentage($vatPercentage);

        $net = self::netFromGross($cents, $vatPercentage);

        return new self($cents, $net, $vatPercentage, self::resolveCurrency($currency));
    }

    /**
     * Build a price from its net (VAT-exclusive) amount in cents.
     *
     * The gross amount is computed once and the net is re-derived from it, so
     * the returned object satisfies the same `net + vat === gross` invariant
     * as every other price.
     */
    public static function fromNet(int $cents, float $vatPercentage, ?string $currency = null): self
    {
        self::guardVatPercentage($vatPercentage);

        $gross = (int) round($cents * (1 + $vatPercentage / 100));

        return self::fromGross($gross, $vatPercentage, $currency);
    }

    /**
     * A zero-valued price, useful as a starting point when summing.
     */
    public static function zero(float $vatPercentage = 0.0, ?string $currency = null): self
    {
        return new self(0, 0, $vatPercentage, self::resolveCurrency($currency));
    }

    /**
     * The VAT contained in this price, in cents.
     */
    public function vatAmount(): int
    {
        return $this->amountIncludingVat - $this->amountExcludingVat;
    }

    /**
     * Multiply the price by a whole factor (typically a line quantity).
     */
    public function multiply(int $factor): self
    {
        return self::fromGross($this->amountIncludingVat * $factor, $this->vatPercentage, $this->currency);
    }

    /**
     * A percentage of this price, e.g. `percentage(10)` for a 10% discount base.
     */
    public function percentage(float $percent): self
    {
        $gross = (int) round($this->amountIncludingVat * $percent / 100);

        return self::fromGross($gross, $this->vatPercentage, $this->currency);
    }

    /**
     * Add another price of the same currency and VAT rate.
     */
    public function add(self $other): self
    {
        $this->guardSameCurrency($other);
        $this->guardSameVatPercentage($other);

        return self::fromGross(
            $this->amountIncludingVat + $other->amountIncludingVat,
            $this->vatPercentage,
            $this->currency,
        );
    }

    /**
     * The negation of this price; discount lines are stored as negatives.
     */
    public function negate(): self
    {
        return self::fromGross(-$this->amountIncludingVat, $this->vatPercentage, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amountIncludingVat === 0;
    }

    public function isNegative(): bool
    {
        return $this->amountIncludingVat < 0;
    }

    public function equals(self $other): bool
    {
        return $this->amountIncludingVat === $other->amountIncludingVat
            && $this->vatPercentage === $other->vatPercentage
            && $this->currency === $other->currency;
    }

    /**
     * A localised, currency-formatted representation of the gross amount.
     */
    public function format(?string $locale = null): string
    {
        $locale ??= (string) config('cart.locale', 'nl_NL');

        $formatter = new NumberFormatter($locale, NumberFormatter::CURRENCY);

        return (string) $formatter->formatCurrency($this->amountIncludingVat / 100, $this->currency);
    }

    /**
     * @return array{
     *     amount_including_vat: int,
     *     amount_excluding_vat: int,
     *     vat_amount: int,
     *     vat_percentage: float,
     *     currency: string
     * }
     */
    public function toArray(): array
    {
        return [
            'amount_including_vat' => $this->amountIncludingVat,
            'amount_excluding_vat' => $this->amountExcludingVat,
            'vat_amount' => $this->vatAmount(),
            'vat_percentage' => $this->vatPercentage,
            'currency' => $this->currency,
        ];
    }

    /**
     * @return array{
     *     amount_including_vat: int,
     *     amount_excluding_vat: int,
     *     vat_amount: int,
     *     vat_percentage: float,
     *     currency: string
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function netFromGross(int $gross, float $vatPercentage): int
    {
        return (int) round($gross / (1 + $vatPercentage / 100));
    }

    private static function resolveCurrency(?string $currency): string
    {
        return strtoupper($currency ?? (string) config('cart.currency', 'EUR'));
    }

    private static function guardVatPercentage(float $vatPercentage): void
    {
        if ($vatPercentage < 0) {
            throw new InvalidArgumentException('The VAT percentage cannot be negative.');
        }
    }

    private function guardSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException(
                "Cannot operate on prices of different currencies: {$this->currency} and {$other->currency}.",
            );
        }
    }

    private function guardSameVatPercentage(self $other): void
    {
        if ($this->vatPercentage !== $other->vatPercentage) {
            throw new InvalidArgumentException(
                'Cannot add prices with different VAT percentages; add their net and gross amounts explicitly instead.',
            );
        }
    }
}
