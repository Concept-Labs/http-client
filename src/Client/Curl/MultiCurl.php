<?php

namespace Concept\Http\Client\Curl;

class MultiCurl extends Curl implements MultiCurlInterface
{

    /**
     * {@inheritdoc}
     */
    public function sendMultipleRequests(array $requests, ?array $options = []): array
    {
        return $this->multiRequest($requests, $options);
    }
}
