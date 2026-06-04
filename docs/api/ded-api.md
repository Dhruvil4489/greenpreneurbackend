# DED API Documentation

Base URL: `{{base_url}}/api/v1/ded`

## OTP authentication

DED admins use the admin OTP login flow for APIs, not the normal `/api/v1/auth/login` password endpoint. These endpoints reuse the existing `admin_users` and `admin_login_otps` storage used by the web admin OTP login, then issue a Sanctum bearer token for the DED admin context.

| Method | Endpoint | Description |
| --- | --- | --- |
| POST | `/auth/request-otp` | Sends a 4-digit OTP to an existing DED admin email from `admin_users`. |
| POST | `/auth/verify-otp` | Verifies the OTP, confirms the admin has the `ded` role and an assigned district, and returns a Sanctum bearer token. |

Request OTP body:

```json
{
  "email": "dhruv99h@gmail.com"
}
```

Verify OTP body:

```json
{
  "email": "dhruv99h@gmail.com",
  "otp": "1234"
}
```

Verify success response:

```json
{
  "success": true,
  "message": "DED login successful.",
  "data": {
    "token": "SANCTUM_TOKEN",
    "token_type": "Bearer",
    "admin": {
      "id": "uuid",
      "email": "dhruv99h@gmail.com",
      "role": "ded",
      "district": "Ahmedabad",
      "state": "Gujarat"
    }
  },
  "meta": {}
}
```

Authentication headers for every protected endpoint:

```http
Authorization: Bearer {{token}}
Accept: application/json
Content-Type: application/json
```

Protected endpoints require Sanctum authentication and the token must belong to a DED admin user, or to an app user whose email maps to a DED admin user, with an assigned DED district. Every list, detail, report, and action endpoint applies the same assigned-district scope used by the DED web module.

## Response format

Success:

```json
{
  "success": true,
  "message": "Message here",
  "data": {},
  "meta": {}
}
```

Error:

```json
{
  "success": false,
  "message": "Error message",
  "errors": {}
}
```

Common error statuses: `401` unauthenticated, `403` not a DED / missing district / cross-district record, `404` missing record, `422` validation error.

## Endpoints

| Method | URL | Description | Query/body |
|---|---|---|---|
| GET | `/me` | DED profile, role, assigned district/state, permissions, available modules | none |
| GET | `/dashboard` | District dashboard stats and latest peers | `circle_id`, `date_from`, `date_to` |
| GET | `/circles` | District circles | `search`, `status`, `per_page`, `page` |
| GET | `/peers` | District peers | `search`, `circle_id`, `membership_status`, `status`, `per_page`, `page` |
| GET | `/peers/{id}` | District peer detail | path UUID; 403 outside district |
| GET | `/activities/summary` | Peer activity summary and top 5 district peers | `search`, `circle_id`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/activities/recommend-a-peer` | District-scoped recommend-a-peer activity submissions | `search`, `circle_id`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/activities/find-build-collaborations` | District-scoped collaboration posts | `search`, `circle_id`, `status`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/activities/register-a-visitor` | District-scoped register-a-visitor activity submissions | `search`, `circle_id`, `status`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/referral-report` | District-scoped referral report | `search`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/life-impact` | District-scoped life impact report | `search`, `circle_id`, `per_page`, `page` |
| GET | `/activities/testimonials` | District testimonials | `search`, `circle_id`, `date_from`, `date_to`, `has_media`, `per_page`, `page` |
| GET | `/activities/testimonials/{id}` | Testimonial detail | path UUID; 403/404 outside district scope |
| GET | `/activities/requirements` | District requirements | `search`, `circle_id`, `status`, `category`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/activities/requirements/{id}` | Requirement detail | path UUID |
| GET | `/activities/referrals` | District referrals | `search`, `circle_id`, `referral_type`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/activities/referrals/{id}` | Referral detail | path UUID |
| GET | `/activities/p2p-meetings` | District P2P meetings | `search`, `circle_id`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/activities/p2p-meetings/{id}` | P2P meeting detail | path UUID |
| GET | `/activities/business-deals` | District business deals | `search`, `circle_id`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/activities/business-deals/{id}` | Business deal detail | path UUID |
| GET | `/coins` | District peer coin summary | `search`, `circle_id`, `per_page`, `page` |
| GET | `/coins/history` | District coin ledger | `user_id`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/pending-requests/summary` | Counts for all DED pending request modules | none |
| GET | `/pending-requests/visitor-registrations` | District visitor registrations | `search`, `status`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/pending-requests/visitor-registrations/{id}` | Visitor registration detail | path UUID |
| POST | `/pending-requests/visitor-registrations/{id}/approve` | Approve scoped visitor registration | optional `admin_note`/`remarks` |
| POST | `/pending-requests/visitor-registrations/{id}/reject` | Reject scoped visitor registration | required `reason` |
| GET | `/pending-requests/event-joining-requests` | District event joining requests using `event_registration_requests` | `search`, `status`, `event_id`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/pending-requests/event-joining-requests/{id}` | Event joining request detail | path UUID |
| POST | `/pending-requests/event-joining-requests/{id}/approve` | Approve scoped event joining request | optional `admin_note`/`remarks` |
| POST | `/pending-requests/event-joining-requests/{id}/reject` | Reject scoped event joining request | required `reason` |
| GET | `/pending-requests/coin-claims` | District coin claims | `search`, `status`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/pending-requests/coin-claims/{id}` | Coin claim detail | path UUID |
| POST | `/pending-requests/coin-claims/{id}/approve` | Approve scoped coin claim | optional `admin_note`/`remarks` |
| POST | `/pending-requests/coin-claims/{id}/reject` | Reject scoped coin claim | required `reason` |
| GET | `/pending-requests/circle-joining-requests` | District circle join requests | `search`, `status`, `circle_id`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/pending-requests/circle-joining-requests/{id}` | Circle join request detail | path UUID |
| POST | `/pending-requests/circle-joining-requests/{id}/ded-approve` | DED approve scoped circle join request | optional `admin_note`/`remarks`; stores DED approver/time/status/audit |
| POST | `/pending-requests/circle-joining-requests/{id}/reject` | DED reject scoped circle join request | required `reason` |
| GET | `/pending-requests/pending-impacts` | District pending impacts | `search`, `status`, `date_from`, `date_to`, `per_page`, `page` |
| GET | `/pending-requests/pending-impacts/{id}` | Pending impact detail | path UUID |
| POST | `/pending-requests/pending-impacts/{id}/approve` | Approve scoped pending impact | optional `admin_note`/`remarks` |
| POST | `/pending-requests/pending-impacts/{id}/reject` | Reject scoped pending impact | required `reason` |
| GET | `/reports/referrals` | District referral report JSON | report filters |
| GET | `/reports/activities` | District activities report JSON | report filters |
| GET | `/reports/coins` | District coins report JSON | report filters |
| GET | `/reports/pending-requests` | District pending request report JSON | none |

## Example: dashboard success

```json
{
  "success": true,
  "message": "DED dashboard loaded.",
  "data": {
    "total_district_peers": 44,
    "total_district_circles": 33,
    "total_referrals": 35,
    "total_requirements": 2,
    "total_testimonials": 15,
    "total_business_deals": 37,
    "total_p2p_meetings": 180,
    "total_coins_earned": 741593,
    "pending_requests": 12,
    "latest_district_peers": []
  },
  "meta": {}
}
```

## Example: rejection body

```json
{
  "reason": "Does not meet district approval criteria."
}
```

## Security validation checklist

For a DED assigned to Ahmedabad:

- `/dashboard`, `/circles`, `/peers`, `/activities/*`, `/coins*`, `/pending-requests*`, and `/reports*` must return Ahmedabad records only.
- Passing another district's `circle_id`, `user_id`, or record ID must return `403` or `404` and must never reveal the record.
- Search and pagination are applied after the DED district query scope.
- Circle joining DED approval writes `ded_approved_by`, `ded_approved_at`, `ded_approval_status`, transitions to pending circle fee, and writes an audit log when the audit table exists.

## Database

No new database changes are required for the API layer. Existing DED manual SQL must already be applied, including `database/manual_sql/2026_06_03_ded_district_assignments.sql` and `database/manual_sql/2026_06_03_ded_circle_join_approval.sql`.
