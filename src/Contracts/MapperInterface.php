<?php
namespace Atm\Apisunatwp\Contracts;

interface MapperInterface {

    public static function map(\WC_Order $order): array;
}
