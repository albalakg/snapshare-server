<?php

namespace App\Services\Helpers;

use Exception;
use App\Services\Enums\LogsEnum;
use Illuminate\Http\UploadedFile;
use App\Services\Helpers\EnvService;
use App\Services\Helpers\LogService;
use Illuminate\Support\Facades\Storage;

class FileService
{    
  const DEFAULT_DISK  = 'pub';
  const S3_DISK       = 's3';

  /**
   * Create a file
   *
   * @param string|UploadedFile $file
   * @param string $path
   * @param string $disk
   * @param string $name
   * @return string
  */
  static public function create($file, string $path, string $disk = self::DEFAULT_DISK): string
  {
    try {
      if(!$file) {
        throw new Exception('File is invalid');
      }

      if(is_string($file)) {
        return self::copy($file, $path, 'local');
      } else {
        return Storage::disk($disk)->putFile($path, $file);
      }

    } catch(Exception $ex) {
      self::writeErrorLog($ex);
      return '';
    }
  }

  /**
   * @param string|UploadedFile $file
   * @param string $path
   * @param string $disk
   * @param string $name
   * @return string
  */
  static public function createFileWithPut($file, string $path, string $disk = self::DEFAULT_DISK): string
  {
    try {
      if(!$file) {
        throw new Exception('File is invalid');
      }

      if(is_string($file)) {
        return self::copy($file, $path, 'local');
      } else {
        return Storage::disk($disk)->put($path, $file);
      }

    } catch(Exception $ex) {
      self::writeErrorLog($ex);
      return '';
    }
  }

  /**
   * Create a file
   *
   * @param string|UploadedFile $file
   * @param string $path
   * @param string $disk
   * @param string $name
   * @return string
  */
  static public function createWithName(mixed $file, string $path, string $name, string $disk = self::DEFAULT_DISK): string
  {
    try {
      return Storage::disk($disk)->putFileAs($path, $file, $name);
    } catch(Exception $ex) {
      self::writeErrorLog($ex);
      return '';
    }
  }

  /**
   * Create a file
   *
   * @param string $from_disk
   * @param string $from_path
   * @param string $to_disk
   * @param string $to_path
   * @return string
  */
  static public function copyFileByStream(string $from_disk, string $from_path, string $to_disk, string $to_path): string
  {
    try {
      $inputStream = Storage::disk($from_disk)->getDriver()->readStream($from_path);
      $destination = Storage::disk($to_disk)->getDriver()->getAdapter()->getPathPrefix() . $to_path;

      return Storage::disk($to_disk)->getDriver()->putStream($destination, $inputStream);
    } catch(Exception $ex) {
      self::writeErrorLog($ex);
      return '';
    }
  }
  
  /**
   * Delete a file
   *
   * @param string $path
   * @param string $disk
   * @return bool
   */
  static public function delete(string $path, string $disk = self::DEFAULT_DISK): bool
  {
    try {
      if( !Storage::disk($disk)->exists($path) ) {
        throw new Exception("File $path not found");
      }
      
      Storage::disk($disk)->delete($path);
      return true;
    } catch(Exception $ex) {
      self::writeErrorLog($ex);
      return false;
    }
  }

  /**
   * Move a file to a new location
   *
   * @param string $path_from
   * @param string $path_to
   * @param string $disk
   * @return bool
   */
  static public function move(string $path_from, string $path_to, string $disk = self::DEFAULT_DISK):bool
  {
    try {
      if( !Storage::disk($disk)->exists($path_from) ) {
        throw new Exception("File $path_from not found");
      }
      
      Storage::disk($disk)->move($path_from, $path_to);
      return true;
    } catch(Exception $ex) {
      self::writeErrorLog($ex);
      return false;
    }
  }

  /**
   * Copy the file
   *
   * @param string $path_from
   * @param string $path_to
   * @param string $from_disk
   * @param string $to_disk
   * @return string
   */
  static public function copy(string $path_from, string $path_to, string $from_disk = self::DEFAULT_DISK, string $to_disk = self::DEFAULT_DISK): string
  {
    try {
      if( !Storage::disk($from_disk)->exists($path_from) ) {
        throw new Exception("File $path_from not found");
      }

      $file = Storage::disk($from_disk)->path($path_from);
      return Storage::disk($to_disk)->putFile($path_to , $file);
    } catch(Exception $ex) {
      self::writeErrorLog($ex);
      return '';
    }
  }
  
  /**
   * Get the content of a file
   *
   * @param string $path
   * @param string $disk
   * @return string
   */
  static public function get(string $path, string $disk = self::DEFAULT_DISK): string
  {
    try {
      if( !Storage::disk($disk)->exists($path) ) {
        throw new Exception("File $path not found");
      } 

      return Storage::disk($disk)->get($path);
    } catch(Exception $ex) {
      self::writeErrorLog($ex);
      return '';
    }
  }
  
  /**
   * Get the content of a file
   *
   * @param string $folder_path
   * @param string $disk
   * @return ?array
   */
  static public function getAllFilesInFolder(string $folder_path, string $disk = self::DEFAULT_DISK): ?array
  {
    try {
      if( !Storage::disk($disk)->exists($folder_path) ) {
        throw new Exception("File $folder_path not found");
      } 

      return Storage::disk($disk)->files($folder_path);
    } catch(Exception $ex) {
      self::writeErrorLog($ex);
      return null;
    }
  }
  
  /**
   * Check if the file exists
   *
   * @param string $path
   * @param string $disk
   * @return bool
   */
  static public function exists(string $path, string $disk = self::DEFAULT_DISK): bool
  {
    try {
      return Storage::disk($disk)->exists($path);
    } catch(Exception $ex) {
      self::writeErrorLog($ex);
      return false;
    }
  }
  
  /**
   * Extract the file extension from the uploaded file
   *
   * @param object $file
   * @return string
  */
  static public function getUploadedFileExtension(object $file): string
  {
    $file_name          = $file->getClientOriginalName();
    $file_name_array    = explode('.', $file_name);
    return $file_name_array[count($file_name_array) - 1];
  }
  
  /**
   * Get the basename of the file from the path
   *
   * @param string $file_path
   * @return string
  */
  static public function getLogFileName(string $file_path): string
  {
      return basename($file_path);
  }

  static private function writeErrorLog(Exception $ex)
  {
    LogService::init()->critical($ex, ['error' => LogsEnum::FILE_SERVICE_ERROR]);
  }

}