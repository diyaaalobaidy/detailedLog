<?php

/**
 * @file plugins/generic/detailedLog/classes/DetailedActivityRecorder.php
 *
 * Copyright (c) 2026
 * Distributed under the GNU GPL v3.
 *
 * @class DetailedActivityRecorder
 * @ingroup plugins_generic_detailedLog
 *
 * @brief Universal audit recorder that intercepts and records all submission-related
 *        activity into event_log and event_log_settings, including low-level database
 *        mutations and high-level domain lifecycle events.
 */

namespace APP\plugins\generic\detailedLog\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\publication\Publication;
use APP\submission\Submission;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PKP\author\Author;
use PKP\core\PKPApplication;
use PKP\note\Note;
use PKP\plugins\Hook;
use PKP\query\Query;
use PKP\query\QueryParticipant;
use PKP\stageAssignment\StageAssignment;
use PKP\submission\reviewAssignment\ReviewAssignment;
use PKP\submissionFile\SubmissionFile;

class DetailedActivityRecorder
{
    /** Custom event type constants */
    public const EVENT_TYPE_DB_INSERT = 0x70000001;
    public const EVENT_TYPE_DB_UPDATE = 0x70000002;
    public const EVENT_TYPE_DB_DELETE = 0x70000003;
    public const EVENT_TYPE_METADATA_CHANGE = 0x70000010;
    public const EVENT_TYPE_PUBLICATION_PUBLISH = 0x70000011;
    public const EVENT_TYPE_PUBLICATION_UNPUBLISH = 0x70000012;
    public const EVENT_TYPE_PUBLICATION_VERSION = 0x70000013;
    public const EVENT_TYPE_AUTHOR_ADD = 0x70000020;
    public const EVENT_TYPE_AUTHOR_EDIT = 0x70000021;
    public const EVENT_TYPE_AUTHOR_DELETE = 0x70000022;
    public const EVENT_TYPE_PARTICIPANT_ASSIGN = 0x70000030;
    public const EVENT_TYPE_PARTICIPANT_REMOVE = 0x70000031;
    public const EVENT_TYPE_REVIEW_ASSIGN = 0x70000040;
    public const EVENT_TYPE_REVIEW_EDIT = 0x70000041;
    public const EVENT_TYPE_REVIEW_DELETE = 0x70000042;
    public const EVENT_TYPE_DISCUSSION_NOTE = 0x70000050;
    public const EVENT_TYPE_DISCUSSION_TOPIC = 0x70000051;
    public const EVENT_TYPE_DISCUSSION_PARTICIPANT = 0x70000052;
    public const EVENT_TYPE_FILE_ADD = 0x70000060;
    public const EVENT_TYPE_FILE_EDIT = 0x70000061;
    public const EVENT_TYPE_FILE_DELETE = 0x70000062;

    /** Reentrancy guard to prevent recursive logging loops */
    private static bool $isLogging = false;

    /** Initialization flag */
    private static bool $isInitialized = false;

    /** In-memory cache for submission ID resolutions */
    private static array $submissionIdCache = [];

    /** Deduplication registry to prevent duplicate entries between domain hooks and DB queries */
    private static array $handledEvents = [];

    /** Tables directly or indirectly belonging to a submission */
    private static array $monitoredTables = [
        'submissions' => true,
        'submission_settings' => true,
        'publications' => true,
        'publication_settings' => true,
        'authors' => true,
        'author_settings' => true,
        'publication_galleys' => true,
        'publication_galley_settings' => true,
        'publication_categories' => true,
        'submission_files' => true,
        'submission_file_settings' => true,
        'submission_file_revisions' => true,
        'stage_assignments' => true,
        'review_assignments' => true,
        'review_assignment_settings' => true,
        'review_rounds' => true,
        'review_files' => true,
        'review_round_files' => true,
        'edit_decisions' => true,
        'queries' => true,
        'query_participants' => true,
        'notes' => true,
        'citations' => true,
        'citation_settings' => true,
        'submission_comments' => true,
    ];

    /**
     * Initialize listeners for database queries, domain hooks, and model events
     */
    public static function init(): void
    {
        if (self::$isInitialized) {
            return;
        }
        self::$isInitialized = true;

        try {
            // 1. Low-level Laravel DB Query listener
            DB::listen(self::handleQuery(...));

            // 2. High-level PKP Repository Domain Hooks
            Hook::add('Publication::edit', self::onPublicationEdit(...));
            Hook::add('Publication::publish::before', self::onPublicationPublish(...));
            Hook::add('Publication::unpublish', self::onPublicationUnpublish(...));
            Hook::add('Publication::version', self::onPublicationVersion(...));

            Hook::add('Author::add', self::onAuthorAdd(...));
            Hook::add('Author::edit', self::onAuthorEdit(...));
            Hook::add('Author::delete::before', self::onAuthorDelete(...));

            Hook::add('Submission::edit', self::onSubmissionEdit(...));

            Hook::add('SubmissionFile::add', self::onSubmissionFileAdd(...));
            Hook::add('SubmissionFile::edit', self::onSubmissionFileEdit(...));
            Hook::add('SubmissionFile::delete::before', self::onSubmissionFileDelete(...));

            Hook::add('ReviewAssignment::add', self::onReviewAssignmentAdd(...));
            Hook::add('ReviewAssignment::edit', self::onReviewAssignmentEdit(...));
            Hook::add('ReviewAssignment::delete::before', self::onReviewAssignmentDelete(...));

            // 3. Eloquent Model Observers (models without standard PKP hooks)
            if (class_exists(StageAssignment::class)) {
                StageAssignment::saved(self::onStageAssignmentSaved(...));
                StageAssignment::deleted(self::onStageAssignmentDeleted(...));
            }

            if (class_exists(Query::class)) {
                Query::saved(self::onQuerySaved(...));
                Query::deleted(self::onQueryDeleted(...));
            }

            if (class_exists(QueryParticipant::class)) {
                QueryParticipant::saved(self::onQueryParticipantSaved(...));
                QueryParticipant::deleted(self::onQueryParticipantDeleted(...));
            }

            if (class_exists(Note::class)) {
                Note::saved(self::onNoteSaved(...));
                Note::deleted(self::onNoteDeleted(...));
            }
        } catch (\Throwable $e) {
            error_log('DetailedLog: Initialization error: ' . $e->getMessage());
        }
    }

    /**
     * Mark an event as handled by high-level domain hook to avoid duplicate DB query log
     */
    public static function markHandled(string $table, string|int $recordId, string $operation): void
    {
        $key = strtolower("{$table}:{$recordId}:{$operation}");
        self::$handledEvents[$key] = microtime(true);
    }

    /**
     * Check whether an event was already handled in the current request
     */
    public static function isHandled(string $table, string|int $recordId, string $operation): bool
    {
        $key = strtolower("{$table}:{$recordId}:{$operation}");
        return isset(self::$handledEvents[$key]);
    }

    /**
     * Write an event and its detailed settings into event_log and event_log_settings
     */
    public static function logEvent(
        int $submissionId,
        string $message,
        int $eventType,
        array $settings = [],
        ?int $userId = null,
        ?int $assocType = null,
        ?int $assocId = null
    ): ?int {
        if (self::$isLogging || !$submissionId) {
            return null;
        }

        self::$isLogging = true;
        try {
            if ($userId === null) {
                try {
                    $user = Application::get()->getRequest()?->getUser();
                    $userId = $user?->getId() ?: null;
                } catch (\Throwable $e) {
                    $userId = null;
                }
            }

            $now = date('Y-m-d H:i:s');
            $targetAssocType = $assocType ?: PKPApplication::ASSOC_TYPE_SUBMISSION;
            $targetAssocId = $assocId ?: $submissionId;

            $logId = DB::table('event_log')->insertGetId([
                'assoc_type' => $targetAssocType,
                'assoc_id' => $targetAssocId,
                'user_id' => $userId,
                'date_logged' => $now,
                'event_type' => $eventType,
                'message' => $message,
                'is_translated' => 1,
            ]);

            // Ensure submissionId is always present in settings for fast querying
            $settings['submissionId'] = (string)$submissionId;

            // Enrich user details
            if ($userId) {
                try {
                    $u = Repo::user()->get((int)$userId);
                    if ($u) {
                        $settings['username'] = $u->getUsername();
                        $settings['userFullName'] = $u->getFullName();
                    }
                } catch (\Throwable $e) {
                }
            }

            // Client IP
            if (!isset($settings['ipAddress']) && !empty($_SERVER['REMOTE_ADDR'])) {
                $settings['ipAddress'] = $_SERVER['REMOTE_ADDR'];
            }

            // Insert settings in batch
            $settingsRows = [];
            foreach ($settings as $key => $val) {
                if ($val === null) {
                    continue;
                }
                if (is_bool($val)) {
                    $val = $val ? '1' : '0';
                } elseif (is_array($val) || is_object($val)) {
                    $val = json_encode($val, JSON_UNESCAPED_UNICODE);
                } else {
                    $val = (string)$val;
                }

                $settingName = substr((string)$key, 0, 255);
                $settingsRows[] = [
                    'log_id' => $logId,
                    'locale' => '',
                    'setting_name' => $settingName,
                    'setting_value' => $val,
                ];
            }

            if (!empty($settingsRows)) {
                DB::table('event_log_settings')->insert($settingsRows);
            }

            return $logId;
        } catch (\Throwable $e) {
            error_log('DetailedLog: Failed to insert event log: ' . $e->getMessage());
            return null;
        } finally {
            self::$isLogging = false;
        }
    }

    /**
     * Intercept and process raw queries executed on database
     */
    public static function handleQuery(QueryExecuted $query): void
    {
        if (self::$isLogging) {
            return;
        }

        $sql = $query->sql;
        $firstWord = strtolower(strtok(ltrim($sql), " \t\n\r"));
        if (!in_array($firstWord, ['insert', 'update', 'delete', 'replace'])) {
            return;
        }

        if (!preg_match('/^\s*(insert\s+into|update|delete\s+from|replace\s+into)\s+[`"]?([a-zA-Z0-9_]+)[`"]?/i', $sql, $matches)) {
            return;
        }

        $parts = explode(' ', trim($matches[1]));
        $operation = strtoupper($parts[0]);
        $table = strtolower($matches[2]);

        if (!isset(self::$monitoredTables[$table])) {
            return;
        }

        // Parse query data and where parameters
        $data = [];
        $whereData = [];
        $bindings = $query->bindings ?? [];

        if ($operation === 'INSERT' || $operation === 'REPLACE') {
            if (preg_match('/\(([`"\w\s,]+)\)\s+values/i', $sql, $colMatches)) {
                $cols = array_map(fn($c) => trim(trim($c), '`"'), explode(',', $colMatches[1]));
                $countCols = count($cols);
                for ($i = 0; $i < $countCols; $i++) {
                    $data[$cols[$i]] = $bindings[$i] ?? null;
                }
            }
        } elseif ($operation === 'UPDATE') {
            if (preg_match('/set\s+(.+?)\s+where\s+(.+)$/is', $sql, $updateMatches)) {
                $setClause = $updateMatches[1];
                $whereClause = $updateMatches[2];
                preg_match_all('/[`"]?([a-zA-Z0-9_]+)[`"]?\s*=\s*\?/i', $setClause, $setCols);
                preg_match_all('/[`"]?([a-zA-Z0-9_]+)[`"]?\s*=\s*\?/i', $whereClause, $whereCols);

                $idx = 0;
                if (!empty($setCols[1])) {
                    foreach ($setCols[1] as $col) {
                        $data[$col] = $bindings[$idx++] ?? null;
                    }
                }
                if (!empty($whereCols[1])) {
                    foreach ($whereCols[1] as $col) {
                        $whereData[$col] = $bindings[$idx++] ?? null;
                    }
                }
            }
        } elseif ($operation === 'DELETE') {
            if (preg_match('/where\s+(.+)$/is', $sql, $deleteMatches)) {
                $whereClause = $deleteMatches[1];
                preg_match_all('/[`"]?([a-zA-Z0-9_]+)[`"]?\s*=\s*\?/i', $whereClause, $whereCols);
                $idx = 0;
                if (!empty($whereCols[1])) {
                    foreach ($whereCols[1] as $col) {
                        $whereData[$col] = $bindings[$idx++] ?? null;
                    }
                }
            }
        }

        // Determine record identifier
        $primaryKey = self::getPrimaryKeyForTable($table);
        $recordId = $whereData[$primaryKey] ?? $data[$primaryKey] ?? null;

        if ($recordId && self::isHandled($table, $recordId, $operation)) {
            // Already recorded with full domain context
            return;
        }

        // Resolve submission ID
        $submissionId = self::resolveSubmissionId($table, $operation, $data, $whereData);
        if (!$submissionId) {
            return;
        }

        // Format human-friendly table and message
        $humanTable = self::formatTableName($table);
        $message = "Database Activity: {$operation} on {$humanTable}";

        $eventType = match ($operation) {
            'INSERT', 'REPLACE' => self::EVENT_TYPE_DB_INSERT,
            'UPDATE' => self::EVENT_TYPE_DB_UPDATE,
            'DELETE' => self::EVENT_TYPE_DB_DELETE,
            default => self::EVENT_TYPE_DB_UPDATE,
        };

        $settings = [
            'tableName' => $table,
            'operation' => $operation,
            'submissionId' => $submissionId,
        ];
        if ($recordId) {
            $settings['recordId'] = (string)$recordId;
        }

        foreach ($data as $k => $v) {
            if (in_array(strtolower($k), ['password', 'secret', 'token', 'key'])) {
                continue;
            }
            $settings["field:{$k}"] = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        foreach ($whereData as $k => $v) {
            $settings["where:{$k}"] = is_scalar($v) ? (string)$v : json_encode($v, JSON_UNESCAPED_UNICODE);
        }

        self::logEvent($submissionId, $message, $eventType, $settings);
    }

    /**
     * Resolve submission ID from table, data, or where conditions
     */
    public static function resolveSubmissionId(string $table, string $operation, array $data, array $where): ?int
    {
        // 1. Direct submission ID in data or where
        if (!empty($data['submission_id'])) {
            return (int)$data['submission_id'];
        }
        if (!empty($where['submission_id'])) {
            return (int)$where['submission_id'];
        }

        // 2. Table-specific lookups
        return match ($table) {
            'submissions', 'submission_settings', 'stage_assignments', 'submission_files',
            'review_assignments', 'review_rounds', 'review_round_files', 'edit_decisions',
            'submission_comments' => (int)($where['submission_id'] ?? $data['submission_id'] ?? 0),

            'publications' => self::lookupSubmissionFromPublications($data, $where),
            'publication_settings', 'publication_categories', 'citations' => self::lookupSubmissionFromPublicationId((int)($where['publication_id'] ?? $data['publication_id'] ?? 0)),
            
            'authors' => self::lookupSubmissionFromAuthor($data, $where),
            'author_settings' => self::lookupSubmissionFromAuthorId((int)($where['author_id'] ?? $data['author_id'] ?? 0)),

            'publication_galleys' => self::lookupSubmissionFromGalley($data, $where),
            'publication_galley_settings' => self::lookupSubmissionFromGalleyId((int)($where['galley_id'] ?? $data['galley_id'] ?? 0)),

            'submission_file_settings', 'submission_file_revisions' => self::lookupSubmissionFromFileId((int)($where['submission_file_id'] ?? $data['submission_file_id'] ?? 0)),

            'review_assignment_settings' => self::lookupSubmissionFromReviewId((int)($where['review_assignment_id'] ?? $data['review_assignment_id'] ?? 0)),
            'review_files' => self::lookupSubmissionFromReviewId((int)($where['review_assignment_id'] ?? $where['review_id'] ?? $data['review_assignment_id'] ?? 0)),

            'queries' => self::lookupSubmissionFromQuery($data, $where),
            'query_participants' => self::lookupSubmissionFromQueryId((int)($where['query_id'] ?? $data['query_id'] ?? 0)),

            'notes' => self::lookupSubmissionFromNote($data, $where),
            'citation_settings' => self::lookupSubmissionFromCitationId((int)($where['citation_id'] ?? $data['citation_id'] ?? 0)),

            default => null,
        } ?: null;
    }

    // --- Domain Hook Listeners ---

    public static function onPublicationEdit(string $hookName, array $params): bool
    {
        $newPublication = $params[0] ?? null;
        $oldPublication = $params[1] ?? null;
        $changes = $params[2] ?? [];

        if (!$newPublication instanceof Publication) {
            return Hook::CONTINUE;
        }

        $submissionId = (int)$newPublication->getData('submissionId');
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('publications', $newPublication->getId(), 'UPDATE');

        $settings = [
            'publicationId' => $newPublication->getId(),
            'version' => $newPublication->getData('version'),
            'status' => $newPublication->getData('status'),
            'submissionId' => $submissionId,
        ];

        $changedFields = [];
        foreach ($changes as $key => $val) {
            if (in_array($key, ['lastModified', 'updated_at', 'seq'])) {
                continue;
            }
            $oldVal = $oldPublication ? $oldPublication->getData($key) : null;
            $settings["field:{$key}"] = is_scalar($val) ? (string)$val : json_encode($val, JSON_UNESCAPED_UNICODE);
            if ($oldVal !== null) {
                $settings["previous:{$key}"] = is_scalar($oldVal) ? (string)$oldVal : json_encode($oldVal, JSON_UNESCAPED_UNICODE);
            }
            $changedFields[] = $key;
        }

        $summary = !empty($changedFields) ? implode(', ', array_slice($changedFields, 0, 5)) : 'metadata';
        $message = "Publication Metadata Updated: {$summary}";

        self::logEvent($submissionId, $message, self::EVENT_TYPE_METADATA_CHANGE, $settings);
        return Hook::CONTINUE;
    }

    public static function onPublicationPublish(string $hookName, array $params): bool
    {
        $newPublication = $params[0] ?? null;
        if (!$newPublication instanceof Publication) {
            return Hook::CONTINUE;
        }

        $submissionId = (int)$newPublication->getData('submissionId');
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('publications', $newPublication->getId(), 'UPDATE');

        $settings = [
            'publicationId' => $newPublication->getId(),
            'version' => $newPublication->getData('version'),
            'datePublished' => $newPublication->getData('datePublished') ?: date('Y-m-d H:i:s'),
            'submissionId' => $submissionId,
        ];

        $message = "Publication Published (Version {$newPublication->getData('version')})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_PUBLICATION_PUBLISH, $settings);
        return Hook::CONTINUE;
    }

    public static function onPublicationUnpublish(string $hookName, array $params): bool
    {
        $publication = $params[0] ?? null;
        if (!$publication instanceof Publication) {
            return Hook::CONTINUE;
        }

        $submissionId = (int)$publication->getData('submissionId');
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('publications', $publication->getId(), 'UPDATE');

        $settings = [
            'publicationId' => $publication->getId(),
            'version' => $publication->getData('version'),
            'submissionId' => $submissionId,
        ];

        $message = "Publication Unpublished (Version {$publication->getData('version')})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_PUBLICATION_UNPUBLISH, $settings);
        return Hook::CONTINUE;
    }

    public static function onPublicationVersion(string $hookName, array $params): bool
    {
        $newPublication = $params[0] ?? null;
        $oldPublication = $params[1] ?? null;

        if (!$newPublication instanceof Publication) {
            return Hook::CONTINUE;
        }

        $submissionId = (int)$newPublication->getData('submissionId');
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('publications', $newPublication->getId(), 'INSERT');

        $settings = [
            'newPublicationId' => $newPublication->getId(),
            'newVersion' => $newPublication->getData('version'),
            'oldPublicationId' => $oldPublication?->getId(),
            'submissionId' => $submissionId,
        ];

        $message = "New Publication Version Created (Version {$newPublication->getData('version')})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_PUBLICATION_VERSION, $settings);
        return Hook::CONTINUE;
    }

    public static function onAuthorAdd(string $hookName, array $params): bool
    {
        $author = $params[0] ?? null;
        if (!$author instanceof Author) {
            return Hook::CONTINUE;
        }

        $pubId = (int)$author->getData('publicationId');
        $submissionId = self::lookupSubmissionFromPublicationId($pubId);
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('authors', $author->getId(), 'INSERT');

        $name = $author->getFullName();
        $settings = [
            'authorId' => $author->getId(),
            'authorName' => $name,
            'email' => $author->getData('email'),
            'userGroupId' => $author->getData('userGroupId'),
            'seq' => $author->getData('seq'),
            'publicationId' => $pubId,
            'submissionId' => $submissionId,
        ];

        $message = "Contributor Added: {$name}";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_AUTHOR_ADD, $settings);
        return Hook::CONTINUE;
    }

    public static function onAuthorEdit(string $hookName, array $params): bool
    {
        $newAuthor = $params[0] ?? null;
        $oldAuthor = $params[1] ?? null;
        $changes = $params[2] ?? [];

        if (!$newAuthor instanceof Author) {
            return Hook::CONTINUE;
        }

        $pubId = (int)$newAuthor->getData('publicationId');
        $submissionId = self::lookupSubmissionFromPublicationId($pubId);
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('authors', $newAuthor->getId(), 'UPDATE');

        $name = $newAuthor->getFullName() ?: ($oldAuthor ? $oldAuthor->getFullName() : "Contributor #{$newAuthor->getId()}");
        $settings = [
            'authorId' => $newAuthor->getId(),
            'authorName' => $name,
            'publicationId' => $pubId,
            'submissionId' => $submissionId,
        ];

        foreach ($changes as $key => $val) {
            $oldVal = $oldAuthor ? $oldAuthor->getData($key) : null;
            $settings["field:{$key}"] = is_scalar($val) ? (string)$val : json_encode($val, JSON_UNESCAPED_UNICODE);
            if ($oldVal !== null) {
                $settings["previous:{$key}"] = is_scalar($oldVal) ? (string)$oldVal : json_encode($oldVal, JSON_UNESCAPED_UNICODE);
            }
        }

        $message = "Contributor Modified: {$name}";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_AUTHOR_EDIT, $settings);
        return Hook::CONTINUE;
    }

    public static function onAuthorDelete(string $hookName, array $params): bool
    {
        $author = $params[0] ?? null;
        if (!$author instanceof Author) {
            return Hook::CONTINUE;
        }

        $pubId = (int)$author->getData('publicationId');
        $submissionId = self::lookupSubmissionFromPublicationId($pubId);
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('authors', $author->getId(), 'DELETE');

        $name = $author->getFullName();
        $settings = [
            'authorId' => $author->getId(),
            'authorName' => $name,
            'email' => $author->getData('email'),
            'publicationId' => $pubId,
            'submissionId' => $submissionId,
        ];

        $message = "Contributor Removed: {$name}";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_AUTHOR_DELETE, $settings);
        return Hook::CONTINUE;
    }

    public static function onSubmissionEdit(string $hookName, array $params): bool
    {
        $newSubmission = $params[0] ?? null;
        $oldSubmission = $params[1] ?? null;
        $changes = $params[2] ?? [];

        if (!$newSubmission instanceof Submission) {
            return Hook::CONTINUE;
        }

        $submissionId = $newSubmission->getId();
        self::markHandled('submissions', $submissionId, 'UPDATE');

        $settings = [
            'submissionId' => $submissionId,
            'status' => $newSubmission->getData('status'),
            'stageId' => $newSubmission->getData('stageId'),
        ];

        $changedKeys = [];
        foreach ($changes as $key => $val) {
            if (in_array($key, ['lastModified', 'updated_at'])) {
                continue;
            }
            $oldVal = $oldSubmission ? $oldSubmission->getData($key) : null;
            $settings["field:{$key}"] = is_scalar($val) ? (string)$val : json_encode($val, JSON_UNESCAPED_UNICODE);
            if ($oldVal !== null) {
                $settings["previous:{$key}"] = is_scalar($oldVal) ? (string)$oldVal : json_encode($oldVal, JSON_UNESCAPED_UNICODE);
            }
            $changedKeys[] = $key;
        }

        if (empty($changedKeys)) {
            return Hook::CONTINUE;
        }

        $summary = implode(', ', array_slice($changedKeys, 0, 5));
        $message = "Submission Record Updated: {$summary}";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_DB_UPDATE, $settings);
        return Hook::CONTINUE;
    }

    public static function onSubmissionFileAdd(string $hookName, array $params): bool
    {
        $file = $params[0] ?? null;
        if (!$file instanceof SubmissionFile) {
            return Hook::CONTINUE;
        }

        $submissionId = (int)$file->getData('submissionId');
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('submission_files', $file->getId(), 'INSERT');

        $filename = $file->getLocalizedData('name') ?: "File #{$file->getId()}";
        $settings = [
            'submissionFileId' => $file->getId(),
            'fileId' => $file->getData('fileId'),
            'filename' => $filename,
            'fileStage' => $file->getData('fileStage'),
            'genreId' => $file->getData('genreId'),
            'submissionId' => $submissionId,
        ];

        $message = "File Added: {$filename}";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_FILE_ADD, $settings);
        return Hook::CONTINUE;
    }

    public static function onSubmissionFileEdit(string $hookName, array $params): bool
    {
        $newFile = $params[0] ?? null;
        $oldFile = $params[1] ?? null;
        $changes = $params[2] ?? [];

        if (!$newFile instanceof SubmissionFile) {
            return Hook::CONTINUE;
        }

        $submissionId = (int)$newFile->getData('submissionId');
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('submission_files', $newFile->getId(), 'UPDATE');

        $filename = $newFile->getLocalizedData('name') ?: "File #{$newFile->getId()}";
        $settings = [
            'submissionFileId' => $newFile->getId(),
            'fileId' => $newFile->getData('fileId'),
            'filename' => $filename,
            'fileStage' => $newFile->getData('fileStage'),
            'submissionId' => $submissionId,
        ];

        foreach ($changes as $key => $val) {
            $oldVal = $oldFile ? $oldFile->getData($key) : null;
            $settings["field:{$key}"] = is_scalar($val) ? (string)$val : json_encode($val, JSON_UNESCAPED_UNICODE);
            if ($oldVal !== null) {
                $settings["previous:{$key}"] = is_scalar($oldVal) ? (string)$oldVal : json_encode($oldVal, JSON_UNESCAPED_UNICODE);
            }
        }

        $message = "File Modified: {$filename}";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_FILE_EDIT, $settings);
        return Hook::CONTINUE;
    }

    public static function onSubmissionFileDelete(string $hookName, array $params): bool
    {
        $file = $params[0] ?? null;
        if (!$file instanceof SubmissionFile) {
            return Hook::CONTINUE;
        }

        $submissionId = (int)$file->getData('submissionId');
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('submission_files', $file->getId(), 'DELETE');

        $filename = $file->getLocalizedData('name') ?: "File #{$file->getId()}";
        $settings = [
            'submissionFileId' => $file->getId(),
            'fileId' => $file->getData('fileId'),
            'filename' => $filename,
            'fileStage' => $file->getData('fileStage'),
            'genreId' => $file->getData('genreId'),
            'submissionId' => $submissionId,
        ];

        $message = "File Deleted: {$filename}";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_FILE_DELETE, $settings);
        return Hook::CONTINUE;
    }

    public static function onReviewAssignmentAdd(string $hookName, array $params): bool
    {
        $reviewAssignment = $params[0] ?? null;
        if (!$reviewAssignment instanceof ReviewAssignment) {
            return Hook::CONTINUE;
        }

        $submissionId = (int)$reviewAssignment->getSubmissionId();
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('review_assignments', $reviewAssignment->getId(), 'INSERT');

        $reviewer = Repo::user()->get((int)$reviewAssignment->getReviewerId());
        $reviewerName = $reviewer ? $reviewer->getFullName() : "Reviewer #{$reviewAssignment->getReviewerId()}";

        $settings = [
            'reviewAssignmentId' => $reviewAssignment->getId(),
            'reviewerId' => $reviewAssignment->getReviewerId(),
            'reviewerName' => $reviewerName,
            'stageId' => $reviewAssignment->getStageId(),
            'round' => $reviewAssignment->getRound(),
            'reviewMethod' => $reviewAssignment->getReviewMethod(),
            'dateDue' => $reviewAssignment->getDateDue(),
            'dateResponseDue' => $reviewAssignment->getDateResponseDue(),
            'submissionId' => $submissionId,
        ];

        $message = "Reviewer Assigned: {$reviewerName} (Round {$reviewAssignment->getRound()})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_REVIEW_ASSIGN, $settings);
        return Hook::CONTINUE;
    }

    public static function onReviewAssignmentEdit(string $hookName, array $params): bool
    {
        $newReview = $params[0] ?? null;
        $oldReview = $params[1] ?? null;
        $changes = $params[2] ?? [];

        if (!$newReview instanceof ReviewAssignment) {
            return Hook::CONTINUE;
        }

        $submissionId = (int)$newReview->getSubmissionId();
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('review_assignments', $newReview->getId(), 'UPDATE');

        $reviewer = Repo::user()->get((int)$newReview->getReviewerId());
        $reviewerName = $reviewer ? $reviewer->getFullName() : "Reviewer #{$newReview->getReviewerId()}";

        $settings = [
            'reviewAssignmentId' => $newReview->getId(),
            'reviewerId' => $newReview->getReviewerId(),
            'reviewerName' => $reviewerName,
            'round' => $newReview->getRound(),
            'submissionId' => $submissionId,
        ];

        foreach ($changes as $key => $val) {
            $oldVal = $oldReview ? $oldReview->getData($key) : null;
            $settings["field:{$key}"] = is_scalar($val) ? (string)$val : json_encode($val, JSON_UNESCAPED_UNICODE);
            if ($oldVal !== null) {
                $settings["previous:{$key}"] = is_scalar($oldVal) ? (string)$oldVal : json_encode($oldVal, JSON_UNESCAPED_UNICODE);
            }
        }

        $message = "Review Assignment Updated: {$reviewerName} (Round {$newReview->getRound()})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_REVIEW_EDIT, $settings);
        return Hook::CONTINUE;
    }

    public static function onReviewAssignmentDelete(string $hookName, array $params): bool
    {
        $reviewAssignment = $params[0] ?? null;
        if (!$reviewAssignment instanceof ReviewAssignment) {
            return Hook::CONTINUE;
        }

        $submissionId = (int)$reviewAssignment->getSubmissionId();
        if (!$submissionId) {
            return Hook::CONTINUE;
        }

        self::markHandled('review_assignments', $reviewAssignment->getId(), 'DELETE');

        $reviewer = Repo::user()->get((int)$reviewAssignment->getReviewerId());
        $reviewerName = $reviewer ? $reviewer->getFullName() : "Reviewer #{$reviewAssignment->getReviewerId()}";

        $settings = [
            'reviewAssignmentId' => $reviewAssignment->getId(),
            'reviewerId' => $reviewAssignment->getReviewerId(),
            'reviewerName' => $reviewerName,
            'round' => $reviewAssignment->getRound(),
            'submissionId' => $submissionId,
        ];

        $message = "Reviewer Removed: {$reviewerName} (Round {$reviewAssignment->getRound()})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_REVIEW_DELETE, $settings);
        return Hook::CONTINUE;
    }

    // --- Eloquent Model Observers ---

    public static function onStageAssignmentSaved(StageAssignment $assignment): void
    {
        $submissionId = (int)$assignment->submissionId;
        if (!$submissionId) {
            return;
        }

        $assignmentId = $assignment->id ?? $assignment->stageAssignmentId ?? '0';
        self::markHandled('stage_assignments', $assignmentId, 'INSERT');
        self::markHandled('stage_assignments', $assignmentId, 'UPDATE');

        $user = Repo::user()->get((int)$assignment->userId);
        $userGroup = Repo::userGroup()->get((int)$assignment->userGroupId);

        $userName = $user ? $user->getFullName() : "User #{$assignment->userId}";
        $roleName = $userGroup ? $userGroup->getLocalizedName() : "Role #{$assignment->userGroupId}";

        $settings = [
            'stageAssignmentId' => $assignmentId,
            'assignedUserId' => $assignment->userId,
            'assignedUserName' => $userName,
            'userGroupId' => $assignment->userGroupId,
            'userGroupName' => $roleName,
            'stageId' => $assignment->stageId,
            'submissionId' => $submissionId,
        ];

        $message = "Participant Assigned: {$userName} ({$roleName})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_PARTICIPANT_ASSIGN, $settings);
    }

    public static function onStageAssignmentDeleted(StageAssignment $assignment): void
    {
        $submissionId = (int)$assignment->submissionId;
        if (!$submissionId) {
            return;
        }

        $assignmentId = $assignment->id ?? $assignment->stageAssignmentId ?? '0';
        self::markHandled('stage_assignments', $assignmentId, 'DELETE');

        $user = Repo::user()->get((int)$assignment->userId);
        $userGroup = Repo::userGroup()->get((int)$assignment->userGroupId);

        $userName = $user ? $user->getFullName() : "User #{$assignment->userId}";
        $roleName = $userGroup ? $userGroup->getLocalizedName() : "Role #{$assignment->userGroupId}";

        $settings = [
            'stageAssignmentId' => $assignmentId,
            'assignedUserId' => $assignment->userId,
            'assignedUserName' => $userName,
            'userGroupId' => $assignment->userGroupId,
            'userGroupName' => $roleName,
            'submissionId' => $submissionId,
        ];

        $message = "Participant Removed: {$userName} ({$roleName})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_PARTICIPANT_REMOVE, $settings);
    }

    public static function onQuerySaved(Query $query): void
    {
        if ((int)$query->assocType !== PKPApplication::ASSOC_TYPE_SUBMISSION) {
            return;
        }
        $submissionId = (int)$query->assocId;
        if (!$submissionId) {
            return;
        }

        $queryId = $query->id ?? $query->queryId ?? '0';
        self::markHandled('queries', $queryId, 'INSERT');
        self::markHandled('queries', $queryId, 'UPDATE');

        $statusStr = $query->closed ? 'Closed' : 'Active';
        $settings = [
            'queryId' => $queryId,
            'stageId' => $query->stageId,
            'closed' => $query->closed ? '1' : '0',
            'submissionId' => $submissionId,
        ];

        $message = "Discussion Topic " . ($query->closed ? "Closed" : "Updated") . " (Query #{$queryId})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_DISCUSSION_TOPIC, $settings);
    }

    public static function onQueryDeleted(Query $query): void
    {
        if ((int)$query->assocType !== PKPApplication::ASSOC_TYPE_SUBMISSION) {
            return;
        }
        $submissionId = (int)$query->assocId;
        if (!$submissionId) {
            return;
        }

        $queryId = $query->id ?? $query->queryId ?? '0';
        self::markHandled('queries', $queryId, 'DELETE');

        $settings = [
            'queryId' => $queryId,
            'submissionId' => $submissionId,
        ];

        $message = "Discussion Topic Deleted (Query #{$queryId})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_DISCUSSION_TOPIC, $settings);
    }

    public static function onQueryParticipantSaved(QueryParticipant $participant): void
    {
        $queryId = (int)$participant->queryId;
        $submissionId = self::lookupSubmissionFromQueryId($queryId);
        if (!$submissionId) {
            return;
        }

        self::markHandled('query_participants', "{$queryId}_{$participant->userId}", 'INSERT');

        $user = Repo::user()->get((int)$participant->userId);
        $userName = $user ? $user->getFullName() : "User #{$participant->userId}";

        $settings = [
            'queryId' => $queryId,
            'participantUserId' => $participant->userId,
            'participantUserName' => $userName,
            'submissionId' => $submissionId,
        ];

        $message = "Participant Added to Discussion: {$userName} (Query #{$queryId})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_DISCUSSION_PARTICIPANT, $settings);
    }

    public static function onQueryParticipantDeleted(QueryParticipant $participant): void
    {
        $queryId = (int)$participant->queryId;
        $submissionId = self::lookupSubmissionFromQueryId($queryId);
        if (!$submissionId) {
            return;
        }

        self::markHandled('query_participants', "{$queryId}_{$participant->userId}", 'DELETE');

        $user = Repo::user()->get((int)$participant->userId);
        $userName = $user ? $user->getFullName() : "User #{$participant->userId}";

        $settings = [
            'queryId' => $queryId,
            'participantUserId' => $participant->userId,
            'participantUserName' => $userName,
            'submissionId' => $submissionId,
        ];

        $message = "Participant Removed from Discussion: {$userName} (Query #{$queryId})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_DISCUSSION_PARTICIPANT, $settings);
    }

    public static function onNoteSaved(Note $note): void
    {
        $assocType = (int)$note->assocType;
        $assocId = (int)$note->assocId;
        $submissionId = null;

        if ($assocType === PKPApplication::ASSOC_TYPE_SUBMISSION) {
            $submissionId = $assocId;
        } elseif ($assocType === PKPApplication::ASSOC_TYPE_QUERY) {
            $submissionId = self::lookupSubmissionFromQueryId($assocId);
        }

        if (!$submissionId) {
            return;
        }

        $noteId = $note->id ?? $note->noteId ?? '0';
        self::markHandled('notes', $noteId, 'INSERT');
        self::markHandled('notes', $noteId, 'UPDATE');

        $user = Repo::user()->get((int)$note->userId);
        $userName = $user ? $user->getFullName() : "User #{$note->userId}";

        $settings = [
            'noteId' => $noteId,
            'noteTitle' => $note->title,
            'noteAuthor' => $userName,
            'assocType' => $assocType,
            'assocId' => $assocId,
            'submissionId' => $submissionId,
        ];
        if (!empty($note->contents)) {
            $settings['noteExcerpt'] = mb_substr(strip_tags($note->contents), 0, 150);
        }

        $titleSuffix = $note->title ? ": {$note->title}" : '';
        $message = "Discussion Note Added by {$userName}{$titleSuffix}";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_DISCUSSION_NOTE, $settings);
    }

    public static function onNoteDeleted(Note $note): void
    {
        $assocType = (int)$note->assocType;
        $assocId = (int)$note->assocId;
        $submissionId = null;

        if ($assocType === PKPApplication::ASSOC_TYPE_SUBMISSION) {
            $submissionId = $assocId;
        } elseif ($assocType === PKPApplication::ASSOC_TYPE_QUERY) {
            $submissionId = self::lookupSubmissionFromQueryId($assocId);
        }

        if (!$submissionId) {
            return;
        }

        $noteId = $note->id ?? $note->noteId ?? '0';
        self::markHandled('notes', $noteId, 'DELETE');

        $settings = [
            'noteId' => $noteId,
            'assocType' => $assocType,
            'assocId' => $assocId,
            'submissionId' => $submissionId,
        ];

        $message = "Discussion Note Deleted (Note #{$noteId})";
        self::logEvent($submissionId, $message, self::EVENT_TYPE_DISCUSSION_NOTE, $settings);
    }

    // --- Fast Lookup & Resolution Helpers ---

    private static function lookupSubmissionFromPublications(array $data, array $where): ?int
    {
        if (!empty($data['submission_id'])) {
            return (int)$data['submission_id'];
        }
        $pubId = (int)($where['publication_id'] ?? $data['publication_id'] ?? 0);
        return $pubId ? self::lookupSubmissionFromPublicationId($pubId) : null;
    }

    public static function lookupSubmissionFromPublicationId(int $publicationId): ?int
    {
        if (!$publicationId) {
            return null;
        }
        if (isset(self::$submissionIdCache['pub_' . $publicationId])) {
            return self::$submissionIdCache['pub_' . $publicationId];
        }

        $subId = DB::table('publications')
            ->where('publication_id', $publicationId)
            ->value('submission_id');

        $subId = $subId ? (int)$subId : null;
        self::$submissionIdCache['pub_' . $publicationId] = $subId;
        return $subId;
    }

    private static function lookupSubmissionFromAuthor(array $data, array $where): ?int
    {
        if (!empty($data['publication_id'])) {
            return self::lookupSubmissionFromPublicationId((int)$data['publication_id']);
        }
        $authorId = (int)($where['author_id'] ?? $data['author_id'] ?? 0);
        return $authorId ? self::lookupSubmissionFromAuthorId($authorId) : null;
    }

    public static function lookupSubmissionFromAuthorId(int $authorId): ?int
    {
        if (!$authorId) {
            return null;
        }
        if (isset(self::$submissionIdCache['auth_' . $authorId])) {
            return self::$submissionIdCache['auth_' . $authorId];
        }

        $subId = DB::table('authors as a')
            ->join('publications as p', 'a.publication_id', '=', 'p.publication_id')
            ->where('a.author_id', $authorId)
            ->value('p.submission_id');

        $subId = $subId ? (int)$subId : null;
        self::$submissionIdCache['auth_' . $authorId] = $subId;
        return $subId;
    }

    private static function lookupSubmissionFromGalley(array $data, array $where): ?int
    {
        if (!empty($data['publication_id'])) {
            return self::lookupSubmissionFromPublicationId((int)$data['publication_id']);
        }
        $galleyId = (int)($where['galley_id'] ?? $data['galley_id'] ?? 0);
        return $galleyId ? self::lookupSubmissionFromGalleyId($galleyId) : null;
    }

    public static function lookupSubmissionFromGalleyId(int $galleyId): ?int
    {
        if (!$galleyId) {
            return null;
        }
        if (isset(self::$submissionIdCache['galley_' . $galleyId])) {
            return self::$submissionIdCache['galley_' . $galleyId];
        }

        $subId = DB::table('publication_galleys as g')
            ->join('publications as p', 'g.publication_id', '=', 'p.publication_id')
            ->where('g.galley_id', $galleyId)
            ->value('p.submission_id');

        $subId = $subId ? (int)$subId : null;
        self::$submissionIdCache['galley_' . $galleyId] = $subId;
        return $subId;
    }

    public static function lookupSubmissionFromFileId(int $submissionFileId): ?int
    {
        if (!$submissionFileId) {
            return null;
        }
        if (isset(self::$submissionIdCache['file_' . $submissionFileId])) {
            return self::$submissionIdCache['file_' . $submissionFileId];
        }

        $subId = DB::table('submission_files')
            ->where('submission_file_id', $submissionFileId)
            ->value('submission_id');

        $subId = $subId ? (int)$subId : null;
        self::$submissionIdCache['file_' . $submissionFileId] = $subId;
        return $subId;
    }

    public static function lookupSubmissionFromReviewId(int $reviewAssignmentId): ?int
    {
        if (!$reviewAssignmentId) {
            return null;
        }
        if (isset(self::$submissionIdCache['review_' . $reviewAssignmentId])) {
            return self::$submissionIdCache['review_' . $reviewAssignmentId];
        }

        $subId = DB::table('review_assignments')
            ->where('review_assignment_id', $reviewAssignmentId)
            ->value('submission_id');

        $subId = $subId ? (int)$subId : null;
        self::$submissionIdCache['review_' . $reviewAssignmentId] = $subId;
        return $subId;
    }

    private static function lookupSubmissionFromQuery(array $data, array $where): ?int
    {
        if (isset($data['assoc_type']) && (int)$data['assoc_type'] === PKPApplication::ASSOC_TYPE_SUBMISSION) {
            return (int)($data['assoc_id'] ?? 0);
        }
        $queryId = (int)($where['query_id'] ?? $data['query_id'] ?? 0);
        return $queryId ? self::lookupSubmissionFromQueryId($queryId) : null;
    }

    public static function lookupSubmissionFromQueryId(int $queryId): ?int
    {
        if (!$queryId) {
            return null;
        }
        if (isset(self::$submissionIdCache['query_' . $queryId])) {
            return self::$submissionIdCache['query_' . $queryId];
        }

        $subId = DB::table('queries')
            ->where('query_id', $queryId)
            ->where('assoc_type', PKPApplication::ASSOC_TYPE_SUBMISSION)
            ->value('assoc_id');

        $subId = $subId ? (int)$subId : null;
        self::$submissionIdCache['query_' . $queryId] = $subId;
        return $subId;
    }

    private static function lookupSubmissionFromNote(array $data, array $where): ?int
    {
        $assocType = (int)($data['assoc_type'] ?? 0);
        $assocId = (int)($data['assoc_id'] ?? 0);

        if ($assocType === PKPApplication::ASSOC_TYPE_SUBMISSION) {
            return $assocId;
        }
        if ($assocType === PKPApplication::ASSOC_TYPE_QUERY) {
            return self::lookupSubmissionFromQueryId($assocId);
        }

        $noteId = (int)($where['note_id'] ?? $data['note_id'] ?? 0);
        if ($noteId) {
            $note = DB::table('notes')->where('note_id', $noteId)->first();
            if ($note) {
                if ((int)$note->assoc_type === PKPApplication::ASSOC_TYPE_SUBMISSION) {
                    return (int)$note->assoc_id;
                }
                if ((int)$note->assoc_type === PKPApplication::ASSOC_TYPE_QUERY) {
                    return self::lookupSubmissionFromQueryId((int)$note->assoc_id);
                }
            }
        }

        return null;
    }

    public static function lookupSubmissionFromCitationId(int $citationId): ?int
    {
        if (!$citationId) {
            return null;
        }
        if (isset(self::$submissionIdCache['citation_' . $citationId])) {
            return self::$submissionIdCache['citation_' . $citationId];
        }

        $subId = DB::table('citations as c')
            ->join('publications as p', 'c.publication_id', '=', 'p.publication_id')
            ->where('c.citation_id', $citationId)
            ->value('p.submission_id');

        $subId = $subId ? (int)$subId : null;
        self::$submissionIdCache['citation_' . $citationId] = $subId;
        return $subId;
    }

    private static function getPrimaryKeyForTable(string $table): string
    {
        return match ($table) {
            'submissions' => 'submission_id',
            'publications' => 'publication_id',
            'authors' => 'author_id',
            'publication_galleys' => 'galley_id',
            'submission_files' => 'submission_file_id',
            'stage_assignments' => 'stage_assignment_id',
            'review_assignments' => 'review_assignment_id',
            'review_rounds' => 'review_round_id',
            'edit_decisions' => 'edit_decision_id',
            'queries' => 'query_id',
            'notes' => 'note_id',
            'citations' => 'citation_id',
            'submission_comments' => 'comment_id',
            default => 'id',
        };
    }

    private static function formatTableName(string $table): string
    {
        return match ($table) {
            'submissions' => 'Submissions',
            'submission_settings' => 'Submission Settings',
            'publications' => 'Publications',
            'publication_settings' => 'Publication Settings',
            'authors' => 'Contributors / Authors',
            'author_settings' => 'Contributor Settings',
            'publication_galleys' => 'Publication Galleys',
            'publication_galley_settings' => 'Galley Settings',
            'publication_categories' => 'Publication Categories',
            'submission_files' => 'Submission Files',
            'submission_file_settings' => 'File Settings',
            'submission_file_revisions' => 'File Revisions',
            'stage_assignments' => 'Stage Participants',
            'review_assignments' => 'Review Assignments',
            'review_assignment_settings' => 'Review Settings',
            'review_rounds' => 'Review Rounds',
            'review_files' => 'Review Files',
            'review_round_files' => 'Review Round Files',
            'edit_decisions' => 'Editorial Decisions',
            'queries' => 'Discussions / Queries',
            'query_participants' => 'Discussion Participants',
            'notes' => 'Discussion Notes',
            'citations' => 'Citations',
            'citation_settings' => 'Citation Settings',
            'submission_comments' => 'Submission Comments',
            default => $table,
        };
    }
}
