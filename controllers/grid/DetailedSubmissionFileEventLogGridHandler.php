<?php

/**
 * @file plugins/generic/detailedLog/controllers/grid/DetailedSubmissionFileEventLogGridHandler.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class DetailedSubmissionFileEventLogGridHandler
 * @ingroup plugins_generic_detailedLog
 *
 * @brief Detailed Grid handler presenting submission file specific event log entries
 *        with full event_log_settings decoding.
 */

namespace APP\plugins\generic\detailedLog\controllers\grid;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\detailedLog\classes\DetailedLogHelper;
use APP\plugins\generic\detailedLog\DetailedLogPlugin;
use PKP\core\PKPApplication;
use PKP\core\PKPRequest;
use PKP\security\authorization\SubmissionFileAccessPolicy;
use PKP\submissionFile\SubmissionFile;

class DetailedSubmissionFileEventLogGridHandler extends DetailedSubmissionEventLogGridHandler
{
    /** @var SubmissionFile */
    public $_submissionFile;

    public function getSubmissionFile(): ?SubmissionFile
    {
        return $this->_submissionFile;
    }

    public function setSubmissionFile(SubmissionFile $submissionFile): void
    {
        $this->_submissionFile = $submissionFile;
    }

    /**
     * @see PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new SubmissionFileAccessPolicy($request, $args, $roleAssignments, SubmissionFileAccessPolicy::SUBMISSION_FILE_ACCESS_READ, (int) $args['submissionFileId']));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * @see DetailedSubmissionEventLogGridHandler::initialize
     */
    public function initialize($request, $args = null)
    {
        try {
            parent::initialize($request, $args);

            $submissionFile = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION_FILE);
            $this->setSubmissionFile($submissionFile);
        } catch (\Throwable $e) {
            DetailedLogHelper::logError('Error initializing DetailedSubmissionFileEventLogGridHandler', $e);
        }
    }

    /**
     * @return array
     */
    public function getRequestArgs()
    {
        try {
            $submissionFile = $this->getSubmissionFile();

            return [
                'submissionId' => $submissionFile ? $submissionFile->getData('submissionId') : null,
                'submissionFileId' => $submissionFile ? $submissionFile->getId() : null,
                'stageId' => $this->_stageId,
            ];
        } catch (\Throwable $e) {
            DetailedLogHelper::logError('Error getting request args in DetailedSubmissionFileEventLogGridHandler', $e);
            return [];
        }
    }

    /**
     * @copydoc GridHandler::loadData
     */
    protected function loadData($request, $filter = null)
    {
        try {
            $submissionFile = $this->getSubmissionFile();
            if (!$submissionFile) {
                return [];
            }

            $fileId = $submissionFile->getId();

            $entries = Repo::eventLog()->getCollector()
                ->filterByAssoc(PKPApplication::ASSOC_TYPE_SUBMISSION_FILE, [$fileId])
                ->getMany()
                ->toArray();

            $logIds = array_map(fn($e) => $e->getId(), $entries);
            DetailedLogHelper::preloadSettings($logIds);

            // Sort by date, most recent first
            usort($entries, function ($a, $b) {
                $aDate = $a->getDateLogged();
                $bDate = $b->getDateLogged();
                if ($aDate == $bDate) {
                    return 0;
                }
                return $aDate < $bDate ? 1 : -1;
            });

            return array_values($entries);
        } catch (\Throwable $e) {
            DetailedLogHelper::logError('Error loading data in DetailedSubmissionFileEventLogGridHandler', $e);
            return [];
        }
    }
}
