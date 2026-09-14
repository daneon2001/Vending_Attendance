<?php

namespace App\Support\Testing;

final class SafeRefreshCommand extends \Illuminate\Database\Console\Migrations\RefreshCommand
{
    use GuardsDestructiveCommand;
}
