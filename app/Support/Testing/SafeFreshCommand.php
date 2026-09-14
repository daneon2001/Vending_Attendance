<?php

namespace App\Support\Testing;

final class SafeFreshCommand extends \Illuminate\Database\Console\Migrations\FreshCommand
{
    use GuardsDestructiveCommand;
}
