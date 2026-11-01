<?php

namespace App\Game;

use RuntimeException;

/**
 * A move the rules don't allow. Carries a translation key (lang/*.json) instead of a message so
 * every player sees the reason in their own language.
 */
class GameRuleException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $replace
     */
    public function __construct(
        public readonly string $key,
        public readonly array $replace = [],
    ) {
        parent::__construct($key);
    }
}
