<?php

namespace App\Domain\Analytics\Semantic;

use InvalidArgumentException;

final class MetricDatasetClass
{
    public const SELL_IN = 'SELL_IN';

    public const SELL_OUT = 'SELL_OUT';

    public const INVENTORY = 'INVENTORY';

    public const OPERATIONS = 'OPERATIONS';

    public static function fromChannel(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            'b2b' => self::SELL_IN,
            'b2c' => self::SELL_OUT,
            default => throw new InvalidArgumentException('Semantic metric channel must be b2b or b2c.'),
        };
    }

    public static function channel(string $datasetClass): string
    {
        return match (strtoupper(trim($datasetClass))) {
            self::SELL_IN => 'b2b',
            self::SELL_OUT => 'b2c',
            default => throw new InvalidArgumentException('Order semantic metrics require SELL_IN or SELL_OUT dataset class.'),
        };
    }

    public static function prefix(string $datasetClass): string
    {
        return strtolower($datasetClass);
    }
}
