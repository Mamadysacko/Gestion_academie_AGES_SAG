<?php
/**
 * Planification impossible ou conflictuelle : porte le diagnostic détaillé (conflits, alternatives,
 * groupes non placés) destiné à l'interface. Code HTTP 409.
 */
final class PlanningException extends DomainException
{
    public function __construct(string $message, public readonly array $details = [])
    {
        parent::__construct($message, 409);
    }
}
