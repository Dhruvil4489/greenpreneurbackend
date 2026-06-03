@php
    $admin = auth('admin')->user();
    $isSuper = \App\Support\AdminAccess::isSuper($admin);
    $isCircleScoped = \App\Support\AdminAccess::isCircleScoped($admin);
    $isDed = \App\Support\AdminAccess::isDed($admin);
    $roleBadge = $isSuper
        ? 'Global Admin'
        : ($isDed ? 'DED' : ($isCircleScoped ? \App\Support\AdminAccess::primaryCircleRoleLabel($admin) : 'Admin'));
@endphp
<header class="admin-topbar d-flex align-items-center justify-content-between px-4 py-3 border-bottom bg-white">
    <div class="d-flex align-items-center gap-3 flex-grow-1">
        <div class="search-box flex-grow-1">
            <form class="w-100">
                <div class="input-group">
                    <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control border-start-0" placeholder="Search anything...">
                </div>
            </form>
        </div>
        <div class="d-none d-md-flex align-items-center gap-2">
            <div class="dropdown">
                <button class="btn btn-light dropdown-toggle" type="button" data-bs-toggle="dropdown">
                    Quick Actions
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    @if ($isSuper && ! $isCircleScoped)
                        <li><a class="dropdown-item" href="{{ route('admin.users.index') }}">View Peers</a></li>
                    @endif
                    @if ($isCircleScoped || $isDed)
                        <li><a class="dropdown-item" href="{{ route('admin.users.index') }}">View Peers</a></li>
                        <li><a class="dropdown-item" href="{{ route('admin.activities.index') }}">View Activities</a></li>
                        <li><a class="dropdown-item" href="{{ route('admin.coins.index') }}">View Coins</a></li>
                    @else
                        <li><a class="dropdown-item" href="{{ route('admin.circles.index') }}">View Circles</a></li>
                    @endif
                    <li><a class="dropdown-item disabled" href="#">Create Announcement</a></li>
                </ul>
            </div>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">{{ $roleBadge }}</span>
            <button class="btn btn-light position-relative">
                <i class="bi bi-bell"></i>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">3</span>
            </button>
            <div class="dropdown">
                <a class="d-flex align-items-center text-decoration-none dropdown-toggle" href="#" data-bs-toggle="dropdown">
                    <img src="https://ui-avatars.com/api/?name={{ urlencode($admin?->name ?? 'Admin') }}&background=0D6EFD&color=fff" class="rounded-circle me-2" width="36" height="36" alt="Admin Avatar">
                    <div class="d-none d-lg-block">
                        <div class="fw-semibold">{{ $admin?->name ?? 'Admin' }}</div>
                        <small class="text-muted">{{ $admin?->email ?? '' }}</small>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li class="dropdown-item-text">{{ $admin?->email ?? '' }}</li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('admin.logout') }}">
                            @csrf
                            <button class="dropdown-item">Logout</button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</header>
