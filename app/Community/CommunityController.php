<?php

declare(strict_types=1);

namespace MeldeVerkehr\Community;

use MeldeVerkehr\Auth\AuthManager;
use MeldeVerkehr\Auth\AuthService;
use MeldeVerkehr\Core\Application;
use MeldeVerkehr\Http\Request;
use MeldeVerkehr\Http\Response;
use MeldeVerkehr\Security\Csrf;
use MeldeVerkehr\Support\View;

final class CommunityController
{
    private readonly AuthManager $auth;
    private readonly View $view;

    public function __construct(private readonly Application $app)
    {
        $this->auth = new AuthManager();
        $this->view = new View($app->basePath() . '/resources/views');
    }

    public function feed(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $core = CommunityServiceFactory::core($this->app);

        return Response::html($this->view->render('community/feed', [
            'profile' => $core->profileByUser($userId, $userId),
            'posts' => $core->feed($userId, $this->nullable($request->query('group'))),
            'groups' => $core->groups($userId),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('community_message'),
            'error' => $this->pullFlash('community_error'),
        ]));
    }

    public function profile(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        return Response::html($this->view->render('community/profile', [
            'profile' => CommunityServiceFactory::core($this->app)->profileByUser($userId, $userId),
            'score' => CommunityServiceFactory::reputation($this->app)->score($userId),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('community_message'),
            'error' => $this->pullFlash('community_error'),
        ]));
    }

    public function saveProfile(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/profile')) {
            return Response::redirect('/community/profile');
        }

        try {
            CommunityServiceFactory::core($this->app)->saveProfile($userId, [
                'username' => $request->input('username'),
                'bio' => $request->input('bio'),
                'region_state' => $request->input('region_state'),
                'region_district' => $request->input('region_district'),
                'region_city' => $request->input('region_city'),
                'visibility_bio' => $request->input('visibility_bio'),
                'visibility_state' => $request->input('visibility_state'),
                'visibility_district' => $request->input('visibility_district'),
                'visibility_city' => $request->input('visibility_city'),
                'leaderboard_opt_in' => $request->input('leaderboard_opt_in') === '1',
            ]);
            $_SESSION['community_message'] = 'Community-Profil gespeichert.';
        } catch (\InvalidArgumentException|\DomainException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/profile');
    }

    public function userProfile(Request $request): Response
    {
        $viewer = $this->auth->id();
        $profile = CommunityServiceFactory::core($this->app)->profileByUsername(
            $viewer,
            (string) $request->route('username', '')
        );

        if ($profile === null) {
            return Response::html('<h1>404</h1><p>Community-Profil nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('community/user', [
            'profile' => $profile,
            'viewerUserId' => $viewer,
            'csrf' => $viewer !== null ? Csrf::token() : null,
        ]));
    }

    public function groups(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        return Response::html($this->view->render('community/groups', [
            'groups' => CommunityServiceFactory::core($this->app)->groups($userId),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('community_message'),
            'error' => $this->pullFlash('community_error'),
        ]));
    }

    public function createGroup(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/groups')) {
            return Response::redirect('/community/groups');
        }

        try {
            CommunityServiceFactory::core($this->app)->createGroup($userId, [
                'group_type' => $request->input('group_type'),
                'name' => $request->input('name'),
                'description' => $request->input('description'),
                'region_level' => $request->input('region_level'),
                'region_code' => $request->input('region_code'),
            ]);
            $_SESSION['community_message'] = 'Gruppe wurde erstellt.';
        } catch (\InvalidArgumentException|\DomainException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/groups');
    }

    public function joinGroup(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/groups')) {
            return Response::redirect('/community/groups');
        }

        try {
            CommunityServiceFactory::core($this->app)->joinGroup(
                $userId,
                (string) $request->route('id', '')
            );
            $_SESSION['community_message'] = 'Gruppe beigetreten.';
        } catch (\DomainException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/groups');
    }

    public function createPost(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community')) {
            return Response::redirect('/community');
        }

        try {
            CommunityServiceFactory::core($this->app)->createPost($userId, [
                'body' => $request->input('body'),
                'topic' => $request->input('topic'),
                'group_id' => $request->input('group_id'),
                'problem_area_id' => $request->input('problem_area_id'),
                'case_release_id' => $request->input('case_release_id'),
            ]);
            $_SESSION['community_message'] = 'Beitrag veröffentlicht.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community');
    }

    public function post(Request $request): Response
    {
        $viewer = $this->auth->id();
        $post = CommunityServiceFactory::core($this->app)->post(
            $viewer,
            (string) $request->route('id', '')
        );

        if ($post === null) {
            return Response::html('<h1>404</h1><p>Beitrag nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('community/post', [
            'post' => $post,
            'viewerUserId' => $viewer,
            'csrf' => $viewer !== null ? Csrf::token() : null,
        ]));
    }

    public function comment(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $postId = (string) $request->route('id', '');
        if (!$this->csrf($request, '/community/posts/' . rawurlencode($postId))) {
            return Response::redirect('/community/posts/' . rawurlencode($postId));
        }

        try {
            CommunityServiceFactory::core($this->app)->comment(
                $userId,
                $postId,
                (string) $request->input('body', ''),
                $this->nullable($request->input('parent_comment_id'))
            );
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/posts/' . rawurlencode($postId));
    }

    public function react(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $postId = (string) $request->route('id', '');
        if (!$this->csrf($request, '/community/posts/' . rawurlencode($postId))) {
            return Response::redirect('/community/posts/' . rawurlencode($postId));
        }

        try {
            $inserted = CommunityServiceFactory::core($this->app)->reactHelpful($userId, $postId);
            if ($inserted) {
                CommunityServiceFactory::reputation($this->app)->awardHelpfulReaction($userId, $postId);
            }
        } catch (\DomainException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/posts/' . rawurlencode($postId));
    }

    public function problems(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        return Response::html($this->view->render('community/problems', [
            'areas' => CommunityServiceFactory::core($this->app)->publicProblemAreas(true, $userId),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('community_message'),
            'error' => $this->pullFlash('community_error'),
        ]));
    }

    public function createProblem(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/problems')) {
            return Response::redirect('/community/problems');
        }

        try {
            CommunityServiceFactory::core($this->app)->createPublicProblemArea($userId, [
                'name' => $request->input('name'),
                'city' => $request->input('city'),
                'district' => $request->input('district'),
                'state' => $request->input('state'),
                'latitude' => $request->input('latitude'),
                'longitude' => $request->input('longitude'),
                'radius_m' => $request->input('radius_m'),
            ]);
            $_SESSION['community_message'] = 'Öffentliche Problemstelle wurde zur Moderation eingereicht.';
        } catch (\InvalidArgumentException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/problems');
    }

    public function problem(Request $request): Response
    {
        $area = CommunityServiceFactory::core($this->app)->publicProblemArea(
            (string) $request->route('id', '')
        );

        if ($area === null) {
            return Response::html('<h1>404</h1><p>Öffentliche Problemstelle nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('community/problem', [
            'area' => $area,
            'viewerUserId' => $this->auth->id(),
            'csrf' => $this->auth->id() !== null ? Csrf::token() : null,
        ]));
    }

    public function observeProblem(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $areaId = (string) $request->route('id', '');
        if (!$this->csrf($request, '/community/problems/' . rawurlencode($areaId))) {
            return Response::redirect('/community/problems/' . rawurlencode($areaId));
        }

        try {
            $observationId = CommunityServiceFactory::core($this->app)->addPublicProblemObservation(
                $userId,
                $areaId,
                (string) $request->input('observation_date', ''),
                $this->nullable($request->input('offense_category')),
                $this->nullable($request->input('note'))
            );
            CommunityServiceFactory::reputation($this->app)->award(
                $userId,
                'PROBLEM_REPORT',
                1,
                'PUBLIC_PROBLEM_OBSERVATION',
                'PROBLEM_OBSERVATION',
                $observationId,
                'problem-observation:' . $observationId
            );
        } catch (\InvalidArgumentException|\DomainException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/problems/' . rawurlencode($areaId));
    }

    public function messages(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        return Response::html($this->view->render('community/messages', [
            'userId' => $userId,
            'messages' => CommunityServiceFactory::core($this->app)->inbox($userId),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('community_message'),
            'error' => $this->pullFlash('community_error'),
        ]));
    }

    public function sendMessage(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/messages')) {
            return Response::redirect('/community/messages');
        }

        try {
            CommunityServiceFactory::core($this->app)->sendMessageToUsername(
                $userId,
                (string) $request->input('username', ''),
                (string) $request->input('body', '')
            );
            $_SESSION['community_message'] = 'Nachricht gesendet.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/messages');
    }

    public function acceptMessage(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/messages')) {
            return Response::redirect('/community/messages');
        }

        try {
            CommunityServiceFactory::core($this->app)->acceptMessageRequest(
                $userId,
                (string) $request->route('id', '')
            );
            $_SESSION['community_message'] = 'Nachrichtenanfrage angenommen.';
        } catch (\DomainException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/messages');
    }

    public function blockUser(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/messages')) {
            return Response::redirect('/community/messages');
        }

        try {
            CommunityServiceFactory::core($this->app)->blockUsername(
                $userId,
                (string) $request->input('username', '')
            );
            $_SESSION['community_message'] = 'Nutzer wurde blockiert.';
        } catch (\InvalidArgumentException|\DomainException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/messages');
    }

    public function leaderboard(Request $request): Response
    {
        return Response::html($this->view->render('community/leaderboard', [
            'rows' => CommunityServiceFactory::reputation($this->app)->leaderboard(
                (string) $request->query('category', 'TOTAL'),
                $this->nullable($request->query('region_level')),
                $this->nullable($request->query('region_value'))
            ),
            'category' => (string) $request->query('category', 'TOTAL'),
        ]));
    }

    public function releases(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');

        $releaseService = CommunityServiceFactory::releases($this->app);

        return Response::html($this->view->render('community/releases', [
            'caseId' => $caseId,
            'releases' => $releaseService->ownedReleases($userId, $caseId),
            'availableEvidence' => $releaseService->availableEvidence($userId, $caseId),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('community_message'),
            'error' => $this->pullFlash('community_error'),
        ]));
    }

    public function createRelease(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = (string) $request->route('id', '');
        if (!$this->csrf($request, '/cases/' . rawurlencode($caseId) . '/community-release')) {
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/community-release');
        }

        $evidence = $request->input('evidence_ids', []);
        if (!is_array($evidence)) {
            $evidence = [];
        }

        try {
            CommunityServiceFactory::releases($this->app)->createDraft(
                $userId,
                $caseId,
                [
                    'public_text' => $request->input('public_text'),
                    'location_level' => $request->input('location_level'),
                    'include_date' => $request->input('include_date') === '1',
                    'include_offense' => $request->input('include_offense') === '1',
                ],
                $evidence
            );
            $_SESSION['community_message'] = 'Separate anonymisierte Community-Kopie als Entwurf erstellt.';
        } catch (\InvalidArgumentException|\DomainException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/community-release');
    }

    public function publishRelease(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));
        if (!$this->csrf($request, '/cases/' . rawurlencode($caseId) . '/community-release')) {
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/community-release');
        }

        try {
            CommunityServiceFactory::releases($this->app)->publish(
                $userId,
                (string) $request->route('id', '')
            );
            $_SESSION['community_message'] = 'Anonymisierte Community-Kopie veröffentlicht.';
        } catch (\DomainException|\RuntimeException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/community-release');
    }

    public function withdrawRelease(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        $caseId = trim((string) $request->input('case_id', ''));
        if (!$this->csrf($request, '/cases/' . rawurlencode($caseId) . '/community-release')) {
            return Response::redirect('/cases/' . rawurlencode($caseId) . '/community-release');
        }

        try {
            CommunityServiceFactory::releases($this->app)->withdraw(
                $userId,
                (string) $request->route('id', '')
            );
            $_SESSION['community_message'] = 'Community-Freigabe zurückgezogen.';
        } catch (\DomainException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/cases/' . rawurlencode($caseId) . '/community-release');
    }

    public function publicRelease(Request $request): Response
    {
        $release = CommunityServiceFactory::releases($this->app)->publicRelease(
            (string) $request->route('token', '')
        );

        if ($release === null) {
            return Response::html('<h1>404</h1><p>Freigabe nicht gefunden.</p>', 404);
        }

        return Response::html($this->view->render('community/public-release', [
            'release' => $release,
        ]));
    }

    public function publicReleaseEvidence(Request $request): Response
    {
        try {
            $file = CommunityServiceFactory::releases($this->app)->publicEvidence(
                (string) $request->route('token', ''),
                (int) $request->route('order', 0)
            );

            return Response::binary(
                $file['body'],
                $file['mime_type'],
                200,
                ['Cache-Control' => 'public, max-age=300', 'ETag' => '"' . $file['sha256'] . '"']
            );
        } catch (\DomainException $e) {
            return Response::html('<h1>404</h1>', 404);
        }
    }

    public function reportContent(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            return Response::redirect('/community');
        }

        try {
            CommunityServiceFactory::moderation($this->app)->report(
                $userId,
                (string) $request->input('target_type', ''),
                (string) $request->input('target_id', ''),
                (string) $request->input('category', ''),
                $this->nullable($request->input('reason'))
            );
            $_SESSION['community_message'] = 'Inhalt wurde an die Moderation gemeldet.';
        } catch (\InvalidArgumentException|\DomainException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect((string) $request->input('return_to', '/community'));
    }

    public function appeals(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        return Response::html($this->view->render('community/appeals', [
            'items' => CommunityServiceFactory::moderation($this->app)->appealableForUser($userId),
            'csrf' => Csrf::token(),
            'message' => $this->pullFlash('community_message'),
            'error' => $this->pullFlash('community_error'),
        ]));
    }

    public function submitAppeal(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/appeals')) {
            return Response::redirect('/community/appeals');
        }

        try {
            CommunityServiceFactory::moderation($this->app)->submitAppeal(
                $userId,
                (string) $request->route('id', ''),
                (string) $request->input('reason', '')
            );
            $_SESSION['community_message'] = 'Einspruch wurde eingereicht.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/appeals');
    }

    public function moderation(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        try {
            $service = CommunityServiceFactory::moderation($this->app);
            return Response::html($this->view->render('community/moderation', [
                'reports' => $service->queue($userId),
                'pendingProblems' => $service->pendingProblemAreas($userId),
                'appeals' => $service->appealsQueue($userId),
                'abuseFlags' => $service->abuseFlags($userId),
                'escalations' => $service->escalations($userId),
                'canResolveEscalations' => $service->canResolveEscalations($userId),
                'csrf' => Csrf::token(),
                'message' => $this->pullFlash('community_message'),
                'error' => $this->pullFlash('community_error'),
            ]));
        } catch (\MeldeVerkehr\Auth\AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Moderationszugriff.</p>', 403);
        }
    }

    public function approveProblem(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/moderation')) {
            return Response::redirect('/community/moderation');
        }

        try {
            $areaId = (string) $request->route('id', '');
            $moderation = CommunityServiceFactory::moderation($this->app);
            $moderation->approveProblemArea($userId, $areaId);

            $area = CommunityServiceFactory::core($this->app)->publicProblemArea($areaId, true);
            if (is_array($area)) {
                CommunityServiceFactory::reputation($this->app)->award(
                    (string) $area['created_by_user_id'],
                    'PROBLEM_REPORT',
                    5,
                    'PUBLIC_PROBLEM_APPROVED',
                    'PUBLIC_PROBLEM_AREA',
                    $areaId,
                    'problem-approved:' . $areaId
                );
            }
            $_SESSION['community_message'] = 'Öffentliche Problemstelle freigegeben.';
        } catch (\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/moderation');
    }

    public function resolveModeration(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/moderation')) {
            return Response::redirect('/community/moderation');
        }

        try {
            CommunityServiceFactory::moderation($this->app)->resolve(
                $userId,
                (string) $request->route('id', ''),
                (string) $request->input('action', ''),
                $this->nullable($request->input('reason'))
            );
            $_SESSION['community_message'] = 'Moderationsfall abgeschlossen.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/moderation');
    }

    public function resolveAppeal(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/moderation')) {
            return Response::redirect('/community/moderation');
        }

        try {
            CommunityServiceFactory::moderation($this->app)->resolveAppeal(
                $userId,
                (string) $request->route('id', ''),
                (string) $request->input('outcome', ''),
                (string) $request->input('reason', '')
            );
            $_SESSION['community_message'] = 'Einspruch wurde entschieden.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/moderation');
    }

    public function resolveAbuseFlag(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/moderation')) {
            return Response::redirect('/community/moderation');
        }

        try {
            CommunityServiceFactory::moderation($this->app)->resolveAbuseFlag(
                $userId,
                (string) $request->route('id', ''),
                (string) $request->input('action', ''),
                (string) $request->input('reason', '')
            );
            $_SESSION['community_message'] = 'Abuse-Flag wurde bearbeitet.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/moderation');
    }

    public function escalateModeration(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/moderation')) {
            return Response::redirect('/community/moderation');
        }

        try {
            CommunityServiceFactory::moderation($this->app)->escalate(
                $userId,
                (string) $request->input('source_type', ''),
                (string) $request->input('source_id', ''),
                (string) $request->input('priority', 'NORMAL'),
                (string) $request->input('reason', '')
            );
            $_SESSION['community_message'] = 'Fall wurde eskaliert.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/moderation');
    }

    public function resolveEscalation(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/moderation')) {
            return Response::redirect('/community/moderation');
        }

        try {
            CommunityServiceFactory::moderation($this->app)->resolveEscalation(
                $userId,
                (string) $request->route('id', ''),
                (string) $request->input('resolution', '')
            );
            $_SESSION['community_message'] = 'Eskalation wurde abgeschlossen.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/moderation');
    }

    public function reputationAdmin(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        try {
            $service = CommunityServiceFactory::reputation($this->app);

            return Response::html($this->view->render('community/reputation-admin', [
                'anomalies' => $service->anomalies($userId, 'OPEN'),
                'policies' => $service->policies(),
                'corrections' => $service->recentCorrections($userId),
                'csrf' => Csrf::token(),
                'message' => $this->pullFlash('community_message'),
                'error' => $this->pullFlash('community_error'),
            ]));
        } catch (\MeldeVerkehr\Auth\AuthorizationException $e) {
            return Response::html('<h1>403</h1><p>Kein Zugriff auf die Reputationsverwaltung.</p>', 403);
        }
    }

    public function correctReputation(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/moderation/reputation')) {
            return Response::redirect('/community/moderation/reputation');
        }

        try {
            CommunityServiceFactory::reputation($this->app)->adminCorrectionByUsername(
                $userId,
                (string) $request->input('username', ''),
                (string) $request->input('category', ''),
                (int) $request->input('points', 0),
                (string) $request->input('reason', '')
            );
            $_SESSION['community_message'] = 'Reputationskorrektur wurde revisionssicher gebucht.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/moderation/reputation');
    }

    public function resolveReputationAnomaly(Request $request): Response
    {
        $userId = $this->requireUser();
        if ($userId instanceof Response) {
            return $userId;
        }

        if (!$this->csrf($request, '/community/moderation/reputation')) {
            return Response::redirect('/community/moderation/reputation');
        }

        try {
            CommunityServiceFactory::reputation($this->app)->resolveAnomaly(
                $userId,
                (string) $request->route('id', ''),
                (string) $request->input('resolution', '')
            );
            $_SESSION['community_message'] = 'Reputationsanomalie wurde geprüft.';
        } catch (\InvalidArgumentException|\DomainException|\MeldeVerkehr\Auth\AuthorizationException $e) {
            $_SESSION['community_error'] = $e->getMessage();
        }

        return Response::redirect('/community/moderation/reputation');
    }

    private function csrf(Request $request, string $redirect): bool
    {
        if (!Csrf::validate((string) $request->input('_csrf', ''))) {
            $_SESSION['community_error'] = 'Sitzung abgelaufen. Bitte erneut versuchen.';
            return false;
        }

        return true;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function requireUser(): string|Response
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            return Response::redirect('/login');
        }

        $user = (new AuthService($this->app->database()))->findById($userId);
        if ($user === null || $user['status'] !== 'ACTIVE') {
            $this->auth->logout();
            return Response::redirect('/login');
        }

        if ($user['email_verified_at'] === null) {
            return Response::redirect('/verify-email/pending');
        }

        return $userId;
    }

    private function pullFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return is_string($value) ? $value : null;
    }
}
