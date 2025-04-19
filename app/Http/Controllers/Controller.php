<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use App\Services\Helpers\EnvService;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

class Controller extends BaseController
{
    use AuthorizesRequests, ValidatesRequests;

    const DEFAULT_ERROR = 'Sorry, we encountered an error. Please let us know';

    /** 
     * @param string $message
     * @param mixed $data
     * @param int $status
     * @param string $log_message
     * @return JsonResponse
     */
    protected function successResponse(string $message, $data = null, int $status = Response::HTTP_OK, string $log_message = ''): JsonResponse
    {
        if ($log_message) {
            // $logger = new LogService($this->log_file ?? LogService::DEFAULT_CHANNEL, Auth::user());
            // $logger->info($log_message);
        }

        return response()->json(
            [
                'message'   => $message,
                'status'    => true,
                'data'      => $data
            ],
            $status
        );
    }

    /**
     * @param Exception $exception
     * @param mixed $data
     * @param int $status
     * @return JsonResponse
     */
    protected function errorResponse(Exception $exception, $data = null, $status = Response::HTTP_BAD_REQUEST): JsonResponse
    {
        // $logger = new LogService($this->log_channel, Auth::user());
        // $logger->error($exception);

        $error_data = [
            'message'   => EnvService::isNotProd() ? $exception->getMessage() : self::DEFAULT_ERROR,
            'status'    => false,
            'data'      => $data
        ];

        if (EnvService::isNotProd()) {
            $error_data['debug_info'] = [
                'ErrorMessage'  => $exception->getMessage(),
                'File'          => $exception->getFile(),
                'Line'          => $exception->getLine(),
            ];;
        }

        return response()->json(
            $error_data,
            $status ? $status : Response::HTTP_BAD_REQUEST
        );
    }
}
