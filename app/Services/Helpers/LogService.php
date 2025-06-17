<?php

namespace App\Services\Helpers;

use Exception;
use Illuminate\Support\Str;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use App\Mail\ApplicationErrorMail;

class LogService
{    
    const SEPARATOR         = ' | ',
          DEFAULT_CHANNEL   = 'custom',
          TRACK_ID          = 'track_id',
          LOCAL_IP          = '127.0.0.1';

    private null | string $trace_id = null;

    private static ?self $instance = null;

    /**
     * Log object
     *
     * @var Log
    */
    private $log;

    /**
     * User object
     *
     * @var User
    */
    private $user;
    
    /**
     * Log content
     *
     * @var array
    */
    private $log_meta_data = [];
    
    /**
     * Start a logger channel
     *
     * @param string $channel
     * @param ?User $user
     * @return void
    */ 
    public function __construct(string $channel = self::DEFAULT_CHANNEL, ?User $user = null)
    {
        $this->log  = Log::channel($channel);
        $this->user = $user ?? Auth::user();
        $this->trace_id = Str::uuid()->toString();
        $this->setMetaData();
    }
    
    /**
     * @param string $channel
     * @param ?User $user
     * @return self
    */
    static public function init(string $channel = self::DEFAULT_CHANNEL, ?User $user = null): self
    {
        if (!self::$instance) {
            self::$instance = new self($channel, $user);
        }

        return self::$instance;
    }
    
    /**
     * Create an info log
     *
     * @param string $content
     * @param array $context
     * @return string|null
    */
    public function info(string $content, array $context = []) :?string
    {
       return $this->writeLog($content, $context, 'info');
    }
    
    /**
     * Create an error log
     *
     * @param Exception|string $ex
     * @param array $context
     * @return string|null
    */
    public function error($ex, array $context = []) :?string
    {
        if(!is_string($ex)) {
            $content = $this->getErrorContent($ex);
        } else {
            $content = $ex;
        }
        
        return $this->writeLog($content, $context, 'error');
    }
    
    /**
     * Create a critical log
     *
     * @param Exception $ex
     * @param array $context
     * @return string|null
    */
    public function critical(Exception $ex, array $context = []) :?string
    {
        $content = $this->getErrorContent($ex);
        $this->sendMail($ex);
        return $this->writeLog($content, $context, 'critical');
    }
    
    /**
     * Create a warning log
     *
     * @param string $content
     * @param array $context
     * @return string|null
    */
    public function warning(string $content, array $context = []) :?string
    {
       return $this->writeLog($content, $context, 'warning');
    }
    
    /**
     * Create a debug log
     *
     * @param string $content
     * @param array $context
     * @return string|null
    */
    public function debug(string $content, array $context = []) :?string
    {
       return $this->writeLog($content, $context, 'debug');
    }
    
    /**
     * @param string $content
     * @param array $context
     * @param string $action
     * @return string|null
    */
    private function writeLog(string $content, array $context, $action) :?string
    {
        try {
            $full_log_content = $content;
            $this->log->$action($full_log_content, array_merge($this->log_meta_data, $context));
            return $full_log_content;
        } catch(Exception $ex) {
            Log::channel(self::DEFAULT_CHANNEL)->critical($ex->__toString());
            $this->sendMail($ex);
            return null;
        } 
    }
    
    /**
     * @param Exception $ex
     * @return string
    */ 
    private function getErrorContent(Exception $ex): string
    {
        $content  = '';
        $content .= 'Message: ' . $ex->getMessage() . self::SEPARATOR;
        $content .= 'File: '    . $ex->getFile()    . self::SEPARATOR;
        $content .= 'Line: '    . $ex->getLine()    . self::SEPARATOR;
        return $content;
    }

    private function setMetaData()
    {
        try {
            if($this->isLocalIp() && $this->isGuest()) {
                $this->log_meta_data['user'] = 'Worker';
                return;
            }

            $this->log_meta_data = [
                'user'      => $this->getUser(),
                'ip'        => request()->ip(),
                'browser'   => request()->header('user-agent'),
                'url'       => request()->url(),
                'trace_id'  => $this->trace_id,
            ];
        } catch (Exception $ex) {
            Log::channel(self::DEFAULT_CHANNEL)->critical($ex->__toString());
            $this->sendMail($ex);
        }
    }

    private function getUser()
    {
        return $this->user ? $this->user->id : 'GUEST';
    }

    private function isGuest()
    {
        return $this->getUser() === 'GUEST';
    }
    
    private function isLocalIp()
    {
        return request()->ip() === self::LOCAL_IP;
    }
    
    /**
     * Send an application error mail
     *
     * @param Exception $ex
     * @return void
    */
    private function sendMail(Exception $ex)
    {
        $mail_service = new MailService;
        $mail_service->send(MailService::SYSTEM_EMAILS, ApplicationErrorMail::class, ['data' => $ex]);
    }
}