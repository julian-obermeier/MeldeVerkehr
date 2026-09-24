<?php

declare(strict_types=1);

use MeldeVerkehr\Admin\AdminController;
use MeldeVerkehr\Analytics\MapAnalyticsController;
use MeldeVerkehr\Assist\AssistController;
use MeldeVerkehr\AuthorityPortal\AuthorityApiController;
use MeldeVerkehr\AuthorityPortal\AuthorityPortalController;
use MeldeVerkehr\Auth\AuthController;
use MeldeVerkehr\Auth\DashboardController;
use MeldeVerkehr\Auth\PasskeyLoginController;
use MeldeVerkehr\Auth\SecurityController;
use MeldeVerkehr\Auth\TwoFactorController;
use MeldeVerkehr\Cases\CaseController;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Evidence\EvidenceController;
use MeldeVerkehr\Evidence\EvidencePrivacyController;
use MeldeVerkehr\Evidence\EvidenceReviewController;
use MeldeVerkehr\Dispatch\DispatchController;
use MeldeVerkehr\Communication\CommunicationController;
use MeldeVerkehr\Community\CommunityController;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Install\InstallerController;
use MeldeVerkehr\Install\InstallerService;
use MeldeVerkehr\Operations\OperationsController;
use MeldeVerkehr\Release\ReleaseAdminController;
use MeldeVerkehr\Release\ReleaseHealthController;
use MeldeVerkehr\Release\ReleaseServiceFactory;
use MeldeVerkehr\Witness\FinalReviewController;
use MeldeVerkehr\Witness\WitnessController;

/** @var Application $app */

$installer = new InstallerController($app->basePath());
$installerService = new InstallerService($app->basePath());
$releaseHealth = new ReleaseHealthController($app);

$app->router()->get('/install', [$installer, 'index']);
$app->router()->post('/install/database', [$installer, 'database']);
$app->router()->get('/install/admin', [$installer, 'adminForm']);
$app->router()->post('/install/admin', [$installer, 'install']);
$app->router()->get('/install/complete', [$installer, 'complete']);

if ($installerService->isInstalled()) {
    $auth = new AuthController($app);
    $dashboard = new DashboardController($app);
    $admin = new AdminController($app);
    $twoFactor = new TwoFactorController($app);
    $security = new SecurityController($app);
    $passkeyLogin = new PasskeyLoginController($app);
    $cases = new CaseController($app);
    $caseLifecycle = new CaseLifecycleController($app);
    $evidence = new EvidenceController($app);
    $privacy = new EvidencePrivacyController($app);
    $evidenceReview = new EvidenceReviewController($app);
    $witness = new WitnessController($app);
    $finalReview = new FinalReviewController($app);
    $dispatch = new DispatchController($app);
    $communication = new CommunicationController($app);
    $assist = new AssistController($app);
    $analytics = new MapAnalyticsController($app);
    $community = new CommunityController($app);
    $operations = new OperationsController($app);
    $releaseAdmin = new ReleaseAdminController($app);
    $authorityPortal = new AuthorityPortalController($app);
    $authorityApi = new AuthorityApiController($app);

    $app->router()->get('/register', [$auth, 'registerForm']);
    $app->router()->post('/register', [$auth, 'register']);
    $app->router()->get('/login', [$auth, 'loginForm']);
    $app->router()->post('/login', [$auth, 'login']);
    $app->router()->post('/logout', [$auth, 'logout']);

    $app->router()->get('/passkey', [$passkeyLogin, 'page']);
    $app->router()->get('/passkey/login/options', [$passkeyLogin, 'options']);
    $app->router()->post('/passkey/login', [$passkeyLogin, 'login']);

    $app->router()->get('/two-factor', [$twoFactor, 'form']);
    $app->router()->post('/two-factor', [$twoFactor, 'verify']);

    $app->router()->get('/verify-email', [$auth, 'verify']);
    $app->router()->get('/verify-email/pending', [$auth, 'verificationPending']);
    $app->router()->post('/verify-email/resend', [$auth, 'resendVerification']);

    $app->router()->get('/forgot-password', [$auth, 'forgotForm']);
    $app->router()->post('/forgot-password', [$auth, 'forgot']);
    $app->router()->get('/reset-password', [$auth, 'resetForm']);
    $app->router()->post('/reset-password', [$auth, 'reset']);

    $app->router()->get('/dashboard', [$dashboard, 'index']);

    $app->router()->get('/cases', [$cases, 'index']);
    $app->router()->post('/cases', [$cases, 'create']);
    $app->router()->get('/cases/{id}', [$cases, 'show']);
    $app->router()->post('/cases/{id}/vehicle', [$cases, 'saveVehicle']);
    $app->router()->post('/cases/{id}/location', [$cases, 'saveLocation']);
    $app->router()->post('/cases/{id}/observation', [$cases, 'saveObservation']);
    $app->router()->post('/cases/{id}/offense', [$cases, 'saveOffense']);
    $app->router()->get('/cases/{id}/review', [$cases, 'review']);
    $app->router()->post('/cases/{id}/review', [$cases, 'confirmReview']);

    $app->router()->get('/cases/{id}/lifecycle', [$caseLifecycle, 'index']);
    $app->router()->post('/cases/{id}/lifecycle/amendments', [$caseLifecycle, 'addAmendment']);
    $app->router()->post('/cases/{id}/lifecycle/corrections', [$caseLifecycle, 'requestCorrection']);
    $app->router()->post('/cases/{id}/lifecycle/corrections/{correction}/complete', [$caseLifecycle, 'completeCorrection']);
    $app->router()->post('/cases/{id}/lifecycle/withdrawals', [$caseLifecycle, 'requestWithdrawal']);
    $app->router()->post('/cases/{id}/lifecycle/withdrawals/{withdrawal}/complete', [$caseLifecycle, 'completeWithdrawal']);
    $app->router()->post('/cases/{id}/lifecycle/close', [$caseLifecycle, 'close']);
    $app->router()->post('/cases/{id}/lifecycle/archive', [$caseLifecycle, 'archive']);
    $app->router()->get('/cases/{id}/lifecycle/closures/{closure}/export', [$caseLifecycle, 'exportClosure']);

    $app->router()->get('/cases/{id}/evidence', [$evidence, 'index']);
    $app->router()->post('/cases/{id}/evidence', [$evidence, 'upload']);
    $app->router()->post('/evidence/{id}/remove', [$evidence, 'remove']);

    $app->router()->get('/evidence/{id}/privacy', [$privacy, 'index']);
    $app->router()->post('/evidence/{id}/privacy/regions', [$privacy, 'addRegion']);
    $app->router()->post('/privacy-regions/{id}/dismiss', [$privacy, 'dismissRegion']);
    $app->router()->post('/evidence/{id}/privacy/confirm', [$privacy, 'confirm']);
    $app->router()->get('/evidence/{id}/preview', [$privacy, 'preview']);

    $app->router()->get('/cases/{id}/evidence/review', [$evidenceReview, 'index']);
    $app->router()->post('/cases/{id}/evidence/review', [$evidenceReview, 'confirm']);

    $app->router()->get('/cases/{id}/witness', [$witness, 'index']);
    $app->router()->post('/cases/{id}/witness/observation', [$witness, 'saveObservation']);
    $app->router()->post('/cases/{id}/witness/narrative/generate', [$witness, 'generateNarrative']);
    $app->router()->post('/cases/{id}/witness/narrative', [$witness, 'saveNarrative']);
    $app->router()->post('/cases/{id}/witness/report', [$witness, 'createReport']);
    $app->router()->post('/witness-reports/{id}/confirm', [$witness, 'confirmReport']);

    $app->router()->get('/cases/{id}/final-review', [$finalReview, 'index']);
    $app->router()->post('/cases/{id}/final-review', [$finalReview, 'confirm']);

    $app->router()->get('/cases/{id}/dispatch', [$dispatch, 'index']);
    $app->router()->post('/cases/{id}/dispatch', [$dispatch, 'queue']);

    $app->router()->get('/cases/{id}/communication', [$communication, 'index']);
    $app->router()->get('/communication-attachments/{id}', [$communication, 'attachment']);
    $app->router()->post('/communication-tasks/{id}/complete', [$communication, 'completeTask']);
    $app->router()->post('/communication-deadlines/{id}/resolve', [$communication, 'resolveDeadline']);
    $app->router()->post('/authority-messages/{id}/reply-draft', [$communication, 'createDraft']);
    $app->router()->post('/authority-reply-drafts/{id}/save', [$communication, 'saveDraft']);
    $app->router()->post('/authority-reply-drafts/{id}/queue', [$communication, 'queueDraft']);

    $app->router()->get('/cases/{id}/assist', [$assist, 'index']);
    $app->router()->post('/assist/evidence/{id}/quality', [$assist, 'quality']);
    $app->router()->post('/assist/evidence/{id}/analyze', [$assist, 'analyze']);
    $app->router()->post('/assist/suggestions/{id}/confirm', [$assist, 'confirm']);
    $app->router()->post('/assist/suggestions/{id}/reject', [$assist, 'reject']);
    $app->router()->post('/assist/suggestions/{id}/apply-plate', [$assist, 'applyPlate']);
    $app->router()->post('/assist/suggestions/{id}/apply-offense', [$assist, 'applyOffense']);

    $app->router()->get('/map', [$analytics, 'map']);
    $app->router()->get('/analytics', [$analytics, 'analytics']);
    $app->router()->get('/problem-areas', [$analytics, 'areas']);
    $app->router()->post('/problem-areas', [$analytics, 'createArea']);
    $app->router()->get('/problem-areas/{id}', [$analytics, 'area']);
    $app->router()->post('/problem-areas/{id}/sync', [$analytics, 'syncArea']);
    $app->router()->post('/problem-areas/{id}/reports', [$analytics, 'createReport']);
    $app->router()->get('/municipal-reports/{id}', [$analytics, 'report']);

    $app->router()->get('/community', [$community, 'feed']);
    $app->router()->get('/community/profile', [$community, 'profile']);
    $app->router()->post('/community/profile', [$community, 'saveProfile']);
    $app->router()->get('/community/u/{username}', [$community, 'userProfile']);
    $app->router()->get('/community/groups', [$community, 'groups']);
    $app->router()->post('/community/groups', [$community, 'createGroup']);
    $app->router()->post('/community/groups/{id}/join', [$community, 'joinGroup']);
    $app->router()->post('/community/posts', [$community, 'createPost']);
    $app->router()->get('/community/posts/{id}', [$community, 'post']);
    $app->router()->post('/community/posts/{id}/comments', [$community, 'comment']);
    $app->router()->post('/community/posts/{id}/helpful', [$community, 'react']);
    $app->router()->get('/community/problems', [$community, 'problems']);
    $app->router()->post('/community/problems', [$community, 'createProblem']);
    $app->router()->get('/community/problems/{id}', [$community, 'problem']);
    $app->router()->post('/community/problems/{id}/observations', [$community, 'observeProblem']);
    $app->router()->get('/community/messages', [$community, 'messages']);
    $app->router()->post('/community/messages', [$community, 'sendMessage']);
    $app->router()->post('/community/messages/{id}/accept', [$community, 'acceptMessage']);
    $app->router()->post('/community/blocks', [$community, 'blockUser']);
    $app->router()->get('/community/leaderboard', [$community, 'leaderboard']);
    $app->router()->get('/cases/{id}/community-release', [$community, 'releases']);
    $app->router()->post('/cases/{id}/community-release', [$community, 'createRelease']);
    $app->router()->post('/community/releases/{id}/publish', [$community, 'publishRelease']);
    $app->router()->post('/community/releases/{id}/withdraw', [$community, 'withdrawRelease']);
    $app->router()->get('/community/releases/{token}', [$community, 'publicRelease']);
    $app->router()->get('/community/releases/{token}/evidence/{order}', [$community, 'publicReleaseEvidence']);
    $app->router()->post('/community/reports', [$community, 'reportContent']);
    $app->router()->get('/community/moderation', [$community, 'moderation']);
    $app->router()->post('/community/moderation/problems/{id}/approve', [$community, 'approveProblem']);
    $app->router()->post('/community/moderation/reports/{id}/resolve', [$community, 'resolveModeration']);

    $app->router()->get('/search', [$operations, 'search']);
    $app->router()->post('/search/filters', [$operations, 'saveFilter']);
    $app->router()->post('/search/filters/{id}/delete', [$operations, 'deleteFilter']);
    $app->router()->get('/documents', [$operations, 'documents']);
    $app->router()->get('/notifications', [$operations, 'notifications']);
    $app->router()->post('/notifications/{id}/read', [$operations, 'markNotification']);
    $app->router()->post('/notifications/read-all', [$operations, 'markAllNotifications']);
    $app->router()->post('/exports/cases', [$operations, 'createExport']);
    $app->router()->get('/exports/{id}', [$operations, 'exportBinary']);
    $app->router()->get('/settings/retention', [$operations, 'retention']);

    $app->router()->get('/authority', [$authorityPortal, 'index']);
    $app->router()->get('/authority/cases/{id}', [$authorityPortal, 'case']);
    $app->router()->post('/authority/cases/{id}/inquiries', [$authorityPortal, 'createInquiry']);
    $app->router()->get('/authority/cases/{id}/holder', [$authorityPortal, 'holder']);
    $app->router()->post('/authority/cases/{id}/holder', [$authorityPortal, 'saveHolder']);
    $app->router()->get('/authority/admin', [$authorityPortal, 'admin']);
    $app->router()->post('/authority/{id}/users', [$authorityPortal, 'assignUser']);
    $app->router()->post('/authority/{id}/tokens', [$authorityPortal, 'createToken']);
    $app->router()->post('/authority/tokens/{id}/revoke', [$authorityPortal, 'revokeToken']);
    $app->router()->get('/authority/{id}/export/{format}', [$authorityPortal, 'export']);

    $app->router()->get('/api/v1/authority/cases', [$authorityApi, 'cases']);
    $app->router()->get('/api/v1/authority/cases/{id}', [$authorityApi, 'case']);
    $app->router()->post('/api/v1/authority/cases/{id}/inquiries', [$authorityApi, 'inquiry']);

    $app->router()->get('/settings/security', [$security, 'index']);
    $app->router()->post('/settings/security/totp/start', [$security, 'startTotp']);
    $app->router()->post('/settings/security/totp/confirm', [$security, 'confirmTotp']);
    $app->router()->post('/settings/security/totp/disable', [$security, 'disableTotp']);
    $app->router()->get('/settings/security/passkeys/options', [$security, 'passkeyOptions']);
    $app->router()->post('/settings/security/passkeys/register', [$security, 'registerPasskey']);
    $app->router()->post('/settings/security/passkeys/delete', [$security, 'deletePasskey']);

    $app->router()->get('/admin', [$admin, 'index']);
    $app->router()->get('/admin/system/update', [$releaseAdmin, 'index']);
    $app->router()->post('/admin/system/update/backup', [$releaseAdmin, 'backup']);
    $app->router()->post('/admin/system/update/backups/{id}/verify', [$releaseAdmin, 'verify']);
    $app->router()->post('/admin/system/update/run', [$releaseAdmin, 'update']);
    $app->router()->post('/admin/system/update/maintenance-off', [$releaseAdmin, 'maintenanceOff']);
}

$app->router()->get('/', static function (Request $request) use ($installerService): Response {
    if (!$installerService->isInstalled()) {
        return Response::redirect('/install');
    }

    return Response::redirect('/dashboard');
});

$app->router()->get('/health/live', [$releaseHealth, 'live']);
$app->router()->get('/health/ready', [$releaseHealth, 'ready']);

$app->router()->get('/health', static function (Request $request) use ($installerService, $app): Response {
    return Response::json([
        'success' => true,
        'data' => [
            'service' => 'MeldeVerkehr',
            'status' => 'ok',
            'installed' => $installerService->isInstalled(),
            'version' => trim((string) @file_get_contents(dirname(__DIR__) . '/VERSION')),
            'maintenance' => ReleaseServiceFactory::maintenance($app)->active(),
        ],
        'errors' => [],
        'meta' => [],
    ]);
});
