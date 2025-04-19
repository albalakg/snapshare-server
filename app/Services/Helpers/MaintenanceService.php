<?php

namespace App\Services\Helpers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

class MaintenanceService
{    
    /**
     * TODO: Need to fix this
     * Check if the application is in maintenance mode
     *
     * @return bool
    */
    static public function isActive() :bool
    {
      if(self::hasOverride()) {
        return self::getOverrideStatus();
      }

      return false;
    }
    
    /**
     * Update the maintenance mode
     *
     * @param bool $status
     * @return void
    */
    static public function updateMaintenance(bool $status)
    {
      Storage::put('maintenance.json', json_encode(['status' => $status]));
    }
    
    /**
     * Check if the user is authorized to access while the application is in maintenance mode
     *
     * @param User $user
     * @return bool
    */
    static public function isAuthorized(User $user) :bool
    {
      return $user->isAdmin();
    }
    

    /**
     * Check if the default maintenance mode has been override
     *
     * @return bool
    */
    static private function hasOverride() :bool
    {
      return Storage::exists('maintenance.json');
    }

    /**
     * Get the override status if exists
     *
     * @return bool
    */
    static private function getOverrideStatus() :bool
    {
      if(!self::hasOverride()) {
        return false;
      }
  
      $maintenance_object = json_decode(Storage::get('maintenance.json'));
      return $maintenance_object->status;
    }
}