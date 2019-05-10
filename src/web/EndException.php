<?php declare(strict_types=1);

namespace yii\Psr7\web;

use \Exception;
use Psr\Http\Message\ResponseInterface;

class EndException extends Exception
{
    /**
     * The PSR7 interface
     * @var ResponseInterface
     */
    private $response;

    /**
     * Overloaded constructor to init response
     *
     * @param ResponseInterface $response
     */
    public function __construct(ResponseInterface $response = null) {
        parent::__construct();
        $this->response = $response;
    }

    /**
     * Get response
     *
     * @return ResponseInterface
     */
    public function getResponse(): ResponseInterface
    {
        $response = $this->response;
        if ($response === NULL) {
            $response = new \Zend\Diactoros\Response(); 
        }
        return $response;
    }
}