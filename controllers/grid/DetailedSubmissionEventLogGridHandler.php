<?php

/**
 * @file plugins/generic/detailedLog/controllers/grid/DetailedSubmissionEventLogGridHandler.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class DetailedSubmissionEventLogGridHandler
 * @ingroup plugins_generic_detailedLog
 *
 * @brief Grid handler presenting the full detailed submission event log with
 *        all parameters from event_log_settings, CSV/JSON export, and rich modal inspection.
 */

namespace APP\plugins\generic\detailedLog\controllers\grid;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\detailedLog\classes\DetailedEventLogGridCellProvider;
use APP\plugins\generic\detailedLog\classes\DetailedLogHelper;
use APP\plugins\generic\detailedLog\DetailedLogPlugin;
use APP\template\TemplateManager;
use PKP\controllers\grid\eventLog\SubmissionEventLogGridHandler;
use PKP\controllers\grid\GridColumn;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\core\PKPRequest;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\OpenWindowAction;
use PKP\log\EmailLogEntry;
use PKP\log\event\EventLogEntry;
use PKP\plugins\PluginRegistry;
use PKP\security\Role;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submissionFile\SubmissionFile;

class DetailedSubmissionEventLogGridHandler extends SubmissionEventLogGridHandler
{
    /** @var DetailedLogPlugin The plugin instance */
    public ?DetailedLogPlugin $_plugin = null;

    /** @var bool Is current user author */
    public $_isCurrentUserAssignedAuthor = false;

    /**
     * Constructor
     */
    public function __construct(?DetailedLogPlugin $plugin = null)
    {
        parent::__construct();
        $this->_plugin = $plugin;

        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT],
            ['fetchGrid', 'fetchRow', 'viewEmail', 'viewLogDetails', 'exportCsv', 'exportJson', 'downloadFile']
        );
    }

    /**
     * Get the plugin instance
     */
    public function getPlugin(): DetailedLogPlugin
    {
        if (!$this->_plugin) {
            $this->_plugin = PluginRegistry::getPlugin('generic', 'detailedlogplugin');
            if (!$this->_plugin) {
                $this->_plugin = PluginRegistry::loadPlugin('generic', 'detailedLog');
            }
            if (!$this->_plugin) {
                $this->_plugin = new DetailedLogPlugin();
            }
        }
        return $this->_plugin;
    }

    /**
     * @copydoc GridHandler::initialize()
     */
    public function initialize($request, $args = null)
    {
        try {
            // Call parent initialize but reset columns so we can configure our rich detailed layout
            parent::initialize($request, $args);

            // Retrieve authorized submission
            $submission = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_SUBMISSION);
            $this->setSubmission($submission);

            $this->_stageId = (int) ($args['stageId'] ?? null);

            // Clear default 3 columns and register our enhanced 5 columns
            $this->_columns = [];

            $cellProvider = new DetailedEventLogGridCellProvider((bool) ($this->_isCurrentUserAssignedAuthor ?? false));

            // Column 1: Date & Time
            $this->addColumn(
                new GridColumn(
                    'date',
                    'common.date',
                    null,
                    null,
                    $cellProvider,
                    ['width' => 18]
                )
            );

            // Column 2: User & Role
            $this->addColumn(
                new GridColumn(
                    'user',
                    'common.user',
                    null,
                    null,
                    $cellProvider,
                    ['width' => 18]
                )
            );

            // Column 3: Event / Action
            $this->addColumn(
                new GridColumn(
                    'event',
                    'common.event',
                    null,
                    null,
                    $cellProvider,
                    ['width' => 20]
                )
            );

            // Column 4: Workflow Stage
            $this->addColumn(
                new GridColumn(
                    'stage',
                    'workflow.stage',
                    null,
                    null,
                    $cellProvider,
                    ['width' => 14]
                )
            );

            // Column 5: Full Event Details from event_log_settings
            $this->addColumn(
                new GridColumn(
                    'details',
                    'plugins.generic.detailedLog.grid.column.details',
                    null,
                    null,
                    $cellProvider,
                    ['width' => 30]
                )
            );

            // Header Actions: Export to CSV & JSON
            $router = $request->getRouter();
            $actionArgs = $this->getRequestArgs();

            $this->addAction(
                new LinkAction(
                    'exportCsv',
                    new OpenWindowAction($router->url($request, null, null, 'exportCsv', null, $actionArgs)),
                    DetailedLogHelper::translate('plugins.generic.detailedLog.exportCsv', [], 'Export CSV'),
                    'export'
                )
            );

            $this->addAction(
                new LinkAction(
                    'exportJson',
                    new OpenWindowAction($router->url($request, null, null, 'exportJson', null, $actionArgs)),
                    DetailedLogHelper::translate('plugins.generic.detailedLog.exportJson', [], 'Export JSON'),
                    'export'
                )
            );
        } catch (\Throwable $e) {
            DetailedLogHelper::logError('Error initializing DetailedSubmissionEventLogGridHandler', $e);
        }
    }

    /**
     * @see GridHandler::getRowInstance()
     */
    protected function getRowInstance()
    {
        return new DetailedEventLogGridRow($this->getSubmission(), (bool) ($this->_isCurrentUserAssignedAuthor ?? false));
    }

    /**
     * @copydoc GridHandler::loadData
     */
    protected function loadData($request, $filter = null)
    {
        try {
            $submission = $this->getSubmission();
            if (!$submission) {
                return [];
            }
            $submissionId = $submission->getId();

            // 1. Fetch all event IDs (direct submission events + file events associated with this submission)
            $allLogIds = DetailedLogHelper::getAllSubmissionLogIds($submissionId);

            // Preload settings into cache
            DetailedLogHelper::preloadSettings($allLogIds);

            // 2. Fetch event log entries
            $eventLogEntries = [];
            foreach ($allLogIds as $id) {
                $entry = Repo::eventLog()->get((int)$id);
                if ($entry) {
                    $eventLogEntries[] = $entry;
                }
            }

            // 3. Fetch email log entries
            $emailLogEntries = EmailLogEntry::withAssocId($submissionId)
                ->withAssocType(Application::ASSOC_TYPE_SUBMISSION)
                ->get();

            $entries = array_merge($eventLogEntries, $emailLogEntries->all());

            // 4. Apply Filters if requested
            $categoryFilter = $filter['category'] ?? null;
            $searchQuery = trim($filter['searchQuery'] ?? '');

            if (!empty($categoryFilter) && $categoryFilter !== 'all') {
                $entries = array_filter($entries, function ($entry) use ($categoryFilter) {
                    $cat = DetailedLogHelper::getEventCategory($entry);
                    return $cat['category'] === $categoryFilter;
                });
            }

            if (!empty($searchQuery)) {
                $lowerSearch = strtolower($searchQuery);
                $entries = array_filter($entries, function ($entry) use ($lowerSearch) {
                    if ($entry instanceof EmailLogEntry) {
                        return str_contains(strtolower($entry->subject ?? ''), $lowerSearch) ||
                               str_contains(strtolower($entry->senderFullName ?? ''), $lowerSearch);
                    }

                    $message = strtolower($entry->getTranslatedMessage() ?: $entry->getMessage());
                    $userName = strtolower($entry->getUserFullName() ?: '');
                    $username = strtolower((string)($entry->getData('username') ?: ''));
                    $filename = strtolower((string)(is_array($entry->getData('filename')) ? implode(' ', $entry->getData('filename')) : ($entry->getData('filename') ?: '')));
                    $decision = strtolower((string)($entry->getData('decision') ?: ''));

                    return str_contains($message, $lowerSearch) ||
                           str_contains($userName, $lowerSearch) ||
                           str_contains($username, $lowerSearch) ||
                           str_contains($filename, $lowerSearch) ||
                           str_contains($decision, $lowerSearch);
                });
            }

            // Sort most recent first
            usort($entries, function ($a, $b) {
                $aDate = $a instanceof EventLogEntry ? $a->getDateLogged() : $a->dateSent;
                $bDate = $b instanceof EventLogEntry ? $b->getDateLogged() : $b->dateSent;

                if ($aDate == $bDate) {
                    return 0;
                }
                return $aDate < $bDate ? 1 : -1;
            });

            return array_values($entries);
        } catch (\Throwable $e) {
            DetailedLogHelper::logError('Error loading data in DetailedSubmissionEventLogGridHandler', $e);
            try {
                return parent::loadData($request, $filter);
            } catch (\Throwable $parentEx) {
                return [];
            }
        }
    }

    /**
     * Operation to view complete log entry details in a modal
     */
    public function viewLogDetails($args, $request): JSONMessage
    {
        try {
            $logId = (int) ($args['logId'] ?? 0);
            $entry = Repo::eventLog()->get($logId);
            if (!$entry) {
                return new JSONMessage(false, DetailedLogHelper::translate('plugins.generic.detailedLog.entryNotFound', [], 'Log entry not found.'));
            }

            $submission = $this->getSubmission();
            $submissionId = $submission ? $submission->getId() : (int) ($args['submissionId'] ?? $request->getUserVar('submissionId') ?: $entry->getData('submissionId'));
            if (!$submissionId && $entry->getAssocType() == Application::ASSOC_TYPE_SUBMISSION) {
                $submissionId = (int) $entry->getAssocId();
            }
            if (!$submission && $submissionId) {
                $submission = Repo::submission()->get($submissionId);
                if ($submission) {
                    $this->setSubmission($submission);
                }
            }

            $rawSettings = DetailedLogHelper::getRawSettings($logId);
            $interpretedSettings = [];
            foreach ($rawSettings as $setting) {
                $interpretedSettings[] = DetailedLogHelper::interpretSetting(
                    $setting['name'],
                    $setting['value'],
                    $setting['locale']
                );
            }

            $highlights = DetailedLogHelper::getStructuredHighlights($entry, $this->_isCurrentUserAssignedAuthor);
            $category = DetailedLogHelper::getEventCategory($entry);
            $userDetails = DetailedLogHelper::getUserDetails($entry->getUserId());

            $templateMgr = TemplateManager::getManager($request);
            $plugin = $this->getPlugin();

            $realIp = null;
            $requestBody = null;
            foreach ($rawSettings as $setting) {
                if ($setting['name'] === 'realIp') {
                    $realIp = $setting['value'];
                } elseif ($setting['name'] === 'ipAddress' && !$realIp) {
                    $realIp = $setting['value'];
                } elseif ($setting['name'] === 'requestBody') {
                    $requestBody = $setting['value'];
                }
            }

            $fileInfo = DetailedLogHelper::getFileInfoForLogEntry($entry, (bool) ($this->_isCurrentUserAssignedAuthor ?? false));
            $fileDownloadUrl = null;
            $filePhysicalExists = false;
            if ($fileInfo && !empty($fileInfo['fileId'])) {
                $router = $request->getRouter();
                $downloadArgs = [
                    'submissionId' => $submissionId,
                    'logId' => $logId,
                    'fileId' => $fileInfo['fileId'],
                ];
                if (!empty($fileInfo['submissionFileId'])) {
                    $downloadArgs['submissionFileId'] = $fileInfo['submissionFileId'];
                }
                $fileDownloadUrl = $router->url($request, null, null, 'downloadFile', null, $downloadArgs);
                $filePhysicalExists = !empty($fileInfo['filePath']) && app()->get('file')->fs->has($fileInfo['filePath']);
            }

            $templateMgr->assign([
                'logEntry' => $entry,
                'logId' => $logId,
                'submission' => $submission,
                'submissionId' => $submissionId,
                'eventCategory' => $category,
                'translatedMessage' => $entry->getTranslatedMessage(null, $this->_isCurrentUserAssignedAuthor),
                'userDetails' => $userDetails,
                'realIp' => $realIp,
                'requestBody' => $requestBody,
                'rawSettings' => $rawSettings,
                'interpretedSettings' => $interpretedSettings,
                'highlights' => $highlights,
                'fileInfo' => $fileInfo,
                'fileDownloadUrl' => $fileDownloadUrl,
                'filePhysicalExists' => $filePhysicalExists,
                'isCurrentUserAssignedAuthor' => $this->_isCurrentUserAssignedAuthor,
                'allDataJson' => json_encode($entry->getAllData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
            ]);

            return new JSONMessage(true, $templateMgr->fetch($plugin->getTemplateResource('viewLogDetails.tpl')));
        } catch (\Throwable $e) {
            DetailedLogHelper::logError('Error viewing log details in DetailedSubmissionEventLogGridHandler', $e);
            return new JSONMessage(false, DetailedLogHelper::translate('plugins.generic.detailedLog.entryNotFound', [], 'An unexpected error occurred while loading log details.'));
        }
    }

    /**
     * Export all activity logs for this article to CSV
     */
    public function exportCsv($args, $request)
    {
        $submission = $this->getSubmission();
        $submissionId = $submission ? $submission->getId() : 0;

        // Must have editorial or manager role to export
        $userRoles = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
        if (!array_intersect([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT], $userRoles ?: [])) {
            fatalError('Unauthorized');
        }

        try {
            $entries = $this->loadData($request);

            $filename = "submission-{$submissionId}-detailed-activity-log-" . date('Y-m-d') . '.csv';

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            $out = fopen('php://output', 'w');
            // UTF-8 BOM for Excel compatibility
            fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // CSV Header
            fputcsv($out, [
                'Log ID',
                'Date Logged',
                'Category',
                'Event Action',
                'Workflow Stage',
                'User Full Name',
                'Username',
                'User Role',
                'File Name',
                'File ID',
                'Submission File ID',
                'File Stage',
                'Decision',
                'Editor Name',
                'Reviewer Name',
                'Review Round',
                'All event_log_settings (Key=Value)',
            ]);

            foreach ($entries as $entry) {
                if ($entry instanceof EmailLogEntry) {
                    $senderInfo = DetailedLogHelper::getEmailSenderInfo($entry);
                    fputcsv($out, [
                        $entry->id,
                        $entry->dateSent,
                        'Email / Notification',
                        $entry->subject,
                        'Notification',
                        $senderInfo['name'],
                        $senderInfo['email'],
                        'Sender',
                        '', '', '', '',
                        '', '', '', '',
                        'recipients=' . (is_array($entry->recipients) ? implode(';', $entry->recipients) : $entry->recipients),
                    ]);
                    continue;
                }

                $cat = DetailedLogHelper::getEventCategory($entry);
                $highlights = DetailedLogHelper::getStructuredHighlights($entry, false);
                $rawSettings = DetailedLogHelper::getRawSettings($entry->getId());

                $settingsSummary = [];
                foreach ($rawSettings as $s) {
                    $settingsSummary[] = "{$s['name']}={$s['value']}";
                }

                $userGroup = $entry->getData('userGroupName');
                if (is_array($userGroup)) {
                    $userGroup = current($userGroup);
                }

                fputcsv($out, [
                    $entry->getId(),
                    $entry->getDateLogged(),
                    $cat['name'],
                    $entry->getTranslatedMessage(),
                    $highlights['workflowStage'] ?: '',
                    $entry->getUserFullName(),
                    $entry->getData('username') ?: '',
                    $userGroup ?: '',
                    $highlights['files']['filename'] ?? '',
                    $highlights['files']['fileId'] ?? '',
                    $highlights['files']['submissionFileId'] ?? '',
                    $highlights['files']['fileStageLabel'] ?? '',
                    $highlights['decision']['decision'] ?? '',
                    $highlights['decision']['editorName'] ?? '',
                    $highlights['review']['reviewerName'] ?? '',
                    $highlights['review']['round'] ?? '',
                    implode(' | ', $settingsSummary),
                ]);
            }

            fclose($out);
            exit;
        } catch (\Throwable $e) {
            DetailedLogHelper::logError('Error exporting CSV in DetailedSubmissionEventLogGridHandler', $e);
            exit;
        }
    }

    /**
     * Export all activity logs for this article to JSON
     */
    public function exportJson($args, $request)
    {
        $submission = $this->getSubmission();
        $submissionId = $submission ? $submission->getId() : 0;

        $userRoles = $this->getAuthorizedContextObject(Application::ASSOC_TYPE_USER_ROLES);
        if (!array_intersect([Role::ROLE_ID_MANAGER, Role::ROLE_ID_SITE_ADMIN, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT], $userRoles ?: [])) {
            fatalError('Unauthorized');
        }

        try {
            $publication = $submission?->getCurrentPublication();
            $submissionTitle = $publication ? $publication->getLocalizedTitle() : ('Submission #' . $submissionId);

            $entries = $this->loadData($request);
            $exportData = [
                'submissionId' => $submissionId,
                'submissionTitle' => $submissionTitle,
                'exportedAt' => date('c'),
                'totalEvents' => count($entries),
                'events' => [],
            ];

            foreach ($entries as $entry) {
                if ($entry instanceof EmailLogEntry) {
                    $senderInfo = DetailedLogHelper::getEmailSenderInfo($entry);
                    $exportData['events'][] = [
                        'type' => 'email',
                        'id' => $entry->id,
                        'date' => $entry->dateSent,
                        'subject' => $entry->subject,
                        'sender' => $senderInfo['name'],
                        'senderEmail' => $senderInfo['email'],
                        'recipients' => $entry->recipients,
                    ];
                    continue;
                }

                $rawSettings = DetailedLogHelper::getRawSettings($entry->getId());
                $settingsMap = [];
                foreach ($rawSettings as $s) {
                    $settingsMap[$s['name']] = [
                        'value' => $s['value'],
                        'locale' => $s['locale'],
                    ];
                }

                $cat = DetailedLogHelper::getEventCategory($entry);
                $highlights = DetailedLogHelper::getStructuredHighlights($entry, false);

                $exportData['events'][] = [
                    'type' => 'event',
                    'logId' => $entry->getId(),
                    'eventType' => $entry->getEventType(),
                    'messageKey' => $entry->getMessage(),
                    'translatedMessage' => $entry->getTranslatedMessage(),
                    'category' => $cat['category'],
                    'dateLogged' => $entry->getDateLogged(),
                    'userId' => $entry->getUserId(),
                    'userFullName' => $entry->getUserFullName(),
                    'highlights' => $highlights,
                    'eventLogSettings' => $settingsMap,
                ];
            }

            $filename = "submission-{$submissionId}-detailed-activity-log-" . date('Y-m-d') . '.json';

            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');

            echo json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            DetailedLogHelper::logError('Error exporting JSON in DetailedSubmissionEventLogGridHandler', $e);
            echo json_encode(['error' => 'An error occurred during export.']);
            exit;
        }
    }

    /**
     * @copydoc GridHandler::getFilterForm()
     */
    protected function getFilterForm()
    {
        return $this->getPlugin()->getTemplateResource('detailedLogGridFilter.tpl');
    }

    /**
     * @copydoc GridHandler::getFilterSelectionData()
     */
    public function getFilterSelectionData($request)
    {
        return [
            'category' => $request->getUserVar('category'),
            'searchQuery' => $request->getUserVar('searchQuery'),
        ];
    }

    /**
     * Download a file referenced in a submission event log entry
     */
    public function downloadFile($args, $request)
    {
        try {
            $submission = $this->getSubmission();
            $submissionId = $submission ? $submission->getId() : (int) ($args['submissionId'] ?? $request->getUserVar('submissionId'));

            $logId = (int) ($args['logId'] ?? $request->getUserVar('logId'));
            $fileId = (int) ($args['fileId'] ?? $request->getUserVar('fileId'));
            $submissionFileId = (int) ($args['submissionFileId'] ?? $request->getUserVar('submissionFileId'));
            $filename = $args['filename'] ?? $request->getUserVar('filename');

            // Resolve details from log entry if logId is provided
            if ($logId) {
                $entry = Repo::eventLog()->get($logId);
                if ($entry) {
                    $fileInfo = DetailedLogHelper::getFileInfoForLogEntry($entry, (bool) ($this->_isCurrentUserAssignedAuthor ?? false));
                    if ($fileInfo) {
                        $fileId = $fileId ?: $fileInfo['fileId'];
                        $submissionFileId = $submissionFileId ?: $fileInfo['submissionFileId'];
                        $filename = $filename ?: $fileInfo['filename'];
                    }
                }
            }

            // Fallback resolution via submissionFileId
            if (!$fileId && $submissionFileId) {
                $subFile = Repo::submissionFile()->get($submissionFileId);
                if ($subFile) {
                    $fileId = (int) $subFile->getData('fileId');
                    $filename = $filename ?: $subFile->getLocalizedData('name');
                } else {
                    $subFileRow = \Illuminate\Support\Facades\DB::table('submission_files')
                        ->where('submission_file_id', $submissionFileId)
                        ->first();
                    if ($subFileRow) {
                        $fileId = (int) $subFileRow->file_id;
                    }
                }
            }

            if (!$fileId) {
                fatalError(DetailedLogHelper::translate('plugins.generic.detailedLog.fileNotFound', [], 'File identifier not specified or invalid.'));
            }

            // Anonymization protection for author users
            if ($this->_isCurrentUserAssignedAuthor && $submissionFileId) {
                $subFile = Repo::submissionFile()->get($submissionFileId);
                if ($subFile && $subFile->getData('fileStage') === SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT) {
                    if ($subFile->getData('assocType') === Application::ASSOC_TYPE_REVIEW_ASSIGNMENT) {
                        $reviewAssignment = Repo::reviewAssignment()->get($subFile->getData('assocId'));
                        if ($reviewAssignment && in_array($reviewAssignment->getReviewMethod(), [
                            ReviewAssignment::SUBMISSION_REVIEW_METHOD_ANONYMOUS,
                            ReviewAssignment::SUBMISSION_REVIEW_METHOD_DOUBLEANONYMOUS
                        ])) {
                            fatalError(DetailedLogHelper::translate('plugins.generic.detailedLog.fileAccessDeniedAnonymized', [], 'Access to this file is restricted to preserve reviewer anonymity.'));
                        }
                    }
                }
            }

            // Verify submission ownership if submissionFileId is known
            if ($submissionFileId && $submissionId) {
                $subFile = Repo::submissionFile()->get($submissionFileId);
                if ($subFile && $subFile->getData('submissionId') != $submissionId) {
                    fatalError(DetailedLogHelper::translate('plugins.generic.detailedLog.fileAccessDenied', [], 'Access denied: File does not belong to this submission.'));
                }
            }

            $fileService = app()->get('file');
            $fileRecord = $fileService->get($fileId);
            if (!$fileRecord) {
                fatalError(DetailedLogHelper::translate('plugins.generic.detailedLog.fileNotFoundDb', [], 'The requested file record was not found in the database.'));
            }

            if (!$fileService->fs->has($fileRecord->path)) {
                fatalError(DetailedLogHelper::translate('plugins.generic.detailedLog.fileNotFoundDisk', [], 'The requested file was found in the database records, but the physical file is missing from the server files directory.'));
            }

            if (is_array($filename)) {
                $filename = current($filename);
            }
            $filename = $filename ? (string) $filename : basename($fileRecord->path);
            $filename = $fileService->formatFilename($fileRecord->path, $filename);

            $fileService->download((int) $fileId, $filename);
        } catch (\Throwable $e) {
            DetailedLogHelper::logError('Error downloading file in DetailedSubmissionEventLogGridHandler', $e);
            fatalError('Unable to download requested file.');
        }
    }
}
