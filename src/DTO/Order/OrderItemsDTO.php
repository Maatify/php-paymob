<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 18:40
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\DTO\Order;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

final class OrderItemsDTO implements IteratorAggregate, Countable
{
    /** @var OrderItemDTO[] */
    private array $items = [];

    public function __construct(OrderItemDTO ...$items)
    {
        $this->items = $items;
    }

    public function add(OrderItemDTO $item): void
    {
        $this->items[] = $item;
    }

    /**
     * @return OrderItemDTO[]
     */
    public function all(): array
    {
        return $this->items;
    }

    public function toArray(): array
    {
        return array_map(
            fn(OrderItemDTO $i) => $i->toArray(),
            $this->items
        );
    }

    public static function fromArray(array $data): self
    {
        $items = array_map(
            fn(array $i) => OrderItemDTO::fromArray($i),
            $data
        );

        return new self(...$items);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}