@php
    $adminUser = Auth::guard('admin')->user();
    $adminUser?->loadMissing('roles:key');
    $isSuper = \App\Support\AdminAccess::isSuper($adminUser);
    $isCircleScoped = \App\Support\AdminAccess::isCircleScoped($adminUser);
    $isGlobalAdmin = \App\Support\AdminAccess::isGlobalAdmin($adminUser);

    $dashboardItem = $isCircleScoped
        ? null
        : ['icon' => 'bi-speedometer2', 'label' => 'Dashboard', 'route' => 'admin.dashboard'];

    $navItems = $isCircleScoped
        ? [
            ['icon' => 'bi-people', 'label' => 'Peers', 'route' => 'admin.users.index'],
            ['icon' => 'bi-coin', 'label' => 'Coins', 'route' => 'admin.coins.index'],
            ['icon' => 'bi-heart-pulse', 'label' => 'Life Impact', 'route' => 'admin.life-impact.index'],
            ['icon' => 'bi-envelope-paper', 'label' => 'Email Logs', 'route' => 'admin.email-logs.index'],
            ...($isGlobalAdmin ? [
                ['icon' => 'bi-calendar-check', 'label' => 'Events Management', 'route' => 'admin.events.index', 'active_routes' => ['admin.events.*']],
                ['icon' => 'bi-images', 'label' => 'Event Gallery', 'route' => 'admin.event-gallery.index'],
                ['icon' => 'bi-tags', 'label' => 'Circle Categories', 'route' => 'admin.categories.index'],
                ['icon' => 'bi-megaphone', 'label' => 'Ads', 'route' => 'admin.ads.index', 'active_routes' => ['admin.ads.*']],
                ['icon' => 'bi-lightning-charge', 'label' => 'Impact Option', 'route' => 'admin.impacts.index', 'active_routes' => ['admin.impacts.index', 'admin.impacts.store', 'admin.impacts.show', 'admin.impacts.posts']],
            ] : []),
        ]
        : [
            ['icon' => 'bi-people', 'label' => 'Peers', 'route' => 'admin.users.index'],
            ['icon' => 'bi-person-badge', 'label' => 'Leadership', 'route' => 'admin.execution.leadership'],
            ['icon' => 'bi-diagram-2', 'label' => 'Industries', 'route' => 'admin.execution.industries'],
            ...($isGlobalAdmin ? [['icon' => 'bi-clock-history', 'label' => 'Login History', 'route' => 'admin.login-history.index']] : []),
            ['icon' => 'bi-diagram-3', 'label' => 'Circles', 'route' => 'admin.circles.index'],
            ['icon' => 'bi-megaphone', 'label' => 'Circulars', 'route' => 'admin.circulars.index'],
            ['icon' => 'bi-coin', 'label' => 'Coins', 'route' => 'admin.coins.index'],
            ['icon' => 'bi-heart-pulse', 'label' => 'Life Impact', 'route' => 'admin.life-impact.index'],
            ['icon' => 'bi-envelope-paper', 'label' => 'Email Logs', 'route' => 'admin.email-logs.index'],
            ...($isGlobalAdmin ? [
                ['icon' => 'bi-calendar-check', 'label' => 'Events Management', 'route' => 'admin.events.index', 'active_routes' => ['admin.events.*']],
                ['icon' => 'bi-images', 'label' => 'Event Gallery', 'route' => 'admin.event-gallery.index'],
                ['icon' => 'bi-tags', 'label' => 'Circle Categories', 'route' => 'admin.categories.index'],
                ['icon' => 'bi-megaphone', 'label' => 'Ads', 'route' => 'admin.ads.index', 'active_routes' => ['admin.ads.*']],
                ['icon' => 'bi-lightning-charge', 'label' => 'Impact Option', 'route' => 'admin.impacts.index', 'active_routes' => ['admin.impacts.index', 'admin.impacts.store', 'admin.impacts.show', 'admin.impacts.posts']],
            ] : []),
            ['icon' => 'bi-wallet2', 'label' => 'Wallet & Finance', 'route' => 'admin.execution.finance'],
            ['icon' => 'bi-chat-dots', 'label' => 'Posts & Moderation', 'route' => '#'],
            ['icon' => 'bi-calendar-check', 'label' => 'Events Management', 'route' => 'admin.events.index', 'active_routes' => ['admin.events.*']],
            ['icon' => 'bi-calendar-event', 'label' => 'Events', 'route' => 'admin.execution.events'],
            ['icon' => 'bi-people-fill', 'label' => 'Referrals & Visitors', 'route' => '#'],
            ['icon' => 'bi-life-preserver', 'label' => 'Support & Feedback', 'route' => '#'],
            ['icon' => 'bi-bell', 'label' => 'Notifications & Email', 'route' => 'admin.execution.communications'],
            ['icon' => 'bi-calendar2-week', 'label' => 'Meetings & Warnings', 'route' => 'admin.execution.meetings'],
            ['icon' => 'bi-shield-lock', 'label' => 'Audit & Compliance', 'route' => 'admin.execution.reports'],
            ['icon' => 'bi-gear', 'label' => 'System Settings', 'route' => '#'],
        ];

    $activityMenu = ($isSuper || $isCircleScoped) ? [
        ['label' => 'Summary', 'route' => 'admin.activities.index'],
        ['label' => 'Testimonials', 'route' => 'admin.activities.testimonials.index'],
        ['label' => 'Requirements', 'route' => 'admin.activities.requirements.index'],
        ['label' => 'Referrals', 'route' => 'admin.activities.referrals.index'],
        ['label' => 'P2P Meetings', 'route' => 'admin.activities.p2p-meetings.index'],
        ['label' => 'Business Deals', 'route' => 'admin.activities.business-deals.index'],
        ['label' => 'Become A Leader', 'route' => 'admin.activities.become-a-leader.index'],
        ['label' => 'Recommend A Peer', 'route' => 'admin.activities.recommend-peer.index'],
        ['label' => 'Find & Build Collaborations', 'route' => 'admin.collaborations.index'],
        ['label' => 'Register A Visitor', 'route' => 'admin.activities.register-visitor.index'],
    ] : [];

    $activityActive = request()->routeIs('admin.activities.*') || request()->routeIs('admin.collaborations.*');
    $referralReportItem = ($isSuper || $isCircleScoped)
        ? ['icon' => 'bi-person-lines-fill', 'label' => 'Referral Report', 'route' => 'admin.referral-report.index', 'active_routes' => ['admin.referral-report.*']]
        : null;
    $activityExpanded = $activityActive || ! $isGlobalAdmin;

    $postsMenu = $isGlobalAdmin ? [
        ['label' => 'All Posts', 'route' => 'admin.posts.index'],
        ['label' => 'Post Reports', 'route' => 'admin.post-reports.index'],
    ] : [];
    $postsActive = request()->routeIs('admin.posts.*') || request()->routeIs('admin.post-reports.*');

    $leadsMenu = [
        ['label' => 'Entrepreneur Certification', 'route' => 'admin.leads.entrepreneur-certification.index'],
        ['label' => 'Leadership Certification', 'route' => 'admin.leads.leadership-certification.index'],
        ['label' => 'Partner With Us', 'route' => 'admin.leads.partner-with-us.index'],
        ['label' => 'Become Speaker', 'route' => 'admin.leads.become-speaker.index'],
        ['label' => 'Become Mentor', 'route' => 'admin.leads.become-mentor.index'],
    ];

    $pendingRequestsMenu = [
        ['label' => 'Visitor Registrations', 'route' => 'admin.visitor-registrations.index'],
        ['label' => 'Coin Claims', 'route' => 'admin.coin-claims.index'],
        ['label' => 'Circle Joining Requests', 'route' => 'admin.circle-joining-requests.index'],
        ['label' => 'Pending Impacts', 'route' => 'admin.impacts.pending'],
    ];

    $leadsActive = request()->routeIs('admin.leads.*');
    $pendingRequestsActive =
        request()->routeIs('admin.visitor-registrations.*') ||
        request()->routeIs('admin.coin-claims.*') ||
        request()->routeIs('admin.circle-joining-requests.*') ||
        request()->routeIs('admin.impacts.pending');
@endphp

<aside class="admin-sidebar d-flex flex-column">
    <div class="text-center mb-2">
        <a href="{{ route('admin.users.index') }}" class="d-inline-block">
            <img
                src="/api/v1/files/019bd9d7-7e13-71fc-8395-0e1dd20a268b"
                alt="Peers Global Unity"
                style="max-height:68px; width:auto;"
                class="d-block mx-auto my-3"
                loading="lazy"
            />
        </a>
    </div>

    <nav class="flex-grow-1">
        <ul class="nav flex-column">
            @if ($dashboardItem)
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs($dashboardItem['route']) ? 'active' : '' }}" href="{{ route($dashboardItem['route']) }}">
                        <i class="bi {{ $dashboardItem['icon'] }} me-2"></i>{{ $dashboardItem['label'] }}
                    </a>
                </li>
            @endif

            @if ($activityMenu)
                <li class="nav-item menu-parent {{ $activityExpanded ? 'open' : '' }}">
                    <a class="nav-link d-flex justify-content-between align-items-center {{ $activityExpanded ? 'active' : '' }}" data-bs-toggle="collapse" href="#activitiesSubmenu" role="button" aria-expanded="{{ $activityExpanded ? 'true' : 'false' }}" aria-controls="activitiesSubmenu">
                        <span><i class="bi bi-activity me-2"></i>Activities</span>
                        <i class="bi bi-chevron-right menu-arrow"></i>
                    </a>
                    <div class="collapse {{ $activityExpanded ? 'show' : '' }}" id="activitiesSubmenu">
                        <ul class="nav flex-column ms-3">
                            @foreach ($activityMenu as $item)
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs($item['route']) ? 'active' : '' }}" href="{{ route($item['route']) }}">
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </li>
            @endif

            @if ($referralReportItem)
                <li class="nav-item">
                    <a class="nav-link {{ request()->routeIs(...$referralReportItem['active_routes']) ? 'active' : '' }}" href="{{ route($referralReportItem['route']) }}">
                        <i class="bi {{ $referralReportItem['icon'] }} me-2"></i>{{ $referralReportItem['label'] }}
                    </a>
                </li>
            @endif

            @if ($postsMenu)
                <li class="nav-item menu-parent {{ $postsActive ? 'open' : '' }}">
                    <a class="nav-link d-flex justify-content-between align-items-center {{ $postsActive ? 'active' : '' }}" data-bs-toggle="collapse" href="#postsSubmenu" role="button" aria-expanded="{{ $postsActive ? 'true' : 'false' }}" aria-controls="postsSubmenu">
                        <span><i class="bi bi-chat-dots me-2"></i>Posts &amp; Timeline</span>
                        <i class="bi bi-chevron-right menu-arrow"></i>
                    </a>
                    <div class="collapse {{ $postsActive ? 'show' : '' }}" id="postsSubmenu">
                        <ul class="nav flex-column ms-3">
                            @foreach ($postsMenu as $item)
                                <li class="nav-item">
                                    <a class="nav-link {{ request()->routeIs($item['route']) ? 'active' : '' }}" href="{{ route($item['route']) }}">
                                        {{ $item['label'] }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </li>
            @endif

            <li class="nav-item menu-parent {{ $pendingRequestsActive ? 'open' : '' }}">
                <a class="nav-link d-flex justify-content-between align-items-center {{ $pendingRequestsActive ? 'active' : '' }}" data-bs-toggle="collapse" href="#pendingRequestsSubmenu" role="button" aria-expanded="{{ $pendingRequestsActive ? 'true' : 'false' }}" aria-controls="pendingRequestsSubmenu">
                    <span><i class="bi bi-hourglass-split me-2"></i>Pending Requests</span>
                    <i class="bi bi-chevron-right menu-arrow"></i>
                </a>
                <div class="collapse {{ $pendingRequestsActive ? 'show' : '' }}" id="pendingRequestsSubmenu">
                    <ul class="nav flex-column ms-3">
                        @foreach ($pendingRequestsMenu as $item)
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs($item['route']) ? 'active' : '' }}" href="{{ route($item['route']) }}">
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </li>

            <li class="nav-item menu-parent {{ $leadsActive ? 'open' : '' }}">
                <a class="nav-link d-flex justify-content-between align-items-center {{ $leadsActive ? 'active' : '' }}" data-bs-toggle="collapse" href="#leadsSubmenu" role="button" aria-expanded="{{ $leadsActive ? 'true' : 'false' }}" aria-controls="leadsSubmenu">
                    <span><i class="bi bi-person-lines-fill me-2"></i>Leads</span>
                    <i class="bi bi-chevron-right menu-arrow"></i>
                </a>
                <div class="collapse {{ $leadsActive ? 'show' : '' }}" id="leadsSubmenu">
                    <ul class="nav flex-column ms-3">
                        @foreach ($leadsMenu as $item)
                            <li class="nav-item">
                                <a class="nav-link {{ request()->routeIs($item['route']) ? 'active' : '' }}" href="{{ route($item['route']) }}">
                                    {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </li>

            @foreach ($navItems as $item)
                <li class="nav-item">
                    @if ($item['route'] === '#')
                        <span class="nav-link disabled">
                            <i class="bi {{ $item['icon'] }} me-2"></i>{{ $item['label'] }}
                        </span>
                    @else
                        <a class="nav-link {{ (isset($item['active_routes']) ? request()->routeIs(...$item['active_routes']) : request()->routeIs($item['route'])) ? 'active' : '' }}" href="{{ route($item['route']) }}">
                            <i class="bi {{ $item['icon'] }} me-2"></i>{{ $item['label'] }}
                        </a>
                    @endif
                </li>
            @endforeach
        </ul>
    </nav>

    <div class="sidebar-footer">
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button class="btn btn-outline-secondary w-100">
                <i class="bi bi-box-arrow-right me-2"></i>Logout
            </button>
        </form>
    </div>
</aside>

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            ['activitiesSubmenu', 'postsSubmenu', 'pendingRequestsSubmenu', 'leadsSubmenu'].forEach((submenuId) => {
                const submenu = document.getElementById(submenuId);
                if (!submenu) {
                    return;
                }

                const parentItem = submenu.closest('.menu-parent');
                if (!parentItem) {
                    return;
                }

                if (submenu.classList.contains('show')) {
                    parentItem.classList.add('open');
                }

                submenu.addEventListener('show.bs.collapse', () => {
                    parentItem.classList.add('open');
                });

                submenu.addEventListener('hide.bs.collapse', () => {
                    parentItem.classList.remove('open');
                });
            });
        });
    </script>
@endpush
