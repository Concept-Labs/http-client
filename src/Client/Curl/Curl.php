<?php

namespace Concept\Http\Client\Curl;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Curl implements ClientInterface
{

    const USER_AGENT = 'Nltuning-Mailchimp-Client/1.0';
    const TIMEOUT = 10;
    const ENCODING = 'UTF-8';

    private ?ResponseFactoryInterface $responseFactory = null;
    private ?StreamFactoryInterface $streamFactory = null;    

    /**
     * Dependency injection
     */
    public function __construct(ResponseFactoryInterface $response, StreamFactoryInterface $streamFactory)
    {
        $this->responseFactory = $response;
        $this->streamFactory = $streamFactory;

        $this->init();
    }

    /**
     * Initialization
     */
    protected function init()
    {}

    /**
     * {@inheritdoc}
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $method = (string)$request->getMethod();

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[] = sprintf('%s: %s', $name, $request->getHeaderLine($name));
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, (string)$request->getUri());

         /**
         * Default options
         */
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, static::USER_AGENT);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, static::TIMEOUT);
        //curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_ENCODING, static::ENCODING);
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);


        /**
         * Method specific options
         */
        switch ($method) {
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$request->getBody());
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$request->getBody());
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
        }

        $responseText = curl_exec($ch);

        $body = $this->getStreamFactory()->createStream($responseText);
        $headers = $this->parseHeaders(curl_getinfo($ch, CURLINFO_HEADER_OUT));

        $response = $this->getResponseFactory()
            ->createResponse(curl_getinfo($ch, CURLINFO_HTTP_CODE))
            ->withBody($body);

        foreach ($headers as $header => $value) {
            $response = $response->withAddedHeader($header, $value);
        }

        curl_close($ch);

        return $response;
    }

    protected function parseHeaders(string $headers): array
    {
        $headers = explode("\r\n", $headers);
        $result = [];
        foreach ($headers as $header) {
            if (empty($header) || strpos($header, ':') === false) {
                continue;
            }
            $header = explode(':', $header);
            $result[$header[0]] = $header[1];
        }
        return $result;
    }

    /**
     * Get response factory
     * 
     * @return ResponseFactoryInterface
     */
    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return clone $this->responseFactory;
    }

    /**
     * Get stream factory
     * 
     * @return StreamFactoryInterface
     */
    protected function getStreamFactory(): StreamFactoryInterface
    {
        return clone $this->streamFactory;
    }

}