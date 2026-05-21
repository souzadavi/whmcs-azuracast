<?php

namespace WHMCS\Module\Server\AzuraCast;

use Illuminate\Database\Eloquent\Model;
use WHMCS\Database\Capsule;

class Service
{
    private const SHORT_NAME_MAX_LENGTH = 63;

    /**
     * @var int Bitrate in Kbps
     */
    private int $maxBitrate;
    private int $maxMounts;
    private int $maxHlsStreams;
    /**
     * @var int Max Storage Space in MB
     */
    private int $mediaStorage, $recordingsStorage, $podcastsStorage;

    private int|string $maxListeners;
    private string $stationName;
    private string $userEmail;
    private string $userFullName;
    private string $password;
    private string $serverType;
    private ?int $cloneSourceStationId;
    private ?string $userLocale;
    private ?bool $userShow24HourTime;
    private array $stationPermissions;
    private Model $model;
    /**
     * Holds IDs staged during provisioning before they are persisted to serviceProperties.
     * Call commitIds() after all API calls succeed to write them to the database.
     * If creation fails mid-way, these are never persisted, keeping DB state clean.
     */
    private array $pendingIds = [];
    private string $userTheme;

    public function __construct(array $params)
    {
        $this->maxBitrate = $params['configoption1'] ?? 0;
        $this->maxMounts = $params['configoption2'] ?? 0;
        $this->maxHlsStreams = $params['configoption3'] ?? 0;
        $this->mediaStorage = (int)($params['configoption4'] ?? 0);
        $this->recordingsStorage = (int)($params['configoption5'] ?? 0);
        $this->podcastsStorage = (int)($params['configoption6'] ?? 0);
        $this->userTheme = trim((string)($params['configoption13'] ?? 'dark'));
        $this->maxListeners = $params['configoption7'] ?? 0;
        // Allowlist: update this list if AzuraCast adds new frontend types in the future.
        $serverType = $params['configoption8'] ?? 'icecast';
        if (!in_array($serverType, ['icecast', 'shoutcast'], true)) {
            throw new \InvalidArgumentException("Invalid Server Type '{$serverType}'. Allowed values: icecast, shoutcast.");
        }
        $this->serverType = $serverType;
        // 0 or empty => no clone. Cast to int first so '' becomes 0.
        $this->cloneSourceStationId = (int)($params['configoption9'] ?? 0) ?: null;
        $this->userLocale = $this->parseUserLocale($params['configoption10'] ?? null);
        $this->userShow24HourTime = $this->parseUserShow24HourTime($params['configoption11'] ?? null);
        $this->stationPermissions = $this->parseStationPermissions($params);
        $this->password = $params['password'];
        $this->model = $params['model'];
        $stationName = trim((string)($params['customfields']['Station Name'] ?? ''));
        if ($stationName === '') {
            $stationName = $this->generateDefaultStationName($params);
        }
        $this->stationName = $stationName;
        $this->userFullName = $params['clientsdetails']['fullname'];
        $this->userEmail = $params['clientsdetails']['email'];
    }

    public function getMaxBitrate(): int
    {
        return $this->maxBitrate;
    }

    public function getMaxMounts(): int
    {
        return $this->maxMounts;
    }

    public function getMaxHlsStreams(): int
    {
        return $this->maxHlsStreams;
    }

    public function getMediaStorage(): string
    {
        return $this->mediaStorage;
    }
    public function getUserTheme(): string
    {
        return $this->userTheme;
    }
    public function getMediaStorageInBytes(): int
    {
        return $this->mediaStorage * 1000000;
    }

    public function getRecordingsStorage(): string
    {
        return $this->recordingsStorage;
    }

    public function getRecordingsStorageInBytes(): int
    {
        return $this->recordingsStorage * 1000000;
    }

    public function getPodcastsStorage(): string
    {
        return $this->podcastsStorage;
    }

    public function getPodcastsStorageInBytes(): int
    {
        return $this->podcastsStorage * 1000000;
    }

    public function getMaxListeners(): int|string
    {
        return $this->maxListeners;
    }

    public function getStationName(): string
    {
        return $this->stationName;
    }

    public function getStationShortName(): string
    {
        return self::normalizeShortName($this->stationName);
    }

    public static function normalizeShortName(string $stationName): string
    {
        $normalized = strtolower(trim($stationName));
        $normalized = str_replace(' ', '_', $normalized);
        $normalized = preg_replace('/_+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');
        $normalized = preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '';

        if ($normalized === '') {
            throw new \InvalidArgumentException('Station short_name is empty after normalization.');
        }

        if (strlen($normalized) <= self::SHORT_NAME_MAX_LENGTH) {
            return $normalized;
        }

        $hashSuffix = substr(md5($normalized), 0, 12);
        $prefixLength = self::SHORT_NAME_MAX_LENGTH - 1 - strlen($hashSuffix);
        $prefix = substr($normalized, 0, $prefixLength);

        return $prefix . '_' . $hashSuffix;
    }

    private function generateDefaultStationName(array $params): string
    {
        $serviceId = (int)($params['serviceid'] ?? 0);
        if ($serviceId > 0) {
            return 'OWH_S' . $serviceId;
        }

        $modelId = (int)($this->model->id ?? 0);
        if ($modelId > 0) {
            return 'OWH_M' . $modelId;
        }

        $userId = (int)($params['clientsdetails']['userid'] ?? 0);
        if ($userId > 0) {
            return 'OWH_U' . $userId;
        }

        $seed = (string)($params['clientsdetails']['email'] ?? 'owh-station');
        return 'OWH_A' . strtoupper(substr(sha1($seed), 0, 10));
    }

    // Keep in sync with ConfigOptions 'User Language' and the azuracast_locale list in AzuraCast.
    // Reference: https://github.com/AzuraCast/AzuraCast/blob/main/config/locales.php
    private const LOCALE_ALLOWLIST = [
        'en_US', 'pt_BR', 'es_ES', 'de_DE', 'fr_FR', 'it_IT', 'nl_NL',
        'pl_PL', 'tr_TR', 'ru_RU', 'ja_JP', 'ko_KR', 'zh_CN',
        'cs_CZ', 'nb_NO', 'el_GR', 'sv_SE', 'uk_UA',
    ];

    /**
     * Maps short aliases (used in the configoption12 CSV field) to AzuraCast permission strings.
     * The special alias 'all' or an empty field grants every permission (backward-compatible).
     */
    private const PERMISSION_ALIAS_MAP = [
        'view'         => 'view station management',
        'reports'      => 'view station reports',
        'logs'         => 'view station logs',
        'profile'      => 'manage station profile',
        'broadcasting' => 'manage station broadcasting',
        'streamers'    => 'manage station streamers',
        'mounts'       => 'manage station mounts',
        'remotes'      => 'manage station remotes',
        'media'        => 'manage station media',
        'delete_media' => 'delete station media',
        'automation'   => 'manage station automation',
        'webhooks'     => 'manage station web hooks',
        'podcasts'     => 'manage station podcasts',
    ];

    private function parseStationPermissions(array $params): array
    {
        $raw = trim((string)($params['configoption12'] ?? ''));

        // Empty value or explicit 'all' → grant every permission.
        // Also acts as the backward-compatible fallback for products created before this field existed.
        if ($raw === '' || strtolower($raw) === 'all') {
            return array_values(self::PERMISSION_ALIAS_MAP);
        }

        $selected = [];
        foreach (array_map('trim', explode(',', $raw)) as $alias) {
            $alias = strtolower($alias);
            if ($alias === 'all') {
                return array_values(self::PERMISSION_ALIAS_MAP);
            }
            if (isset(self::PERMISSION_ALIAS_MAP[$alias])) {
                $selected[] = self::PERMISSION_ALIAS_MAP[$alias];
            }
        }

        // If every supplied alias was invalid, fall back to all permissions.
        return $selected !== [] ? $selected : array_values(self::PERMISSION_ALIAS_MAP);
    }

    public function getStationPermissions(): array
    {
        return $this->stationPermissions;
    }

    private function parseUserLocale(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $locale = trim($value);
        if ($locale === '') {
            return null;
        }

        // WHMCS may pass unexpected values (for example 'on') for unset configoptions
        // on older product configurations. Ignore invalid locale values safely.
        if (!in_array($locale, self::LOCALE_ALLOWLIST, true)) {
            return null;
        }

        return $locale;
    }

    private function parseUserShow24HourTime(mixed $value): ?bool
    {
        if (!is_string($value)) {
            return null;
        }

        $timeDisplay = trim($value);
        if ($timeDisplay === '24') {
            return true;
        }

        if ($timeDisplay === '12') {
            return false;
        }

        return null;
    }

    public function getServerType(): string
    {
        return $this->serverType;
    }

    public function getCloneSourceStationId(): ?int
    {
        return $this->cloneSourceStationId;
    }

    public function getUserLocale(): ?string
    {
        return $this->userLocale;
    }

    public function getUserShow24HourTime(): ?bool
    {
        return $this->userShow24HourTime;
    }

    public function getUserEmail(): string
    {
        return $this->userEmail;
    }

    public function getTechnicalEmail(): string
    {
        return sprintf('svc-%d@local.invalid', (int)$this->model->id);
    }

    public function getUserFullName(): string
    {
        return $this->userFullName;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    /**
     * Maps pendingIds keys to tblmodule_configuration setting_name values (max 16 chars).
     */
    private const PROPERTY_KEY_MAP = [
        'stationId'           => 'stationId',
        'userId'              => 'userId',
        'roleId'              => 'roleId',
        'mediaStorageId'      => 'mediaStorageId',
        'recordingsStorageId' => 'recStorId',
        'podcastsStorageId'   => 'podStorId',
    ];

    private function saveServiceProperty(string $key, int $value): void
    {
        $dbKey     = self::PROPERTY_KEY_MAP[$key] ?? $key;
        $serviceId = (int)$this->model->id;
        $now       = date('Y-m-d H:i:s');

        $exists = Capsule::table('tblmodule_configuration')
            ->where('entity_type', 'hosting')
            ->where('entity_id', $serviceId)
            ->where('setting_name', $dbKey)
            ->exists();

        if ($exists) {
            Capsule::table('tblmodule_configuration')
                ->where('entity_type', 'hosting')
                ->where('entity_id', $serviceId)
                ->where('setting_name', $dbKey)
                ->update(['value' => (string)$value, 'updated_at' => $now]);
        } else {
            Capsule::table('tblmodule_configuration')->insert([
                'entity_type'   => 'hosting',
                'entity_id'     => $serviceId,
                'setting_name'  => $dbKey,
                'friendly_name' => $key,
                'value'         => (string)$value,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }


private function getServiceProperty(string $key): ?int
{
    // 1. Tenta o novo mecanismo (tblmodule_configuration)
    $dbKey = self::PROPERTY_KEY_MAP[$key] ?? $key;
    $value = Capsule::table('tblmodule_configuration')
        ->where('entity_type', 'hosting')
        ->where('entity_id', (int)$this->model->id)
        ->where('setting_name', $dbKey)
        ->value('value');

    if ($value !== null && trim((string)$value) !== '') {
        return (int)$value;
    }

    // 2. Fallback: tenta o serviceProperties original (dados antigos)
    try {
        $spValue = $this->model->serviceProperties->get($key);
        if ($spValue !== null && (string)$spValue !== '') {
            return (int)$spValue;
        }
    } catch (\Throwable $e) {
        // serviceProperties indisponível — retorna null
    }

    return null;
}

    public function setStationId(int $stationId): void
    {
        $this->pendingIds['stationId'] = $stationId;
    }

    public function getStationId(): ?int
    {
        return $this->pendingIds['stationId'] ?? $this->getServiceProperty('stationId');
    }

    public function setUserId(int $userId): void
    {
        $this->pendingIds['userId'] = $userId;
    }

    public function getUserId(): ?int
    {
        return $this->pendingIds['userId'] ?? $this->getServiceProperty('userId');
    }

    public function setRoleId(int $roleId): void
    {
        $this->pendingIds['roleId'] = $roleId;
    }

    public function getRoleId(): ?int
    {
        return $this->pendingIds['roleId'] ?? $this->getServiceProperty('roleId');
    }

    public function setMediaStorageId(int $mediaStorageId): void
    {
        $this->pendingIds['mediaStorageId'] = $mediaStorageId;
    }

    public function getMediaStorageId(): ?int
    {
        return $this->pendingIds['mediaStorageId'] ?? $this->getServiceProperty('mediaStorageId');
    }

    public function setRecordingsStorageId(int $recordingsStorageId): void
    {
        $this->pendingIds['recordingsStorageId'] = $recordingsStorageId;
    }

    public function getRecordingsStorageId(): ?int
    {
        return $this->pendingIds['recordingsStorageId'] ?? $this->getServiceProperty('recordingsStorageId');
    }

    public function setPodcastsStorageId(int $podcastsStorageId): void
    {
        $this->pendingIds['podcastsStorageId'] = $podcastsStorageId;
    }

    public function getPodcastsStorageId(): ?int
    {
        return $this->pendingIds['podcastsStorageId'] ?? $this->getServiceProperty('podcastsStorageId');
    }

    /**
    * Persists all IDs staged via the set*Id() methods to tblmodule_configuration.
     * Call this only after all AzuraCast API calls have succeeded.
     */
    public function commitIds(): void
    {
        foreach ($this->pendingIds as $key => $value) {
            $this->saveServiceProperty($key, $value);
        }
        $this->pendingIds = [];
    }

    public function getModel(): Model
    {
        return $this->model;
    }

}
