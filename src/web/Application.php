<?php declare (strict_types = 1);

namespace yii\Psr7\web;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Yii;
use yii\base\Component;
use yii\Psr7\web\Response;

/**
 * A Yii2 compatible A PSR-15 RequestHandlerInterface Application component
 *
 * This class is a \yii\web\Application substitute for use with PSR-7 and PSR-15 middlewares
 */
class Application extends \yii\web\Application implements RequestHandlerInterface
{
    /**
     * @inheritdoc
     */
    public $version = "0.0.1";

    /**
     * @var array The configuration
     */
    private $config;

    /**
     * @var int $memoryLimit
     */
    private $memoryLimit;

    /**
     * @var bool $fisrtFlag
     */
    private $fisrtFlag = true;

    /**
     * Overloaded constructor to persist configuration
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config;

        // Set the environment aliases
        Yii::setAlias('@webroot', \getenv('YII_ALIAS_WEBROOT'));
        Yii::setAlias('@web', \getenv('YII_ALIAS_WEB'));

        // This is necessary to get \yii\web\Session to work properly.
        ini_set('use_cookies', 'false');
        ini_set('use_only_cookies', 'true');

        Yii::$app = $this;
        static::setInstance($this);
    }

    /**
     * init all global vars
     * @return void
     */
    private function initGlobal(ServerRequestInterface $request)
    {
        foreach ($request->getServerParams() as $k => $v) {
            $_SERVER[$k] = $v;
        }
        foreach ($request->getUploadedFiles() as $k=>$v) {
            $_FILES[$k] = $v;
        }

        $order = ini_get('request_order');
        if (empty($order)) {
            $order = ini_get('variables_order');
        }
        if (empty($order)) {
            $order = 'EGPCS';
        }
        $order = \str_split(ini_get('variables_order'), 1);

        foreach ($order as $c) {
            switch ($c) {
                case 'E':
                    foreach (\getenv() as $k => $v) {
                        $_REQUEST[$k] = $v;
                    }
                    break;
                case 'G':
                    foreach ($request->getQueryParams() as $k => $v) {
                        $_GET[$k] = $v;
                        $_REQUEST[$k] = $v;
                    }
                    break;
                case 'P':
                    $body = $request->getParsedBody();
                    if (is_array($body)) {
                        foreach ($body as $k => $v) {
                            $_POST[$k] = $v;
                            $_REQUEST[$k] = $v;
                        }
                    }
                    break;
                case 'C':
                    foreach ($request->getCookieParams() as $k => $v) {
                        $_COOKIE[$k] = $v;
                        $_REQUEST[$k] = $v;
                    }
                    break;
                case 'S':
                    //TODO
                    // ignore sessions
                    break;
            }
        }
    }

    /**
     * Registers all components with the original configuration
     * @return void
     */
    protected function reset(ServerRequestInterface $request)
    {
        $config = $this->config;

        $config['components']['request']['psr7Request'] = $request;

        $this->preInit($config);
        $this->registerErrorHandler($config);
        Component::__construct($config);

        // Session data has to be explicitly loaded before any bootstrapping occurs to ensure compatability
        // with bootstrapped components (such as yii2-debug).
        if (($session = $this->getSession()) !== null) {
            // Close the session if it was open.
            $session->close();

            // If a session cookie is defined, load it into Yii::$app->session
            if (isset($request->getCookieParams()[$session->getName()])) {
                $session->setId($request->getCookieParams()[$session->getName()]);
            }
        }
        $this->initGlobal($request);

        if ($this->fisrtFlag) {

            // Open the session before any modules that need it are bootstrapped.
            $session->open();
            $this->bootstrap();

            // Once bootstrapping is done we can close the session.
            // Accessing it in the future will re-open it.
            $session->close();
            $this->fisrtFlag = false;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function registerErrorHandler(&$config)
    {
        if (YII_ENABLE_ERROR_HANDLER) {
            if (!isset($config['components']['errorHandler']['class'])) {
                echo "Error: no errorHandler component is configured.\n";
                exit(1);
            }
            if (!$this->has('errorHandler')) {
                $this->set('errorHandler', $config['components']['errorHandler']);
                $this->getErrorHandler()->register();
            }
            unset($config['components']['errorHandler']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function init()
    {
        $this->state = self::STATE_INIT;
    }

    /**
     * PSR-15 RequestHandlerInterface
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            $this->state = self::STATE_BEGIN;
            $this->reset($request);

            $this->state = self::STATE_BEFORE_REQUEST;
            $this->trigger(self::EVENT_BEFORE_REQUEST);

            $this->state = self::STATE_HANDLING_REQUEST;

            $response = $this->handleRequest($this->getRequest());

            $this->state = self::STATE_AFTER_REQUEST;
            $this->trigger(self::EVENT_AFTER_REQUEST);

            $this->state = self::STATE_END;
            return $this->terminate($response->getPsr7Response());
        } catch (\Exception $e) {
            return $this->terminate($this->handleError($e));
        } catch (\Throwable $e) {
            return $this->terminate($this->handleError($e));
        }
    }

    /**
     * Terminates the application
     *
     * This method handles final log flushing and session termination
     *
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    protected function terminate(ResponseInterface $response): ResponseInterface
    {
        // Final flush of Yii2's logger to ensure log data is written at the end of the request
        // and to ensure Yii2 Debug populates correctly
        if (($logger = Yii::getLogger()) !== null) {
            $logger->flush(true);
        }

        // Close all instances of \yii\db\Connection
        foreach ($this->getComponents(false) as $id => $component) {
            if ($component instanceof \yii\db\Connection) {
                $component->close();
            }
        }

        // De-register the event handlers for this class
        $this->off(self::EVENT_BEFORE_REQUEST);
        $this->off(self::EVENT_AFTER_REQUEST);

        // Detatch response events
        $r = $this->getResponse();
        $r->off(Response::EVENT_AFTER_PREPARE);
        $r->off(Response::EVENT_AFTER_SEND);
        $r->off(Response::EVENT_BEFORE_SEND);

        // reset global variables
        unset($GLOBALS['_SERVER'], $GLOBALS['_GET'], $GLOBALS['_POST'], $GLOBALS['_COOKIE'], $GLOBALS['_ENV'], $GLOBALS['_REQUEST'], $GLOBALS['_FILES']);

        // Return the parent response
        return $response;
    }

    /**
     * Handles exceptions and errors thrown by the request handler
     *
     * @param \Throwable|\Exception $exception
     * @return ResponseInterface
     */
    private function handleError(\Throwable $exception): ResponseInterface
    {
        $response = $this->getErrorHandler()->handleException($exception);

        $this->trigger(self::EVENT_AFTER_REQUEST);
        $this->state = self::STATE_END;

        return $response->getPsr7Response();
    }

    /**
     * {@inheritdoc}
     */
    public function coreComponents()
    {
        return array_merge(parent::coreComponents(), [
            'request' => ['class' => \yii\Psr7\web\Request::class],
            'response' => ['class' => \yii\Psr7\web\Response::class],
            'session' => ['class' => \yii\web\Session::class],
            'user' => ['class' => \yii\web\User::class],
            'errorHandler' => ['class' => \yii\Psr7\web\ErrorHandler::class],
        ]);
    }

    /**
     * Cleanup function to be called at the end of the script execution
     * This will automatically run garbage collection, and if the script
     * is within 5% of the memory limit will pre-maturely kill the worker
     * forcing your task-runner to rebuild it.
     *
     * This is implemented to avoid requests failing due to memory exhaustion
     *
     * @return boolean
     */
    public function clean($limit = 20)
    {
        gc_collect_cycles();
        if (!isset($limit)) {
            $limit = $this->getMemoryLimit();
        } else {
            $limit = $limit * 1024;
        }
        $limit = $this->getMemoryLimit();
        $bound = $limit * .90;
        $usage = memory_get_usage(true);
        if ($usage >= $bound) {
            return true;
        }

        return false;
    }

    /**
     * Retrieves the current memory as integer bytes
     *
     * @return int
     */
    private function getMemoryLimit()
    {
        if (!$this->memoryLimit) {
            $limit = ini_get('memory_limit');
            sscanf($limit, '%u%c', $number, $suffix);
            if (isset($suffix)) {
                $number = $number * pow(1024, strpos(' KMG', strtoupper($suffix)));
            }

            $this->memoryLimit = $number;
        }

        return (int) $this->memoryLimit;
    }
}
