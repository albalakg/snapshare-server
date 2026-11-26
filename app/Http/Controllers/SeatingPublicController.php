<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SeatingResponses;
use App\Services\Seating\TablePageService;

/** Public guest "your table" page (token link from WhatsApp / SMS: {{table_link}}). */
class SeatingPublicController extends Controller
{
    use SeatingResponses;

    public function table(string $token)
    {
        return $this->respond('Table fetched', fn () => (new TablePageService())->show($token));
    }
}
