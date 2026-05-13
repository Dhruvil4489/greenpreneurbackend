<?php

namespace Tests\Unit;

use App\Http\Resources\CircleMemberResource;
use App\Models\Circle;
use App\Models\CircleCategory;
use App\Models\CircleCategoryLevel2;
use App\Models\CircleCategoryLevel3;
use App\Models\CircleCategoryLevel4;
use App\Models\CircleMember;
use App\Models\City;
use App\Models\JoinedCircleCategory;
use App\Models\User;
use Illuminate\Http\Request;
use Tests\TestCase;

class CircleMemberResourceTest extends TestCase
{
    public function test_it_enriches_user_with_city_and_joined_circle_categories(): void
    {
        $city = new City([
            'name' => 'Ahmedabad',
        ]);
        $city->id = 'city-123';

        $user = new User([
            'display_name' => 'Unity Member',
            'email' => 'member@example.com',
            'city_id' => $city->id,
            'business_sub_category' => 'Software Development',
        ]);
        $user->id = 'user-123';
        $user->setRelation('city', $city);
        $user->setRelation('joinedCircleCategories', collect([
            $this->joinedCircleCategoryRow(),
        ]));

        $data = $this->resourceUserPayload($user);

        $this->assertSame('city-123', $data['city_id']);
        $this->assertSame('Ahmedabad', $data['city_name']);
        $this->assertSame('Ahmedabad', $data['city']);
        $this->assertSame(1, $data['business_category_id']);
        $this->assertSame('Manufacturing & Engineering Circles', $data['business_category_name']);
        $this->assertSame('Manufacturing & Engineering Circles', $data['business_category']);
        $this->assertSame('Software Development', $data['business_sub_category']);
        $this->assertSame('circle-123', $data['categories'][0]['circle_id']);
        $this->assertSame('test1', $data['categories'][0]['circle_name']);
        $this->assertSame(['id' => 1, 'name' => 'Manufacturing & Engineering Circles'], $data['categories'][0]['level1_category']);
        $this->assertSame(['id' => 1, 'name' => 'CORE MANUFACTURING INDUSTRIES'], $data['categories'][0]['level2_category']);
        $this->assertSame(['id' => 3, 'name' => 'Automotive & Mobility'], $data['categories'][0]['level3_category']);
        $this->assertSame(['id' => 22, 'name' => 'Auto Components Manufacturing'], $data['categories'][0]['level4_category']);
    }

    public function test_it_supports_string_city_and_empty_categories_safely(): void
    {
        $user = new User([
            'display_name' => 'Unity Member',
            'email' => 'member@example.com',
            'city_id' => 'city-456',
            'city' => 'Ahmedabad',
            'business_sub_category' => 'Software Development',
        ]);
        $user->id = 'user-456';
        $user->setRelation('joinedCircleCategories', collect());

        $data = $this->resourceUserPayload($user);

        $this->assertSame('city-456', $data['city_id']);
        $this->assertSame('Ahmedabad', $data['city_name']);
        $this->assertSame('Ahmedabad', $data['city']);
        $this->assertNull($data['business_category_id']);
        $this->assertNull($data['business_category_name']);
        $this->assertNull($data['business_category']);
        $this->assertSame('Software Development', $data['business_sub_category']);
        $this->assertSame([], $data['categories']);
    }

    private function resourceUserPayload(User $user): array
    {
        $member = new CircleMember([
            'circle_id' => 'circle-123',
            'role' => 'member',
            'status' => 'approved',
        ]);
        $member->id = 'member-123';
        $member->setRelation('user', $user);

        $data = (new CircleMemberResource($member))->toArray(Request::create('/'));

        return $data['user'];
    }

    private function joinedCircleCategoryRow(): JoinedCircleCategory
    {
        $circle = new Circle(['name' => 'test1']);
        $circle->id = 'circle-123';

        $level1 = new CircleCategory(['name' => 'Manufacturing & Engineering Circles']);
        $level1->id = 1;

        $level2 = new CircleCategoryLevel2(['name' => 'CORE MANUFACTURING INDUSTRIES']);
        $level2->id = 1;

        $level3 = new CircleCategoryLevel3(['name' => 'Automotive & Mobility']);
        $level3->id = 3;

        $level4 = new CircleCategoryLevel4(['name' => 'Auto Components Manufacturing']);
        $level4->id = 22;

        $row = new JoinedCircleCategory([
            'circle_id' => $circle->id,
            'level1_category_id' => $level1->id,
            'level2_category_id' => $level2->id,
            'level3_category_id' => $level3->id,
            'level4_category_id' => $level4->id,
        ]);
        $row->setRelation('circle', $circle);
        $row->setRelation('level1Category', $level1);
        $row->setRelation('level2Category', $level2);
        $row->setRelation('level3Category', $level3);
        $row->setRelation('level4Category', $level4);

        return $row;
    }
}
