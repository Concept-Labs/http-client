<?php

namespace Concept\Http\Client\Curl;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Concept\Http\Client\Exception\ClientException;
use Concept\Http\Client\Exception\NetworkException;
use Concept\Http\Client\Exception\ResponseException;

class Curl implements ClientInterface
{
    const TIMEOUT = 10;
    const ENCODING = 'UTF-8';

    const DEFAULT_OPTIONS = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => self::TIMEOUT,
        CURLOPT_ENCODING => self::ENCODING,
    ];

    private ?ResponseFactoryInterface $responseFactory = null;
    private ?StreamFactoryInterface $streamFactory = null;

    /**
     * Dependency injection
     */
    public function __construct(ResponseFactoryInterface $responseFactory, StreamFactoryInterface $streamFactory)
    {
        $this->responseFactory = $responseFactory;
        $this->streamFactory = $streamFactory;
    }

    /**
     * {@inheritdoc}
     * 
     * @param RequestInterface $request
     * @param array $options
     * 
     * @throws \Psr\Http\Client\ClientExceptionInterface
     * @throws ClientException
     * @throws NetworkException
     * @throws ResponseException
     */
    public function sendRequest(RequestInterface $request, array $options = []): ResponseInterface
    {
        $responses = $this->multiRequest([$request], $options);

        return reset($responses);
    }


    /**
     * @param RequestInterface[] $requests
     * @param array|null $options
     * 
     * @return ResponseInterface[]
     * 
     * @throws \Psr\Http\Client\ClientExceptionInterface
     * @throws ResponseException
     */
    protected function createResponse($ch, string $responseText): ResponseInterface
    {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        /**
         * @todo check httpCode or keep for service handling
         */

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($responseText, 0, $headerSize);
        $body = substr($responseText, $headerSize);

        $response = $this
            ->getResponseFactory()
            ->createResponse($httpCode)
            ->withBody(
                $this
                    ->getStreamFactory()
                    ->createStream($body)
            );

        foreach ($this->parseResponseHeaders($headers) as $header => $value) {
            $response = $response->withAddedHeader($header, $value);
        }

        return $response;
    }

    /**
     * @param RequestInterface[] $requests
     * @param array|null $options
     * 
     * @return ResponseInterface[]
     * 
     * @throws \Psr\Http\Client\ClientExceptionInterface
     * @throws ClientException
     * @throws NetworkException
     */
    protected function multiRequest(array $requests, array $options = []): array
    {
        $handles = [];
        $responses = [];
        $multiHandle = curl_multi_init();

        foreach ($requests as $key => $request) {
            $handles[$key] = $this->initializeCurlHandle($request, $options);
            curl_multi_add_handle($multiHandle, $handles[$key]);
        }

        $active = null;
        do {
            $status = curl_multi_exec($multiHandle, $active);
            if ($active) {
                curl_multi_select($multiHandle);
            }
        } while ($active && $status == CURLM_OK);

        foreach ($handles as $key => $ch) {
            if (false === $responseText = curl_multi_getcontent($ch)) {
                $curlError = curl_error($ch);
                $curlErrno = curl_errno($ch);
                curl_multi_remove_handle($multiHandle, $ch);
                curl_close($ch);

                // Обробка помилок CURL
                if ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
                    throw new NetworkException('Timeout occurred during request', $curlErrno);
                }

                throw new ClientException("Curl error: {$curlError}", $curlErrno);
            }

            // Створюємо відповідь з отриманого контенту
            $responses[$key] = $this->createResponse($ch, $responseText);

            curl_multi_remove_handle($multiHandle, $ch);
            curl_close($ch);
        }

        curl_multi_close($multiHandle);

        return $responses;
    }

    /**
     * @param RequestInterface $request
     * @param array $options
     * 
     * @return resource
     */
    protected function initializeCurlHandle(RequestInterface $request, array $options)
    {
        $options = $options + self::DEFAULT_OPTIONS;

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, (string)$request->getUri());
        curl_setopt($ch, CURLOPT_USERAGENT, $this->getUserAgent());
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->parseRequestHeders($request));

        foreach ($options as $option => $value) {
            curl_setopt($ch, $option, $value);
        }

        $this->setCurlMethod($ch, $request);

        return $ch;
    }

    /**
     * @param RequestInterface $request
     * 
     * @return string[]
     */
    protected function parseRequestHeders(RequestInterface $request): array
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[] = sprintf('%s: %s', $name, $request->getHeaderLine($name));
        }

        $headers[] = 'Content-Length: ' . $request->getBody()->getSize();
        

        return $headers;
    }

    /**
     * @param resource $ch
     * @param RequestInterface $request
     */
    protected function setCurlMethod($ch, RequestInterface $request): void
    {
        $method = strtoupper($request->getMethod());
        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$request->getBody());
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_PUT, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$request->getBody());
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$request->getBody());
            case 'DELETE':
            case 'GET':
            default:
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
        }
    }

    /**
     * @param string $headers
     * 
     * @return string[]
     */
    protected function parseResponseHeaders(string $headers): array
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
     * @return string
     */
    protected function getUserAgent(): string
    {
        return 'Concept-Labs Curl Client/1.1 (PHP/' . PHP_VERSION . '; ' . php_uname('s') . ' ' . php_uname('r') . ')';
    }

    /**
     * @return ResponseFactoryInterface
     */
    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @return StreamFactoryInterface
     */
    protected function getStreamFactory(): StreamFactoryInterface
    {
        return $this->streamFactory;
    }
}
