<?php

namespace Toneflix\Money;

use Stringable;
use Toneflix\Money\Exceptions\DivisionByZeroException;

/**
 * An amount in a currency: formatting, arithmetic and conversion.
 * Every operation returns a new Money in the same currency.
 */
class Money implements Stringable
{
    protected static string $defaultCurrency = 'USD';

    protected string $currency;

    protected string $negativeStyle = 'minus';

    public function __construct(protected int|float|string|null $amount = 0, ?string $currency = null)
    {
        $this->currency = strtoupper($currency ?? static::$defaultCurrency);
    }

    public static function of(int|float|string|null $amount, ?string $currency = null): static
    {
        return new static($amount, $currency);
    }

    public static function setDefaultCurrency(string $currency): void
    {
        static::$defaultCurrency = strtoupper($currency);
    }

    /**
     * Show negative amounts as `-$100.00` (minus) or `($100.00)` (parentheses).
     */
    public function setNegativeStyle(string $style): static
    {
        $this->negativeStyle = $style === 'parentheses' ? 'parentheses' : 'minus';

        return $this;
    }

    public function value(): float
    {
        return static::number($this->amount);
    }

    public function currencyCode(): string
    {
        return $this->currency;
    }

    public function currencySymbol(): string
    {
        return Currencies::symbol($this->currency);
    }

    /**
     * "$1,234.56"
     */
    public function format(): string
    {
        return $this->render(fn(float $value) => number_format($value, 2, '.', ','));
    }

    /**
     * "$1,234"
     */
    public function whole(): string
    {
        return $this->render(fn(float $value) => number_format($value, 0, '.', ','));
    }

    /**
     * "$1.2K", "$3.4M"
     */
    public function compact(): string
    {
        return $this->render(function (float $value) {
            $suffix = '';

            foreach (['T' => 1e12, 'B' => 1e9, 'M' => 1e6, 'K' => 1e3] as $unit => $size) {
                if ($value >= $size) {
                    $value /= $size;
                    $suffix = $unit;
                    break;
                }
            }

            return preg_replace('/\.0$/', '', number_format($value, 1, '.', '')) . $suffix;
        });
    }

    /**
     * This amount in another currency at the current rate.
     */
    public function convert(string $to): static
    {
        $amount = Exchange::from($this->currency)
            ->to($to)
            ->convert($this->value())
            ->value();

        return new static($amount, $to);
    }

    public static function convertAmount(int|float|string $amount, string $from, string $to): static
    {
        return static::of($amount, $from)->convert($to);
    }

    public static function formatAmount(int|float|string $amount, ?string $currency = null): string
    {
        return static::of($amount, $currency)->format();
    }

    public function add(int|float|string|self $other): static
    {
        return $this->with($this->value() + static::number($other));
    }

    public function subtract(int|float|string|self $other): static
    {
        return $this->with($this->value() - static::number($other));
    }

    public function multiply(int|float|string|self $factor): static
    {
        return $this->with($this->value() * static::number($factor));
    }

    public function divide(int|float|string|self $divisor): static
    {
        $divisor = static::number($divisor);

        if ($divisor == 0) {
            throw new DivisionByZeroException;
        }

        return $this->with($this->value() / $divisor);
    }

    public function ceil(): static
    {
        return $this->with(ceil($this->value()));
    }

    public function floor(): static
    {
        return $this->with(floor($this->value()));
    }

    public function round(int $digits = 0): static
    {
        return $this->with(round($this->value(), $digits));
    }

    public function mod(int|float|string|self $divisor): static
    {
        return $this->with(fmod($this->value(), static::number($divisor)));
    }

    public function absolute(): static
    {
        return $this->with(abs($this->value()));
    }

    /**
     * This amount's share of `$total` at `$ratio`, rounded down: floor(amount × ratio / total).
     */
    public function share(int|float|string|self $total, int|float|string $ratio): static
    {
        $total = static::number($total);

        if ($total == 0) {
            throw new DivisionByZeroException;
        }

        return $this->with(floor($this->value() * static::number($ratio) / $total));
    }

    public function __toString(): string
    {
        return $this->format();
    }

    protected function with(float $amount): static
    {
        return (new static($amount, $this->currency))->setNegativeStyle($this->negativeStyle);
    }

    /**
     * @param  callable(float): string  $number
     */
    protected function render(callable $number): string
    {
        if (! Currencies::has($this->currency)) {
            return "{$this->currency} {$this->amount}";
        }

        $value = $this->value();
        $text = $this->currencySymbol() . $number(abs($value));

        if ($value >= 0) {
            return $text;
        }

        return $this->negativeStyle === 'parentheses' ? "({$text})" : "-{$text}";
    }

    protected static function number(int|float|string|self|null $value): float
    {
        return $value instanceof self ? $value->value() : (float) $value;
    }
}
