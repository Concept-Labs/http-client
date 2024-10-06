<?php
namespace Concept\Http\Client\Exception;

use Nltuning\Mailchimp\Api\Exception\MailchimpException;

class ClientException extends MailchimpException implements ClientExceptionInterface
{
    const LOG_FILE = 'client-exception.log';
}