<?php

declare(strict_types=1);

namespace Causal\FalProtect\Stream;

use Lochmueller\HttpRange\Stream\ReadLocalFileStream;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Type\File\FileInfo;

class FileRequestHandler implements RequestHandlerInterface
{
    public function __construct(protected string $fileName = '', protected array $additionalResponseHeaders = [])
    {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $localFileStream = new ReadLocalFileStream($this->fileName);

        $this->additionalResponseHeaders['Content-Type'] = (new FileInfo($this->fileName))->getMimeType();
        $this->additionalResponseHeaders['Content-Length'] = (string) $localFileStream->getSize();
        $response = (new ResponseFactory())->createResponse()->withBody($localFileStream);

        foreach ($this->additionalResponseHeaders as $headerName => $headerValue) {
            $response = $response->withHeader($headerName, $headerValue);
        }

        return $response;
    }
}
