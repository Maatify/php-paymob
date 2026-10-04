<?php

declare(strict_types=1);

namespace Maatify\Paymob\Order\DTO;

use ArrayIterator;
use IteratorAggregate;
use JsonSerializable;
use Traversable;

/** @implements IteratorAggregate<int, OrderItemDTO> */
final readonly class OrderItemCollectionDTO implements IteratorAggregate, JsonSerializable
{
    /** @param list<OrderItemDTO> $items */
    public function __construct(public array $items) {}
    public function getIterator(): Traversable { return new ArrayIterator($this->items); }
    public function jsonSerialize(): array { return $this->items; }
    public function toArray(): array { return array_map(static fn (OrderItemDTO $item) => $item->toArray(), $this->items); }
    public static function fromArray(array $items): self { return new self(array_map(static fn (array $item) => OrderItemDTO::fromArray($item), $items)); }
}
