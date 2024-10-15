<?php

declare(strict_types=1);

namespace Causal\FalProtect\Middleware;

use Causal\FalProtect\Event\SecurityCheckEvent;
use Causal\FalProtect\Middleware\FileMiddleware;
use Causal\FalProtect\Stream\FileRequestHandler;
use Causal\FalProtect\Stream\Typo3SelfEmittableStreamWrapper;
use Lochmueller\HttpRange\HttpRangeMiddleware;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class HttpRangeFileMiddleware extends FileMiddleware
{
    public const MIDDLEWARE_IDENTIFIER = 'causal/fal-protect/http-range-file';

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Respect encoded file names like "sonderzeichenäöü.png" with configurations like [SYS][systemLocale] = "de_DE.UTF8" && [SYS][UTF8filesystem] = "true"
        $target = urldecode($request->getUri()->getPath());

        $file = null;
        // Filter out what is obviously the root page or an non-authorized file name
        if ('/' !== $target && $this->isValidTarget($target)) {
            try {
                $file = GeneralUtility::makeInstance(ResourceFactory::class)->getFileObjectByStorageAndIdentifier(0, $target);
            } catch (\InvalidArgumentException $e) {
                // Nothing to do
            }
        }
        if (null !== $file) {
            $frontendUser = version_compare((new Typo3Version())->getBranch(), '10.4', '<')
                ? $GLOBALS['TSFE']->fe_user
                : $request->getAttribute('frontend.user');

            $maxAge = 14400;
            $isAccessible = $this->isFileAccessible($request, $file, $frontendUser, $maxAge)
                || $this->isFileAccessibleBackendUser($file);

            $eventDispatcher = GeneralUtility::makeInstance(EventDispatcherInterface::class);
            $securityCheckEvent = GeneralUtility::makeInstance(SecurityCheckEvent::class, $file, $isAccessible);
            $eventDispatcher->dispatch($securityCheckEvent);
            $isAccessible = $securityCheckEvent->isAccessible();

            if (!$isAccessible) {
                $settings = GeneralUtility::makeInstance(ExtensionConfiguration::class)->get('fal_protect') ?? [];
                if ((bool)($settings['return_403'] ?? false)) {
                    // @todo handle with middleware stack and real response
                    $this->accessDeniedAction($request);
                }
                // @todo handle with middleware stack and real response
                $this->pageNotFoundAction($request);
            }

            $fileName = $file->getForLocalProcessing(false);

            $headers = [];
            $headers['Content-Type'] = $file->getMimeType();
            if ($maxAge > 0) {
                $headers['Cache-Control'] = 'public, max-age='.$maxAge;
            } else {
                $headers['Cache-Control'] = implode(', ', [
                    'no-store',
                    'no-cache',
                    'must-revalidate',
                    'max-age=0',
                    'post-check=0',
                    'pre-check=0',
                ]);
                $headers['Pragma'] = 'no-cache';
            }

            $headers['X-FalProtect'] = '1';
            $headers['X-Filename'] = $fileName;

            $middleware = new HttpRangeMiddleware(new ResponseFactory());
            $response = $middleware->process($request, new FileRequestHandler($fileName, $headers));

            return $response->withBody(new Typo3SelfEmittableStreamWrapper($response->getBody()));
        }

        return $handler->handle($request);
    }
}
