<?php

namespace App\Contracts;

interface FortiaEmployeeClient
{
    /** Fetch source records only. Never write local or remote state. */
    public function fetch(array $filters = []): array;
}
