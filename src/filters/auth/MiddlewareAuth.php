<?php
declare(strict_types=1);

namespace yii\Psr7\filters\auth;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Yii;
use yii\filters\auth\AuthInterface;

use yii\filters\auth\AuthMethod;
use yii\web\HttpException;
use yii\web\IdentityInterface;
use yii\web\Request;
use yii\web\Response;
use yii\web\User;

class MiddlewareAuth extends AuthMethod implements AuthInterface, RequestHandlerInterface
{
    const TOKEN_ATTRIBUTE_NAME = 'yii_psr7_token_attr';

    /**
     * The internal HTTP status code to throw to indicate that the middleware
     * processing did yet response, and that further handling is required.
     *
     * @var integer
     */
    private int $continueStatusCode = 109;

    /**
     * The PSR-15 middleware to run
     * Must implement process(ServerRequestInterface, RequestHandlerInterface): ResponseInterface
     *
     * @var object|null
     */
    public $middleware;

    /**
     * The attribute to use for loginByAccessToken
     *
     * @var string
     */
    public string $attribute;

    /**
     * The modified request interface
     *
     * @var ServerRequestInterface|null $request
     */
    public ?ServerRequestInterface $request = null;

    /**
     * Returns the modified request
     *
     * @return ServerRequestInterface
     */
    protected function getModifiedRequest(): ServerRequestInterface
    {
        if ($this->request === null) {
            throw new \RuntimeException('Request has not been set. Call handle() method first.');
        }
        return $this->request;
    }

    /**
     * Authenticates a user
     *
     * @param  User     $user
     * @param  Request  $request
     * @param  Response $response
     * @return IdentityInterface|null
     */
    public function authenticate($user, $request, $response): IdentityInterface|null
    {
        if ($this->attribute === null) {
            Yii::error('Token attribute not set.', 'yii\Psr7\filters\auth\MiddlewareAuth');
            $response->setStatusCode(500);
            $response->content = 'An unexpected error occurred.';
            $this->handleFailure($response);
        }

        // Process the PSR-15 middleware
        $instance = $this;
        $process = $this->middleware->process($request->getPsr7Request(), $instance);

        // Update the PSR-7 Request object
        $request->setPsr7Request(
            $instance->getModifiedRequest()
        );

        // If we get a continue status code and the expected user attribute is set
        // attempt to log this user in use yii\web\User::loginByAccessToken
        if ($process->getStatusCode() === $this->continueStatusCode
            && $process->hasHeader(static::TOKEN_ATTRIBUTE_NAME)
        ) {
            // PSR-7 getHeader() returns string[] - get first value
            $tokenHeader = $process->getHeader(static::TOKEN_ATTRIBUTE_NAME);
            $token = is_array($tokenHeader) && count($tokenHeader) > 0 ? $tokenHeader[0] : '';
            if ($identity = $user->loginByAccessToken(
                $token,
                \get_class($this)
            )
            ) {
                return $identity;
            }
        }

        // Populate the response object
        $response->withPsr7Response($process);
        unset($process);
        return null;
    }

    /**
     * RequestHandlerInterface mock method to short-circuit PSR-15 middleware processing
     * If this method is called, then it indicates that the existing requests have not yet
     * returned a response, and that PSR-15 middleware processing has ended for this filter.
     *
     * An out-of-spec HTTP status code is thrown to not interfere with existing HTTP specifications.
     *
     * @param  ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $attributeValue = $request->getAttribute($this->attribute);
        return new \Laminas\Diactoros\Response\EmptyResponse(
            $this->continueStatusCode,
            [
                static::TOKEN_ATTRIBUTE_NAME => $attributeValue !== null ? (string)$attributeValue : ''
            ]
        );
    }

    /**
     * If the authentication event failed, rethrow as an HttpException to end processing
     *
     * @param  Response $response
     * @throws HttpException
     * @return void
     */
    public function handleFailure($response): void
    {
        throw new HttpException(
            $response->getStatusCode(),
            $response->content
        );
    }
}
