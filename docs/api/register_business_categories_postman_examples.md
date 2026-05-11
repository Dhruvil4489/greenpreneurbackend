# Register API Business Category / Residence / Referral Examples

These examples are safe additions to the existing `POST /api/v1/auth/register` flow. All newly added fields are optional, so older registration payloads continue to work.

## Register with optional business category, residence city, and referral

```http
POST /api/v1/auth/register
Content-Type: application/json
Accept: application/json
```

```json
{
  "first_name": "Jay",
  "last_name": "Kanjariya",
  "display_name": "Jay Kanjariya",
  "email": "jay@example.com",
  "phone": "9876543210",
  "password": "password123",
  "password_confirmation": "password123",
  "city_id": "optional_existing_city_uuid",
  "city_of_residence": "Ahmedabad",
  "main_business_category_id": 1,
  "business_category_id": 1,
  "referred_by_user_id": "optional_referrer_user_uuid",
  "referral_code": "JAYKA1234"
}
```

Expected response includes these fields inside `data.user` when available:

```json
{
  "main_business_category": {
    "id": 1,
    "name": "Manufacturing & Engineering Circles"
  },
  "business_category": {
    "id": 1,
    "name": "Steel Manufacturing"
  },
  "city_of_residence": "Ahmedabad",
  "referred_by": {
    "id": "uuid",
    "display_name": "Referrer Name"
  }
}
```

## Fetch main business categories

```http
GET /api/v1/business-categories/main
Accept: application/json
```

```json
{
  "success": true,
  "message": "Main business categories fetched successfully.",
  "data": [
    {
      "id": 1,
      "name": "Manufacturing & Engineering Circles"
    }
  ]
}
```

## Fetch child business categories

Call this endpoint level by level: main category to Level 2, Level 2 to Level 3, and Level 3 to Level 4.

```http
GET /api/v1/business-categories/{parent_id}/children
Accept: application/json
```

```json
{
  "success": true,
  "message": "Business sub categories fetched successfully.",
  "data": [
    {
      "id": 1,
      "name": "Steel Manufacturing",
      "level": 4,
      "parent_id": 1
    }
  ]
}
```

## Search referral users

```http
GET /api/v1/referrals/search?q=jay
Accept: application/json
```

```json
{
  "success": true,
  "message": "Referral users fetched successfully.",
  "data": [
    {
      "user_id": "uuid",
      "display_name": "Jay Kanjariya",
      "company_name": "ABC Company",
      "referral_code": "JAYKA1234"
    }
  ]
}
```
