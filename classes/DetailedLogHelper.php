<?php

/**
 * @file plugins/generic/detailedLog/classes/DetailedLogHelper.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class DetailedLogHelper
 * @ingroup plugins_generic_detailedLog
 *
 * @brief Helper class for decoding, formatting, and presenting complete event log
 *        entries and event_log_settings metadata for OJS 3.5 submissions.
 */

namespace APP\plugins\generic\detailedLog\classes;

use APP\core\Application;
use APP\facades\Repo;
use Illuminate\Support\Facades\DB;
use PKP\core\PKPApplication;
use PKP\facades\Locale;
use PKP\log\EmailLogEntry;
use PKP\log\event\EventLogEntry;
use PKP\log\event\PKPSubmissionEventLogEntry;
use PKP\log\event\SubmissionFileEventLogEntry;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submissionFile\SubmissionFile;

class DetailedLogHelper
{
    /**
     * Cache for user objects to avoid redundant DB queries
     */
    protected static array $userCache = [];

    /**
     * Cache for settings per log_id
     */
    protected static array $settingsCache = [];

    /**
     * Safe translation helper with guaranteed fallback string
     */
    public static function translate(string $key, array $params = [], string $fallback = ''): string
    {
        try {
            $trans = __($key, $params);
            if (!empty($trans) && !str_starts_with($trans, '##') && !str_ends_with($trans, '##') && $trans !== $key) {
                return $trans;
            }
        } catch (\Throwable $e) {
            // fallback
        }

        if ($fallback !== '') {
            foreach ($params as $k => $v) {
                $fallback = str_replace("{\${$k}}", (string)$v, $fallback);
            }
            return $fallback;
        }

        return $key;
    }

    /**
     * Map of known event messages to human-friendly category and icons
     */
    public static function getEventCategory(EventLogEntry|EmailLogEntry $entry): array
    {
        if ($entry instanceof EmailLogEntry) {
            return [
                'category' => 'email',
                'name' => self::translate('plugins.generic.detailedLog.category.email', [], 'Emails & Notices'),
                'icon' => '✉️',
                'badgeClass' => 'badge-email',
            ];
        }

        $message = $entry->getMessage();
        $eventType = $entry->getEventType();

        if (str_starts_with($message, 'Database Activity:') || str_contains($message, 'Database Activity') || ($eventType >= 0x70000001 && $eventType <= 0x70000003)) {
            return [
                'category' => 'database',
                'name' => self::translate('plugins.generic.detailedLog.category.database', [], 'Database Activity'),
                'icon' => '🗄️',
                'badgeClass' => 'badge-database',
            ];
        }

        if (str_starts_with($message, 'log.editor.decision') || str_starts_with($message, 'log.editor.recommendation')) {
            return [
                'category' => 'decision',
                'name' => self::translate('plugins.generic.detailedLog.category.decision', [], 'Editorial Decisions'),
                'icon' => '⚖️',
                'badgeClass' => 'badge-decision',
            ];
        }

        if (str_starts_with($message, 'log.review.') || str_contains($message, 'Reviewer Assigned') || str_contains($message, 'Review Assignment')) {
            return [
                'category' => 'review',
                'name' => self::translate('plugins.generic.detailedLog.category.review', [], 'Peer Review'),
                'icon' => '🔍',
                'badgeClass' => 'badge-review',
            ];
        }

        if (str_starts_with($message, 'submission.event.file') || str_starts_with($message, 'submission.event.revision') || str_starts_with($message, 'File Added') || str_starts_with($message, 'File Modified') || str_starts_with($message, 'File Deleted')) {
            return [
                'category' => 'file',
                'name' => self::translate('plugins.generic.detailedLog.category.file', [], 'File Activities'),
                'icon' => '📁',
                'badgeClass' => 'badge-file',
            ];
        }

        if (str_starts_with($message, 'submission.event.participant') || str_starts_with($message, 'Participant Assigned') || str_starts_with($message, 'Participant Removed')) {
            return [
                'category' => 'participant',
                'name' => self::translate('plugins.generic.detailedLog.category.participant', [], 'Participants'),
                'icon' => '👥',
                'badgeClass' => 'badge-participant',
            ];
        }

        if (str_starts_with($message, 'publication.event.') || str_starts_with($message, 'Publication Metadata') || str_starts_with($message, 'Publication Published') || str_starts_with($message, 'Publication Unpublished') || str_starts_with($message, 'New Publication Version')) {
            return [
                'category' => 'publication',
                'name' => self::translate('plugins.generic.detailedLog.category.publication', [], 'Publications'),
                'icon' => '📢',
                'badgeClass' => 'badge-publication',
            ];
        }

        if (str_starts_with($message, 'informationCenter.') || str_starts_with($message, 'Discussion') || str_contains($message, 'Discussion Note')) {
            return [
                'category' => 'communication',
                'name' => self::translate('plugins.generic.detailedLog.category.communication', [], 'Discussions'),
                'icon' => '💬',
                'badgeClass' => 'badge-communication',
            ];
        }

        if (str_contains($message, 'metadata') || str_starts_with($message, 'Contributor')) {
            return [
                'category' => 'metadata',
                'name' => self::translate('plugins.generic.detailedLog.category.metadata', [], 'Metadata & Contributors'),
                'icon' => '📝',
                'badgeClass' => 'badge-metadata',
            ];
        }

        return [
            'category' => 'general',
            'name' => self::translate('plugins.generic.detailedLog.category.general', [], 'General'),
            'icon' => '📌',
            'badgeClass' => 'badge-general',
        ];
    }

    /**
     * Map fileStage numeric code to readable description
     */
    public static function formatFileStage(?int $fileStage): string
    {
        if ($fileStage === null) {
            return '';
        }

        return match ($fileStage) {
            2 => self::translate('plugins.generic.detailedLog.fileStage.submission', [], 'Submission File'),
            3 => self::translate('plugins.generic.detailedLog.fileStage.note', [], 'Note Attachment'),
            4 => self::translate('plugins.generic.detailedLog.fileStage.reviewFile', [], 'Review File'),
            5 => self::translate('plugins.generic.detailedLog.fileStage.reviewAttachment', [], 'Review Attachment'),
            6 => self::translate('plugins.generic.detailedLog.fileStage.finalDraft', [], 'Final Draft'),
            9 => self::translate('plugins.generic.detailedLog.fileStage.copyedit', [], 'Copyedited File'),
            10 => self::translate('plugins.generic.detailedLog.fileStage.proof', [], 'Proof / Galley'),
            11 => self::translate('plugins.generic.detailedLog.fileStage.productionReady', [], 'Production Ready'),
            13 => self::translate('plugins.generic.detailedLog.fileStage.attachment', [], 'Attachment'),
            15 => self::translate('plugins.generic.detailedLog.fileStage.reviewRevision', [], 'Review Revision'),
            17 => self::translate('plugins.generic.detailedLog.fileStage.dependent', [], 'Dependent File'),
            18 => self::translate('plugins.generic.detailedLog.fileStage.query', [], 'Discussion File'),
            19 => self::translate('plugins.generic.detailedLog.fileStage.internalReviewFile', [], 'Internal Review File'),
            20 => self::translate('plugins.generic.detailedLog.fileStage.internalReviewRevision', [], 'Internal Review Revision'),
            21 => 'JATS XML',
            default => "Stage {$fileStage}",
        };
    }

    /**
     * Map stageId to readable stage name
     */
    public static function formatStageId(?int $stageId): string
    {
        if (!$stageId) {
            return '';
        }

        return match ($stageId) {
            1 => self::translate('workflow.stage.submission', [], 'Submission'),
            2 => self::translate('workflow.stage.internalReview', [], 'Internal Review'),
            3 => self::translate('workflow.stage.externalReview', [], 'External Review'),
            4 => self::translate('workflow.stage.copyediting', [], 'Copyediting'),
            5 => self::translate('workflow.stage.production', [], 'Production'),
            default => "Stage {$stageId}",
        };
    }

    /**
     * Map decision string or constant to human-friendly styled array
     */
    public static function formatDecision(?string $decision): array
    {
        if (empty($decision)) {
            return ['text' => '', 'badge' => ''];
        }

        $lower = strtolower($decision);
        if (str_contains($lower, 'accept')) {
            $badge = 'badge-success';
        } elseif (str_contains($lower, 'decline') || str_contains($lower, 'reject')) {
            $badge = 'badge-danger';
        } elseif (str_contains($lower, 'revision') || str_contains($lower, 'resubmit')) {
            $badge = 'badge-warning';
        } elseif (str_contains($lower, 'review')) {
            $badge = 'badge-primary';
        } elseif (str_contains($lower, 'production')) {
            $badge = 'badge-info';
        } else {
            $badge = 'badge-secondary';
        }

        return [
            'text' => $decision,
            'badge' => $badge,
        ];
    }

    /**
     * Fetch all raw rows from event_log_settings for a given log_id
     */
    public static function getRawSettings(int $logId): array
    {
        if (isset(self::$settingsCache[$logId])) {
            return self::$settingsCache[$logId];
        }

        $rows = DB::table('event_log_settings')
            ->where('log_id', $logId)
            ->orderBy('setting_name')
            ->get();

        $settings = [];
        foreach ($rows as $row) {
            $settings[] = [
                'id' => $row->event_log_setting_id,
                'log_id' => $row->log_id,
                'locale' => $row->locale,
                'name' => $row->setting_name,
                'value' => $row->setting_value,
            ];
        }

        self::$settingsCache[$logId] = $settings;
        return $settings;
    }

    /**
     * Preload settings for an array of log_ids to prevent N+1 queries
     */
    public static function preloadSettings(array $logIds): void
    {
        if (empty($logIds)) {
            return;
        }

        $missingIds = array_diff($logIds, array_keys(self::$settingsCache));
        if (empty($missingIds)) {
            return;
        }

        $rows = DB::table('event_log_settings')
            ->whereIn('log_id', $missingIds)
            ->orderBy('setting_name')
            ->get();

        foreach ($missingIds as $id) {
            self::$settingsCache[$id] = [];
        }

        foreach ($rows as $row) {
            self::$settingsCache[$row->log_id][] = [
                'id' => $row->event_log_setting_id,
                'log_id' => $row->log_id,
                'locale' => $row->locale,
                'name' => $row->setting_name,
                'value' => $row->setting_value,
            ];
        }
    }

    /**
     * Format a setting into label, display value, and human-friendly interpretation
     */
    public static function interpretSetting(string $name, ?string $value, ?string $locale = null): array
    {
        $defaultLabels = [
            'fileId' => 'File ID',
            'filename' => 'File Name',
            'fileStage' => 'File Stage',
            'submissionFileId' => 'Submission File ID',
            'sourceSubmissionFileId' => 'Source Submission File ID',
            'decision' => 'Editorial Decision',
            'editorName' => 'Editor Name',
            'editorId' => 'Editor ID',
            'reviewerName' => 'Reviewer Name',
            'reviewAssignmentId' => 'Review Assignment ID',
            'round' => 'Review Round',
            'reviewDueDate' => 'Review Due Date',
            'stageId' => 'Workflow Stage ID',
            'userFullName' => 'User Full Name',
            'userGroupName' => 'User Role / Group',
            'username' => 'Username',
            'userId' => 'User ID',
            'submissionId' => 'Submission ID',
            'subject' => 'Subject',
            'senderName' => 'Sender Name',
            'senderId' => 'Sender ID',
            'recipientName' => 'Recipient Name',
            'recipientId' => 'Recipient ID',
            'recipientCount' => 'Recipient Count',
            'copyrightNotice' => 'Copyright Notice',
            'tableName' => 'Database Table',
            'operation' => 'Database Operation',
            'recordId' => 'Record ID',
            'realIp' => 'Real IP Address',
            'ipAddress' => 'Client IP Address',
            'requestBody' => 'Request Body (Payload)',
            'requestMethod' => 'HTTP Method',
            'requestUrl' => 'Request URL',
            'authorName' => 'Contributor Name',
            'authorId' => 'Contributor ID',
            'assignedUserName' => 'Assigned User',
            'assignedUserId' => 'Assigned User ID',
            'publicationId' => 'Publication ID',
            'noteTitle' => 'Discussion Subject',
            'noteAuthor' => 'Posted By',
            'noteExcerpt' => 'Message Preview',
        ];

        $label = isset($defaultLabels[$name])
            ? self::translate("plugins.generic.detailedLog.setting.{$name}", [], $defaultLabels[$name])
            : ucwords(preg_replace('/(?<!\ )[A-Z]/', ' $0', $name));

        $interpreted = $value;
        $category = 'general';

        if (str_starts_with($name, 'field:')) {
            $fieldName = substr($name, 6);
            $label = 'Field: ' . ucwords(str_replace('_', ' ', $fieldName));
            $category = 'database';
        } elseif (str_starts_with($name, 'previous:')) {
            $fieldName = substr($name, 9);
            $label = 'Previous: ' . ucwords(str_replace('_', ' ', $fieldName));
            $category = 'database';
        } elseif (str_starts_with($name, 'where:')) {
            $fieldName = substr($name, 6);
            $label = 'Condition: ' . ucwords(str_replace('_', ' ', $fieldName));
            $category = 'database';
        } else {
            switch ($name) {
                case 'tableName':
                case 'operation':
                case 'recordId':
                case 'requestMethod':
                case 'requestUrl':
                    $category = 'database';
                    break;
                case 'requestBody':
                    $category = 'communication';
                    break;
                case 'fileStage':
                    $interpreted = self::formatFileStage((int)$value) . " (Code {$value})";
                    $category = 'file';
                    break;
                case 'stageId':
                    $interpreted = self::formatStageId((int)$value) . " (Stage {$value})";
                    $category = 'workflow';
                    break;
                case 'round':
                    $interpreted = self::translate('submission.round', ['round' => $value], "Round {$value}");
                    $category = 'review';
                    break;
                case 'filename':
                case 'fileId':
                case 'submissionFileId':
                case 'sourceSubmissionFileId':
                    $category = 'file';
                    break;
                case 'decision':
                case 'editorName':
                case 'editorId':
                    $category = 'decision';
                    break;
                case 'reviewerName':
                case 'reviewAssignmentId':
                case 'reviewDueDate':
                    $category = 'review';
                    break;
                case 'userFullName':
                case 'userGroupName':
                case 'username':
                case 'userId':
                case 'realIp':
                case 'ipAddress':
                    $category = 'user';
                    break;
                case 'authorName':
                case 'authorId':
                    $category = 'metadata';
                    break;
                case 'assignedUserName':
                case 'assignedUserId':
                    $category = 'participant';
                    break;
                case 'subject':
                case 'senderName':
                case 'recipientName':
                case 'noteTitle':
                case 'noteAuthor':
                case 'noteExcerpt':
                    $category = 'communication';
                    break;
            }
        }

        return [
            'name' => $name,
            'label' => $label,
            'raw_value' => $value,
            'interpreted' => $interpreted,
            'locale' => $locale ?: '-',
            'category' => $category,
        ];
    }

    /**
     * Get all event IDs related to a submission from event_log and event_log_settings
     */
    public static function getAllSubmissionLogIds(int $submissionId): array
    {
        // 1. Direct events on the submission
        $directIds = DB::table('event_log')
            ->where('assoc_type', PKPApplication::ASSOC_TYPE_SUBMISSION)
            ->where('assoc_id', $submissionId)
            ->pluck('log_id')
            ->toArray();

        // 2. Events where submissionId is stored in event_log_settings
        $settingIds = DB::table('event_log_settings')
            ->where('setting_name', 'submissionId')
            ->where('setting_value', (string)$submissionId)
            ->pluck('log_id')
            ->toArray();

        // 3. Events associated with submission files of this submission
        $submissionFileIds = DB::table('submission_files')
            ->where('submission_id', $submissionId)
            ->pluck('submission_file_id')
            ->toArray();

        $fileEventIds = [];
        if (!empty($submissionFileIds)) {
            $fileEventIds = DB::table('event_log')
                ->where('assoc_type', PKPApplication::ASSOC_TYPE_SUBMISSION_FILE)
                ->whereIn('assoc_id', $submissionFileIds)
                ->pluck('log_id')
                ->toArray();
        }

        $allIds = array_unique(array_merge($directIds, $settingIds, $fileEventIds));
        rsort($allIds);
        return $allIds;
    }

    /**
     * Build rich structured highlights of an event entry
     */
    public static function getStructuredHighlights(EventLogEntry|EmailLogEntry $entry, bool $isCurrentUserAuthor = false): array
    {
        $highlights = [
            'files' => [],
            'decision' => null,
            'review' => null,
            'participant' => null,
            'communication' => null,
            'workflowStage' => null,
            'other' => [],
        ];

        if ($entry instanceof EmailLogEntry) {
            $senderInfo = self::getEmailSenderInfo($entry);
            $highlights['communication'] = [
                'subject' => $entry->subject,
                'sender' => $senderInfo['name'] . ($senderInfo['email'] ? " <{$senderInfo['email']}>" : ''),
                'recipients' => is_array($entry->recipients) ? implode(', ', $entry->recipients) : (string)$entry->recipients,
            ];
            return $highlights;
        }

        $rawSettings = self::getRawSettings($entry->getId());
        $settingsMap = [];
        foreach ($rawSettings as $s) {
            $settingsMap[$s['name']] = $s['value'];
        }
        $data = array_merge($entry->getAllData(), $settingsMap);

        // Check for stageId
        if (!empty($data['stageId'])) {
            $highlights['workflowStage'] = self::formatStageId((int)$data['stageId']);
        }

        // Check for file details
        $hasFile = !empty($data['filename']) || !empty($data['fileId']) || !empty($data['submissionFileId']);
        if ($hasFile) {
            $filename = '';
            if (is_array($data['filename'] ?? null)) {
                $filename = current($data['filename']);
            } elseif (!empty($data['filename'])) {
                $filename = (string)$data['filename'];
            }

            $fileStage = isset($data['fileStage']) ? (int)$data['fileStage'] : null;

            // Anonymize if review attachment and author viewing
            $isAnonymized = false;
            if ($isCurrentUserAuthor && $fileStage === SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT) {
                $isAnonymized = true;
                $filename = self::translate('plugins.generic.detailedLog.anonymizedFile', [], 'Anonymized Review Attachment');
            }

            $highlights['files'] = [
                'filename' => $filename,
                'fileId' => $data['fileId'] ?? null,
                'submissionFileId' => $data['submissionFileId'] ?? null,
                'sourceFileId' => $data['sourceSubmissionFileId'] ?? null,
                'fileStage' => $fileStage,
                'fileStageLabel' => self::formatFileStage($fileStage),
                'isAnonymized' => $isAnonymized,
            ];

            if (!$highlights['workflowStage'] && $fileStage) {
                $highlights['workflowStage'] = self::formatFileStage($fileStage);
            }
        }

        // Check for editorial decision details
        if (!empty($data['decision']) || !empty($data['editorName'])) {
            $highlights['decision'] = [
                'decision' => $data['decision'] ?? '',
                'editorName' => $data['editorName'] ?? '',
                'editorId' => $data['editorId'] ?? null,
            ];
        }

        // Check for peer review details
        if (!empty($data['reviewerName']) || !empty($data['reviewAssignmentId']) || !empty($data['round'])) {
            $reviewerName = $data['reviewerName'] ?? '';
            if ($isCurrentUserAuthor) {
                $reviewerName = self::translate('editor.review.anonymousReviewer', [], 'Anonymous Reviewer');
                if ($reviewAssignmentId = ($data['reviewAssignmentId'] ?? null)) {
                    $reviewAssignment = Repo::reviewAssignment()->get($reviewAssignmentId);
                    if ($reviewAssignment && $reviewAssignment->getReviewMethod() === ReviewAssignment::SUBMISSION_REVIEW_METHOD_OPEN) {
                        $reviewerName = $data['reviewerName'] ?? '';
                    }
                }
            }

            $highlights['review'] = [
                'reviewerName' => $reviewerName,
                'reviewAssignmentId' => $data['reviewAssignmentId'] ?? null,
                'round' => $data['round'] ?? null,
                'reviewDueDate' => $data['reviewDueDate'] ?? null,
            ];
        }

        // Check for participant details
        if (!empty($data['assignedUserName']) || !empty($data['userGroupName']) || (!empty($data['userFullName']) && str_contains($entry->getMessage(), 'participant'))) {
            $userGroupName = $data['userGroupName'] ?? '';
            if (is_array($userGroupName)) {
                $userGroupName = current($userGroupName);
            }

            $highlights['participant'] = [
                'fullName' => $data['assignedUserName'] ?? $data['userFullName'] ?? '',
                'username' => $data['username'] ?? '',
                'userGroup' => $userGroupName,
            ];
        }

        // Check for contributor details
        if (!empty($data['authorName'])) {
            $highlights['contributor'] = [
                'authorName' => $data['authorName'],
                'email' => $data['email'] ?? '',
                'authorId' => $data['authorId'] ?? null,
            ];
        }

        // Check for discussion / note details
        if (!empty($data['noteAuthor']) || !empty($data['noteTitle']) || !empty($data['queryId'])) {
            $highlights['discussion'] = [
                'author' => $data['noteAuthor'] ?? '',
                'title' => $data['noteTitle'] ?? '',
                'excerpt' => $data['noteExcerpt'] ?? '',
                'queryId' => $data['queryId'] ?? null,
            ];
        }

        // Check for database activity details
        if (!empty($data['tableName']) || !empty($data['operation'])) {
            $rawSettings = self::getRawSettings($entry->getId());
            $fields = [];
            foreach ($rawSettings as $s) {
                if (str_starts_with($s['name'], 'field:')) {
                    $fields[substr($s['name'], 6)] = $s['value'];
                }
            }
            $highlights['database'] = [
                'tableName' => $data['tableName'] ?? '',
                'operation' => $data['operation'] ?? '',
                'recordId' => $data['recordId'] ?? null,
                'fields' => $fields,
            ];
        }

        // Check for communication / email
        if (!empty($data['subject']) || !empty($data['recipientName'])) {
            $highlights['communication'] = [
                'subject' => $data['subject'] ?? '',
                'sender' => $data['senderName'] ?? '',
                'recipient' => $data['recipientName'] ?? '',
                'recipientCount' => $data['recipientCount'] ?? null,
            ];
        }

        // Check for real IP
        if (!empty($data['realIp'])) {
            $highlights['realIp'] = $data['realIp'];
        }

        // Check for request body
        if (!empty($data['requestBody'])) {
            $highlights['requestBody'] = $data['requestBody'];
        }

        return $highlights;
    }

    /**
     * Get user details with caching
     */
    public static function getUserDetails(?int $userId): ?array
    {
        if (!$userId) {
            return null;
        }

        if (isset(self::$userCache[$userId])) {
            return self::$userCache[$userId];
        }

        try {
            $user = Repo::user()->get($userId);
            if ($user) {
                self::$userCache[$userId] = [
                    'id' => $user->getId(),
                    'fullName' => $user->getFullName(),
                    'username' => $user->getUsername(),
                    'email' => $user->getEmail(),
                ];
                return self::$userCache[$userId];
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }

    /**
     * Safely get the sender full name and email for an EmailLogEntry without triggering
     * core PKP's bug where senderId is null.
     */
    public static function getEmailSenderInfo(EmailLogEntry $entry): array
    {
        $name = '';
        $email = '';

        if (!empty($entry->senderId)) {
            try {
                $sender = Repo::user()->get((int)$entry->senderId, true);
                if ($sender) {
                    $name = $sender->getFullName();
                    $email = $sender->getEmail();
                }
            } catch (\Throwable $e) {
            }
        }

        if (empty($name)) {
            $name = $entry->from ?: self::translate('plugins.generic.detailedLog.systemUser', [], 'System / Automated');
        }

        if (empty($email) && !empty($entry->fromAddress)) {
            $email = $entry->fromAddress;
        }

        return [
            'name' => $name,
            'email' => $email,
        ];
    }

    /**
     * Resolve file info (fileId, submissionFileId, filename, fileStage) for an event log entry.
     * Returns null if the entry does not represent a file or access is restricted.
     */
    public static function getFileInfoForLogEntry(EventLogEntry|EmailLogEntry $entry, bool $isCurrentUserAuthor = false): ?array
    {
        if ($entry instanceof EmailLogEntry) {
            return null;
        }

        $rawSettings = self::getRawSettings($entry->getId());
        $settingsMap = [];
        foreach ($rawSettings as $s) {
            $settingsMap[$s['name']] = $s['value'];
        }
        $data = array_merge($entry->getAllData(), $settingsMap);

        $fileId = !empty($data['fileId']) ? (int)$data['fileId'] : null;
        $submissionFileId = !empty($data['submissionFileId']) ? (int)$data['submissionFileId'] : null;
        if (!$submissionFileId && $entry->getAssocType() == Application::ASSOC_TYPE_SUBMISSION_FILE) {
            $submissionFileId = (int)$entry->getAssocId();
        }

        $filename = '';
        if (!empty($data['filename'])) {
            $filename = is_array($data['filename']) ? current($data['filename']) : (string)$data['filename'];
        }

        $fileStage = isset($data['fileStage']) ? (int)$data['fileStage'] : null;

        // If neither fileId nor submissionFileId is present, this entry is not a file operation
        if (!$fileId && !$submissionFileId) {
            return null;
        }

        // Anonymization protection: do not reveal anonymous review attachments to authors
        if ($isCurrentUserAuthor) {
            if ($fileStage === SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT) {
                return null;
            }
            if ($submissionFileId) {
                $subFile = Repo::submissionFile()->get($submissionFileId);
                if ($subFile && $subFile->getData('fileStage') === SubmissionFile::SUBMISSION_FILE_REVIEW_ATTACHMENT) {
                    return null;
                }
            }
        }

        // If fileId is missing, attempt to resolve via submission_files
        if (!$fileId && $submissionFileId) {
            $subFile = Repo::submissionFile()->get($submissionFileId);
            if ($subFile) {
                $fileId = (int)$subFile->getData('fileId');
                if (!$filename) {
                    $filename = $subFile->getLocalizedData('name');
                }
                if (!$fileStage) {
                    $fileStage = (int)$subFile->getData('fileStage');
                }
            } else {
                $subFileRow = DB::table('submission_files')
                    ->where('submission_file_id', $submissionFileId)
                    ->first();
                if ($subFileRow) {
                    $fileId = (int)$subFileRow->file_id;
                    if (!$fileStage) {
                        $fileStage = (int)$subFileRow->file_stage;
                    }
                }
            }
        }

        if (!$fileId) {
            return null;
        }

        // Verify the file record exists in the files table
        $fileRecord = DB::table('files')
            ->where('file_id', $fileId)
            ->first();
        if (!$fileRecord) {
            return null;
        }

        if (!$filename) {
            $filename = basename($fileRecord->path);
        }

        return [
            'fileId' => $fileId,
            'submissionFileId' => $submissionFileId,
            'filename' => $filename,
            'fileStage' => $fileStage,
            'fileStageLabel' => $fileStage ? self::formatFileStage($fileStage) : '',
            'filePath' => $fileRecord->path,
        ];
    }
}

