<?php
/**
 * WHMCS Azuracast Provisoioning Module
 * This module allows you to provision AzuraCast instances from WHMCS
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Module\Server\AzuraCast\Client;
use WHMCS\Module\Server\AzuraCast\Dto\RoleDto;
use WHMCS\Module\Server\AzuraCast\Service;
use Illuminate\Database\Capsule\Manager as Capsule;
/**
 * Define module related meta data.
 *
 * @see https://developers.whmcs.com/provisioning-modules/meta-data-params/
 *
 * @return array
 */
function azuracast_MetaData()
{
    return array(
        'DisplayName' => 'AzuraCast',
        'APIVersion' => '1.1',
        'RequiresServer' => true,
        'DefaultNonSSLPort' => '80',
        'DefaultSSLPort' => '443',
        'ServiceSingleSignOnLabel' => 'Login as User',
        'AdminSingleSignOnLabel' => 'Login as Admin',

    );
}

/**
 * Define product configuration options.
 *
 * @see https://developers.whmcs.com/provisioning-modules/config-options/
 *
 * @return array
 */
function azuracast_ConfigOptions()
{
    return array(
        'Maximum Bitrate' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '128',
            'Description' => 'Enter in Kbps',
        ],
        'Maximum Mounts' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '2',
            'Description' => 'Maximum allowed Mount Points',
        ],
        'Maximum HLS Streams' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '2',
            'Description' => 'Maximum allowed HLS Streams',
        ],
        'Media Storage Limit' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '1000',
            'Description' => 'Enter in Mb',
        ],
        'Recordings Storage Limit' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '1000',
            'Description' => 'Enter in Mb',
        ],
        'Podcasts Storage Limit' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '1000',
            'Description' => 'Enter in Mb',
        ],
        'Maximum Listeners' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '100',
            'Description' => 'Maximum Number of Listeners',
        ],
        'Server Type' => [
            "FriendlyName" => "Server Type",
            "Type" => "dropdown",
            "Options" => "icecast,shoutcast",
            "Description" => "The Frontend Type of the Station",
            "Default" => "icecast",
        ],
        'Clone Source Station ID' => [
            'Type' => 'text',
            'Size' => '10',
            'Default' => '0',
            'Description' => 'Numeric ID of the OWH_ template station to clone. Enter 0 (or leave blank) to create a new station from scratch.',
        ],
        'User Language' => [
            'FriendlyName' => 'User Language',
            'Type' => 'dropdown',
            'Options' => ',en_US,pt_BR,es_ES,de_DE,fr_FR,it_IT,nl_NL,pl_PL,tr_TR,ru_RU,ja_JP,ko_KR,zh_CN,cs_CZ,nb_NO,el_GR,sv_SE,uk_UA',
            'Description' => 'Optional. If empty, do not send locale and let AzuraCast use its default.',
            'Default' => '',
        ],
        'User Time Display' => [
            'FriendlyName' => 'User Time Display',
            'Type' => 'dropdown',
            'Options' => ',12,24',
            'Description' => 'Optional. If empty, do not send show_24_hour_time and let AzuraCast use its default.',
            'Default' => '',
        ],
        // Station permissions granted to the provisioned user role (configoption12).
        // Use comma-separated aliases or the special value "all" to grant every permission.
        // Empty value also falls back to all permissions (backward-compatible with old products).
        // Available aliases: view, reports, logs, profile, broadcasting, streamers,
        //                    mounts, remotes, media, delete_media, automation, webhooks, podcasts
        'Station Permissions' => [
            'FriendlyName' => 'Station Permissions',
            'Type'         => 'text',
            'Size'         => '200',
            'Default'      => 'all',
            'Description'  => 'Comma-separated aliases or "all". Options: view, reports, logs, profile, broadcasting, streamers, mounts, remotes, media, delete_media, automation, webhooks, podcasts',
        ],
        // User theme (configoption13).
        'User Theme' => [
            'FriendlyName' => 'User Theme',
            'Type'         => 'dropdown',
            'Options'      => 'dark,light,browser',
            'Description'  => 'Default interface theme for the provisioned AzuraCast user.',
            'Default'      => 'dark',
        ],
    );
}

/**
 * Provision a new instance of a product/service.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return string "success" or an error message
 */
function azuracast_CreateAccount(array $params)
{
    $service = new Service($params);
    $azuracast = azuracast_ApiClient($params);

    // Rollback tracking: record what was successfully created so we can undo on failure.
    $createdStationId           = null;
    $createdRoleId              = null;
    $createdUserId              = null;  // set only when a NEW user is created

    try {
        // Create or clone a Station depending on whether a template station ID is configured
        /** @var \WHMCS\Module\Server\AzuraCast\Dto\StationDto $station */
        $cloneSourceId = $service->getCloneSourceStationId();
        if ($cloneSourceId !== null) {
            // Clone the template station and copy selected components.
            // Storage locations are intentionally excluded so each client gets isolated storage.
            // Permissions are excluded because the module creates a fresh role for this service.
            $station = $azuracast->admin()->stations()->clone(
                $cloneSourceId,
                $service->getStationName(),
                $service->getStationShortName(),
                ['playlists', 'mounts', 'remotes', 'streamers', 'webhooks']
            );
            $createdStationId = $station->getId();

            // Stage IDs in-memory (not yet saved to DB) so StorageClient can read them
            $service->setStationId($station->getId());
            $service->setMediaStorageId($station->getMediaStorageId());
            $service->setRecordingsStorageId($station->getRecordingsStorageId());
            $service->setPodcastsStorageId($station->getPodcastsStorageId());

            // Override plan limits — clone copied the template's limits, which must be replaced
            $azuracast->admin()->stations()->update($service);
        } else {
            $station = $azuracast->admin()->stations()->create($service);
            $createdStationId = $station->getId();

            // Stage IDs in-memory (not yet saved to DB) so StorageClient can read them
            $service->setStationId($station->getId());
            $service->setMediaStorageId($station->getMediaStorageId());
            $service->setRecordingsStorageId($station->getRecordingsStorageId());
            $service->setPodcastsStorageId($station->getPodcastsStorageId());
        }

        // Modify Station's Storage Quota for each type
        $azuracast->admin()->storage()->update($service);

        // Create a role for this station
        $role = $azuracast->admin()->roles()->create("Station {$station->getId()} Role", [], [$station->getId() => $service->getStationPermissions()]);
        $createdRoleId = $role->getId();
        $service->setRoleId($role->getId());

        // Create a dedicated user for this service only.
        $user = $azuracast->admin()->users()->create(
            $service->getTechnicalEmail(),
            $service->getPassword(),
            $service->getUserFullName(),
            [['id' => $role->getId()]],
            $service->getUserLocale(),
            $service->getUserShow24HourTime(),
            $service->getUserTheme()
        );
        $createdUserId = $user->getId();
        $service->setUserId($user->getId());

        // All API calls succeeded — now atomically persist all IDs to the database
        $service->commitIds();
        $model = $service->getModel();
        $model->username = $user->getEmail();
        if (empty($model->domain)) {
            $model->domain = $service->getStationName();
        }
        $model->save();

    } catch (Exception $e) {
        // Compensating transactions: undo AzuraCast resources in reverse creation order.
        // Each step is isolated so a rollback failure does not prevent subsequent rollbacks.

        if ($createdUserId !== null) {
            try {
                $azuracast->admin()->users()->delete($createdUserId);
            } catch (Exception $rollbackEx) {
                logModuleCall('azuracast', 'CreateAccount_rollback_user', azuracast_SanitizeParams($params), $rollbackEx->getMessage(), $rollbackEx->getTraceAsString());
            }
        }

        if ($createdRoleId !== null) {
            try {
                $azuracast->admin()->roles()->delete($createdRoleId);
            } catch (Exception $rollbackEx) {
                logModuleCall('azuracast', 'CreateAccount_rollback_role', azuracast_SanitizeParams($params), $rollbackEx->getMessage(), $rollbackEx->getTraceAsString());
            }
        }

        if ($createdStationId !== null) {
            try {
                $azuracast->admin()->stations()->delete($createdStationId);
            } catch (Exception $rollbackEx) {
                logModuleCall('azuracast', 'CreateAccount_rollback_station', azuracast_SanitizeParams($params), $rollbackEx->getMessage(), $rollbackEx->getTraceAsString());
            }
        }

        // Record the original error in WHMCS's module log.
        logModuleCall(
            'azuracast',
            __FUNCTION__,
            azuracast_SanitizeParams($params),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Suspend an instance of a product/service.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return string "success" or an error message
 */
function azuracast_SuspendAccount(array $params)
{
    try {
        $service = new Service($params);
        $azuracast = azuracast_ApiClient($params);

        // Update the station
        $azuracast->admin()->stations()->update($service, false);

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'azuracast',
            __FUNCTION__,
            azuracast_SanitizeParams($params),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Un-suspend instance of a product/service.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return string "success" or an error message
 */
function azuracast_UnsuspendAccount(array $params)
{
    try {
        $service = new Service($params);
        $azuracast = azuracast_ApiClient($params);

        // Update the station
        $azuracast->admin()->stations()->update($service, true);

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'azuracast',
            __FUNCTION__,
            azuracast_SanitizeParams($params),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Terminate instance of a product/service.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return string "success" or an error message
 */
function azuracast_TerminateAccount(array $params)
{
    try {
        $service = new Service($params);
        $azuracast = azuracast_ApiClient($params);

        $roleId    = $service->getRoleId();
        $stationId = $service->getStationId();
        $userId    = $service->getUserId();

        if ($roleId === null || $stationId === null || $userId === null) {
            throw new \RuntimeException(
                'Cannot terminate: one or more service IDs (role, station, user) are missing from WHMCS. ' .
                'Run the backfill process to restore IDs and retry termination.'
            );
        }

        // Remove User Role
        $azuracast->admin()->roles()->delete($roleId);

        // Remove Station
        $azuracast->admin()->stations()->delete($stationId);

        // Remove the dedicated user for this service.
        $azuracast->admin()->users()->delete($userId);

        // -------------------------
        // Limpeza dinâmica de campos customizados do serviço deste produto
        // -------------------------
        $serviceId = $params['serviceid'];
        $productId = $params['packageid'];

        // Busca todos os fieldid dos custom fields deste produto, tipo produto/serviço
        $fieldIds = Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', $productId)
            ->pluck('id');

        if ($fieldIds->isNotEmpty()) {
            Capsule::table('tblcustomfieldsvalues')
                ->where('relid', $serviceId)
                ->whereIn('fieldid', $fieldIds->toArray())
                ->update(['value' => '']);
        }

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'azuracast',
            __FUNCTION__,
            azuracast_SanitizeParams($params),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Change the password for an instance of a product/service.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return string "success" or an error message
 */
function azuracast_ChangePassword(array $params)
{
    try {
        $service = new Service($params);
        $azuracast = azuracast_ApiClient($params);

        $changePasswordUserId = $service->getUserId();
        if ($changePasswordUserId === null) {
            throw new \RuntimeException('Cannot change password: service user ID is missing. Please contact support.');
        }
        $currentUser = $azuracast->admin()->users()->get($changePasswordUserId);

        // Update the user's password
        $user = $azuracast->admin()->users()->update(
            $currentUser->getId(),
            null,
            $service->getPassword(),
            $currentUser->getName(),
            azuracast_GetCurrentUserRolesArray($currentUser->getRoles()),
            $currentUser->getCreatedAt(),
            $currentUser->getLocale() !== '' ? $currentUser->getLocale() : null,
            null
        );

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'azuracast',
            __FUNCTION__,
            azuracast_SanitizeParams($params),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Upgrade or downgrade an instance of a product/service.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return string "success" or an error message
 */
function azuracast_ChangePackage(array $params)
{
    try {
        $service = new Service($params);
        $azuracast = azuracast_ApiClient($params);
        $isServiceSuspended = azuracast_IsServiceSuspended($params, $service);

        // Update the station with the new service
        $azuracast->admin()->stations()->update($service, !$isServiceSuspended);

        // Modify Station's Storage Quota for each type
        $storage = $azuracast->admin()->storage()->update($service);

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'azuracast',
            __FUNCTION__,
            azuracast_SanitizeParams($params),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        return $e->getMessage();
    }

    return 'success';
}

/**
 * Test connection with the given server parameters.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return array
 */
function azuracast_TestConnection(array $params)
{
    try {
        $azuracast = azuracast_ApiClient($params);
        $azuracast->admin()->serverStats()->get();

        $success = true;
        $errorMsg = '';
    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'azuracast',
            __FUNCTION__,
            azuracast_SanitizeParams($params),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        $success = false;
        $errorMsg = $e->getMessage();
    }

    return array(
        'success' => $success,
        'error' => $errorMsg,
    );
}

/**
 * Perform single sign-on for a given instance of a product/service.
 *
 * @param array $params common module parameters
 *
 * @return array
 *@see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 */

function azuracast_ServiceSingleSignOn(array $params)
{
    $return = array(
        'success' => false,
    );

    try {

        $service = new Service($params);
        if (azuracast_IsServiceSuspended($params, $service)) {
            logModuleCall(
                'azuracast',
                __FUNCTION__,
                azuracast_SanitizeParams($params),
                'SSO blocked because service status is Suspended',
                ''
            );

            $return['error'] = azuracast_GetSuspendedTranslation($params, 'ssoUnavailableSuspended', 'Single Sign-On is unavailable while this service is suspended.');
            return $return;
        }

        $userId = $service->getUserId();
        if ($userId === null) {
            $return['error'] = 'Service configuration is incomplete. Please contact support to restore access.';
            return $return;
        }
        $azuracast = azuracast_ApiClient($params);
        $loginUrl = $azuracast->admin()->users()->getLoginLink($userId);
        azuracast_ValidateSsoRedirectUrl($loginUrl, $params['serverhostname']);

        $return = array(
            'success' => true,
            'redirectTo' => $loginUrl,
        );

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'azuracast',
            __FUNCTION__,
            azuracast_SanitizeParams($params),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        $return['error'] = $e->getMessage();
        return $return;
    }

    return $return;
}

/**
 * Perform single sign-on for a server.
 *
 * @param array $params common module parameters
 *
 * @see https://developers.whmcs.com/provisioning-modules/module-parameters/
 *
 * @return array
 */
function azuracast_AdminSingleSignOn(array $params)
{
    $return = array(
        'success' => false,
    );

    try {

        $service = new Service($params);
        $azuracast = azuracast_ApiClient($params);
        $administratorUserId = $azuracast->admin()->users()->getAdministratorUserIdFromToken();
        $loginUrl = $azuracast->admin()->users()->getLoginLink($administratorUserId);
        azuracast_ValidateSsoRedirectUrl($loginUrl, $params['serverhostname']);

        $return = array(
            'success' => true,
            'redirectTo' => $loginUrl,
        );

    } catch (Exception $e) {
        // Record the error in WHMCS's module log.
        logModuleCall(
            'azuracast',
            __FUNCTION__,
            azuracast_SanitizeParams($params),
            $e->getMessage(),
            $e->getTraceAsString()
        );

        $return['error'] = $e->getMessage();
        return $return;
    }

    return $return;
}

function azuracast_ClientArea($params)
{
    $i18n = azuracast_GetClientAreaTranslations($params);

    $service = new Service($params);
    $productConfigOptions = [
        'Maximum Bitrate' => $service->getMaxBitrate() . ' Kbps',
        'Maximum Mounts' => $service->getMaxMounts() . ' Mounts',
        'Maximum HLS Streams' => $service->getMaxHlsStreams() . ' HLS Streams',
        'Media Storage Limit' => $service->getMediaStorage() . ' MB',
        'Recordings Storage Limit' => $service->getRecordingsStorage() . ' MB',
        'Podcasts Storage Limit' => $service->getPodcastsStorage() . ' MB',
        'Maximum Listeners' => $service->getMaxListeners() . ' Listeners',
        'Server Type' => $service->getServerType(),
    ];

    $dashboard = azuracast_GetClientAreaDashboardData($params, $service, $i18n);
    
    return array(
        'templatefile' => 'clientarea',
        'vars' => array(
            'params' => $params,
            'productConfigOptions' => $productConfigOptions,
            'dashboard' => $dashboard,
            'i18n' => $i18n,
        ),
    );
}

function azuracast_GetClientAreaTranslations(array $params): array
{
    $defaults = [
        'stationOverviewFor' => 'Station overview for',
        'shortCode' => 'Short code:',
        'listeners' => 'Listeners:',
        'liveDj' => 'Live DJ:',
        'connected' => 'Connected',
        'idle' => 'Idle',
        'broadcastSnapshot' => 'Broadcast Snapshot',
        'autoDjStandby' => 'AutoDJ Standby',
        'liveFeedDrivenBy' => 'The live feed is currently being driven by',
        'nextScheduledContent' => 'Next scheduled content:',
        'stationReadyMetadata' => 'The station is ready and waiting for fresh live metadata.',
        'onTheAir' => 'On The Air',
        'albumArt' => 'Album art',
        'noArtwork' => 'No Artwork',
        'liveDjConnected' => 'Live DJ connected:',
        'upcomingShow' => 'Upcoming show:',
        'liveMetadataSoon' => 'Live metadata will appear here as soon as AzuraCast reports it.',
        'kpiListeners' => 'Listeners',
        'stationCode' => 'Station Code',
        'quickShortcuts' => 'Quick Shortcuts',
        'openExternalPage' => 'Open external page',
        'openSecureSession' => 'Open secure session',
        'stationStatus' => 'Station Status',
        'streamOnAir' => 'Stream On-Air',
        'primaryStream' => 'Primary Stream',
        'noPublicStreamUrl' => 'No public stream URL is currently available.',
        'publicPage' => 'Public Page',
        'openPublicStationPage' => 'Open public station page',
        'noPublicPagePublished' => 'No public page published for this station.',
        'streamPlayer' => 'Stream Player',
        'audioElementUnsupported' => 'Your browser does not support the audio element.',
        'playerUnavailable' => 'Player unavailable until AzuraCast exposes a listen URL.',
        'currentSource' => 'Current Source',
        'liveDjSource' => 'Live DJ',
        'autoDjSource' => 'AutoDJ',
        'status' => 'Status',
        'storageUsage' => 'Storage Usage',
        'usedOf' => 'Used of',
        'free' => 'free',
        'quotaMetricsUnavailable' => 'Quota metrics are not available from the API right now.',
        'packageInformation' => 'Package Information',
        // Dashboard state defaults
        'notProvisioned' => 'This service has not been provisioned yet.',
        'statusUnavailable' => 'Unavailable',
        'noTrackPlaying' => 'No track is currently playing.',
        'liveDataUnavailable' => 'Live data is not available yet.',
        'stationDataUnavailable' => 'Live station data is temporarily unavailable. Package information is still shown below.',
        'serviceSuspended' => 'This service is currently suspended. Access to the AzuraCast panel is blocked until reactivation.',
        'ssoUnavailableSuspended' => 'Single Sign-On is unavailable while this service is suspended.',
        // Station status
        'statusOnAir' => 'On the air',
        'statusPartial' => 'Partially online',
        'statusOffline' => 'Offline',
        'statusSuspended' => 'Suspended',
        // Shortcut labels
        'shortcutLoginAzuraCast' => 'Login To AzuraCast',
        'shortcutPublicPage' => 'Public Page',
        'shortcutListenLive' => 'Listen Live',
        'shortcutPublicSchedule' => 'Public Schedule',
        // Service card labels/values/meta
        'cardBroadcasting' => 'Broadcasting',
        'cardAutoDj' => 'AutoDJ',
        'cardRunning' => 'Running',
        'cardStopped' => 'Stopped',
        'cardServiceOk' => 'Service is responding normally',
        'cardServiceDown' => 'Service is not currently running',
        'cardLabelListeners' => 'Listeners',
        'cardMetaListeners' => 'Current concurrent listeners',
        'cardLabelLiveDj' => 'Live DJ',
        'cardMetaNoStreamer' => 'No live streamer connected',
    ];

    $moduleTranslations = azuracast_LoadModuleLanguageStrings($params);
    $resolved = [];

    foreach ($defaults as $key => $defaultValue) {
        $resolved[$key] = azuracast_GetTranslationValue($moduleTranslations, 'azuracast.clientarea.' . $key, $defaultValue);
    }

    return $resolved;
}

function azuracast_LoadModuleLanguageStrings(array $params): array
{
    static $cache = [];

    $languageCode = azuracast_NormalizeLanguageCode(azuracast_GetActiveLanguage($params));
    if (isset($cache[$languageCode])) {
        return $cache[$languageCode];
    }

    $langDir = __DIR__ . '/lang';
    $fallback = azuracast_LoadModuleLanguageFile($langDir . '/en.php');

    $active = [];
    if ('en' !== $languageCode) {
        $active = azuracast_LoadModuleLanguageFile($langDir . '/' . $languageCode . '.php');
    }

    $cache[$languageCode] = array_merge($fallback, $active);

    return $cache[$languageCode];
}

function azuracast_LoadModuleLanguageFile(string $filePath): array
{
    if (!is_file($filePath)) {
        return [];
    }

    $_LANG = [];
    include $filePath;

    return is_array($_LANG) ? $_LANG : [];
}

function azuracast_GetTranslationValue(array $translations, string $key, string $default): string
{
    $value = $translations[$key] ?? $default;
    return is_string($value) && '' !== trim($value) ? $value : $default;
}

function azuracast_GetActiveLanguage(array $params): string
{
    // Priority 1: active session language set by WHMCS language switcher
    if (isset($_SESSION['Language']) && is_string($_SESSION['Language']) && '' !== trim($_SESSION['Language'])) {
        return $_SESSION['Language'];
    }

    // Priority 2: language from the client's profile (passed in $params)
    $clientLanguage = azuracast_ArrayGet($params, ['clientsdetails', 'language']);
    if (is_string($clientLanguage) && '' !== trim($clientLanguage)) {
        return $clientLanguage;
    }

    // Priority 3: system-wide default language
    global $CONFIG;
    if (is_array($CONFIG) && !empty($CONFIG['Language']) && is_string($CONFIG['Language'])) {
        return $CONFIG['Language'];
    }

    return 'en';
}

/**
 * Normalize language code to a supported format (e.g., 'en' or 'pt_BR').
 * 
 * @param mixed $language Language input that should be a string.
 * @return string Normalized language code or 'en' as fallback.
 */
function azuracast_NormalizeLanguageCode($language): string
{
    // Type guard: ensure $language is a string
    if (!is_string($language)) {
        // Log the issue for debugging
        error_log('WARNING: azuracast_NormalizeLanguageCode received non-string value: ' . gettype($language));
        return 'en';
    }

    $normalized = strtolower(trim($language));
    $normalized = str_replace(['-', ' '], '_', $normalized);
    $normalized = preg_replace('/_+/', '_', $normalized) ?? 'en';

    $aliases = [
        // English variants
        'english'       => 'en',
        'en_us'         => 'en',
        'en_gb'         => 'en',
        // Portuguese variants used by WHMCS/client profile labels
        'portuguese'            => 'pt_br',
        'portuguese_br'         => 'pt_br',
        'brazilian_portuguese'  => 'pt_br',
        'pt'                    => 'pt_br',
        'pt_pt'                 => 'pt_br',
    ];

    if (isset($aliases[$normalized])) {
        return $aliases[$normalized];
    }

    if (preg_match('/^[a-z]{2}$/', $normalized)) {
        return $normalized;
    }

    if (preg_match('/^[a-z]{2}_[a-z]{2}$/', $normalized)) {
        return $normalized;
    }

    return 'en';
}

function azuracast_GetClientAreaDashboardData(array $params, Service $service, array $i18n): array
{
    $stationId = $service->getStationId();
    $hasStation = null !== $stationId;
    $isServiceSuspended = azuracast_IsServiceSuspended($params, $service);
    $singleSignOnUrl = $hasStation
        ? sprintf('/clientarea.php?action=productdetails&id=%d&dosinglesignon=1', (int)$params['serviceid'])
        : null;

    $dashboard = [
        'available' => false,
        'warning' => $hasStation ? null : $i18n['notProvisioned'],
        'stationName' => $service->getStationName(),
        'shortName' => $service->getStationShortName(),
        'description' => '',
        'statusText' => $i18n['statusUnavailable'],
        'statusVariant' => 'muted',
        'artworkUrl' => null,
        'listeners' => 0,
        'currentTrackTitle' => $i18n['noTrackPlaying'],
        'currentTrackArtist' => $i18n['liveDataUnavailable'],
        'liveStreamerName' => null,
        'hasLiveBroadcast' => false,
        'upcomingShow' => null,
        'playerUrl' => null,
        'streamUrl' => null,
        'publicPageUrl' => null,
        'scheduleUrl' => null,
        'shortcuts' => [],
        'serviceCards' => [],
        'quotaCards' => [],
    ];

    if ($isServiceSuspended) {
        $dashboard['warning'] = $i18n['serviceSuspended'];
        $dashboard['statusText'] = $i18n['statusSuspended'];
        $dashboard['statusVariant'] = 'warning';
    }

    if (!$hasStation) {
        return $dashboard;
    }

    $client = azuracast_ApiClient($params);

    $stationDashboard = azuracast_TryClientAreaRequest($client, 'GET', sprintf('station/%d/dashboard', $stationId));
    $stationProfile = azuracast_TryClientAreaRequest($client, 'GET', sprintf('station/%d/profile', $stationId));
    $nowPlaying = azuracast_TryClientAreaRequest($client, 'GET', sprintf('station/%d/nowplaying', $stationId));
    $mediaQuota = azuracast_TryClientAreaRequest($client, 'GET', sprintf('station/%d/quota/station_media', $stationId));
    $recordingsQuota = azuracast_TryClientAreaRequest($client, 'GET', sprintf('station/%d/quota/station_recordings', $stationId));
    $podcastsQuota = azuracast_TryClientAreaRequest($client, 'GET', sprintf('station/%d/quota/station_podcasts', $stationId));

    $dashboard['available'] = null !== $stationDashboard || null !== $stationProfile || null !== $nowPlaying;

    if (!$dashboard['available'] && !$isServiceSuspended) {
        $dashboard['warning'] = $i18n['stationDataUnavailable'];
    }

    $dashboard['stationName'] = azuracast_ArrayGet($stationDashboard, ['name'], azuracast_ArrayGet($stationProfile, ['station', 'name'], $dashboard['stationName']));
    $dashboard['shortName'] = azuracast_ArrayGet($stationDashboard, ['shortName'], azuracast_ArrayGet($nowPlaying, ['station', 'shortcode'], $dashboard['shortName']));
    $dashboard['description'] = azuracast_ArrayGet($stationDashboard, ['description'], azuracast_ArrayGet($nowPlaying, ['station', 'description'], ''));
    $dashboard['artworkUrl'] = azuracast_ArrayGet($nowPlaying, ['now_playing', 'song', 'art'], azuracast_ArrayGet($nowPlaying, ['station', 'art'], null));
    $dashboard['listeners'] = (int)azuracast_ArrayGet($nowPlaying, ['listeners', 'total'], 0);
    $dashboard['currentTrackTitle'] = (string)azuracast_ArrayGet($nowPlaying, ['now_playing', 'song', 'title'], $dashboard['currentTrackTitle']);
    $dashboard['currentTrackArtist'] = (string)azuracast_ArrayGet($nowPlaying, ['now_playing', 'song', 'artist'], $dashboard['currentTrackArtist']);
    $dashboard['liveStreamerName'] = azuracast_ArrayGet($nowPlaying, ['live', 'streamer_name']);
    $dashboard['hasLiveBroadcast'] = (bool)azuracast_ArrayGet($nowPlaying, ['live', 'is_live'], false);

    $frontendRunning = (bool)azuracast_ArrayGet($stationProfile, ['services', 'frontendRunning'], false);
    $backendRunning = (bool)azuracast_ArrayGet($stationProfile, ['services', 'backendRunning'], false);
    if ($frontendRunning || $backendRunning) {
        $dashboard['statusText'] = ($frontendRunning && $backendRunning) ? $i18n['statusOnAir'] : $i18n['statusPartial'];
        $dashboard['statusVariant'] = ($frontendRunning && $backendRunning) ? 'live' : 'warning';
    }

    if (!$frontendRunning && !$backendRunning && null !== $stationProfile) {
        $dashboard['statusText'] = $i18n['statusOffline'];
        $dashboard['statusVariant'] = 'offline';
    }

    $mounts = azuracast_ArrayGet($nowPlaying, ['station', 'mounts'], []);
    $defaultMount = azuracast_FindDefaultMount(is_array($mounts) ? $mounts : []);
    $listenUrl = azuracast_ArrayGet($defaultMount, ['url'], azuracast_ArrayGet($nowPlaying, ['station', 'listen_url']));
    $publicPageUrl = azuracast_ArrayGet($stationDashboard, ['publicPageUrl'], azuracast_ArrayGet($nowPlaying, ['station', 'public_page_url']));
    $scheduleUrl = azuracast_ArrayGet($stationDashboard, ['publicScheduleUrl']);

    $dashboard['playerUrl'] = is_string($listenUrl) && $listenUrl !== '' ? $listenUrl : null;
    $dashboard['streamUrl'] = $dashboard['playerUrl'];
    $dashboard['publicPageUrl'] = is_string($publicPageUrl) && $publicPageUrl !== '' ? $publicPageUrl : null;
    $dashboard['scheduleUrl'] = is_string($scheduleUrl) && $scheduleUrl !== '' ? $scheduleUrl : null;

    $schedule = azuracast_ArrayGet($stationProfile, ['schedule'], []);
    if (is_array($schedule) && isset($schedule[0]) && is_array($schedule[0])) {
        $dashboard['upcomingShow'] = azuracast_ArrayGet($schedule[0], ['title'], azuracast_ArrayGet($schedule[0], ['name']));
    }

    if (null !== $singleSignOnUrl && !$isServiceSuspended) {
        $dashboard['shortcuts'][] = [
            'label' => $i18n['shortcutLoginAzuraCast'],
            'url' => $singleSignOnUrl,
            'external' => true,
            'accent' => 'primary',
        ];
    }

    if (null !== $dashboard['publicPageUrl']) {
        $dashboard['shortcuts'][] = [
            'label' => $i18n['shortcutPublicPage'],
            'url' => $dashboard['publicPageUrl'],
            'external' => true,
            'accent' => 'secondary',
        ];
    }

    if (null !== $dashboard['streamUrl']) {
        $dashboard['shortcuts'][] = [
            'label' => $i18n['shortcutListenLive'],
            'url' => $dashboard['streamUrl'],
            'external' => true,
            'accent' => 'secondary',
        ];
    }

    if (null !== $dashboard['scheduleUrl']) {
        $dashboard['shortcuts'][] = [
            'label' => $i18n['shortcutPublicSchedule'],
            'url' => $dashboard['scheduleUrl'],
            'external' => true,
            'accent' => 'secondary',
        ];
    }

    $dashboard['serviceCards'] = [
        azuracast_BuildServiceCard(
            $i18n['cardBroadcasting'],
            $frontendRunning,
            $i18n['cardRunning'],
            $i18n['cardStopped'],
            $i18n['cardServiceOk'],
            $i18n['cardServiceDown']
        ),
        azuracast_BuildServiceCard(
            $i18n['cardAutoDj'],
            $backendRunning,
            $i18n['cardRunning'],
            $i18n['cardStopped'],
            $i18n['cardServiceOk'],
            $i18n['cardServiceDown']
        ),
        [
            'label' => $i18n['cardLabelListeners'],
            'value' => (string)$dashboard['listeners'],
            'variant' => $dashboard['listeners'] > 0 ? 'live' : 'muted',
            'meta' => $i18n['cardMetaListeners'],
        ],
        [
            'label' => $i18n['cardLabelLiveDj'],
            'value' => $dashboard['hasLiveBroadcast'] ? $i18n['connected'] : $i18n['idle'],
            'variant' => $dashboard['hasLiveBroadcast'] ? 'live' : 'muted',
            'meta' => $dashboard['liveStreamerName'] ?: $i18n['cardMetaNoStreamer'],
        ],
    ];

    $dashboard['quotaCards'] = array_values(array_filter([
        azuracast_BuildQuotaCard('Media Storage', $mediaQuota),
        azuracast_BuildQuotaCard('Recordings Storage', $recordingsQuota),
        azuracast_BuildQuotaCard('Podcasts Storage', $podcastsQuota),
    ]));

    return $dashboard;
}

function azuracast_ApiClient($params) : Client
{
    $host = 'https://' . $params['serverhostname'];
    $apiKey = $params['serveraccesshash'];
    return Client::create($host, $apiKey);
}

/**
 * Detect if the WHMCS service is currently suspended.
 */
function azuracast_IsServiceSuspended(array $params, ?Service $service = null): bool
{
    $model = $service ? $service->getModel() : ($params['model'] ?? null);

    if (is_object($model) && isset($model->status) && is_string($model->status)) {
        return strcasecmp($model->status, 'Suspended') === 0;
    }

    if (isset($params['status']) && is_string($params['status'])) {
        return strcasecmp($params['status'], 'Suspended') === 0;
    }

    return false;
}

/**
 * Resolve a translated message for suspended-service scenarios.
 */
function azuracast_GetSuspendedTranslation(array $params, string $translationKey, string $defaultValue): string
{
    $moduleTranslations = azuracast_LoadModuleLanguageStrings($params);

    return azuracast_GetTranslationValue(
        $moduleTranslations,
        'azuracast.clientarea.' . $translationKey,
        $defaultValue
    );
}

/**
 * @param RoleDto[] $existingUserRoles
 * @return array
 */
function azuracast_GetCurrentUserRolesArray(array $existingUserRoles)
{
    $roles = [];
    foreach ($existingUserRoles as $existingUserRole) {
        $roles[] = ['id' => $existingUserRole->getId()];
    }

    return $roles;
}

/**
 * Redacts sensitive fields from $params before passing to logModuleCall.
 * Removes secrets (API key, passwords) and PII (client details) from log output.
 */
function azuracast_SanitizeParams(array $params): array
{
    $sensitiveKeys = ['serveraccesshash', 'password', 'serverpassword', 'clientsdetails'];
    foreach ($sensitiveKeys as $key) {
        if (array_key_exists($key, $params)) {
            $params[$key] = '[REDACTED]';
        }
    }
    return $params;
}

/**
 * Validates that a login URL returned by the AzuraCast API points to the expected server host.
 * Prevents open redirect attacks if the remote API is compromised or misconfigured.
 * Note: if AzuraCast runs behind a CDN/proxy with a different hostname than $expectedHost,
 * this check will fail. In that case, update $expectedHost to match the actual redirect hostname.
 *
 * @throws \RuntimeException if the URL host does not match the expected server hostname.
 */
function azuracast_ValidateSsoRedirectUrl(string $url, string $expectedHost): void
{
    $parsed = parse_url($url);
    if ($parsed === false || !isset($parsed['host'])) {
        throw new \RuntimeException('SSO login URL returned by AzuraCast is invalid or malformed.');
    }
    if (strcasecmp($parsed['host'], $expectedHost) !== 0) {
        throw new \RuntimeException(
            sprintf('SSO login URL host "%s" does not match the configured server hostname "%s".', $parsed['host'], $expectedHost)
        );
    }
}

function azuracast_TryClientAreaRequest(Client $client, string $method, string $uri): ?array
{
    try {
        $data = $client->request($method, $uri);
        return is_array($data) ? $data : null;
    } catch (\Throwable $e) {
        return null;
    }
}

function azuracast_ArrayGet(?array $source, array $path, mixed $default = null): mixed
{
    if (!is_array($source)) {
        return $default;
    }

    $value = $source;
    foreach ($path as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }

        $value = $value[$segment];
    }

    return $value;
}

function azuracast_FindDefaultMount(array $mounts): ?array
{
    foreach ($mounts as $mount) {
        if (is_array($mount) && !empty($mount['is_default'])) {
            return $mount;
        }
    }

    foreach ($mounts as $mount) {
        if (is_array($mount)) {
            return $mount;
        }
    }

    return null;
}

function azuracast_BuildServiceCard(string $label, bool $isRunning, string $runningValue, string $stoppedValue, string $runningMeta, string $stoppedMeta): array
{
    return [
        'label' => $label,
        'value' => $isRunning ? $runningValue : $stoppedValue,
        'variant' => $isRunning ? 'live' : 'offline',
        'meta' => $isRunning ? $runningMeta : $stoppedMeta,
    ];
}

function azuracast_BuildQuotaCard(string $label, ?array $quota): ?array
{
    if (null === $quota) {
        return null;
    }

    $usedPercent = (int)azuracast_ArrayGet($quota, ['used_percent'], 0);
    if ($usedPercent >= 85) {
        $variant = 'offline';
    } elseif ($usedPercent >= 65) {
        $variant = 'warning';
    } else {
        $variant = 'live';
    }

    return [
        'label' => $label,
        'used' => (string)azuracast_ArrayGet($quota, ['used'], '0 B'),
        'quota' => (string)azuracast_ArrayGet($quota, ['quota'], 'Unlimited'),
        'usedPercent' => $usedPercent,
        'meta' => azuracast_ArrayGet($quota, ['available'], null),
        'variant' => $variant,
    ];
}
