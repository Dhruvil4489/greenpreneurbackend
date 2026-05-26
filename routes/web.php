<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\Auth\AdminAuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\UsersController;
use App\Http\Controllers\Admin\Circles\CircleController;
use App\Http\Controllers\Admin\Circles\CircleMemberController;
use App\Http\Controllers\Admin\Users\UserSearchController;
use App\Http\Controllers\Admin\ActivitiesController;
use App\Http\Controllers\Admin\ActivitiesBusinessDealsController;
use App\Http\Controllers\Admin\ActivitiesLeaderInterestController;
use App\Http\Controllers\Admin\ActivitiesP2PMeetingsController;
use App\Http\Controllers\Admin\ActivitiesPeerRecommendationController;
use App\Http\Controllers\Admin\ActivitiesReferralsController;
use App\Http\Controllers\Admin\ActivitiesRequirementsController;
use App\Http\Controllers\Admin\ActivitiesTestimonialsController;
use App\Http\Controllers\Admin\ActivitiesVisitorRegistrationController;
use App\Http\Controllers\Admin\CirclePeersController;
use App\Http\Controllers\Admin\CoinsController;
use App\Http\Controllers\Admin\LifeImpactController;
use App\Http\Controllers\Admin\CollaborationPostController;
use App\Http\Controllers\Admin\CoinClaimsController;
use App\Http\Controllers\Admin\CircleJoinRequestsController;
use App\Http\Controllers\Admin\EventGalleryController;
use App\Http\Controllers\Admin\LoginHistoryController;
use App\Http\Controllers\Admin\MembershipPlanController;
use App\Http\Controllers\Admin\PostReportsController;
use App\Http\Controllers\Admin\PostModerationController;
use App\Http\Controllers\Admin\VisitorRegistrationsController;
use App\Http\Controllers\Admin\CircularController;
use App\Http\Controllers\Admin\CategoryController;
use App\Http\Controllers\Admin\AdController;
use App\Http\Controllers\Admin\EmailLogController;
use App\Http\Controllers\Admin\AdminCampaignController;
use App\Http\Controllers\Admin\CampaignPamphletController;
use App\Http\Controllers\Admin\CampaignEmailTemplateController;
use App\Http\Controllers\Admin\ImpactsController;
use App\Http\Controllers\Admin\LeadSubmissionsController;
use App\Http\Controllers\Admin\ReferralReportController;
use App\Http\Controllers\Admin\AdminExecutionController;
use App\Http\Controllers\Admin\EventManagementController;
use App\Http\Controllers\Admin\ActivityCreativeController;

Route::get('/', function () {
    return view('landing');
});

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('/login', [AdminAuthController::class, 'showLogin'])->name('login');
    Route::post('/login/send-otp', [AdminAuthController::class, 'requestOtp'])->name('login.send-otp');
    Route::post('/login/verify', [AdminAuthController::class, 'verifyOtp'])->name('login.verify');

    Route::middleware(['admin.auth', 'admin.role', 'admin.circle'])->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout'])->name('logout');
        Route::get('/', function () {
            return redirect()->route('admin.dashboard');
        })->name('home');
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/users', [UsersController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UsersController::class, 'create'])->name('users.create');
        Route::post('/users', [UsersController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit', [UsersController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UsersController::class, 'update'])->name('users.update');
        Route::delete('/users/{user}/circle-members/{circleMember}', [UsersController::class, 'removeCircleMembership'])->name('users.circle-members.destroy');
        Route::post('/users/{user}/roles/remove', [UsersController::class, 'removeRole'])->name('users.roles.remove');
        Route::post('/users/{user}/membership-welcome-email/send', [UsersController::class, 'sendWelcomeMembershipEmail'])->name('users.membership-welcome-email.send');
        Route::get('/activities', [ActivitiesController::class, 'index'])->name('activities.index');
        Route::post('/activities/export', [ActivitiesController::class, 'export'])->name('activities.export');
        Route::get('/activities/testimonials', [ActivitiesTestimonialsController::class, 'index'])->name('activities.testimonials.index');
        Route::get('/activities/testimonials/export', [ActivitiesTestimonialsController::class, 'export'])->name('activities.testimonials.export');
        Route::get('/activities/requirements', [ActivitiesRequirementsController::class, 'index'])->name('activities.requirements.index');
        Route::get('/activities/requirements/export', [ActivitiesRequirementsController::class, 'export'])->name('activities.requirements.export');
        Route::get('/activities/referrals', [ActivitiesReferralsController::class, 'index'])->name('activities.referrals.index');
        Route::get('/activities/referrals/export', [ActivitiesReferralsController::class, 'export'])->name('activities.referrals.export');
        Route::get('/referral-report', [ReferralReportController::class, 'index'])->name('referral-report.index');
        Route::get('/referral-report/export', [ReferralReportController::class, 'export'])->name('referral-report.export');
        Route::get('/referral-report/{referrer_user_id}', [ReferralReportController::class, 'show'])->name('referral-report.show');
        Route::get('/activities/p2p-meetings', [ActivitiesP2PMeetingsController::class, 'index'])->name('activities.p2p-meetings.index');
        Route::get('/activities/p2p-meetings/export', [ActivitiesP2PMeetingsController::class, 'export'])->name('activities.p2p-meetings.export');
        Route::get('/activities/business-deals', [ActivitiesBusinessDealsController::class, 'index'])->name('activities.business-deals.index');
        Route::get('/activities/business-deals/export', [ActivitiesBusinessDealsController::class, 'export'])->name('activities.business-deals.export');
        Route::get('/activity-creatives', [ActivityCreativeController::class, 'index'])->name('activity-creatives.index');
        Route::get('/activities/become-a-leader', [ActivitiesLeaderInterestController::class, 'index'])->name('activities.become-a-leader.index');
        Route::get('/activities/recommend-peer', [ActivitiesPeerRecommendationController::class, 'index'])->name('activities.recommend-peer.index');
        Route::get('/activities/register-visitor', [ActivitiesVisitorRegistrationController::class, 'index'])->name('activities.register-visitor.index');
        Route::get('/collaborations', [CollaborationPostController::class, 'index'])->name('collaborations.index');
        Route::get('/collaborations/export', [CollaborationPostController::class, 'export'])->name('collaborations.export');
        Route::get('/collaborations/{id}', [CollaborationPostController::class, 'show'])->name('collaborations.show');
        Route::get('/activities/{peer}/become-a-leader', [ActivitiesLeaderInterestController::class, 'show'])
            ->whereUuid('peer')
            ->name('activities.become-a-leader.show');
        Route::get('/activities/{peer}/recommend-peer', [ActivitiesPeerRecommendationController::class, 'show'])
            ->whereUuid('peer')
            ->name('activities.recommend-peer.show');
        Route::get('/activities/{peer}/register-visitor', [ActivitiesVisitorRegistrationController::class, 'show'])
            ->whereUuid('peer')
            ->name('activities.register-visitor.show');
        Route::get('/activities/{member}/testimonials', [ActivitiesController::class, 'testimonials'])->name('activities.testimonials');
        Route::get('/activities/{member}/referrals', [ActivitiesController::class, 'referrals'])->name('activities.referrals');
        Route::get('/activities/{member}/business-deals', [ActivitiesController::class, 'businessDeals'])->name('activities.business-deals');
        Route::get('/activities/{member}/p2p-meetings', [ActivitiesController::class, 'p2pMeetings'])->name('activities.p2p-meetings');
        Route::get('/activities/{member}/requirements', [ActivitiesController::class, 'requirements'])->name('activities.requirements');
        Route::get('/coins', [CoinsController::class, 'index'])->name('coins.index');
        Route::get('/coins/export', [CoinsController::class, 'exportIndex'])->name('coins.export');
        Route::get('/life-impact', [LifeImpactController::class, 'index'])
            ->name('life-impact.index');
        Route::get('/life-impact/export', [LifeImpactController::class, 'export'])
            ->name('life-impact.export');
        Route::get('/life-impact/{member}/history', [LifeImpactController::class, 'history'])
            ->name('life-impact.history');
        Route::get('/life-impact/{member}/history/{category}', [LifeImpactController::class, 'history'])
            ->name('life-impact.history.category');
        Route::get('/coins/add', [CoinsController::class, 'create'])->name('coins.create');
        Route::post('/coins/add', [CoinsController::class, 'store'])->name('coins.store');
        Route::get('/coins/{member}/ledger', [CoinsController::class, 'ledger'])->name('coins.ledger');
        Route::get('/coins/{member}/ledger/{type}', [CoinsController::class, 'ledgerByType'])->name('coins.ledger.type');
        Route::get('/coins/{member}/ledger-export', [CoinsController::class, 'exportLedger'])->name('coins.ledger.export');
        Route::get('/unity-peers-plans', [MembershipPlanController::class, 'index'])->name('unity-peers-plans.index');
        Route::get('/unity-peers-plans/create', [MembershipPlanController::class, 'create'])->name('unity-peers-plans.create');
        Route::post('/unity-peers-plans', [MembershipPlanController::class, 'store'])->name('unity-peers-plans.store');
        Route::get('/unity-peers-plans/{plan}/edit', [MembershipPlanController::class, 'edit'])->name('unity-peers-plans.edit');
        Route::get('/login-history', [LoginHistoryController::class, 'index'])->name('login-history.index');
        Route::put('/unity-peers-plans/{plan}', [MembershipPlanController::class, 'update'])->name('unity-peers-plans.update');
        Route::post('/files/upload', [\App\Http\Controllers\Admin\AdminFileUploadController::class, 'upload'])->name('files.upload');
        Route::get('/users/import', [UsersController::class, 'importForm'])->name('users.import');
        Route::post('/users/import', [UsersController::class, 'import'])->name('users.import.submit');
        Route::post('/users/export/csv', [UsersController::class, 'exportCsv'])->name('users.export.csv');
        Route::get('/users/search', UserSearchController::class)->name('users.search');
        
        Route::get('/circulars', [CircularController::class, 'index'])->name('circulars.index');
        Route::get('/circulars/create', [CircularController::class, 'create'])->name('circulars.create');
        Route::post('/circulars', [CircularController::class, 'store'])->name('circulars.store');
        Route::get('/circulars/{circular}', [CircularController::class, 'show'])->name('circulars.show');
        Route::get('/circulars/{circular}/edit', [CircularController::class, 'edit'])->name('circulars.edit');
        Route::put('/circulars/{circular}', [CircularController::class, 'update'])->name('circulars.update');
        Route::delete('/circulars/{circular}', [CircularController::class, 'destroy'])->name('circulars.destroy');

        Route::get('/circles', [CircleController::class, 'index'])->name('circles.index');
        Route::get('/circles/create', [CircleController::class, 'create'])->name('circles.create');
        Route::post('/circles', [CircleController::class, 'store'])->name('circles.store');
        Route::get('/circles/{circle}', [CircleController::class, 'show'])->name('circles.show');
        Route::get('/circles/{circle}/edit', [CircleController::class, 'edit'])->name('circles.edit');
        Route::put('/circles/{circle}', [CircleController::class, 'update'])->name('circles.update');
        Route::delete('/circles/{circle}', [CircleController::class, 'destroy'])->name('circles.destroy');
        Route::post('/circles/{circle}/members', [CircleMemberController::class, 'store'])->name('circles.members.store');
        Route::get('/circles/{circle}/peer-options', [CirclePeersController::class, 'peerOptions'])->name('circles.peer-options');
        Route::put('/circles/{circle}/members/{circleMember}', [CircleMemberController::class, 'update'])->name('circles.members.update');
        Route::delete('/circles/{circle}/members/{circleMember}', [CircleMemberController::class, 'destroy'])->name('circles.members.destroy');
        Route::get('/events', [EventManagementController::class, 'index'])->name('events.index');
        Route::get('/events/create', [EventManagementController::class, 'create'])->name('events.create');
        Route::post('/events', [EventManagementController::class, 'store'])->name('events.store');
        Route::get('/events/{id}', [EventManagementController::class, 'show'])->name('events.show');
        Route::get('/events/{id}/attendance', [EventManagementController::class, 'attendance'])->name('events.attendance');
        Route::post('/events/registrations/{registration_id}/sync-zoho-invoice', [EventManagementController::class, 'syncZohoInvoice'])->name('events.registrations.sync-zoho-invoice');

        Route::get('/event-gallery', [EventGalleryController::class, 'index'])->name('event-gallery.index');
        Route::post('/event-gallery/events', [EventGalleryController::class, 'storeEvent'])->name('event-gallery.events.store');
        Route::post('/event-gallery/media', [EventGalleryController::class, 'storeMedia'])->name('event-gallery.media.store');
        Route::delete('/event-gallery/media/{id}', [EventGalleryController::class, 'destroyMedia'])->name('event-gallery.media.destroy');
        Route::get('/categories/export', [CategoryController::class, 'export'])->name('categories.export');
        Route::post('/categories/import', [CategoryController::class, 'import'])->name('categories.import');
        Route::get('/categories/{category}/view', [CategoryController::class, 'show'])->name('categories.view');
        Route::post('/categories/{category}/level2', [CategoryController::class, 'storeLevel2'])->name('categories.level2.store');
        Route::post('/categories/{category}/level3', [CategoryController::class, 'storeLevel3'])->name('categories.level3.store');
        Route::post('/categories/{category}/level4', [CategoryController::class, 'storeLevel4'])->name('categories.level4.store');
        Route::resource('categories', CategoryController::class)->except(['show']);
        Route::get('/ads', [AdController::class, 'index'])->name('ads.index');
        Route::get('/ads/create', [AdController::class, 'create'])->name('ads.create');
        Route::post('/ads', [AdController::class, 'store'])->name('ads.store');
        Route::get('/ads/{ad}/edit', [AdController::class, 'edit'])->name('ads.edit');
        Route::put('/ads/{ad}', [AdController::class, 'update'])->name('ads.update');
        Route::patch('/ads/{ad}/toggle-status', [AdController::class, 'toggleStatus'])->name('ads.toggle-status');
        Route::delete('/ads/{ad}', [AdController::class, 'destroy'])->name('ads.destroy');
        Route::get('/posts', [PostModerationController::class, 'index'])->name('posts.index');
        Route::get('/posts/{post}', [PostModerationController::class, 'show'])->name('posts.show');
        Route::post('/posts/impacts/{impact}/deactivate', [PostModerationController::class, 'deactivateImpact'])->whereUuid('impact')->name('posts.impacts.deactivate');
        Route::get('/post-reports', [PostReportsController::class, 'index'])->name('post-reports.index');
        Route::get('/post-reports/{report}', [PostReportsController::class, 'show'])->name('post-reports.show');
        Route::post('/post-reports/{report}/mark-reviewed', [PostReportsController::class, 'markReviewed'])->name('post-reports.mark-reviewed');
        Route::post('/post-reports/{report}/dismiss', [PostReportsController::class, 'dismiss'])->name('post-reports.dismiss');
        Route::post('/post-reports/{report}/resolve', [PostReportsController::class, 'resolve'])->name('post-reports.resolve');
        Route::delete('/posts/{post}', [PostModerationController::class, 'destroy'])->name('posts.destroy');
        Route::post('/posts/{post}/deactivate', [PostModerationController::class, 'deactivate'])->name('posts.deactivate');
        Route::post('/posts/{post}/restore', [PostModerationController::class, 'restore'])->name('posts.restore');
        Route::get('/visitor-registrations', [VisitorRegistrationsController::class, 'index'])->name('visitor-registrations.index');
        Route::post('/visitor-registrations/{id}/approve', [VisitorRegistrationsController::class, 'approve'])
            ->whereUuid('id')
            ->name('visitor-registrations.approve');
        Route::post('/visitor-registrations/{id}/reject', [VisitorRegistrationsController::class, 'reject'])
            ->whereUuid('id')
            ->name('visitor-registrations.reject');
        Route::get('/coin-claims', [CoinClaimsController::class, 'index'])->name('coin-claims.index');
        Route::get('/coin-claims/{id}', [CoinClaimsController::class, 'show'])->whereUuid('id')->name('coin-claims.show');
        Route::post('/coin-claims/{id}/approve', [CoinClaimsController::class, 'approve'])->whereUuid('id')->name('coin-claims.approve');
        Route::post('/coin-claims/{id}/reject', [CoinClaimsController::class, 'reject'])->whereUuid('id')->name('coin-claims.reject');
        Route::get('/pending-requests/circle-joining-requests', [CircleJoinRequestsController::class, 'index'])->name('circle-joining-requests.index');
        Route::get('/pending-requests/circle-joining-requests/{id}', [CircleJoinRequestsController::class, 'show'])->whereUuid('id')->name('circle-joining-requests.show');
        Route::post('/pending-requests/circle-joining-requests/{id}/approve-cd', [CircleJoinRequestsController::class, 'approveCd'])->whereUuid('id')->name('circle-joining-requests.approve-cd');
        Route::post('/pending-requests/circle-joining-requests/{id}/reject-cd', [CircleJoinRequestsController::class, 'rejectCd'])->whereUuid('id')->name('circle-joining-requests.reject-cd');
        Route::post('/pending-requests/circle-joining-requests/{id}/approve-id', [CircleJoinRequestsController::class, 'approveId'])->whereUuid('id')->name('circle-joining-requests.approve-id');
        Route::post('/pending-requests/circle-joining-requests/{id}/reject-id', [CircleJoinRequestsController::class, 'rejectId'])->whereUuid('id')->name('circle-joining-requests.reject-id');

        Route::get('/pending-requests/leads/entrepreneur-certification', [LeadSubmissionsController::class, 'entrepreneurCertification'])->name('leads.entrepreneur-certification.index');
        Route::get('/pending-requests/leads/entrepreneur-certification/{id}', [LeadSubmissionsController::class, 'entrepreneurCertificationShow'])->name('leads.entrepreneur-certification.show');
        Route::get('/pending-requests/leads/leadership-certification', [LeadSubmissionsController::class, 'leadershipCertification'])->name('leads.leadership-certification.index');
        Route::get('/pending-requests/leads/leadership-certification/{id}', [LeadSubmissionsController::class, 'leadershipCertificationShow'])->name('leads.leadership-certification.show');
        Route::get('/pending-requests/leads/partner-with-us', [LeadSubmissionsController::class, 'partnerWithUs'])->name('leads.partner-with-us.index');
        Route::get('/pending-requests/leads/partner-with-us/{id}', [LeadSubmissionsController::class, 'partnerWithUsShow'])->name('leads.partner-with-us.show');
        Route::get('/pending-requests/leads/become-speaker', [LeadSubmissionsController::class, 'becomeSpeaker'])->name('leads.become-speaker.index');
        Route::get('/pending-requests/leads/become-speaker/{id}', [LeadSubmissionsController::class, 'becomeSpeakerShow'])->name('leads.become-speaker.show');
        Route::get('/pending-requests/leads/become-mentor', [LeadSubmissionsController::class, 'becomeMentor'])->name('leads.become-mentor.index');
        Route::get('/pending-requests/leads/become-mentor/{id}', [LeadSubmissionsController::class, 'becomeMentorShow'])->name('leads.become-mentor.show');
        Route::get('/campaign-email-templates', [CampaignEmailTemplateController::class, 'index'])->name('campaign-email-templates.index');
        Route::get('/campaign-email-templates/list', [CampaignEmailTemplateController::class, 'list'])->name('campaign-email-templates.list');
        Route::get('/campaigns', [AdminCampaignController::class, 'index'])->name('campaigns.index');
        Route::get('/campaigns/create', [AdminCampaignController::class, 'create'])->name('campaigns.create');
        Route::post('/campaigns', [AdminCampaignController::class, 'store'])->name('campaigns.store');
        Route::post('/campaigns/preview-recipients', [AdminCampaignController::class, 'previewRecipients'])->name('campaigns.preview-recipients');
        Route::post('/campaigns/import-audience', [AdminCampaignController::class, 'importAudience'])->name('campaigns.import-audience');
        Route::get('/campaigns/audience-samples/{audienceType}', [AdminCampaignController::class, 'downloadAudienceSample'])->name('campaigns.audience-samples');
        Route::get('/campaigns/filter-options', [AdminCampaignController::class, 'filterOptions'])->name('campaigns.filter-options');
        Route::get('/campaigns/member-search', [AdminCampaignController::class, 'memberSearch'])->name('campaigns.member-search');
        Route::get('/campaigns/{campaign}', [AdminCampaignController::class, 'show'])->name('campaigns.show');
        Route::get('/campaigns/{campaign}/edit', [AdminCampaignController::class, 'edit'])->name('campaigns.edit');
        Route::put('/campaigns/{campaign}', [AdminCampaignController::class, 'update'])->name('campaigns.update');
        Route::post('/campaigns/{campaign}/send', [AdminCampaignController::class, 'send'])->name('campaigns.send');
        Route::get('/campaign-pamphlets/select-list', [CampaignPamphletController::class, 'selectList'])->name('campaign-pamphlets.select-list');
        Route::get('/campaign-pamphlets', [CampaignPamphletController::class, 'index'])->name('campaign-pamphlets.index');
        Route::get('/campaign-pamphlets/create', [CampaignPamphletController::class, 'create'])->name('campaign-pamphlets.create');
        Route::post('/campaign-pamphlets', [CampaignPamphletController::class, 'store'])->name('campaign-pamphlets.store');
        Route::get('/campaign-pamphlets/{pamphlet}/edit', [CampaignPamphletController::class, 'edit'])->name('campaign-pamphlets.edit');
        Route::put('/campaign-pamphlets/{pamphlet}', [CampaignPamphletController::class, 'update'])->name('campaign-pamphlets.update');
        Route::delete('/campaign-pamphlets/{pamphlet}', [CampaignPamphletController::class, 'destroy'])->name('campaign-pamphlets.destroy');

        Route::get('/email-logs', [EmailLogController::class, 'index'])->name('email-logs.index');

        Route::get('/impacts', [ImpactsController::class, 'index'])->name('impacts.index');
        Route::get('/impacts/export/csv', [ImpactsController::class, 'exportCsv'])->name('impacts.export.csv');
        Route::post('/impacts', [ImpactsController::class, 'store'])->name('impacts.store');
        Route::post('/impacts/actions', [ImpactsController::class, 'storeAction'])->name('impacts.actions.store');
        Route::put('/impacts/actions/{id}', [ImpactsController::class, 'updateAction'])->whereUuid('id')->name('impacts.actions.update');
        Route::delete('/impacts/actions/{id}', [ImpactsController::class, 'destroyAction'])->whereUuid('id')->name('impacts.actions.destroy');
        Route::get('/impacts/pending', [ImpactsController::class, 'pending'])->name('impacts.pending');
        Route::get('/impacts/posts', [ImpactsController::class, 'posts'])->name('impacts.posts');
        Route::get('/impacts/{id}', [ImpactsController::class, 'show'])->whereUuid('id')->name('impacts.show');
        Route::post('/impacts/{id}/approve', [ImpactsController::class, 'approve'])->whereUuid('id')->name('impacts.approve');
        Route::post('/impacts/{id}/reject', [ImpactsController::class, 'reject'])->whereUuid('id')->name('impacts.reject');
        Route::get('/email-logs/{id}', [EmailLogController::class, 'show'])->name('email-logs.show');

        Route::get('/execution/leadership', [AdminExecutionController::class, 'leadership'])->name('execution.leadership');
        Route::get('/execution/industries', [AdminExecutionController::class, 'industries'])->name('execution.industries');
        Route::get('/execution/events', [AdminExecutionController::class, 'events'])->name('execution.events');
        Route::get('/execution/finance', [AdminExecutionController::class, 'finance'])->name('execution.finance');
        Route::get('/execution/communications', [AdminExecutionController::class, 'communications'])->name('execution.communications');
        Route::post('/execution/communications/broadcast', [AdminExecutionController::class, 'sendBroadcast'])->name('execution.broadcast.send');
        Route::get('/execution/meetings', [AdminExecutionController::class, 'meetings'])->name('execution.meetings');
        Route::get('/execution/reports', [AdminExecutionController::class, 'reports'])->name('execution.reports');
    });
});
