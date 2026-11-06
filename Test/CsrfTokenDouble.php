<?php
namespace Symfony\Component\Security\Csrf;

final class CsrfToken
{
    public function __construct(private string $id, private string $value) {}
    public function getId(): string { return $this->id; }
    public function getValue(): string { return $this->value; }
}
