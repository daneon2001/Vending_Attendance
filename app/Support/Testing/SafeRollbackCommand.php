<?php

namespace App\Support\Testing;

final class SafeRollbackCommand extends \Illuminate\Database\Console\Migrations\RollbackCommand
{
    use GuardsDestructiveCommand;
}
