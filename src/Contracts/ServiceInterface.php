<?php
namespace Atm\Apisunatwp\Contracts;

interface ServiceInterface {

    public function send(int $order_id): void;

    public function void(int $order_id, string $reason): void;

    public function checkStatus(int $order_id): void;
}
