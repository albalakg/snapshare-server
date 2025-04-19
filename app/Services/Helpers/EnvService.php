<?php

namespace App\Services\Helpers;


class EnvService
{
  const LOCAL = 'local',
        PROD  = 'production';


  static public function isProd()
  {
    return config('app.env') === self::PROD;
  }

  static public function isNotProd()
  {
    return config('app.env') !== self::PROD;
  }

  static public function isLocal()
  {
    return config('app.env') === self::LOCAL;
  }
}