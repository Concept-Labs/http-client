<?php
namespace Concept\Http\Client\Curl;

use Psr\Http\Client\ClientInterface;

interface MultiCurlInterface extends ClientInterface
{
    /**
     * Send multiple requests in parallel using curl_multi_exec()
     *
     * @param RequestInterface[] $requests
     * @return ResponseInterface[]
     */
    public function sendMultipleRequests(array $requests): array;
    
}