<?php

/** Test-only stand-in for core's WP_Ability: only what the admin screens read (the label). */

declare(strict_types=1);

if (!class_exists('WP_Ability', false)) {
    class WP_Ability
    {
        public function __construct(private readonly string $label)
        {
        }

        public function get_label(): string
        {
            return $this->label;
        }
    }
}
