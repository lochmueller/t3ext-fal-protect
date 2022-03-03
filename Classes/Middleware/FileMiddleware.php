<?php
declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace Causal\FalProtect\Middleware;

use Causal\FalProtect\Stream\FileStream;
use Causal\FalProtect\Utility\AccessSecurity;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Http\ImmediateResponseException;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Controller\ErrorController;

/**
 * Class FileMiddleware
 * @package Causal\FalProtect\Middleware
 */
class FileMiddleware implements MiddlewareInterface, LoggerAwareInterface
{

    use LoggerAwareTrait;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $target = $request->getUri()->getPath();
        $fileadminDir = $GLOBALS['TYPO3_CONF_VARS']['BE']['fileadminDir'];

        if (substr($target, 0, strlen($fileadminDir) + 1) === '/' . $fileadminDir) {
            /** @var Response $response */
            $response = GeneralUtility::makeInstance(Response::class);

            $defaultStorage = GeneralUtility::makeInstance(ResourceFactory::class)->getDefaultStorage();
            if ($defaultStorage === null) {
                $this->logger->error('Default storage cannot be determined, please check the configuration of your File Storage record at root.');
                // It is better to block everything than possibly let an administrator think
                // everything is correctly configured
                return $response->withStatus(503, 'Service Unavailable');
            }
            $fileIdentifier = substr($target, strlen($fileadminDir));

            // Respect encoded file names like "sonderzeichenäöü.png" with configurations like [SYS][systemLocale] = "de_DE.UTF8" && [SYS][UTF8filesystem] = "true"
            $fileIdentifier = urldecode($fileIdentifier);

            if (!$defaultStorage->hasFile($fileIdentifier)) {
                $this->pageNotFoundAction($request);
            }

            $frontendUser = version_compare((new \TYPO3\CMS\Core\Information\Typo3Version())->getBranch(), '10.4', '<')
                ? $GLOBALS['TSFE']->fe_user
                : $request->getAttribute('frontend.user');

            $file = $defaultStorage->getFile($fileIdentifier);
            $maxAge = 14400;    // TODO: make this somehow configurable?
            if (!$this->isFileAccessible($file, $frontendUser, $maxAge) && !$this->isFileAccessibleBackendUser($file)) {
                $this->pageNotFoundAction($request);
            }

            $fileName = $file->getForLocalProcessing(false);

            $headers = [];
            $headers['Content-Type'] = $file->getMimeType();
            if ($maxAge > 0) {
                $headers['Cache-Control'] = 'public, max-age=' . $maxAge;
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

            $stream = new FileStream($fileName);
            return new Response($stream, 200, $headers);
        }

        return $handler->handle($request);
    }

    /**
     * Checks whether a given file is accessible by current authenticated user.
     *
     * @param FileInterface $file
     * @param FrontendUserAuthentication $user
     * @param int &$maxAge
     * @return bool
     */
    protected function isFileAccessible(FileInterface $file, FrontendUserAuthentication $user, int &$maxAge): bool
    {
        // This check is supposed to never succeed if the processed folder is properly
        // checked at the Web Server level to allow direct access
        if ($file->getStorage()->isWithinProcessingFolder($file->getIdentifier())) {
            if ($file instanceof \TYPO3\CMS\Core\Resource\ProcessedFile) {
                return $this->isFileAccessible($file->getOriginalFile(), $user, $maxAge);
            }
            return true;
        }

        // Normally done in Middleware typo3/cms-frontend/prepare-tsfe-rendering but we want
        // to be as lightweight as possible:
        $user->fetchGroupData();

        return AccessSecurity::isFileAccessible($file, $maxAge);
    }

    /**
     * Checks whether a given file is accessible by current authenticated backend user.
     *
     * @param FileInterface $file
     * @return bool
     */
    protected function isFileAccessibleBackendUser(FileInterface $file): bool
    {
        // No BE user auth
        if (!($GLOBALS['BE_USER'] instanceof \TYPO3\CMS\Backend\FrontendBackendUserAuthentication)) {
            return false;
        }

        // Not logged in
        if (!is_array($GLOBALS['BE_USER']->user)) {
            return false;
        }

        if ($GLOBALS['BE_USER']->isAdmin()) {
            return true;
        }

        // Handle processed files with original folder access
        if ($file instanceof \TYPO3\CMS\Core\Resource\ProcessedFile) {
            $file = $file->getOriginalFile();
        }

        foreach ($GLOBALS['BE_USER']->getFileMountRecords() as $fileMount) {
            /** @var Folder $fileMountObject */
            $fileMountObject = GeneralUtility::makeInstance(ResourceFactory::class)->getFolderObjectFromCombinedIdentifier($fileMount['base'] . ':' . $fileMount['path']);
            if ($fileMountObject->getStorage()->getUid() === $file->getStorage()->getUid()) {
                if ($this->isFileInFolderOrSubFolder($fileMountObject, $file)) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function isFileInFolderOrSubFolder(Folder $folder, FileInterface $file)
    {
        foreach ($folder->getFiles() as $folderFiles) {
            if ($folderFiles->getUid() === $file->getUid()) {
                return true;
            }
        }
        foreach ($folder->getSubfolders() as $subfolder) {
            if ($this->isFileInFolderOrSubFolder($subfolder, $file)) {
                return true;
            }
        }
        return false;
    }

    protected function pageNotFoundAction(ServerRequestInterface $request, string $message = 'Not Found'): void
    {
        $response = GeneralUtility::makeInstance(ErrorController::class)->pageNotFoundAction($request, $message);

        throw new ImmediateResponseException($response, 1604918043);
    }

}
