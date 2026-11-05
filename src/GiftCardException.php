<?php
namespace Mlab\GiftCard;

final class GiftCardException extends \RuntimeException
{
    public function __construct(public readonly string $error, string $message, public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
