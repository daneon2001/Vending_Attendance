<?php

namespace App\Support\Testing;

final class SafeResetCommand extends \Illuminate\Database\Console\Migrations\ResetCommand
{
    use GuardsDestructiveCommand;
}
