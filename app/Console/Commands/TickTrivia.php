<?php

namespace App\Console\Commands;

use App\Services\Trivia\TriviaService;
use Illuminate\Console\Command;

class TickTrivia extends Command
{
    protected $signature = 'trivia:tick';

    protected $description = 'Move scheduled trivia onto the screen and then to results';

    public function handle(): int
    {
        (new TriviaService())->tick();

        return self::SUCCESS;
    }
}
