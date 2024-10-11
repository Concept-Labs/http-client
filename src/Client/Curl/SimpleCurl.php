<?php

namespace Concept\Http\Client\Curl;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class SimpleCurl implements ClientInterface
{
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
     * Get user agent
     * 
     * @return string
     */
    protected function getUserAgent(): string
    {
        return 'Concept-Labs Curl Client/1.0 (PHP/' . PHP_VERSION . '; ' . php_uname('s') . ' ' . php_uname('r') . ')';
    }

    /**
     * Get timeout
     * 
     * @return int
     */
    protected function getTimeout(): int
    {
        return self::TIMEOUT;
    }

    /**
     * Get encoding
     * 
     * @return string
     */
    protected function getEncoding(): string
    {
        return self::ENCODING;
    }

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
        curl_setopt($ch, CURLOPT_USERAGENT, $this->getUserAgent());
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->getTimeout());
        curl_setopt($ch, CURLOPT_ENCODING, $this->getEncoding());
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt($ch, CURLOPT_HEADER, true); // To include headers in the output
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Enable redirection

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
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$request->getBody());
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$request->getBody());
                break;
        }

        $responseText = curl_exec($ch);

        // Handle curl errors
        if ($responseText === false) {
            throw new \RuntimeException('Curl error: ' . curl_error($ch), curl_errno($ch));
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($responseText, 0, $headerSize);
        $body = substr($responseText, $headerSize);

        $parsedHeaders = $this->parseHeaders($headers);
        $bodyStream = $this->getStreamFactory()->createStream($body);

        $response = $this->getResponseFactory()
            ->createResponse(curl_getinfo($ch, CURLINFO_HTTP_CODE))
            ->withBody($bodyStream);

        foreach ($parsedHeaders as $header => $value) {
            $response = $response->withAddedHeader($header, $value);
        }

        curl_close($ch);

        return $response;
    }

    /**
     * Parse headers from a raw header string
     *
     * @param string $headers
     * @return array
     */
    protected function parseHeaders(string $headers): array
    {
        $lines = explode("\r\n", $headers);
        $result = [];

        foreach ($lines as $line) {
            if (empty($line) || strpos($line, ':') === false) {
                continue;
            }

            [$header, $value] = explode(':', $line, 2);
            $result[trim($header)] = trim($value);
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

   