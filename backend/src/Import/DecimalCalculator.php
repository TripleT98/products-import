<?php

declare(strict_types=1);

namespace App\Import;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class DecimalCalculator
{
    private const MAX = '9999999999999999.99';

    /** @return array{price: string, purchasePrice: string, discount: string} */
    public function calculate(BigDecimal $sale, BigDecimal $purchase): array
    {
        if ($sale->isLessThanOrEqualTo(0)) {
            throw new RowValueException('INVALID_PRICE', 'price', 'Цена продажи должна быть больше нуля.');
        }
        if ($purchase->isLessThan(0)) {
            throw new RowValueException('INVALID_PURCHASE_PRICE', 'purchasePrice', 'Закупочная цена не может быть отрицательной.');
        }
        $price = $sale->toScale(2, RoundingMode::HalfUp);
        $purchasePrice = $purchase->toScale(2, RoundingMode::HalfUp);
        if ($price->isZero()) {
            throw new RowValueException('INVALID_PRICE', 'price', 'Цена продажи после округления должна быть больше нуля.');
        }
        $this->checkRange($price, 'price');
        $this->checkRange($purchasePrice, 'purchasePrice');
        // Exact rational division: never round operands or an intermediate quotient.
        $discount = $sale->minus($purchase)->multipliedBy(100)->toBigRational()->dividedBy($sale)->toScale(2, RoundingMode::HalfUp);
        $this->checkRange($discount, 'discount');
        return ['price' => (string) $price, 'purchasePrice' => (string) $purchasePrice, 'discount' => (string) $discount];
    }

    private function checkRange(BigDecimal $value, string $field): void
    {
        if ($value->abs()->isGreaterThan(self::MAX)) {
            throw new RowValueException('DECIMAL_OUT_OF_RANGE', $field, 'Значение не помещается в DECIMAL(18,2).');
        }
    }
}
