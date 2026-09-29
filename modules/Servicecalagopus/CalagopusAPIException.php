<?php

declare(strict_types=1);

namespace Box\Mod\Servicecalagopus;

class CalagopusAPIException extends \FOSSBilling\Exception
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct(':message', [':message' => $message]);
    }
}
