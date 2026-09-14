<?php

namespace App\Support\Testing;

final class SafeWipeCommand extends \Illuminate\Database\Console\WipeCommand
{
    use GuardsDestructiveCommand;
}
