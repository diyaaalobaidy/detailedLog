<?php

/**
 * @file plugins/generic/detailedLog/controllers/grid/DetailedEventLogGridRow.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class DetailedEventLogGridRow
 * @ingroup plugins_generic_detailedLog
 *
 * @brief Detailed EventLog grid row definition adding the "View Details" modal action
 *        and file download actions.
 */

namespace APP\plugins\generic\detailedLog\controllers\grid;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\detailedLog\classes\DetailedLogHelper;
use APP\submission\Submission;
use PKP\controllers\api\file\linkAction\DownloadFileLinkAction;
use PKP\controllers\grid\eventLog\EventLogGridRow;
use PKP\controllers\grid\eventLog\linkAction\EmailLinkAction;
use PKP\controllers\grid\GridHandler;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RedirectAction;
use PKP\log\EmailLogEntry;
use PKP\log\event\EventLogEntry;
use PKP\log\event\SubmissionFileEventLogEntry;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submissionFile\SubmissionFile;

class DetailedEventLogGridRow extends EventLogGridRow
{
    /**
     * Get the submission associated with this row
     */
    public function getSubmission(): ?Submission
    {
        return $this->_submission;
    }

    /**
     * @copydoc GridRow::initialize()
     */
    public function initialize($request, $template = null)
    {
        try {
            parent::initialize($request, $template);

            $logEntry = $this->getData();
            if (!$logEntry || !($logEntry instanceof EventLogEntry)) {
                return;
            }

            $router = $request->getRouter();
            $submission = $this->getSubmission();
            $submissionId = $submission ? $submission->getId() : (int) ($request->getUserVar('submissionId') ?: $logEntry->getData('submissionId'));
            if (!$submissionId && $logEntry->getAssocType() == Application::ASSOC_TYPE_SUBMISSION) {
                $submissionId = (int) $logEntry->getAssocId();
            }

            $actionArgs = [
                'submissionId' => $submissionId,
                'logId' => $logEntry->getId(),
            ];

            // Add the "View Full Details" modal link action
            $this->addAction(
                new LinkAction(
                    'viewLogDetails',
                    new AjaxModal(
                        $router->url($request, null, null, 'viewLogDetails', null, $actionArgs),
                        DetailedLogHelper::translate('plugins.generic.detailedLog.viewDetailsTitle', ['id' => $logEntry->getId()], "Activity Log Details - #{$logEntry->getId()}"),
                        'modal_information'
                    ),
                    DetailedLogHelper::translate('plugins.generic.detailedLog.viewDetails', [], 'View Details'),
                    'information'
                )
            );

            // Add Download File link action for file operations
            $fileInfo = DetailedLogHelper::getFileInfoForLogEntry($logEntry, (bool) ($this->_isCurrentUserAssignedAuthor ?? false));
            if ($fileInfo && !empty($fileInfo['fileId'])) {
                $downloadArgs = [
                    'submissionId' => $submissionId,
                    'logId' => $logEntry->getId(),
                    'fileId' => $fileInfo['fileId'],
                ];
                if (!empty($fileInfo['submissionFileId'])) {
                    $downloadArgs['submissionFileId'] = $fileInfo['submissionFileId'];
                }

                $downloadUrl = $router->url($request, null, null, 'downloadFile', null, $downloadArgs);

                $downloadLabel = DetailedLogHelper::translate('common.download', [], 'Download');
                if (!empty($fileInfo['filename'])) {
                    $downloadLabel .= ' (' . $fileInfo['filename'] . ')';
                }

                $this->addAction(
                    new LinkAction(
                        'downloadFile',
                        new RedirectAction($downloadUrl),
                        $downloadLabel,
                        'download'
                    )
                );
            } else {
                // Remove any download action if file access is disallowed or invalid
                unset($this->_actions[GridHandler::GRID_ACTION_POSITION_DEFAULT]['downloadFile']);
            }
        } catch (\Throwable $e) {
            DetailedLogHelper::logError('Error initializing DetailedEventLogGridRow', $e);
        }
    }
}
