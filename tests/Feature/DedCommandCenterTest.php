<?php

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Circle;
use App\Models\CircleMember;
use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DedCommandCenterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        \App\Support\AdminCircleScope::resetCache();
        $this->createSchema();

        $roleKeys = ['global_admin', 'industry_director', 'ded', 'circle_leader', 'chair', 'vice_chair', 'secretary', 'member'];
        foreach ($roleKeys as $k) {
            $role = new Role();
            $role->id = (string) Str::uuid();
            $role->name = ucfirst(str_replace('_', ' ', $k));
            $role->key = $k;
            $role->save();
        }
    }

    public function test_non_ded_user_cannot_access_web_dashboard(): void
    {
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'General Admin',
            'email' => 'admin@example.com',
        ]);

        $this->actingAs($admin, 'admin');

        $response = $this->get('/admin/ded-dashboard');
        $response->assertStatus(403);
    }

    public function test_ded_user_can_access_web_dashboard_and_see_assigned_district(): void
    {
        // 1. Get Role
        $role = Role::query()->where('key', 'ded')->firstOrFail();

        // 2. Create AdminUser (DED)
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'DED Ahmedabad',
            'email' => 'ded.ahmedabad@example.com',
        ]);

        // Link Role
        $admin->roles()->attach($role->id);

        // 3. Setup location assignments
        $stateId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('states')->insert([
            'id' => $stateId,
            'name' => 'Gujarat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $districtId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('districts')->insert([
            'id' => $districtId,
            'name' => 'Ahmedabad',
            'state_id' => $stateId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('admin_ded_districts')->insert([
            'id' => (string) Str::uuid(),
            'admin_user_id' => $admin->id,
            'state_id' => $stateId,
            'district_id' => $districtId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 4. Create Circles - one matching Ahmedabad, one matching Bengaluru
        $ahmedabadCityId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('cities')->insert([
            'id' => $ahmedabadCityId,
            'name' => 'Ahmedabad',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bengaluruCityId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('cities')->insert([
            'id' => $bengaluruCityId,
            'name' => 'Bengaluru',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Ahmedabad Circle
        $ahmedabadCircle = Circle::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Ahmedabad Manufacturing Circle',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);

        // Bengaluru Circle
        $bengaluruCircle = Circle::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Bengaluru Founding Members',
            'city_id' => $bengaluruCityId,
            'status' => 'active',
        ]);

        // Put a user in Ahmedabad circle
        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Ahmedabad Member',
            'email' => 'ahm.member@example.com',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);

        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'circle_id' => $ahmedabadCircle->id,
            'role' => 'chair',
            'status' => 'approved',
            'joined_at' => now(),
        ]);

        $this->actingAs($admin, 'admin');

        // Refresh Cache/Static states if any
        \Illuminate\Support\Facades\Cache::flush();

        $response = $this->get('/admin/ded-dashboard');
        $response->assertOk();
        $response->assertViewHas('districtName', 'Ahmedabad');
        
        // Assert we see Ahmedabad Circle but NOT Bengaluru Circle
        $circles = $response->viewData('districtCircles');
        $this->assertTrue($circles->contains('id', $ahmedabadCircle->id));
        $this->assertFalse($circles->contains('id', $bengaluruCircle->id));

        $dashboardData = $response->viewData('dashboardData');
        $this->assertArrayHasKey('master_overview', $dashboardData);
        $overview = $dashboardData['master_overview'];
        
        $this->assertArrayHasKey('total_members', $overview);
        $this->assertArrayHasKey('trend', $overview['total_members']);
        $peersTrend = $overview['total_members']['trend'];
        $this->assertTrue(
            $peersTrend === 'No Change' || 
            str_starts_with($peersTrend, '↑') || 
            str_starts_with($peersTrend, '↓')
        );
        $this->assertStringNotContainsString('%', $peersTrend);
        $this->assertStringNotContainsString('+-', $peersTrend);
        $this->assertStringNotContainsString('+0', $peersTrend);
    }

    public function test_ded_user_can_access_api_dashboard_and_receive_valid_json(): void
    {
        $role = Role::query()->where('key', 'ded')->firstOrFail();

        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'DED Ahmedabad',
            'email' => 'ded.ahmedabad@example.com',
        ]);
        $admin->roles()->attach($role->id);

        $stateId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('states')->insert([
            'id' => $stateId,
            'name' => 'Gujarat',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $districtId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('districts')->insert([
            'id' => $districtId,
            'name' => 'Ahmedabad',
            'state_id' => $stateId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('admin_ded_districts')->insert([
            'id' => (string) Str::uuid(),
            'admin_user_id' => $admin->id,
            'state_id' => $stateId,
            'district_id' => $districtId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create the app user corresponding to the admin user
        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Ahmedabad DED Peer',
            'email' => 'ded.ahmedabad@example.com',
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        // Flush cache
        \Illuminate\Support\Facades\Cache::flush();

        $response = $this->getJson('/api/v1/ded/dashboard');
        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'master_overview',
                'leadership_overview',
                'role_breakdown',
                'circle_overview',
                'pending_requests',
                'health_score',
                'activity_feed',
                'leadership_quick_finder',
            ]
        ]);
    }

    public function test_ded_dashboard_kpis_and_role_listings_match_exactly(): void
    {
        $dedRole = Role::query()->where('key', 'ded')->firstOrFail();
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'DED Ahmedabad',
            'email' => 'ded.ahmedabad.test@example.com',
        ]);
        $admin->roles()->attach($dedRole->id);

        User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Ahmedabad DED Peer',
            'email' => 'ded.ahmedabad.test@example.com',
            'status' => 'active',
        ]);

        $stateId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('states')->insert([
            'id' => $stateId, 'name' => 'Gujarat', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $districtId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('districts')->insert([
            'id' => $districtId, 'name' => 'Ahmedabad', 'state_id' => $stateId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('admin_ded_districts')->insert([
            'id' => (string) Str::uuid(), 'admin_user_id' => $admin->id, 'state_id' => $stateId, 'district_id' => $districtId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ahmedabadCityId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('cities')->insert([
            'id' => $ahmedabadCityId, 'name' => 'Ahmedabad', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $bengaluruCityId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('cities')->insert([
            'id' => $bengaluruCityId, 'name' => 'Bengaluru', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // 1. In-scope circle (Ahmedabad)
        $ahmedabadCircle = Circle::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Ahmedabad Winners Circle',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);

        // 2. Out-of-scope circle (Bengaluru)
        $bengaluruCircle = Circle::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Bengaluru Tech Circle',
            'city_id' => $bengaluruCityId,
            'status' => 'active',
        ]);

        // Leader 1: Ahmedabad resident, founder of Ahmedabad circle
        $user1 = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Founder Ahmedabad',
            'email' => 'founder.ahm@example.com',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);
        $ahmedabadCircle->founder_user_id = $user1->id;
        $ahmedabadCircle->save();

        // Leader 2: Bengaluru resident, director of Ahmedabad circle (lives elsewhere but should be in DED scope due to role)
        $user2 = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Director Ahmedabad Out',
            'email' => 'director.out@example.com',
            'city_id' => $bengaluruCityId,
            'status' => 'active',
        ]);
        $ahmedabadCircle->director_user_id = $user2->id;
        $ahmedabadCircle->save();

        // User 3: Ahmedabad resident, founder of Bengaluru circle (should NOT show up in Ahmedabad DED's founder list)
        $user3 = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Founder Bengaluru In Ahm',
            'email' => 'founder.ben.in.ahm@example.com',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);
        $bengaluruCircle->founder_user_id = $user3->id;
        $bengaluruCircle->save();

        // Join Requests
        // Request A: status pending_cd_approval, DED status pending -> pending approval
        $reqA = \Illuminate\Support\Facades\DB::table('circle_join_requests')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user1->id,
            'circle_id' => $ahmedabadCircle->id,
            'status' => 'pending_cd_approval',
            'ded_approval_status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Request B: status pending_cd_approval, DED status approved -> approved
        $reqB = \Illuminate\Support\Facades\DB::table('circle_join_requests')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user1->id,
            'circle_id' => $ahmedabadCircle->id,
            'status' => 'pending_cd_approval',
            'ded_approval_status' => 'approved',
            'ded_approved_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Request C: status pending_circle_fee, fee_paid_at null -> pending payment
        $reqC = \Illuminate\Support\Facades\DB::table('circle_join_requests')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $user1->id,
            'circle_id' => $ahmedabadCircle->id,
            'status' => 'pending_circle_fee',
            'fee_paid_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'admin');
        \App\Support\AdminCircleScope::resetCache();
        \Illuminate\Support\Facades\Cache::flush();

        // Request dashboard
        $response = $this->get('/admin/ded-dashboard');
        $response->assertOk();

        $overview = $response->viewData('dashboardData')['master_overview'];
        $leadership = $response->viewData('dashboardData')['leadership_overview'];

        // Assert pending DED approvals count is 1 (Request A, not Request B or Request C)
        $this->assertEquals(1, $overview['pending_approvals']['value']);
        // Assert pending payments count is 1 (Request C)
        $this->assertEquals(1, $overview['pending_payments']['value']);

        // Assert circle founder count is 1 (user1, not user3)
        $this->assertEquals(1, $leadership['circle_founders']['count']);
        // Assert circle director count is 1 (user2)
        $this->assertEquals(1, $leadership['circle_direct']['count']);

        // Request listing pages
        // 1. Founders listing
        $foundersResponse = $this->get('/admin/users?role=founder');
        $foundersResponse->assertOk();
        $foundersList = $foundersResponse->viewData('users');
        $this->assertCount(1, $foundersList);
        $this->assertEquals($user1->id, $foundersList->first()->id);

        // 2. Directors listing
        $directorsResponse = $this->get('/admin/users?role=director');
        $directorsResponse->assertOk();
        $directorsList = $directorsResponse->viewData('users');
        $this->assertCount(1, $directorsList);
        $this->assertEquals($user2->id, $directorsList->first()->id);

        // 3. Pending Approvals list
        $approvalsResponse = $this->get('/admin/pending-requests/circle-joining-requests?status=pending_cd_approval');
        $approvalsResponse->assertOk();
        $approvalsList = $approvalsResponse->viewData('requests');
        $this->assertCount(1, $approvalsList);

        // 4. Pending Payments list
        $paymentsResponse = $this->get('/admin/pending-requests/circle-joining-requests?status=pending_circle_fee');
        $paymentsResponse->assertOk();
        $paymentsList = $paymentsResponse->viewData('requests');
        $this->assertCount(1, $paymentsList);
    }

    public function test_ded_user_access_permissions_for_user_profile_and_edit(): void
    {
        $dedRole = Role::query()->where('key', 'ded')->firstOrFail();
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'DED Ahmedabad',
            'email' => 'ded.ahm.profile@example.com',
        ]);
        $admin->roles()->attach($dedRole->id);

        $stateId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('states')->insert([
            'id' => $stateId, 'name' => 'Gujarat', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $districtId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('districts')->insert([
            'id' => $districtId, 'name' => 'Ahmedabad', 'state_id' => $stateId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('admin_ded_districts')->insert([
            'id' => (string) Str::uuid(), 'admin_user_id' => $admin->id, 'state_id' => $stateId, 'district_id' => $districtId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ahmedabadCityId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('cities')->insert([
            'id' => $ahmedabadCityId, 'name' => 'Ahmedabad', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $bengaluruCityId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('cities')->insert([
            'id' => $bengaluruCityId, 'name' => 'Bengaluru', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ahmedabadCircle = Circle::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Ahmedabad Winners Circle',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);

        $bengaluruCircle = Circle::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Bengaluru Tech Circle',
            'city_id' => $bengaluruCityId,
            'status' => 'active',
        ]);

        // User A: in-scope (Ahmedabad circle member)
        $userA = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'In Scope Member',
            'email' => 'in.scope@example.com',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);
        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $userA->id,
            'circle_id' => $ahmedabadCircle->id,
            'role' => 'member',
            'status' => 'approved',
            'joined_at' => now(),
        ]);

        // User B: out-of-scope (Bengaluru circle member only)
        $userB = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Out Scope Member',
            'email' => 'out.scope@example.com',
            'city_id' => $bengaluruCityId,
            'status' => 'active',
        ]);
        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $userB->id,
            'circle_id' => $bengaluruCircle->id,
            'role' => 'member',
            'status' => 'approved',
            'joined_at' => now(),
        ]);

        $this->actingAs($admin, 'admin');
        \App\Support\AdminCircleScope::resetCache();
        \Illuminate\Support\Facades\Cache::flush();

        // 1. DED user should be able to VIEW in-scope user details
        $viewInScope = $this->get("/admin/users/{$userA->id}");
        $viewInScope->assertOk();
        $viewInScope->assertViewHas('isReadOnly', true);

        // 2. DED user should get 403 when trying to VIEW out-of-scope user details
        $viewOutScope = $this->get("/admin/users/{$userB->id}");
        $viewOutScope->assertStatus(403);

        // 3. DED user should get 403 when trying to EDIT in-scope user details
        $editInScope = $this->get("/admin/users/{$userA->id}/edit");
        $editInScope->assertStatus(403);

        // 4. DED user should be able to access leadership details drilldown
        $drilldown = $this->get("/admin/ded-dashboard/leadership/member");
        $drilldown->assertOk();
        $drilldown->assertViewHas('records');
        $records = $drilldown->viewData('records');
        $this->assertNotEmpty($records);
        $this->assertArrayHasKey('circle_memberships_list', $records[0]);
        $this->assertArrayHasKey('leadership_roles_list', $records[0]);
        $this->assertArrayHasKey('coins_balance', $records[0]);
    }

    public function test_ded_user_can_access_health_score_drilldowns(): void
    {
        $dedRole = Role::query()->where('key', 'ded')->firstOrFail();
        $admin = AdminUser::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'DED Ahmedabad',
            'email' => 'ded.ahm.health@example.com',
        ]);
        $admin->roles()->attach($dedRole->id);

        $stateId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('states')->insert([
            'id' => $stateId, 'name' => 'Gujarat', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $districtId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('districts')->insert([
            'id' => $districtId, 'name' => 'Ahmedabad', 'state_id' => $stateId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('admin_ded_districts')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'admin_user_id' => $admin->id, 'state_id' => $stateId, 'district_id' => $districtId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ahmedabadCityId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('cities')->insert([
            'id' => $ahmedabadCityId, 'name' => 'Ahmedabad', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ahmedabadCircle = Circle::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Ahmedabad Winners Circle',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);

        $userA = User::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Active Member',
            'email' => 'active.ahm@example.com',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
            'last_login_at' => now(),
        ]);

        CircleMember::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $userA->id,
            'circle_id' => $ahmedabadCircle->id,
            'role' => 'chair',
            'status' => 'approved',
            'joined_at' => now(),
        ]);

        $this->actingAs($admin, 'admin');
        \App\Support\AdminCircleScope::resetCache();
        \Illuminate\Support\Facades\Cache::flush();

        // 1. Active Members
        $response = $this->get('/admin/ded-dashboard/health/active-members');
        $response->assertOk();
        $response->assertViewHas('records');
        $response->assertViewHas('summary');
        $this->assertEquals(1, $response->viewData('summary')['numerator']);

        // 2. Leadership Spots
        $response = $this->get('/admin/ded-dashboard/health/leadership-spots');
        $response->assertOk();
        $response->assertViewHas('records');
        $response->assertViewHas('summary');
        $this->assertEquals(0, $response->viewData('summary')['numerator']); // VC and Sec are vacant

        // 3. Membership Conversion
        $response = $this->get('/admin/ded-dashboard/health/membership-conversion');
        $response->assertOk();
        $response->assertViewHas('records');
        $response->assertViewHas('summary');

        // 4. Referral Activity
        $response = $this->get('/admin/ded-dashboard/health/referral-activity');
        $response->assertOk();
        $response->assertViewHas('records');
        $response->assertViewHas('summary');
    }

    public function test_ded_user_can_access_industries_overview(): void
    {
        $dedRole = Role::query()->where('key', 'ded')->firstOrFail();
        $admin = AdminUser::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'DED Ahmedabad',
            'email' => 'ded.ahm.ind.overview@example.com',
        ]);
        $admin->roles()->attach($dedRole->id);

        $stateId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('states')->insert([
            'id' => $stateId, 'name' => 'Gujarat', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $districtId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('districts')->insert([
            'id' => $districtId, 'name' => 'Ahmedabad', 'state_id' => $stateId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('admin_ded_districts')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'admin_user_id' => $admin->id, 'state_id' => $stateId, 'district_id' => $districtId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ahmedabadCityId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('cities')->insert([
            'id' => $ahmedabadCityId, 'name' => 'Ahmedabad', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ahmedabadCircle = Circle::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Ahmedabad Manufacturing Circle',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);

        // Create circle category
        $catId = \Illuminate\Support\Facades\DB::table('circle_categories')->insertGetId([
            'name' => 'Manufacturing & Engineering Circles',
            'parent_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('circle_category_mappings')->insert([
            'circle_id' => $ahmedabadCircle->id,
            'category_id' => $catId,
        ]);

        $user = User::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Active Member',
            'email' => 'active.ahm.ind@example.com',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
            'main_business_category_id' => $catId,
            'last_login_at' => now(),
        ]);

        CircleMember::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'circle_id' => $ahmedabadCircle->id,
            'role' => 'chair',
            'status' => 'approved',
            'joined_at' => now(),
        ]);

        $this->actingAs($admin, 'admin');
        \App\Support\AdminCircleScope::resetCache();
        \Illuminate\Support\Facades\Cache::flush();

        $response = $this->get('/admin/ded-dashboard/industries');
        $response->assertOk();
        $response->assertViewHas('records');
        $response->assertViewHas('summary');
        
        $records = $response->viewData('records');
        $this->assertNotEmpty($records);
        $this->assertEquals('Manufacturing & Engineering Circles', $records[0]['name']);
        $this->assertEquals(1, $records[0]['members_count']);
        $this->assertEquals(1, $records[0]['circles_count']);
    }

    public function test_ded_user_can_access_industry_detail(): void
    {
        $dedRole = Role::query()->where('key', 'ded')->firstOrFail();
        $admin = AdminUser::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'DED Ahmedabad',
            'email' => 'ded.ahm.ind.detail@example.com',
        ]);
        $admin->roles()->attach($dedRole->id);

        $stateId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('states')->insert([
            'id' => $stateId, 'name' => 'Gujarat', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $districtId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('districts')->insert([
            'id' => $districtId, 'name' => 'Ahmedabad', 'state_id' => $stateId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('admin_ded_districts')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'admin_user_id' => $admin->id, 'state_id' => $stateId, 'district_id' => $districtId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ahmedabadCityId = (string) \Illuminate\Support\Str::uuid();
        \Illuminate\Support\Facades\DB::table('cities')->insert([
            'id' => $ahmedabadCityId, 'name' => 'Ahmedabad', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ahmedabadCircle = Circle::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Ahmedabad Manufacturing Circle',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);

        // Create circle category
        $catId = \Illuminate\Support\Facades\DB::table('circle_categories')->insertGetId([
            'name' => 'Manufacturing & Engineering Circles',
            'parent_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('circle_category_mappings')->insert([
            'circle_id' => $ahmedabadCircle->id,
            'category_id' => $catId,
        ]);

        $user = User::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'first_name' => 'Active Member',
            'email' => 'active.ahm.ind@example.com',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
            'main_business_category_id' => $catId,
            'last_login_at' => now(),
        ]);

        CircleMember::query()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id,
            'circle_id' => $ahmedabadCircle->id,
            'role' => 'chair',
            'status' => 'approved',
            'joined_at' => now(),
        ]);

        $this->actingAs($admin, 'admin');
        \App\Support\AdminCircleScope::resetCache();
        \Illuminate\Support\Facades\Cache::flush();

        $response = $this->get('/admin/ded-dashboard/industries/' . $catId);
        $response->assertOk();
        $response->assertViewHas('summary');
        $response->assertViewHas('members');
        $response->assertViewHas('circles');

        $summary = $response->viewData('summary');
        $this->assertEquals('Manufacturing & Engineering Circles', $summary['name']);
        $this->assertEquals(1, $summary['total_members']);
        $this->assertEquals(1, $summary['total_circles']);
    }

    public function test_ded_user_can_access_new_json_apis(): void
    {
        $dedRole = Role::query()->where('key', 'ded')->firstOrFail();
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'DED Ahmedabad',
            'email' => 'ded.ahm.api.test@example.com',
        ]);
        $admin->roles()->attach($dedRole->id);

        $stateId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('states')->insert([
            'id' => $stateId, 'name' => 'Gujarat', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $districtId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('districts')->insert([
            'id' => $districtId, 'name' => 'Ahmedabad', 'state_id' => $stateId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\DB::table('admin_ded_districts')->insert([
            'id' => (string) Str::uuid(), 'admin_user_id' => $admin->id, 'state_id' => $stateId, 'district_id' => $districtId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ahmedabadCityId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('cities')->insert([
            'id' => $ahmedabadCityId, 'name' => 'Ahmedabad', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $circle = Circle::query()->create([
            'id' => (string) Str::uuid(),
            'name' => 'Ahmedabad Manufacturing',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);

        $catId = \Illuminate\Support\Facades\DB::table('circle_categories')->insertGetId([
            'name' => 'Manufacturing & Engineering',
            'parent_id' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('circle_category_mappings')->insert([
            'circle_id' => $circle->id,
            'category_id' => $catId,
        ]);

        $user = User::query()->create([
            'id' => (string) Str::uuid(),
            'first_name' => 'Ahmedabad DED Peer',
            'email' => 'ded.ahm.api.test@example.com',
            'city_id' => $ahmedabadCityId,
            'status' => 'active',
        ]);

        CircleMember::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'circle_id' => $circle->id,
            'role' => 'chair',
            'status' => 'approved',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($user);

        // Flush cache
        \App\Support\AdminCircleScope::resetCache();
        \Illuminate\Support\Facades\Cache::flush();

        // 1. Drilldowns
        $this->getJson('/api/v1/ded/drilldowns/active-members')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['summary']]);

        $this->getJson('/api/v1/ded/drilldowns/leadership-spots')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['summary']]);

        $this->getJson('/api/v1/ded/drilldowns/membership-conversion')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['total_requests', 'approved', 'rejected', 'conversion_percentage', 'records'], 'meta' => ['summary']]);

        $this->getJson('/api/v1/ded/drilldowns/referral-activity')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['summary']]);

        // 2. Industries
        $this->getJson('/api/v1/ded/industries')
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data', 'meta' => ['summary']]);

        $this->getJson('/api/v1/ded/industries/' . $catId)
            ->assertOk()
            ->assertJsonStructure(['success', 'message', 'data' => ['summary', 'members', 'circles']]);

        // 3. Leadership Roles
        foreach (['industry-directors', 'founders', 'directors', 'chairs', 'vice-chairs', 'secretaries', 'members'] as $role) {
            $this->getJson("/api/v1/ded/leadership/{$role}")
                ->assertOk()
                ->assertJsonStructure([
                    'success',
                    'message',
                    'data' => [
                        'summary' => [
                            'total_count',
                            'revenue_contribution',
                            'members_managed',
                            'circles_covered',
                            'district_coverage'
                        ],
                        'records'
                    ]
                ]);
        }
    }

    private function createSchema(): void
    {
        Schema::dropIfExists('circle_category_mappings');
        Schema::dropIfExists('circle_categories');
        Schema::dropIfExists('circle_subscriptions');
        Schema::dropIfExists('circle_members');
        Schema::dropIfExists('circles');
        Schema::dropIfExists('admin_ded_districts');
        Schema::dropIfExists('districts');
        Schema::dropIfExists('states');
        Schema::dropIfExists('admin_user_roles');
        Schema::dropIfExists('admin_users');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('users');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('p2p_meetings');
        Schema::dropIfExists('business_deals');
        Schema::dropIfExists('testimonials');
        Schema::dropIfExists('requirements');
        Schema::dropIfExists('visitor_registrations');
        Schema::dropIfExists('event_registration_requests');
        Schema::dropIfExists('coin_claim_requests');
        Schema::dropIfExists('circle_join_requests');
        Schema::dropIfExists('impacts');
        Schema::dropIfExists('industry_director_assignments');
        Schema::dropIfExists('industries');

        Schema::create('states', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('districts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('state_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('cities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('display_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company_name')->nullable();
            $table->string('membership_status')->nullable();
            $table->integer('coins_balance')->default(0);
            $table->integer('life_impacted_count')->default(0);
            $table->timestamp('last_login_at')->nullable();
            $table->uuid('city_id')->nullable();
            $table->string('city')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('main_business_category_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('admin_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('key')->unique();
            $table->timestamps();
        });

        Schema::create('admin_user_roles', function (Blueprint $table): void {
            $table->uuid('user_id');
            $table->uuid('role_id');
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('admin_ded_districts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('admin_user_id');
            $table->uuid('state_id')->nullable();
            $table->uuid('district_id')->nullable();
            $table->string('district_name')->nullable();
            $table->string('state_name')->nullable();
            $table->timestamps();
        });

        Schema::create('circles', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->uuid('city_id')->nullable();
            $table->string('city')->nullable();
            $table->uuid('founder_user_id')->nullable();
            $table->uuid('director_user_id')->nullable();
            $table->uuid('industry_director_user_id')->nullable();
            $table->string('status')->default('active');
            $table->string('circle_stage')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('circle_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('circle_id');
            $table->string('status')->nullable();
            $table->string('role')->nullable();
            $table->uuid('role_id')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('paid_ends_at')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('circle_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('circle_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('circle_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('circle_category_mappings', function (Blueprint $table): void {
            $table->uuid('circle_id');
            $table->unsignedBigInteger('category_id');
            $table->primary(['circle_id', 'category_id']);
        });

        Schema::create('referrals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('from_user_id');
            $table->uuid('to_user_id');
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('p2p_meetings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('initiator_user_id');
            $table->uuid('peer_user_id');
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('business_deals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('from_user_id');
            $table->uuid('to_user_id');
            $table->decimal('deal_amount', 15, 2)->nullable();
            $table->boolean('is_deleted')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('requirements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('testimonials', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('from_user_id');
            $table->uuid('to_user_id');
            $table->boolean('is_deleted')->default(false);
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('circle_join_requests', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('circle_id');
            $table->string('status')->nullable();
            $table->string('ded_approval_status')->nullable();
            $table->timestamp('ded_approved_at')->nullable();
            $table->timestamp('fee_paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('industries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('industry_director_assignments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('admin_user_id');
            $table->uuid('industry_id');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }
}
