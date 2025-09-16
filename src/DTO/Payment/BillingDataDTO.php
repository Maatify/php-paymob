<?php
/**
 * Created by Maatify.dev
 * User: Maatify.dev
 * Date: 2025-09-16
 * Time: 19:12
 * Project: paymob-php
 * IDE: PhpStorm
 * https://www.Maatify.dev
 */

declare(strict_types=1);

namespace Maatify\Paymob\DTO\Payment;

final readonly class BillingDataDTO
{
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $email,
        public string $phoneNumber,
        public ?string $country = null,
        public ?string $city = null,
        public ?string $street = null,
        public ?string $building = null,
        public ?string $floor = null,
        public ?string $apartment = null,
        public ?string $postalCode = null,
        public ?string $state = null,
    )
    {
    }

    public function toArray(): array
    {
        return [
            'first_name'   => $this->firstName,
            'last_name'    => $this->lastName,
            'email'        => $this->email,
            'phone_number' => $this->phoneNumber,
            'country'      => $this->country    ?? 'NA',
            'city'         => $this->city       ?? 'NA',
            'street'       => $this->street     ?? 'NA',
            'building'     => $this->building   ?? 'NA',
            'floor'        => $this->floor      ?? 'NA',
            'apartment'    => $this->apartment  ?? 'NA',
            'postal_code'  => $this->postalCode ?? 'NA',
            'state'        => $this->state      ?? 'NA',
        ];
    }
}