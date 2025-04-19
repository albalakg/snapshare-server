<?php

namespace App\Services\Helpers;

use Illuminate\Support\Str;

class TokenService
{  
  /**
   * generates a token
   * 
   * @param int $length
   * @return string
  */
  static public function generate(int $length = 50): string
  {
    return Str::random($length);
  }
}
